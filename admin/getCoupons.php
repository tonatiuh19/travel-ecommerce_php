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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

require_once('../db_cnn/cnn.php');

try {
    $sql = "SELECT 
                c.id,
                c.code,
                c.description,
                c.discount_type as discountType,
                c.discount_value as discountValue,
                c.min_purchase_amount as minPurchaseAmount,
                c.max_discount_amount as maxDiscountAmount,
                c.usage_limit as usageLimit,
                c.usage_count as usageCount,
                c.valid_from as validFrom,
                c.valid_until as validUntil,
                c.is_active as isActive,
                c.created_at as createdAt,
                c.updated_at as updatedAt,
                COUNT(b.id) as bookingsCount,
                COALESCE(SUM(b.discount_amount), 0) as totalDiscountsGiven
            FROM coupons c
            LEFT JOIN bookings b ON c.id = b.coupon_id
            GROUP BY c.id
            ORDER BY c.created_at DESC";
    
    $result = $conn->query($sql);
    $coupons = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['discountValue'] = (float)$row['discountValue'];
            $row['minPurchaseAmount'] = (float)$row['minPurchaseAmount'];
            $row['maxDiscountAmount'] = $row['maxDiscountAmount'] ? (float)$row['maxDiscountAmount'] : null;
            $row['usageLimit'] = $row['usageLimit'] ? (int)$row['usageLimit'] : null;
            $row['usageCount'] = (int)$row['usageCount'];
            $row['isActive'] = (bool)$row['isActive'];
            $row['bookingsCount'] = (int)$row['bookingsCount'];
            $row['totalDiscountsGiven'] = (float)$row['totalDiscountsGiven'];
            $coupons[] = $row;
        }
    }

    echo json_encode($coupons);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
