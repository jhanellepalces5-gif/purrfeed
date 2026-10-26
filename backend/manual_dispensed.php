
<?php
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    sendJson([
        "success" => false,
        "message" => "Only POST requests are allowed."
    ], 405);
}

$data = getJsonInput();

$commandId = $data["command_id"] ?? null;
$status = $data["status"] ?? "";
$seconds = $data["dispense_seconds"] ?? null;

// Validate command ID.
if (
    !filter_var($commandId, FILTER_VALIDATE_INT) ||
    (int)$commandId < 1
) {
    sendJson([
        
        "success" => false,
        "message" => "A valid command_id is required."
    ], 400);
}

// Validate result status.
if (!in_array($status, ["Completed", "Failed"], true)) {
    sendJson([
        "success" => false,
        "message" => "Status must be Completed or Failed."
    ], 400);
}

// Validate dispensing duration.
if (
    !is_numeric($seconds) ||
    !is_finite((float)$seconds) ||
    (float)$seconds <= 0 ||
    (float)$seconds > 30
) {
    sendJson([
        "success" => false,
        "message" => "A valid dispense_seconds value is required."
    ], 400);
}

$commandId = (int)$commandId;
$seconds = (float)$seconds;

$gramsPerSecond = 15.0;
$estimatedGrams = round(
    $seconds * $gramsPerSecond,
    0
);

$conn->begin_transaction();

try {
    // Lock the command while processing its result.
    $stmt = $conn->prepare(
        "SELECT status
         FROM feeder_commands
         WHERE id = ?
           AND command_type = 'Manual'
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception("Could not prepare command lookup.");
    }

    $stmt->bind_param("i", $commandId);
    $stmt->execute();

    $command = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$command) {
        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "Manual command not found."
        ], 404);
    }

    $currentStatus = $command["status"];

    // A previous request may have saved the result,
    // but its HTTP response may not have reached ESP32.
    // Accept a retry without inserting history again.
    if (
        $currentStatus === "Completed" ||
        $currentStatus === "Failed"
    ) {
        if ($currentStatus === $status) {
            $conn->commit();

            sendJson([
                "success" => true,
                "message" => "Result was already saved.",
                "command_id" => $commandId,
                "status" => $currentStatus,
                "duplicate" => true
            ]);
        }

        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "This command already has a different final result.",
            "command_id" => $commandId,
            "status" => $currentStatus
        ], 409);
    }

    // Only Processing commands can receive a new result.
    if ($currentStatus !== "Processing") {
        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "Manual command is not Processing.",
            "status" => $currentStatus
        ], 409);
    }

    // Update the command's final result.
    $update = $conn->prepare(
        "UPDATE feeder_commands
         SET status = ?, dispense_seconds = ?
         WHERE id = ?
           AND command_type = 'Manual'
           AND status = 'Processing'"
    );

    if (!$update) {
        throw new Exception("Could not prepare status update.");
    }

    $update->bind_param(
        "sdi",
        $status,
        $seconds,
        $commandId
    );

    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new Exception("Could not update command.");
    }

    $update->close();

    // Record the feeding history once.
    $scheduleId = null;
    $feedingType = "Manual";

    $history = $conn->prepare(
        "INSERT INTO feeding_history
         (schedule_id, feeding_type, dispense_seconds, status)
         VALUES (?, ?, ?, ?)"
    );

    if (!$history) {
        throw new Exception("Could not prepare feeding history.");
    }

    $history->bind_param(
        "isds",
        $scheduleId,
        $feedingType,
        $seconds,
        $status
    );

    if (!$history->execute()) {
        $history->close();
        throw new Exception("Could not save feeding history.");
    }

    $history->close();

    
    // Release the lock only if it belongs to this manual command.
    $unlock = $conn->prepare(
        "UPDATE feeder_lock
         SET is_locked = 0,
             feeding_type = NULL,
             feeding_id = NULL,
             locked_at = NULL
         WHERE id = 1
           AND is_locked = 1
           AND feeding_type = 'Manual'
           AND feeding_id = ?"
    );

    if (!$unlock) {
        throw new Exception("Could not prepare feeder unlock.");
    }

    $unlock->bind_param("i", $commandId);

    if (!$unlock->execute()) {
        $unlock->close();
        throw new Exception("Could not release feeder lock.");
    }

    $unlock->close();

    // Commit the command result, history, and lock release together.
    
    // Release the lock only if it belongs to this manual command.
    $unlock = $conn->prepare(
        "UPDATE feeder_lock
         SET is_locked = 0,
             feeding_type = NULL,
             feeding_id = NULL,
             locked_at = NULL
         WHERE id = 1
           AND is_locked = 1
           AND feeding_type = 'Manual'
           AND feeding_id = ?"
    );

    if (!$unlock) {
        throw new Exception("Could not prepare feeder unlock.");
    }

    $unlock->bind_param("i", $commandId);

    if (!$unlock->execute()) {
        $unlock->close();
        throw new Exception("Could not release feeder lock.");
    }

    $unlock->close();

    // Commit the command result, history, and lock release together.
    $conn->commit();

 



    sendJson([
        "success" => true,
        "message" => "Manual feeding result saved.",
        "command_id" => $commandId,
        "dispense_seconds" => $seconds,
        "grams_per_second" => $gramsPerSecond,
        "estimated_grams" => $estimatedGrams,
        "status" => $status,
        "duplicate" => false
    ]);

} catch (Throwable $e) {
    $conn->rollback();

    error_log("PurrFeed manual result error: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Could not save the manual feeding result."
    ], 500);
}
?>
