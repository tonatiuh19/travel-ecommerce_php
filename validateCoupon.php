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

require_once('db_cnn/cnn.php');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['code']) || !isset($input['totalAmount'])) {
        http_response_code(400);
        echo json_encode([
            'valid' => false,
            'error' => 'Missing required fields: code and totalAmount'
        ]);
        exit;
    }

    $code = $conn->real_escape_string($input['code']);
    $totalAmount = (float)$input['totalAmount'];

    // Validate coupon
    $sql = "SELECT 
                id,
                code,
                description,
                discount_type as discountType,
                discount_value as discountValue,
                min_purchase_amount as minPurchaseAmount,
                max_discount_amount as maxDiscountAmount,
                usage_limit as usageLimit,
                usage_count as usageCount
            FROM coupons 
            WHERE code = ? 
            AND is_active = TRUE 
            AND (valid_from IS NULL OR valid_from <= NOW())
            AND (valid_until IS NULL OR valid_until >= NOW())
            AND (usage_limit IS NULL OR usage_count < usage_limit)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'valid' => false,
            'error' => 'Invalid, expired, or fully used coupon code'
        ]);
        exit;
    }

    $coupon = $result->fetch_assoc();
    
    // Check minimum purchase amount
    if ($totalAmount < (float)$coupon['minPurchaseAmount']) {
        echo json_encode([
            'valid' => false,
            'error' => 'Minimum purchase amount of €' . $coupon['minPurchaseAmount'] . ' required'
        ]);
        exit;
    }

    // Calculate discount
    $discountAmount = 0;
    if ($coupon['discountType'] === 'percentage') {
        $discountAmount = ($totalAmount * (float)$coupon['discountValue']) / 100;
        
        // Apply max discount cap if set
        if ($coupon['maxDiscountAmount'] !== null && $discountAmount > (float)$coupon['maxDiscountAmount']) {
            $discountAmount = (float)$coupon['maxDiscountAmount'];
        }
    } else {
        // Fixed amount discount
        $discountAmount = (float)$coupon['discountValue'];
        
        // Don't allow discount to exceed total amount
        if ($discountAmount > $totalAmount) {
            $discountAmount = $totalAmount;
        }
    }

    $finalPrice = $totalAmount - $discountAmount;

    echo json_encode([
        'valid' => true,
        'coupon' => [
            'id' => (int)$coupon['id'],
            'code' => $coupon['code'],
            'description' => $coupon['description'],
            'discountType' => $coupon['discountType'],
            'discountValue' => (float)$coupon['discountValue']
        ],
        'discountAmount' => round($discountAmount, 2),
        'finalPrice' => round($finalPrice, 2)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'valid' => false,
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
