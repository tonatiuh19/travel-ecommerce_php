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

    if (isset($params['email'])) {
        $email = $params['email'];

        // Fetch id_platforms_user from platforms_users by email
        $sql = "SELECT id_platforms_user FROM platforms_users WHERE type=2 AND email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $userData = $result->fetch_assoc();
            $id_platforms_user = $userData['id_platforms_user'];
        } else {
            echo json_encode(false);
            $stmt->close();
            exit;
        }
        $stmt->close();

        // Delete the old session code
        $sql = "DELETE FROM platforms_users_sessions WHERE id_platforms_user = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_platforms_user);
        $stmt->execute();
        $stmt->close();

        // Generate a random six-digit session code and ensure it is unique
        do {
            $session_code = rand(100000, 999999);
            $sql = "SELECT COUNT(*) as count FROM platforms_users_sessions WHERE code = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $session_code);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } while ($result['count'] > 0);

        // Insert the new session code into platforms_users_sessions
        $date_start = date('Y-m-d H:i:s');
        $sql = "INSERT INTO platforms_users_sessions (id_platforms_user, code, session, date_start) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $session_active = false;
        $stmt->bind_param("iiss", $id_platforms_user, $session_code, $session_active, $date_start);
        $stmt->execute();
        $stmt->close();

        // Send session code via PHPMailer
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->SMTPDebug = 0;                                     // Enable verbose debug output
            // $mail->isSMTP();                                            // Set mailer to use SMTP
            $mail->Host = 'mail.intelipadel.com';  // Specify main and backup SMTP servers
            $mail->SMTPAuth = true;                                   // Enable SMTP authentication
            $mail->Username = 'no-reply@intelipadel.com';                     // SMTP username
            $mail->Password = 'Mailer123';                               // SMTP password
            $mail->SMTPSecure = 'ssl';                                  // Enable TLS encryption, `ssl` also accepted
            $mail->Port = 469;                                   // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above
            $mail->CharSet = 'UTF-8';

            //Recipients
            $mail->setFrom('no-reply@intelipadel.com', 'PadelRoom');
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Your PadelRoom Session Code';
            $mail->Body = "Your session code for PadelRoom is: $session_code";

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