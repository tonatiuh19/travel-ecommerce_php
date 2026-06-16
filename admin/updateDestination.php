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
    $name = $input['name'] ?? '';
    $slug = $input['slug'] ?? '';
    $duration = $input['duration'] ?? '';
    $distance = $input['distance'] ?? '';
    $isRoundTrip = isset($input['isRoundTrip']) ? (int)$input['isRoundTrip'] : 0;
    $description = $input['description'] ?? '';
    
    if (empty($id) || empty($name) || empty($slug)) {
        echo json_encode(['success' => false, 'error' => 'ID, name, and slug are required']);
        exit;
    }
    
    $sql = "UPDATE destinations 
            SET name = ?, slug = ?, duration = ?, distance = ?, is_round_trip = ?, description = ?
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssisi', $name, $slug, $duration, $distance, $isRoundTrip, $description, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Destination updated successfully',
            'data' => [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'duration' => $duration,
                'distance' => $distance,
                'isRoundTrip' => (bool)$isRoundTrip,
                'description' => $description
            ]
        ]);
    } else {
        throw new Exception('Failed to update destination');
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
