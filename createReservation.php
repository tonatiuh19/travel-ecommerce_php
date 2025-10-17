<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once('db_cnn/cnn.php');
require_once './vendor/autoload.php';

use Stripe\StripeClient;

// Add a function to generate reservation codes
function generateReservationCode($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

function getConnectedAccountId($isTest = true) {
    return $isTest ? 'acct_1SAZjV2Ng2t9x570' : 'acct_1SAl7ARsEkDpHb3i';
}

// Function to calculate transfer amount (base price - service fee of 8.5%)
function calculateTransferAmount($totalPrice) {
    $serviceFeeRate = 0.085; // 8.5%
    $basePrice = $totalPrice / (1 + $serviceFeeRate);
    return (int) round($basePrice * 100); // Convert to cents
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'POST') {
    $requestBody = file_get_contents('php://input');
    $params = json_decode($requestBody, true);

    // Required user fields
    if (
        isset($params['name']) &&
        isset($params['email']) &&
        isset($params['country']) &&
        isset($params['phone'])
    ) {
        $name = $params['name'];
        $email = $params['email'];
        $country = $params['country'];
        $phone = $params['phone']; // Fixed: removed isset() which was causing issues
        $birth_date = $params['birth_date'] ?? null;
        $passport_no = $params['passport_no'] ?? null;
        
        // Payment information
        $token = $params['token'] ?? '';
        $payment_type = $params['payment_type'] ?? 'stripe';

        // Get Stripe secret key
        $sql = "SELECT a.key_string, a.key_test FROM environments_keys as a INNER JOIN environments as b ON b.type = a.title AND b.test = a.key_test WHERE a.type = 'secret'";
        $result = $conn->query($sql);
        if (!$result || $result->num_rows == 0) {
            echo json_encode(['error' => 'Stripe API key not found']);
            $conn->close();
            exit;
        }
        $keyData = $result->fetch_assoc();
        $secretKey = $keyData['key_string'];
        $isTestMode = $keyData['key_test'];
        $stripe = new StripeClient($secretKey); // Initialize stripe here

        // Check if user exists
        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $user_id = $user['id'];
            $stripe_customer_id = $user['stripe_id'];
        } else {
            // Create Stripe customer
            $customer = $stripe->customers->create([
                'name' => $name,
                'email' => $email
            ]);
            $stripe_customer_id = $customer->id;

            // Insert user
            $sql = "INSERT INTO users (name, email, phone, country, birth_date, passport_no, stripe_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssss", $name, $email, $phone, $country, $birth_date, $passport_no, $stripe_customer_id);
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
            } else {
                echo json_encode(['error' => 'User creation failed']);
                $stmt->close();
                $conn->close();
                exit;
            }
            $stmt->close();
        }

        // Required reservation fields
        $required = ['service_type', 'origin', 'destination', 'pickup_datetime', 'passengers', 'price', 'price_eur'];
        foreach ($required as $field) {
            if (!isset($params[$field])) {
                echo json_encode(['error' => "Missing reservation field: $field"]);
                $conn->close();
                exit;
            }
        }

        // Prepare reservation data
        $service_type = $params['service_type'];
        $origin = $params['origin'];
        $destination = $params['destination'];
        $pickup_type = $params['pickup_type'] ?? null;
        $pickup_airport = $params['pickup_airport'] ?? null;
        $pickup_flight_number = $params['pickup_flight_number'] ?? null;
        $pickup_airline = $params['pickup_airline'] ?? null;
        $pickup_terminal = $params['pickup_terminal'] ?? null;
        $pickup_hotel_name = $params['pickup_hotel_name'] ?? null;
        $pickup_address = $params['pickup_address'] ?? null;
        $pickup_datetime = $params['pickup_datetime'];
        $return_datetime = $params['return_datetime'] ?? null;
        $passengers = $params['passengers'];
        $wheelchair_access = $params['wheelchair_access'] ?? 0;
        $special_note = $params['special_note'] ?? null;
        $price = $params['price'];
        $price_eur = $params['price_eur'];
        $urgency_trip = $params['urgency_trip'] ?? 0;
        $status = $params['status'] ?? 'pending';

        $stripe_payment_id = null;

        // Process payment if token provided
        if (!empty($token)) {
            try {
                if ($payment_type === 'stripe') {
                    // 1. Attach the token to the customer (creates a card)
                    $card = $stripe->customers->createSource(
                        $stripe_customer_id,
                        ['source' => $token]
                    );
                    // 2. Charge the customer using the attached card
                    $charge = $stripe->charges->create([
                        'amount' => (int) round($price * 100),
                        'currency' => 'mxn',
                        'customer' => $stripe_customer_id,
                        'source' => $card->id
                    ]);
                    $stripe_payment_id = $charge["id"];


                    $transferAmount = calculateTransferAmount($price);
                    $connectedAccountId = getConnectedAccountId($isTestMode);
                    $chargeCurrency = $charge->currency;

                    $transfer = $stripe->transfers->create([
                        'amount' => $transferAmount,
                        'currency' => 'mxn', // match charge currency to avoid the MXN/EUR mismatch error
                        'destination' => $connectedAccountId,
                        'source_transaction' => $charge->id,
                        'metadata' => [
                            'reservation_code' => '', // Will be updated after reservation creation
                            'service_type' => $service_type,
                            'total_amount' => $charge->amount,
                            'service_fee_rate' => '7%'
                        ]
                    ]);
                    $transfer_id = $transfer->id;
                } else {
                    $stripe_payment_id = $token;
                }
            } catch (\Stripe\Exception\CardException $e) {
                echo json_encode(false);
                exit;
            } catch (\Stripe\Exception\ApiErrorException $e) {
                echo json_encode(false);
                exit;
            } catch (Exception $e) {
                echo json_encode(false);
                exit;
            }
        }

        // Generate unique reservation code
        $reservation_code = '';
        do {
            $reservation_code = generateReservationCode(8); // 8 chars
            $sql = "SELECT id FROM reservations WHERE reservation_code = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $reservation_code);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
        } while ($exists);

        // Insert reservation
        $sql = "INSERT INTO reservations (
            user_id, service_type, origin, destination, pickup_type, pickup_airport, pickup_flight_number, pickup_airline, pickup_terminal, pickup_hotel_name, pickup_address, pickup_datetime, return_datetime, passengers, wheelchair_access, special_note, price, price_eur, urgency_trip, status, stripe_id, reservation_code
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "issssssssssssissddisss", // <-- add an extra 'd' for price_eur (double/float)
            $user_id, $service_type, $origin, $destination, $pickup_type, $pickup_airport, $pickup_flight_number, $pickup_airline, $pickup_terminal, $pickup_hotel_name, $pickup_address, $pickup_datetime, $return_datetime, $passengers, $wheelchair_access, $special_note, $price, $price_eur, $urgency_trip, $status, $stripe_payment_id, $reservation_code
        );

        if ($stmt->execute()) {
            $reservation_id = $stmt->insert_id;

            $reservation_id = $stmt->insert_id;
            // Notify user
            $ch = curl_init('https://garbrix.com/travel-ecommerce/api/notify.php');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['reservation_id' => $reservation_id]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            // Return reservation data
            $sql = "SELECT * FROM reservations WHERE id = ?";
            $stmt2 = $conn->prepare($sql);
            $stmt2->bind_param("i", $reservation_id);
            $stmt2->execute();
            $reservation = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            
            echo json_encode([
                'user_id' => $user_id, 
                'stripe_id' => $stripe_payment_id, // Fixed variable name
                'reservation' => $reservation
            ]);
        } else {
            echo json_encode(['error' => 'Reservation creation failed']);
        }
        $stmt->close();
    } else {
        echo json_encode(['error' => 'Missing user fields']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}

$conn->close();
?>
