
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

if (!$scheduleId || $scheduleId < 1) {
    sendJson([
        "success" => false,
        "message" => "A valid schedule_id is required."
    ], 400);
}

try {
    $conn->begin_transaction();

    // Lock the schedule before claiming it.
    $stmt = $conn->prepare("
    
        SELECT id, feeding_date, feeding_time, dispense_seconds
        FROM schedules
        WHERE id = ?
          AND status = 'Pending'
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
            "message" => "Schedule is unavailable or already claimed."
        ], 409);
    }

    // Only claim a schedule when its scheduled time has arrived.
    $scheduledAt = strtotime(
        $schedule["feeding_date"] . " " . $schedule["feeding_time"]
    );

    if ($scheduledAt === false || $scheduledAt > time()) {
        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "This schedule is not due yet."
        ], 409);
    }

    
    // Reject schedules that are more than 2 minutes overdue.
    $graceSeconds = 120;

    if ($scheduledAt < (time() - $graceSeconds)) {
        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "This schedule is overdue and cannot be claimed."
        ], 409);
    }


    // Atomically change Pending to Processing.
    $update = $conn->prepare("
        UPDATE schedules
        SET status = 'Processing'
        WHERE id = ?
          AND status = 'Pending'
    ");

   if (!$update) {
        throw new Exception("Could not prepare schedule update.");
    }

    $update->bind_param("i", $scheduleId);
    $update->execute();

    if ($update->affected_rows !== 1) {
        $update->close();
        $conn->rollback();

        sendJson([
            "success" => false,
            "message" => "Could not claim this schedule."
        ], 409);
    }

    $update->close();
    $conn->commit();

    sendJson([
        "success" => true,
        "message" => "Schedule claimed successfully.",
        "schedule_id" => (int) $schedule["id"],
        "dispense_seconds" => (float) $schedule["dispense_seconds"],
        "status" => "Processing"
    ]);

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log("claim_schedule.php: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Could not claim the schedule. Check the PHP error log."
    ], 500);
}
?>
