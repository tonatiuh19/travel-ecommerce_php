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
    $typeKey = $input['typeKey'] ?? '';
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? '';
    $requiresAddress = isset($input['requiresAddress']) ? (int)$input['requiresAddress'] : 0;
    $requiresAirport = isset($input['requiresAirport']) ? (int)$input['requiresAirport'] : 0;
    
    if (empty($id) || empty($typeKey) || empty($name)) {
        echo json_encode(['success' => false, 'error' => 'ID, type key, and name are required']);
        exit;
    }
    
    $sql = "UPDATE pickup_types 
            SET type_key = ?, name = ?, description = ?, requires_address = ?, requires_airport = ?
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssiii', $typeKey, $name, $description, $requiresAddress, $requiresAirport, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Pickup type updated successfully',
            'data' => [
                'id' => $id,
                'typeKey' => $typeKey,
                'name' => $name,
                'description' => $description,
                'requiresAddress' => (bool)$requiresAddress,
                'requiresAirport' => (bool)$requiresAirport
            ]
        ]);
    } else {
        throw new Exception('Failed to update pickup type');
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
