<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

require_once('../db_cnn/cnn.php');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: id']);
        exit;
    }

    $id = (int)$input['id'];

    // Check if coupon has been used in any bookings
    $checkSql = "SELECT COUNT(*) as booking_count FROM bookings WHERE coupon_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();

    if ($row['booking_count'] > 0) {
        // If coupon has been used, deactivate instead of delete
        $sql = "UPDATE coupons SET is_active = FALSE WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Coupon deactivated (was used in ' . $row['booking_count'] . ' bookings)',
                'deactivated' => true
            ]);
        }
    } else {
        // If coupon hasn't been used, safe to delete
        $sql = "DELETE FROM coupons WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Coupon deleted successfully',
                    'deleted' => true
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Coupon not found'
                ]);
            }
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($checkStmt)) {
        $checkStmt->close();
    }
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>
