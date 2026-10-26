
<?php
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    sendJson([
        "success" => false,
        "message" => "Only GET requests are allowed."
    ], 405);
}

$action = $_GET["action"] ?? "check";

// ==================================================
// ACTION 1: CHECK FOR A PENDING MANUAL COMMAND
// Does not change any status.
// ==================================================
if ($action === "check") {
    // Do not offer a command while another feeding is active.
    $lockResult = $conn->query(
        "SELECT is_locked FROM feeder_lock WHERE id = 1"
    );

    if (!$lockResult) {
        sendJson([
            "success" => false,
            "message" => "Unable to check feeder lock."
        ], 500);
    }

    $lock = $lockResult->fetch_assoc();

    if (!$lock) {
        sendJson([
            "success" => false,
            "message" => "Feeder lock row id=1 is missing."
        ], 500);
    }

    if ((int)$lock["is_locked"] === 1) {
        sendJson([
            "success" => true,
            "command_available" => false,
            "message" => "Another feeding is in progress."
        ]);
    }

    $stmt = $conn->prepare(
        "SELECT id, command_type, dispense_seconds
         FROM feeder_commands
         WHERE status = 'Pending'
           AND command_type = 'Manual'
         ORDER BY id ASC
         LIMIT 1"
    );

    if (!$stmt || !$stmt->execute()) {
        if ($stmt) {
            $stmt->close();
        }

        sendJson([
            "success" => false,
            "message" => "Unable to check manual commands."
        ], 500);
    }

    $result = $stmt->get_result();
    $command = $result->fetch_assoc();
    $stmt->close();

    if (!$command) {
        sendJson([
            "success" => true,
            "command_available" => false,
            "message" => "No pending manual feeding command."
        ]);
    }

    $seconds = (float)$command["dispense_seconds"];

    if ($seconds <= 0 || $seconds > 30) {
        sendJson([
            "success" => false,
            "message" => "Invalid dispensing duration."
        ], 422);
    }

    sendJson([
        "success" => true,
        "command_available" => true,
        "command_id" => (int)$command["id"],
        "command_type" => "Manual",
        "dispense_seconds" => $seconds,
        "status" => "Pending"
    ]);
}

// ==================================================
// ACTION 2: CLAIM A MANUAL COMMAND
// Acquires the shared lock and changes Pending -> Processing
// in one database transaction.
// ==================================================
if ($action === "claim") {
    $commandId = filter_input(
        INPUT_GET,
        "id",
        FILTER_VALIDATE_INT
    );

    if (!$commandId || $commandId <= 0) {
        sendJson([
            "success" => false,
            "message" => "A valid command ID is required."
        ], 400);
    }

    try {
        $conn->begin_transaction();

        // Lock the shared feeder row FIRST.
        $stmt = $conn->prepare(
            "SELECT is_locked, feeding_type, feeding_id
             FROM feeder_lock
             WHERE id = 1
             FOR UPDATE"
        );

        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            throw new Exception("Could not retrieve feeder lock.");
        }

        $lock = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lock) {
            throw new Exception("Feeder lock row id=1 is missing.");
        }

        if ((int)$lock["is_locked"] === 1) {
            $conn->rollback();

            sendJson([
                "success" => false,
                "command_available" => false,
                "message" => "Feeder is busy with another feeding."
            ], 409);
        }

        // Lock and verify this specific pending manual command.
        $stmt = $conn->prepare(
            "SELECT id, command_type, dispense_seconds
             FROM feeder_commands
             WHERE id = ?
               AND command_type = 'Manual'
               AND status = 'Pending'
             FOR UPDATE"
        );

        if (!$stmt) {
            throw new Exception("Could not prepare command lookup.");
        }

        $stmt->bind_param("i", $commandId);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Could not retrieve manual command.");
        }

        $command = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$command) {
            $conn->rollback();

            sendJson([
                "success" => false,
                "command_available" => false,
                "message" => "Command is no longer Pending."
            ], 409);
        }

        $seconds = (float)$command["dispense_seconds"];

        if ($seconds <= 0 || $seconds > 30) {
            $conn->rollback();

            sendJson([
                "success" => false,
                "message" => "Invalid dispensing duration."
            ], 422);
        }

        // Mark command Processing.
        $stmt = $conn->prepare(
            "UPDATE feeder_commands
             SET status = 'Processing'
             WHERE id = ?
               AND command_type = 'Manual'
               AND status = 'Pending'"
        );

        if (!$stmt) {
            throw new Exception("Could not prepare command update.");
        }

        $stmt->bind_param("i", $commandId);

        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception("Could not claim manual command.");
        }

        $stmt->close();

        // Acquire the shared lock for this manual feeding.
        $stmt = $conn->prepare(
            "UPDATE feeder_lock
             SET is_locked = 1,
                 feeding_type = 'Manual',
                 feeding_id = ?,
                 locked_at = NOW()
             WHERE id = 1
               AND is_locked = 0"
        );

        if (!$stmt) {
            throw new Exception("Could not prepare feeder lock update.");
        }

        $stmt->bind_param("i", $commandId);

        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception("Could not acquire feeder lock.");
        }

        $stmt->close();

       

        // Both changes succeed together or neither is saved.
        $conn->commit();

        sendJson([
            "success" => true,
            "command_available" => true,
            "command_id" => (int)$command["id"],
            "command_type" => "Manual",
            "dispense_seconds" => $seconds,
            "status" => "Processing",
            "feeder_locked" => true
        ]);

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
            // Transaction may already be closed.
        }

        error_log("ESP32 manual claim error: " . $e->getMessage());

        sendJson([
            "success" => false,
            "message" => "Unable to claim manual feeding command."
        ], 500);
    }
}

// ==================================================
// INVALID ACTION
// ==================================================
sendJson([
    "success" => false,
    "message" => "Invalid action. Use check or claim."
], 400);
?>
