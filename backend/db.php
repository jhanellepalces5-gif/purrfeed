<?php
date_default_timezone_set("Asia/Manila");

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$database = "purrfeedn";

$conn = new mysqli(
    $host,
    $user,
    $password,
    $database
);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: "
        . $conn->connect_error);
}

// Support special characters
$conn->set_charset("utf8mb4");

// Send JSON responses
function sendJson($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data);
    exit;
}

// Receive JSON input
function getJsonInput()
{
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    return is_array($data) ? $data : [];
}
?>
