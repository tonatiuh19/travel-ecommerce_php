<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once('db_cnn/cnn.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestBody = file_get_contents('php://input');
    $params = json_decode($requestBody, true);

    $device = $params['device'] ?? null;
    $section = $params['section'] ?? null;

    if (!$device || !$section) {
        echo json_encode(['error' => 'Missing device or section']);
        exit;
    }

    $sql = "INSERT INTO visitors (device, section, datetime) VALUES (?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $device, $section);

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(true);
    } else {
        echo json_encode(['error' => 'Insert failed']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

$conn->close();
?>