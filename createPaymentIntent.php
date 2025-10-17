<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');

require_once('vendor/autoload.php');
require_once('db_cnn/cnn.php'); // Include your database connection

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $amount = $data['amount'];
    $customerId = isset($data['customer_id']) ? $data['customer_id'] : null;

    // Query to get the API key from the database
    $sql = "SELECT a.navios_environments_keys_key_string 
                FROM navios_environments_keys as a
                INNER JOIN navios_environments as b 
                    ON b.navios_environment_type = a.navios_environments_keys_title 
                    AND b.navios_environment_test = a.navios_environments_keys_test
                WHERE a.navios_environments_keys_type = 'secret'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $apiKey = $row['navios_environments_keys_key_string'];

        \Stripe\Stripe::setApiKey($apiKey); // Set the Stripe API key from the database

        try {
            $paymentIntentData = [
                'amount' => $amount,
                'currency' => 'mxn',
            ];

            if ($customerId) {
                $paymentIntentData['customer'] = $customerId;
            }

            $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentData);

            echo json_encode(['clientSecret' => $paymentIntent->client_secret]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'API key not found']);
    }

    $conn->close();
} else {
    echo json_encode(['message' => 'Invalid request method']);
}
?>