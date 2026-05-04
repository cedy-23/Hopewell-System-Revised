<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

/**
 * Send employee account email with password setup link
 */
function sendEmployeeCredentials($conn, $email, $user_id, $rawPassword, $employee_id)
{
    // ===============================
    // GENERATE SECURE TOKEN
    // ===============================
    $token = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

    // Delete old tokens
    $delete = $conn->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
    $delete->bind_param("i", $user_id);
    $delete->execute();

    // Insert new token
    $insert = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $insert->bind_param("iss", $user_id, $token, $expires);
    $insert->execute();

    // Reset password link with token
    $reset_url = "https://hopewellsalecorporation-ticketingsystem.com/reset_password.php?token=" . $token;

    $mail = new PHPMailer(true);

    try {
        // ===============================
        // SMTP SETTINGS (HOSTINGER)
        // ===============================
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'mis-helpdesk@hopewellsalecorporation-ticketingsystem.com';
        $mail->Password   = 'Hopewellsystem123!';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // Remove debug in production
        $mail->SMTPDebug  = 0;

        // ===============================
        // EMAIL INFO
        // ===============================
        $mail->setFrom(
            'mis-helpdesk@hopewellsalecorporation-ticketingsystem.com',
            'MIS Helpdesk'
        );
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Set Your Employee Account Password';

        // ===============================
        // EMAIL BODY
        // ===============================
        $mail->Body = "
            <h2>Welcome to MIS Ticketing System</h2>
            <p>Your employee account has been successfully created.</p>

            <p><strong>Employee ID:</strong> {$employee_id}</p>
            <p><strong>Temporary Password:</strong> {$rawPassword}</p>
            <br>
            <a href='{$reset_url}'
               style='background:#007bff;color:#ffffff;padding:12px 20px;
               text-decoration:none;border-radius:6px;display:inline-block;'>
               Login Here
            </a>
            <br>
            <small>This link will expire in 1 hour.</small>
        ";

        $mail->AltBody = "Your account has been created. 
        Visit this link to set your password: $reset_url";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}