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
    
    $transferTypeId = $input['transferTypeId'] ?? 0;
    $destinationId = $input['destinationId'] ?? null;
    $minPassengers = $input['minPassengers'] ?? 1;
    $maxPassengers = $input['maxPassengers'] ?? 8;
    $priceEur = $input['priceEur'] ?? 0;
    $isSingleTrip = isset($input['isSingleTrip']) ? (int)$input['isSingleTrip'] : 0;
    
    if (empty($transferTypeId)) {
        echo json_encode(['success' => false, 'error' => 'Transfer type ID is required']);
        exit;
    }
    
    $sql = "INSERT INTO pricing_tiers (transfer_type_id, destination_id, min_passengers, max_passengers, price_eur, is_single_trip) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiidi', $transferTypeId, $destinationId, $minPassengers, $maxPassengers, $priceEur, $isSingleTrip);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            'success' => true,
            'message' => 'Pricing tier created successfully',
            'data' => [
                'id' => $newId,
                'transferTypeId' => $transferTypeId,
                'destinationId' => $destinationId,
                'minPassengers' => $minPassengers,
                'maxPassengers' => $maxPassengers,
                'priceEur' => (float)$priceEur,
                'isSingleTrip' => (bool)$isSingleTrip
            ]
        ]);
    } else {
        throw new Exception('Failed to create pricing tier');
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
