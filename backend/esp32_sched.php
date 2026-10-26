
<?php
require_once __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");

    sendJson([
        "success" => false,
        "message" => "Only GET requests are allowed."
    ], 405);
}

// Use the Philippines timezone for schedule comparisons.
$timezone = new DateTimeZone("Asia/Manila");
$now = new DateTimeImmutable("now", $timezone);

// Allow a short grace period for ESP32 polling and claiming.
$graceCutoff = $now
    ->modify("-2 minutes")
    ->format("Y-m-d H:i:s");

try {
    $conn->begin_transaction();

    /*
     * Mark schedules older than the grace period as missed.
     * Only Pending schedules are changed.
     */
    $skip = $conn->prepare("
        UPDATE schedules
        SET status = 'Missed Feeding'
        WHERE status = 'Pending'
          AND TIMESTAMP(feeding_date, feeding_time) < ?
    ");

    if (!$skip) {
        throw new Exception("Could not prepare overdue schedule update.");
    }

    $skip->bind_param("s", $graceCutoff);

    if (!$skip->execute()) {
        throw new Exception("Could not mark overdue schedules.");
    }

    $skippedCount = $skip->affected_rows;
    $skip->close();

    /*
     * Return future schedules and schedules within the
     * 2-minute grace period. Old overdue schedules have
     * already been marked Missed Feeding.
     */
    $stmt = $conn->prepare("
        SELECT
            id,
            feeding_date,
            feeding_time,
            status
        FROM schedules
        WHERE status = 'Pending'
          AND TIMESTAMP(feeding_date, feeding_time) >= ?
        ORDER BY feeding_date ASC, feeding_time ASC
    ");

    if (!$stmt) {
        throw new Exception("Could not prepare schedule lookup.");
    }

    $stmt->bind_param("s", $graceCutoff);

    if (!$stmt->execute()) {
        throw new Exception("Could not retrieve schedules.");
    }

    $result = $stmt->get_result();
    $schedules = [];

    while ($row = $result->fetch_assoc()) {
        $schedules[] = [
            "schedule_id" => (int) $row["id"],
            "feeding_date" => $row["feeding_date"],
            "feeding_time" => $row["feeding_time"],
            "status" => $row["status"]
        ];
    }

    $stmt->close();
    $conn->commit();

    sendJson([
        "success" => true,
        "count" => count($schedules),
        "skipped_count" => $skippedCount,
        "grace_period_minutes" => 2,
        "schedules" => $schedules
    ]);

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log("esp32_sched.php: " . $e->getMessage());

    sendJson([
        "success" => false,
        "message" => "Could not retrieve schedules. Check the PHP error log."
    ], 500);
}
?>
