
<?php
require_once __DIR__ . "/db.php";

$sql = "
    SELECT
        id,
        feeding_type,
        dispense_seconds,
        status,
        created_at
    FROM feeding_history
    WHERE feeding_type = 'Manual'
    ORDER BY created_at DESC
";

$result = $conn->query($sql);

if (!$result) {
    sendJson([
        "success" => false,
        "message" => "Could not load manual feeding history."
    ], 500);
}

$records = [];

$gramsPerSecond = 15.0;

while ($row = $result->fetch_assoc()) {
    $seconds = (float) $row["dispense_seconds"];

    $records[] = [
        "id" => (int) $row["id"],
        "date_time" => $row["created_at"],
        "dispense_seconds" => $seconds,
        "estimated_grams" => round($seconds * $gramsPerSecond),
        "status" => $row["status"]
    ];
}

sendJson([
    "success" => true,
    "records" => $records
]);
?>
