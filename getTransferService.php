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
    $response = [
        'transferTypes' => [],
        'destinations' => [],
        'pricingTiers' => [],
        'airports' => [],
        'airportTerminals' => [],
        'pickupTypes' => []
    ];

    // 1. Get Transfer Types
    $sql = "SELECT 
                id,
                type_key as typeKey,
                name,
                description,
                is_round_trip_option as isRoundTripOption,
                max_passengers as maxPassengers
            FROM transfer_types
            ORDER BY id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['isRoundTripOption'] = (bool)$row['isRoundTripOption'];
            $row['maxPassengers'] = (int)$row['maxPassengers'];
            $response['transferTypes'][] = $row;
        }
    }

    // 2. Get Destinations
    $sql = "SELECT 
                id,
                name,
                slug,
                duration,
                distance,
                is_round_trip as isRoundTrip,
                description
            FROM destinations
            ORDER BY id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['isRoundTrip'] = (bool)$row['isRoundTrip'];
            $response['destinations'][] = $row;
        }
    }

    // 3. Get Pricing Tiers
    $sql = "SELECT 
                id,
                transfer_type_id as transferTypeId,
                destination_id as destinationId,
                min_passengers as minPassengers,
                max_passengers as maxPassengers,
                price_eur as priceEur,
                is_single_trip as isSingleTrip
            FROM pricing_tiers
            ORDER BY id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['transferTypeId'] = (int)$row['transferTypeId'];
            $row['destinationId'] = $row['destinationId'] ? (int)$row['destinationId'] : null;
            $row['minPassengers'] = (int)$row['minPassengers'];
            $row['maxPassengers'] = (int)$row['maxPassengers'];
            $row['priceEur'] = (float)$row['priceEur'];
            $row['isSingleTrip'] = (bool)$row['isSingleTrip'];
            $response['pricingTiers'][] = $row;
        }
    }

    // 4. Get Airports
    $sql = "SELECT 
                id,
                code,
                name,
                city
            FROM airports
            ORDER BY id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $response['airports'][] = $row;
        }
    }

    // 5. Get Airport Terminals
    $sql = "SELECT 
                id,
                airport_id as airportId,
                terminal_code as terminalCode,
                terminal_name as terminalName
            FROM airport_terminals
            ORDER BY airport_id, id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['airportId'] = (int)$row['airportId'];
            $response['airportTerminals'][] = $row;
        }
    }

    // 6. Get Pickup Types
    $sql = "SELECT 
                id,
                type_key as typeKey,
                name,
                description,
                requires_address as requiresAddress,
                requires_airport as requiresAirport
            FROM pickup_types
            ORDER BY id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['requiresAddress'] = (bool)$row['requiresAddress'];
            $row['requiresAirport'] = (bool)$row['requiresAirport'];
            $response['pickupTypes'][] = $row;
        }
    }

    echo json_encode($response);

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