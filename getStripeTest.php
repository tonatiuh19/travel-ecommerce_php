<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

require_once('db_cnn/cnn.php');

$sql = "SELECT a.test FROM environments as a WHERE a.type = 'stripe'";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    $isTest = (int)$row['test'] === 1;
    echo json_encode($isTest);
} else {
    echo json_encode(['error' => 'Stripe environment not found']);
}

$conn->close();
?>