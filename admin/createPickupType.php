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
    
    $typeKey = $input['typeKey'] ?? '';
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? '';
    $requiresAddress = isset($input['requiresAddress']) ? (int)$input['requiresAddress'] : 0;
    $requiresAirport = isset($input['requiresAirport']) ? (int)$input['requiresAirport'] : 0;
    
    if (empty($typeKey) || empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Type key and name are required']);
        exit;
    }
    
    $sql = "INSERT INTO pickup_types (type_key, name, description, requires_address, requires_airport) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssii', $typeKey, $name, $description, $requiresAddress, $requiresAirport);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            'success' => true,
            'message' => 'Pickup type created successfully',
            'data' => [
                'id' => $newId,
                'typeKey' => $typeKey,
                'name' => $name,
                'description' => $description,
                'requiresAddress' => (bool)$requiresAddress,
                'requiresAirport' => (bool)$requiresAirport
            ]
        ]);
    } else {
        throw new Exception('Failed to create pickup type');
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
