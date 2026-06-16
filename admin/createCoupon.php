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
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($input['code']) || !isset($input['discountType']) || !isset($input['discountValue'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required fields: code, discountType, discountValue'
        ]);
        exit;
    }

    $code = $conn->real_escape_string(strtoupper(trim($input['code'])));
    $description = isset($input['description']) ? $conn->real_escape_string($input['description']) : null;
    $discountType = $conn->real_escape_string($input['discountType']);
    $discountValue = (float)$input['discountValue'];
    $minPurchaseAmount = isset($input['minPurchaseAmount']) ? (float)$input['minPurchaseAmount'] : 0;
    $maxDiscountAmount = isset($input['maxDiscountAmount']) ? (float)$input['maxDiscountAmount'] : null;
    $usageLimit = isset($input['usageLimit']) ? (int)$input['usageLimit'] : null;
    $validFrom = isset($input['validFrom']) ? $input['validFrom'] : null;
    $validUntil = isset($input['validUntil']) ? $input['validUntil'] : null;
    $isActive = isset($input['isActive']) ? (bool)$input['isActive'] : true;

    // Validate discount type
    if (!in_array($discountType, ['percentage', 'fixed_amount'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid discount type. Must be "percentage" or "fixed_amount"'
        ]);
        exit;
    }

    $sql = "INSERT INTO coupons 
            (code, description, discount_type, discount_value, min_purchase_amount, 
             max_discount_amount, usage_limit, valid_from, valid_until, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssdddissi",
        $code,
        $description,
        $discountType,
        $discountValue,
        $minPurchaseAmount,
        $maxDiscountAmount,
        $usageLimit,
        $validFrom,
        $validUntil,
        $isActive
    );

    if ($stmt->execute()) {
        $couponId = $conn->insert_id;
        
        echo json_encode([
            'success' => true,
            'message' => 'Coupon created successfully',
            'couponId' => $couponId
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create coupon',
            'message' => $stmt->error
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>
