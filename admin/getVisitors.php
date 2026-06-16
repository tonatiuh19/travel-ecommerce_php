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
    $response = [
        'dailyStats' => [],
        'deviceStats' => [],
        'sectionStats' => [],
        'totalVisitors' => 0
    ];

    // Get date range from request (optional)
    $input = json_decode(file_get_contents('php://input'), true);
    $dateFrom = isset($input['dateFrom']) ? $input['dateFrom'] : date('Y-m-d', strtotime('-30 days'));
    $dateTo = isset($input['dateTo']) ? $input['dateTo'] : date('Y-m-d');

    // 1. Get daily visitor statistics
    $sql = "SELECT 
                DATE(datetime) as date,
                COUNT(*) as visitors,
                SUM(CASE WHEN device = 'mobile' THEN 1 ELSE 0 END) as mobile,
                SUM(CASE WHEN device = 'desktop' THEN 1 ELSE 0 END) as desktop
            FROM visitors
            WHERE DATE(datetime) BETWEEN ? AND ?
            GROUP BY DATE(datetime)
            ORDER BY date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['visitors'] = (int)$row['visitors'];
            $row['mobile'] = (int)$row['mobile'];
            $row['desktop'] = (int)$row['desktop'];
            $response['dailyStats'][] = $row;
        }
    }

    // 2. Get device statistics (overall)
    $sql = "SELECT 
                device,
                COUNT(*) as count
            FROM visitors
            WHERE DATE(datetime) BETWEEN ? AND ?
            GROUP BY device";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['count'] = (int)$row['count'];
            $response['deviceStats'][] = $row;
        }
    }

    // 3. Get section statistics (overall)
    $sql = "SELECT 
                section,
                COUNT(*) as count
            FROM visitors
            WHERE DATE(datetime) BETWEEN ? AND ?
            GROUP BY section";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['count'] = (int)$row['count'];
            $response['sectionStats'][] = $row;
        }
    }

    // 4. Get total visitors count
    $sql = "SELECT COUNT(*) as total FROM visitors WHERE DATE(datetime) BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $row = $result->fetch_assoc();
        $response['totalVisitors'] = (int)$row['total'];
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
