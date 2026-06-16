<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

require_once('../db_cnn/cnn.php');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? 0;
    $code = $input['code'] ?? '';
    $name = $input['name'] ?? '';
    $city = $input['city'] ?? '';
    
    if (empty($id) || empty($code) || empty($name) || empty($city)) {
        echo json_encode(['success' => false, 'error' => 'ID, code, name, and city are required']);
        exit;
    }
    
    $sql = "UPDATE airports SET code = ?, name = ?, city = ? WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssi', $code, $name, $city, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Airport updated successfully',
            'data' => [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'city' => $city
            ]
        ]);
    } else {
        throw new Exception('Failed to update airport');
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
