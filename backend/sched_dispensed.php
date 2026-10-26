
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

$scheduleId = filter_var(
    $data["schedule_id"] ?? null,
    FILTER_VALIDATE_INT
);

$status = $data["status"] ?? "";

if (!$scheduleId || $scheduleId < 1) {
    sendJson([
        "success" => false,
        "message" => "A valid schedule_id is required."
    ], 400);
}

if (!in_array($status, ["Completed", "Failed"], true)) {
    sendJson([
        "success" => false,
        "message" => "Status must be Completed or Failed."
    ], 400);
}

try {
    $conn->begin_transaction();

    // Lock the schedule so simultaneous reports cannot
    // update it or insert history twice.
    $stmt = $conn->prepare("
        SELECT id, dispense_seconds, status
        FROM schedules
        WHERE id = ?
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new Exception("Could not prepare schedule lookup.");
    }

    $stmt->bind_param("i", $scheduleId);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$schedule) {
        $conn->rollback();
        sendJson([
            "success" => false,
            "message" => "Schedule not found."
        ], 404);
    }

    // If a result was already saved, check whether this
    // request is an exact duplicate.
    if (in_array($schedule["status"], ["Completed", "Failed"], true)) {
        $historyStmt = $conn->prepare("
            SELECT id, status
            FROM feeding_history
            WHERE schedule_id = ?
              AND feeding_type = 'Automatic'
            LIMIT 1
        ");

        if (!$historyStmt) {
            throw new Exception("Could not prepare history lookup.");
        }

        $historyStmt->bind_param("i", $scheduleId);
        $historyStmt->execute();
        $history = $historyStmt->get_result()->fetch_assoc();
        $historyStmt->close();

        if (
            $schedule["status"] === $status &&
            $history &&
            $history["status"] === $status
        ) {
            $conn->commit();

            sendJson([
                "success" => true,
                "duplicate" => true,
                "message" => "This result was already recorded.",
                "schedule_id" => (int) $scheduleId,
                "status" => $status
            ]);
        }

        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "This schedule already has a different or incomplete result."
        ], 409);
    }

    // Only a claimed schedule may report a result.
    if ($schedule["status"] !== "Processing") {
        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "Schedule must be Processing before reporting a result."
        ], 409);
    }

    $update = $conn->prepare("
        UPDATE schedules
        SET status = ?
        WHERE id = ?
          AND status = 'Processing'
    ");

    if (!$update) {
        throw new Exception("Could not prepare schedule update.");
    }

    $update->bind_param("si", $status, $scheduleId);
    $update->execute();

    if ($update->affected_rows !== 1) {
        $update->close();
        throw new Exception("Schedule status was not updated.");
    }

    $update->close();

    // Insert exactly one history record in this transaction.
    $historyStmt = $conn->prepare("
        INSERT INTO feeding_history
            (schedule_id, feeding_type, dispense_seconds, status)
        VALUES (?, 'Automatic', ?, ?)
    ");

    if (!$historyStmt) {
        throw new Exception("Could not prepare feeding history insert.");
    }

    $seconds = (float) $schedule["dispense_seconds"];
    $historyStmt->bind_param("ids", $scheduleId, $seconds, $status);
    $historyStmt->execute();
    $historyStmt->close();

    $conn->commit();

    sendJson([
        "success" => true,
        "duplicate" => false,
        "message" => "Schedule result recorded successfully.",
        "schedule_id" => (int) $scheduleId,
        "status" => $status
    ]);

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {}

    error_log("sched_dispensed.php: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Could not record the schedule result. Check the PHP error log."
    ], 500);
}
?>
