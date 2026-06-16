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
    $isRoundTripOption = isset($input['isRoundTripOption']) ? (int)$input['isRoundTripOption'] : 0;
    $maxPassengers = $input['maxPassengers'] ?? 8;
    
    if (empty($typeKey) || empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Type key and name are required']);
        exit;
    }
    
    $sql = "INSERT INTO transfer_types (type_key, name, description, is_round_trip_option, max_passengers) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssii', $typeKey, $name, $description, $isRoundTripOption, $maxPassengers);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            'success' => true,
            'message' => 'Transfer type created successfully',
            'data' => [
                'id' => $newId,
                'typeKey' => $typeKey,
                'name' => $name,
                'description' => $description,
                'isRoundTripOption' => (bool)$isRoundTripOption,
                'maxPassengers' => (int)$maxPassengers
            ]
        ]);
    } else {
        throw new Exception('Failed to create transfer type');
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
