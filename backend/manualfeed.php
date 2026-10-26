
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


// Change the manual dispensing duration here, in seconds.
$seconds = 1.00;

$stmt = $conn->prepare(
    "INSERT INTO feeder_commands
        (command_type, schedule_id, dispense_seconds, status)
     VALUES ('Manual', NULL, ?, 'Pending')"
);

if (!$stmt) {
    sendJson([
        "success" => false,
        "message" => "Could not prepare manual feeding request."
    ], 500);
}

$stmt->bind_param("d", $seconds);

if (!$stmt->execute()) {
    $stmt->close();

    sendJson([
        "success" => false,
        "message" => "Could not save manual feeding request."
    ], 500);
}

$commandId = $conn->insert_id;
$stmt->close();

sendJson([
    "success" => true,
    "message" => "Manual feeding request saved successfully.",
    "command_id" => $commandId,
    "command_type" => "Manual",
    "dispense_seconds" => $seconds,
    "status" => "Pending"
], 201);
?>
