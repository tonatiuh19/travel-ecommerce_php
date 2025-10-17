<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once('db_cnn/cnn.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestBody = file_get_contents('php://input');
    $params = json_decode($requestBody, true);

    $reservation_code = $params['reservation_code'] ?? null;

    if (!$reservation_code) {
        echo json_encode(['error' => 'Missing reservation_code']);
        exit;
    }

    $sql = "SELECT r.*, 
                   u.name as user_name, u.email as user_email, u.phone as user_phone, 
                   u.country as user_country, u.birth_date as user_birth_date, u.passport_no as user_passport_no
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            WHERE r.reservation_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $reservation_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservation = $result->fetch_assoc();
    $stmt->close();

    if ($reservation) {
        echo json_encode($reservation);
    } else {
        echo json_encode(['error' => 'Reservation not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

$conn->close();
?>