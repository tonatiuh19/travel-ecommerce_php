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
    
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: id']);
        exit;
    }

    $id = (int)$input['id'];
    $code = isset($input['code']) ? $conn->real_escape_string(strtoupper(trim($input['code']))) : null;
    $description = isset($input['description']) ? $conn->real_escape_string($input['description']) : null;
    $discountType = isset($input['discountType']) ? $conn->real_escape_string($input['discountType']) : null;
    $discountValue = isset($input['discountValue']) ? (float)$input['discountValue'] : null;
    $minPurchaseAmount = isset($input['minPurchaseAmount']) ? (float)$input['minPurchaseAmount'] : null;
    $maxDiscountAmount = isset($input['maxDiscountAmount']) ? (float)$input['maxDiscountAmount'] : null;
    $usageLimit = isset($input['usageLimit']) ? (int)$input['usageLimit'] : null;
    $validFrom = isset($input['validFrom']) ? $input['validFrom'] : null;
    $validUntil = isset($input['validUntil']) ? $input['validUntil'] : null;
    $isActive = isset($input['isActive']) ? (int)(bool)$input['isActive'] : null;

    // Build dynamic update query
    $updates = [];
    $types = "";
    $values = [];

    if ($code !== null) {
        $updates[] = "code = ?";
        $types .= "s";
        $values[] = $code;
    }
    if ($description !== null) {
        $updates[] = "description = ?";
        $types .= "s";
        $values[] = $description;
    }
    if ($discountType !== null) {
        if (!in_array($discountType, ['percentage', 'fixed_amount'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid discount type']);
            exit;
        }
        $updates[] = "discount_type = ?";
        $types .= "s";
        $values[] = $discountType;
    }
    if ($discountValue !== null) {
        $updates[] = "discount_value = ?";
        $types .= "d";
        $values[] = $discountValue;
    }
    if ($minPurchaseAmount !== null) {
        $updates[] = "min_purchase_amount = ?";
        $types .= "d";
        $values[] = $minPurchaseAmount;
    }
    if ($maxDiscountAmount !== null) {
        $updates[] = "max_discount_amount = ?";
        $types .= "d";
        $values[] = $maxDiscountAmount;
    }
    if ($usageLimit !== null) {
        $updates[] = "usage_limit = ?";
        $types .= "i";
        $values[] = $usageLimit;
    }
    if ($validFrom !== null) {
        $updates[] = "valid_from = ?";
        $types .= "s";
        $values[] = $validFrom;
    }
    if ($validUntil !== null) {
        $updates[] = "valid_until = ?";
        $types .= "s";
        $values[] = $validUntil;
    }
    if ($isActive !== null) {
        $updates[] = "is_active = ?";
        $types .= "i";
        $values[] = $isActive;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        exit;
    }

    $sql = "UPDATE coupons SET " . implode(", ", $updates) . " WHERE id = ?";
    $types .= "i";
    $values[] = $id;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Coupon updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No changes made or coupon not found'
            ]);
        }
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to update coupon',
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
