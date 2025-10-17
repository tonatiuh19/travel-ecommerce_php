<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once('db_cnn/cnn.php');
require_once './vendor/autoload.php';

use Stripe\StripeClient;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Generate unique booking reference
function generateBookingReference($length = 10) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = 'VT'; // Van Transfer prefix
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// Get Stripe connected account ID
function getConnectedAccountId($isTest = true) {
    return $isTest ? 'acct_1SAZjV2Ng2t9x570' : 'acct_1SAl7ARsEkDpHb3i';
}

// Calculate transfer amount (base price - service fee)
function calculateTransferAmount($totalPrice) {
    $serviceFeeRate = 0.085; // 8.5%
    $basePrice = $totalPrice / (1 + $serviceFeeRate);
    return (int) round($basePrice * 100); // Convert to cents
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'POST') {
    $requestBody = file_get_contents('php://input');
    $params = json_decode($requestBody, true);

    // Log received data for debugging
    error_log('Van Transfer Booking Request: ' . print_r($params, true));

    // ========================================
    // VALIDATE REQUIRED CUSTOMER FIELDS
    // ========================================
    $requiredCustomerFields = ['firstName', 'lastName', 'email', 'phone'];
    foreach ($requiredCustomerFields as $field) {
        if (!isset($params[$field]) || empty($params[$field])) {
            echo json_encode(['error' => "Missing customer field: $field"]);
            exit;
        }
    }

    $firstName = trim($params['firstName']);
    $lastName = trim($params['lastName']);
    $email = trim($params['email']);
    $phone = trim($params['phone']);
    $country = $params['country'] ?? null;

    // ========================================
    // VALIDATE REQUIRED BOOKING FIELDS
    // ========================================
    $requiredBookingFields = [
        'transferType', 'passengerCount', 'isRoundTrip', 
        'pickupType', 'serviceDate', 'serviceTime',
        'basePrice', 'serviceFee', 'totalPrice'
    ];
    foreach ($requiredBookingFields as $field) {
        if (!isset($params[$field])) {
            echo json_encode(['error' => "Missing booking field: $field"]);
            exit;
        }
    }

    // Extract booking data
    $transferType = $params['transferType'];
    $destinationId = $params['destinationId'] ?? null;
    $passengerCount = (int) $params['passengerCount'];
    $isRoundTrip = (bool) $params['isRoundTrip'];
    $requiresWheelchairAccess = (bool) ($params['requiresWheelchairAccess'] ?? false);
    
    // Pickup information
    $pickupType = $params['pickupType'];
    $pickupName = $params['pickupName'] ?? null;
    $pickupAddress = $params['pickupAddress'] ?? null;
    $pickupAirportId = $params['pickupAirportId'] ?? null;
    $pickupTerminalId = $params['pickupTerminalId'] ?? null;
    $pickupFlightNumber = $params['pickupFlightNumber'] ?? null;
    
    // Service date/time
    $serviceDate = $params['serviceDate'];
    $serviceTime = $params['serviceTime'];
    
    // Return information
    $returnDate = $params['returnDate'] ?? null;
    $returnTime = $params['returnTime'] ?? null;
    
    // Pricing
    $basePrice = (float) $params['basePrice'];
    $emergencyFee = (float) ($params['emergencyFee'] ?? 0);
    $serviceFee = (float) $params['serviceFee'];
    $totalPrice = (float) $params['totalPrice'];
    
    // Special requests
    $specialRequests = $params['specialRequests'] ?? null;
    
    // Payment information
    $stripePaymentMethodId = $params['stripePaymentMethodId'] ?? '';
    $paymentType = $params['paymentType'] ?? 'card';

    // ========================================
    // GET STRIPE CONFIGURATION
    // ========================================
    $sql = "SELECT a.key_string, a.key_test FROM environments_keys as a 
            INNER JOIN environments as b ON b.type = a.title AND b.test = a.key_test 
            WHERE a.type = 'secret'";
    $result = $conn->query($sql);
    if (!$result || $result->num_rows == 0) {
        echo json_encode(['error' => 'Stripe API key not found']);
        $conn->close();
        exit;
    }
    $keyData = $result->fetch_assoc();
    $secretKey = $keyData['key_string'];
    $isTestMode = (bool) $keyData['key_test'];
    $stripe = new StripeClient($secretKey);

    // ========================================
    // FIND OR CREATE CUSTOMER
    // ========================================
    $sql = "SELECT * FROM customers WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($customer) {
        $customerId = $customer['id'];
        
        // Update customer info if changed
        $sql = "UPDATE customers SET 
                first_name = ?, 
                last_name = ?, 
                phone = ?, 
                country = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", $firstName, $lastName, $phone, $country, $customerId);
        $stmt->execute();
        $stmt->close();
    } else {
        // Insert new customer
        $sql = "INSERT INTO customers (first_name, last_name, email, phone, country) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $firstName, $lastName, $email, $phone, $country);
        if ($stmt->execute()) {
            $customerId = $stmt->insert_id;
        } else {
            echo json_encode(['error' => 'Customer creation failed: ' . $stmt->error]);
            $stmt->close();
            $conn->close();
            exit;
        }
        $stmt->close();
    }

    // ========================================
    // GET TRANSFER TYPE ID
    // ========================================
    $sql = "SELECT id FROM transfer_types WHERE type_key = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $transferType);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        echo json_encode(['error' => 'Invalid transfer type']);
        $stmt->close();
        $conn->close();
        exit;
    }
    $transferTypeId = $result->fetch_assoc()['id'];
    $stmt->close();

    // ========================================
    // GET PICKUP TYPE ID
    // ========================================
    $sql = "SELECT id FROM pickup_types WHERE type_key = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $pickupType);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        echo json_encode(['error' => 'Invalid pickup type']);
        $stmt->close();
        $conn->close();
        exit;
    }
    $pickupTypeId = $result->fetch_assoc()['id'];
    $stmt->close();

    // ========================================
    // PROCESS STRIPE PAYMENT
    // ========================================
    $stripePaymentIntentId = null;
    $paymentStatus = 'pending';

    if (!empty($stripePaymentMethodId)) {
        try {
            // Create or get Stripe customer
            $stripeCustomerId = null;
            
            // Check if customer already has a Stripe ID
            $sql = "SELECT stripe_customer_id FROM customers WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $result = $stmt->get_result();
            $customerData = $result->fetch_assoc();
            $stmt->close();
            
            if ($customerData && !empty($customerData['stripe_customer_id'])) {
                $stripeCustomerId = $customerData['stripe_customer_id'];
            } else {
                // Create new Stripe customer
                $stripeCustomer = $stripe->customers->create([
                    'name' => "$firstName $lastName",
                    'email' => $email,
                    'phone' => $phone,
                    'metadata' => [
                        'customer_id' => $customerId
                    ]
                ]);
                $stripeCustomerId = $stripeCustomer->id;
                    // Update customer with Stripe ID
                    $sql = "UPDATE customers SET stripe_customer_id = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("si", $stripeCustomerId, $customerId);
                    $stmt->execute();
                    $stmt->close();
            }

            // Process payment using PaymentMethods API
            $amountInCents = (int) round($totalPrice * 100);
            
            // 1. Attach the payment method to the customer
            $stripe->paymentMethods->attach(
                $stripePaymentMethodId,
                ['customer' => $stripeCustomerId]
            );
            
            // 2. Create PaymentIntent and charge the customer
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $amountInCents,
                'currency' => 'mxn',
                'customer' => $stripeCustomerId,
                'payment_method' => $stripePaymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'customer_id' => $customerId,
                    'transfer_type' => $transferType,
                    'passenger_count' => $passengerCount,
                    'service_date' => $serviceDate
                ]
            ]);
            
            $stripePaymentIntentId = $paymentIntent->id;
            
            // Check if payment succeeded
            if ($paymentIntent->status === 'succeeded') {
                $paymentStatus = 'paid';
                
                // 3. Create transfer to connected account
                $transferAmount = calculateTransferAmount($totalPrice);
                $connectedAccountId = getConnectedAccountId($isTestMode);
                
                // Get the charge ID from the payment intent
                $chargeId = $paymentIntent->latest_charge;
                
                $transfer = $stripe->transfers->create([
                    'amount' => $transferAmount,
                    'currency' => 'mxn',
                    'destination' => $connectedAccountId,
                    'source_transaction' => $chargeId,
                    'metadata' => [
                        'customer_id' => $customerId,
                        'service_type' => $transferType,
                        'total_amount' => $amountInCents,
                        'service_fee_rate' => '8.5%'
                    ]
                ]);
            } else {
                $paymentStatus = 'requires_action';
            }

        } catch (\Stripe\Exception\CardException $e) {
            error_log('Stripe Card Error: ' . $e->getMessage());
            echo json_encode([
                'error' => 'Payment failed',
                'message' => $e->getMessage(),
                'type' => 'card_error'
            ]);
            $conn->close();
            exit;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('Stripe API Error: ' . $e->getMessage());
            echo json_encode([
                'error' => 'Payment processing error',
                'message' => $e->getMessage(),
                'type' => 'api_error'
            ]);
            $conn->close();
            exit;
        } catch (Exception $e) {
            error_log('General Error: ' . $e->getMessage());
            echo json_encode([
                'error' => 'Unexpected error',
                'message' => $e->getMessage()
            ]);
            $conn->close();
            exit;
        }
    }

    // ========================================
    // GENERATE UNIQUE BOOKING REFERENCE
    // ========================================
    $bookingReference = '';
    do {
        $bookingReference = generateBookingReference(10);
        $sql = "SELECT id FROM bookings WHERE booking_reference = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $bookingReference);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
    } while ($exists);

    // ========================================
    // INSERT BOOKING
    // ========================================
    $bookingStatus = ($paymentStatus === 'paid') ? 'confirmed' : 'pending';
    
    $sql = "INSERT INTO bookings (
        booking_reference, 
        customer_id, 
        transfer_type_id, 
        destination_id, 
        passenger_count, 
        is_round_trip, 
        requires_wheelchair_access,
        pickup_type_id, 
        pickup_name, 
        pickup_address, 
        pickup_airport_id, 
        pickup_terminal_id, 
        pickup_flight_number,
        service_date, 
        service_time, 
        return_date, 
        return_time,
        base_price, 
        emergency_fee, 
        service_fee, 
        total_price,
        special_requests,
        status,
        payment_status,
        stripe_payment_method_id,
        stripe_payment_intent_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
        $conn->close();
        exit;
    }

    $stmt->bind_param(
        "siiiiiiissiiissssddddsssss",
        $bookingReference,
        $customerId,
        $transferTypeId,
        $destinationId,
        $passengerCount,
        $isRoundTrip,
        $requiresWheelchairAccess,
        $pickupTypeId,
        $pickupName,
        $pickupAddress,
        $pickupAirportId,
        $pickupTerminalId,
        $pickupFlightNumber,
        $serviceDate,
        $serviceTime,
        $returnDate,
        $returnTime,
        $basePrice,
        $emergencyFee,
        $serviceFee,
        $totalPrice,
        $specialRequests,
        $bookingStatus,
        $paymentStatus,
        $stripePaymentMethodId,
        $stripePaymentIntentId
    );

    if ($stmt->execute()) {
        $bookingId = $stmt->insert_id;
        $stmt->close();

        // ========================================
        // SEND EMAIL NOTIFICATION
        // ========================================
        if ($paymentStatus === 'paid') {
            try {
                // Load email template
                $templatePath = __DIR__ . '/confirmation.html';
                $emailBody = file_exists($templatePath) ? file_get_contents($templatePath) : '';

                // Prepare customer info
                $customer_name = $firstName . ' ' . $lastName;
                
                // Format service type for display
                $service_type_display = $booking['transfer_type_name'];
                if ($booking['destination_name']) {
                    $service_type_display .= ' - ' . $booking['destination_name'];
                }

                // Format pickup details
                $pickup_details = '';
                if ($booking['pickup_type_key'] === 'airport') {
                    $pickup_details = $booking['airport_name'];
                    if ($booking['terminal_name']) {
                        $pickup_details .= ' - Terminal ' . $booking['terminal_name'];
                    }
                    if ($pickupFlightNumber) {
                        $pickup_details .= ' (Vuelo: ' . $pickupFlightNumber . ')';
                    }
                } elseif ($booking['pickup_type_key'] === 'hotel') {
                    $pickup_details = 'Hotel: ' . $pickupName;
                } else {
                    $pickup_details = $pickupAddress;
                }

                // Format date and time
                $service_datetime = date('d/m/Y', strtotime($serviceDate)) . ' a las ' . date('H:i', strtotime($serviceTime));
                
                // Format payment status in Spanish
                $payment_status_spanish = 'Pagado';

                // Format booking status in Spanish
                $booking_status_spanish = 'Confirmado';

                // Build additional details section
                $additional_details = '';
                $additional_details .= '<div class="info-section">';
                $additional_details .= '<div class="info-title">Detalles de Recogida</div>';
                $additional_details .= '<p style="color: #666; line-height: 1.6">';
                $additional_details .= '<strong>Ubicación:</strong> ' . htmlspecialchars($pickup_details) . '<br>';
                $additional_details .= '<strong>Fecha y Hora:</strong> ' . htmlspecialchars($service_datetime) . '<br>';
                $additional_details .= '<strong>Pasajeros:</strong> ' . $passengerCount . '<br>';
                
                if ($isRoundTrip && $returnDate && $returnTime) {
                    $return_datetime = date('d/m/Y', strtotime($returnDate)) . ' a las ' . date('H:i', strtotime($returnTime));
                    $additional_details .= '<strong>Viaje de Regreso:</strong> ' . htmlspecialchars($return_datetime) . '<br>';
                }
                
                if ($requiresWheelchairAccess) {
                    $additional_details .= '<strong>Acceso para Silla de Ruedas:</strong> Sí<br>';
                }
                
                if ($specialRequests) {
                    $additional_details .= '<strong>Solicitudes Especiales:</strong> ' . htmlspecialchars($specialRequests) . '<br>';
                }
                
                $additional_details .= '</p></div>';

                // Build pricing breakdown
                $pricing_breakdown = '';
                $pricing_breakdown .= '<div class="info-section">';
                $pricing_breakdown .= '<div class="info-title">Desglose de Precios</div>';
                $pricing_breakdown .= '<p style="color: #666; line-height: 1.6">';
                $pricing_breakdown .= '<strong>Precio Base:</strong> €' . number_format($basePrice, 2) . '<br>';
                
                if ($emergencyFee > 0) {
                    $pricing_breakdown .= '<strong>Tarifa de Emergencia:</strong> €' . number_format($emergencyFee, 2) . '<br>';
                }
                
                $pricing_breakdown .= '<strong>Tarifa de Servicio:</strong> €' . number_format($serviceFee, 2) . '<br>';
                $pricing_breakdown .= '<strong>Total:</strong> €' . number_format($totalPrice, 2) . '<br>';
                $pricing_breakdown .= '<strong>Estado del Pago:</strong> ' . $payment_status_spanish;
                $pricing_breakdown .= '</p></div>';

                // Replace placeholders in email template
                $replacements = [
                    '{{name}}' => htmlspecialchars($customer_name),
                    '{{reservation_code}}' => htmlspecialchars($bookingReference),
                    '{{service_type}}' => htmlspecialchars($service_type_display),
                    '{{price}}' => number_format($totalPrice, 2),
                    '{{currency}}' => 'EUR',
                    '{{created_at}}' => date('d/m/Y H:i'),
                    '{{status}}' => $booking_status_spanish
                ];

                // Replace placeholders
                foreach ($replacements as $placeholder => $value) {
                    $emailBody = str_replace($placeholder, $value, $emailBody);
                }

                // Add additional details before the "¿Qué sigue ahora?" section
                $emailBody = str_replace(
                    '<div class="info-section">
          <div class="info-title">¿Qué sigue ahora?</div>',
                    $additional_details . $pricing_breakdown . '<div class="info-section">
          <div class="info-title">¿Qué sigue ahora?</div>',
                    $emailBody
                );

                // Send email using PHPMailer
                $mail = new PHPMailer(true);
                
                // Server settings
                $mail->Host = 'mail.latinosporeuropa.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'no-reply@latinosporeuropa.com';
                $mail->Password = 'Mailer123';
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 469;
                $mail->CharSet = 'UTF-8';

                // Recipients
                $mail->setFrom('no-reply@latinosporeuropa.com', 'Latinos Por Europa');
                $mail->addAddress($email, $customer_name);

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Confirmación de Reserva de Latinos por Europa - ' . $bookingReference;
                $mail->Body = $emailBody ?: "Tu reserva de Latinos por Europa ha sido confirmada. Código de reserva: " . $bookingReference;
                $mail->AltBody = 'Tu reserva de Latinos por Europa ha sido confirmada. Código de reserva: ' . $bookingReference . '. Por favor, revisa tu email en formato HTML para ver todos los detalles.';

                $mail->send();
                error_log('Confirmation email sent successfully to: ' . $email);
                
            } catch (Exception $e) {
                error_log('Email Error: ' . $e->getMessage());
                // Don't fail the booking if email fails
            }
        }

        // ========================================
        // RETRIEVE COMPLETE BOOKING DATA
        // ========================================
        $sql = "SELECT 
                    b.*,
                    c.first_name,
                    c.last_name,
                    c.email,
                    c.phone,
                    c.country,
                    tt.name as transfer_type_name,
                    d.name as destination_name,
                    pt.name as pickup_type_name,
                    pt.type_key as pickup_type_key,
                    a.name as airport_name,
                    at.terminal_name
                FROM bookings b
                JOIN customers c ON b.customer_id = c.id
                JOIN transfer_types tt ON b.transfer_type_id = tt.id
                JOIN pickup_types pt ON b.pickup_type_id = pt.id
                LEFT JOIN destinations d ON b.destination_id = d.id
                LEFT JOIN airports a ON b.pickup_airport_id = a.id
                LEFT JOIN airport_terminals at ON b.pickup_terminal_id = at.id
                WHERE b.id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $bookingId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Return success response
        echo json_encode([
            'success' => true,
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'customer_id' => $customerId,
            'payment_status' => $paymentStatus,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
            'booking' => $booking
        ]);
    } else {
        echo json_encode(['error' => 'Booking creation failed: ' . $stmt->error]);
        $stmt->close();
    }

} else {
    echo json_encode(['error' => 'Invalid request method. Only POST is allowed.']);
}

$conn->close();
?>
