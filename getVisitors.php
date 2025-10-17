<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once('db_cnn/cnn.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT id, section, device, datetime FROM visitors";
    $result = $conn->query($sql);

    $visitors = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $visitors[] = $row;
        }
        echo json_encode($visitors);
    } else {
        echo json_encode(['error' => 'Query failed']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

$conn->close();
?>