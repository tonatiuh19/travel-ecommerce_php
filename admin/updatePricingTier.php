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
    $transferTypeId = $input['transferTypeId'] ?? 0;
    $destinationId = $input['destinationId'] ?? null;
    $minPassengers = $input['minPassengers'] ?? 1;
    $maxPassengers = $input['maxPassengers'] ?? 8;
    $priceEur = $input['priceEur'] ?? 0;
    $isSingleTrip = isset($input['isSingleTrip']) ? (int)$input['isSingleTrip'] : 0;
    
    if (empty($id) || empty($transferTypeId)) {
        echo json_encode(['success' => false, 'error' => 'ID and transfer type ID are required']);
        exit;
    }
    
    $sql = "UPDATE pricing_tiers 
            SET transfer_type_id = ?, destination_id = ?, min_passengers = ?, max_passengers = ?, price_eur = ?, is_single_trip = ?
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiidii', $transferTypeId, $destinationId, $minPassengers, $maxPassengers, $priceEur, $isSingleTrip, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Pricing tier updated successfully',
            'data' => [
                'id' => $id,
                'transferTypeId' => $transferTypeId,
                'destinationId' => $destinationId,
                'minPassengers' => $minPassengers,
                'maxPassengers' => $maxPassengers,
                'priceEur' => (float)$priceEur,
                'isSingleTrip' => (bool)$isSingleTrip
            ]
        ]);
    } else {
        throw new Exception('Failed to update pricing tier');
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
