<?php
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
    public static function sendOtp($toEmail, $toName, $otp)
    {
        $config = mailConfig();

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$config['port'];
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($toEmail, $toName ?: 'Customer');
        $mail->Subject = 'Your checkout OTP';
        $mail->Body = "Your OTP for checkout is {$otp}. It is valid for 5 minutes.";
        $mail->AltBody = "Your OTP for checkout is {$otp}. It is valid for 5 minutes.";

        return $mail->send();
    }
}
