
<?php
require_once __DIR__ . "/db.php";

date_default_timezone_set("Asia/Manila");

$method = $_SERVER["REQUEST_METHOD"];

/* GET: Retrieve schedules and mark overdue Pending schedules as missed */
if ($method === "GET") {
    try {
        $now = date("Y-m-d H:i:s");

        // Only Pending schedules can become missed.
        // Processing schedules are left alone.
        $stmt = $conn->prepare("
            UPDATE schedules
            SET status = 'Missed Feeding'
            WHERE status = 'Pending'
              AND TIMESTAMP(feeding_date, feeding_time) < ?
        ");
        $stmt->bind_param("s", $now);
        $stmt->execute();
        $stmt->close();

        $result = $conn->query("
            SELECT *
            FROM schedules
            ORDER BY feeding_date ASC, feeding_time ASC
        ");

        if (!$result) {
            sendJson([
                "success" => false,
                "message" => "Failed to retrieve schedules."
            ], 500);
        }

        $schedules = [];

        while ($row = $result->fetch_assoc()) {
            $schedules[] = $row;
        }

        sendJson([
            "success" => true,
            "schedules" => $schedules
        ]);

    } catch (Throwable $e) {
        error_log("PurrFeed schedules GET: " . $e->getMessage());

        sendJson([
            "success" => false,
            "message" => "Could not retrieve schedules."
        ], 500);
    }
}

/* Read JSON for POST, PUT, and DELETE */
$data = getJsonInput();

/* POST: Add a schedule */
if ($method === "POST") {
    $date = $data["feeding_date"] ?? "";
    $time = $data["feeding_time"] ?? "";
    $seconds = $data["dispense_seconds"] ?? null;

    if (
        !is_string($date) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !is_string($time) ||
        !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) ||
        !is_numeric($seconds) ||
        $seconds < 0.1 ||
        $seconds > 10
    ) {
        sendJson([
            "success" => false,
            "message" => "Invalid date, time, or servo duration."
        ], 400);
    }

    $checkDate = DateTime::createFromFormat("!Y-m-d", $date);
    $checkTimeString = strlen($time) === 5 ? $time . ":00" : $time;

    $checkTime = DateTime::createFromFormat(
        "!H:i:s",
        $checkTimeString
    );

    if (!$checkDate || $checkDate->format("Y-m-d") !== $date) {
        sendJson([
            "success" => false,
            "message" => "Invalid feeding date."
        ], 400);
    }

    if (
        !$checkTime ||
        $checkTime->format("H:i:s") !== $checkTimeString
    ) {
        sendJson([
            "success" => false,
            "message" => "Invalid feeding time."
        ], 400);
    }

    $time = $checkTimeString;

    if (strtotime("$date $time") <= time()) {
        sendJson([
            "success" => false,
            "message" => "Choose a future feeding date and time."
        ], 400);
    }

    $seconds = (float) $seconds;

    $stmt = $conn->prepare("
        INSERT INTO schedules
            (feeding_date, feeding_time, dispense_seconds, status)
        VALUES (?, ?, ?, 'Pending')
    ");

    $stmt->bind_param("ssd", $date, $time, $seconds);

    if (!$stmt->execute()) {
        sendJson([
            "success" => false,
            "message" => "Could not save schedule."
        ], 500);
    }

    $id = $conn->insert_id;
    $stmt->close();

    sendJson([
        "success" => true,
        "message" => "Schedule saved successfully!",
        "id" => $id,
        "status" => "Pending"
    ], 201);
}

/* PUT: Edit a Pending schedule */
if ($method === "PUT") {
    $id = $data["id"] ?? 0;
    $date = $data["feeding_date"] ?? "";
    $time = $data["feeding_time"] ?? "";
    $seconds = $data["dispense_seconds"] ?? null;

    if (
        !filter_var($id, FILTER_VALIDATE_INT) ||
        $id < 1 ||
        !is_string($date) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !is_string($time) ||
        !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) ||
        !is_numeric($seconds) ||
        $seconds < 0.1 ||
        $seconds > 10
    ) {
        sendJson([
            "success" => false,
            "message" => "Invalid schedule information."
        ], 400);
    }

    $checkDate = DateTime::createFromFormat("!Y-m-d", $date);
    $time = strlen($time) === 5 ? $time . ":00" : $time;

    $checkTime = DateTime::createFromFormat("!H:i:s", $time);

    if (!$checkDate || $checkDate->format("Y-m-d") !== $date) {
        sendJson([
            "success" => false,
            "message" => "Invalid feeding date."
        ], 400);
    }

    if (!$checkTime || $checkTime->format("H:i:s") !== $time) {
        sendJson([
            "success" => false,
            "message" => "Invalid feeding time."
        ], 400);
    }

    if (strtotime("$date $time") <= time()) {
        sendJson([
            "success" => false,
            "message" => "Choose a future feeding date and time."
        ], 400);
    }

    $seconds = (float) $seconds;

    $stmt = $conn->prepare("
        UPDATE schedules
        SET feeding_date = ?,
            feeding_time = ?,
            dispense_seconds = ?
        WHERE id = ? AND status = 'Pending'
    ");

    $stmt->bind_param("ssdi", $date, $time, $seconds, $id);

    if (!$stmt->execute()) {
        sendJson([
            "success" => false,
            "message" => "Could not update schedule."
        ], 500);
    }

    if ($stmt->affected_rows === 0) {
        $check = $conn->prepare("
            SELECT id FROM schedules
            WHERE id = ? AND status = 'Pending'
        ");
        $check->bind_param("i", $id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$exists) {
            sendJson([
                "success" => false,
                "message" => "Schedule not found or no longer editable."
            ], 404);
        }
    }

    $stmt->close();

    sendJson([
        "success" => true,
        "message" => "Schedule updated successfully!"
    ]);
}

/* DELETE: Delete a Pending schedule */
if ($method === "DELETE") {
    $id = $data["id"] ?? 0;

    if (!filter_var($id, FILTER_VALIDATE_INT) || $id < 1) {
        sendJson([
            "success" => false,
            "message" => "A valid schedule ID is required."
        ], 400);
    }

    $stmt = $conn->prepare("
        DELETE FROM schedules
        WHERE id = ? AND status = 'Pending'
    ");

    $stmt->bind_param("i", $id);

    if (!$stmt->execute()) {
        sendJson([
            "success" => false,
            "message" => "Could not delete schedule."
        ], 500);
    }

    if ($stmt->affected_rows === 0) {
        sendJson([
            "success" => false,
            "message" => "Schedule not found or already processed."
        ], 404);
    }

    $stmt->close();

    sendJson([
        "success" => true,
        "message" => "Schedule deleted successfully!"
    ]);
}

header("Allow: GET, POST, PUT, DELETE");

sendJson([
    "success" => false,
    "message" => "Method not allowed."
], 405);
?>
