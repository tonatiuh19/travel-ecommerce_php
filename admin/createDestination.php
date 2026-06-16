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
    
    $name = $input['name'] ?? '';
    $slug = $input['slug'] ?? '';
    $duration = $input['duration'] ?? '';
    $distance = $input['distance'] ?? '';
    $isRoundTrip = isset($input['isRoundTrip']) ? (int)$input['isRoundTrip'] : 0;
    $description = $input['description'] ?? '';
    
    if (empty($name) || empty($slug)) {
        echo json_encode(['success' => false, 'error' => 'Name and slug are required']);
        exit;
    }
    
    $sql = "INSERT INTO destinations (name, slug, duration, distance, is_round_trip, description) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss is', $name, $slug, $duration, $distance, $isRoundTrip, $description);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            'success' => true,
            'message' => 'Destination created successfully',
            'data' => [
                'id' => $newId,
                'name' => $name,
                'slug' => $slug,
                'duration' => $duration,
                'distance' => $distance,
                'isRoundTrip' => (bool)$isRoundTrip,
                'description' => $description
            ]
        ]);
    } else {
        throw new Exception('Failed to create destination');
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
