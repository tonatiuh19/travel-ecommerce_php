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
    
    $code = $input['code'] ?? '';
    $name = $input['name'] ?? '';
    $city = $input['city'] ?? '';
    
    if (empty($code) || empty($name) || empty($city)) {
        echo json_encode(['success' => false, 'error' => 'Code, name, and city are required']);
        exit;
    }
    
    $sql = "INSERT INTO airports (code, name, city) VALUES (?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $code, $name, $city);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            'success' => true,
            'message' => 'Airport created successfully',
            'data' => [
                'id' => $newId,
                'code' => $code,
                'name' => $name,
                'city' => $city
            ]
        ]);
    } else {
        throw new Exception('Failed to create airport');
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
