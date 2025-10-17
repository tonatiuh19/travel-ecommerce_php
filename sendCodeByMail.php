<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');
require_once('db_cnn/cnn.php');
require_once './vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'POST') {
    $requestBody = file_get_contents('php://input');
    $params = json_decode($requestBody, true);

    if (isset($params['navios_user_id']) && isset($params['email'])) {
        $navios_user_id = $params['navios_user_id'];
        $email = $params['email'];

        // Get user name from DB
        $sql = "SELECT navios_user_full_name FROM navios_users WHERE navios_user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $navios_user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $user_name = $result ? $result['navios_user_full_name'] : '';

        // Delete old session codes for this user
        $sql = "DELETE FROM navios_users_sessions WHERE navios_user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $navios_user_id);
        $stmt->execute();
        $stmt->close();

        // Generate a random six-digit session code and ensure it is unique
        do {
            $session_code = rand(100000, 999999);
            $sql = "SELECT COUNT(*) as count FROM navios_users_sessions WHERE navios_users_session_code = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $session_code);
            $stmt->execute();
            $uniqueResult = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } while ($uniqueResult['count'] > 0);

        // Insert the new session code into navios_users_sessions
        $date_start = date('Y-m-d H:i:s');
        $session_active = 0;
        $sql = "INSERT INTO navios_users_sessions (navios_user_id, navios_users_session_code, navios_users_session_session, navios_users_session_date_start) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiis", $navios_user_id, $session_code, $session_active, $date_start);
        $stmt->execute();
        $stmt->close();

        // Load email template and replace placeholders
        $templatePath = __DIR__ . '/code-email.html';
        $emailBody = file_exists($templatePath) ? file_get_contents($templatePath) : '';
        $emailBody = str_replace(['{{code}}', '{{name}}'], [$session_code, $user_name], $emailBody);

        // Send session code via PHPMailer
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->Host = 'mail.garbrix.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'no-reply@garbrix.com';
            $mail->Password = 'Mailer123';
            $mail->SMTPSecure = 'ssl';
            $mail->Port = 469;
            $mail->CharSet = 'UTF-8';

            //Recipients
            $mail->setFrom('no-reply@garbrix.com', 'Navios');
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $session_code . ' is your DockNow verification code';
            $mail->Body = $emailBody ?: "Your verification code is: $session_code";

            $mail->send();
            echo json_encode(true);
        } catch (Exception $e) {
            echo json_encode(["message" => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}"]);
        }
    } else {
        echo json_encode(false);
    }
} else {
    echo json_encode(false);
}

$conn->close();
?>