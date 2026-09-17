<?php

declare(strict_types=1);

/**
 * Envia um e-mail transacional usando a mesma configuracao das notificacoes.
 * Retorna false quando o transporte nao esta configurado ou rejeita o envio.
 */
function system_email_send(string $to, string $subject, string $text, string $idempotencyKey): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $resendKey = getenv('RESEND_API_KEY') ?: '';
    $smtpPassword = getenv('SMTP_PASSWORD') ?: '';
    $smtpUser = getenv('SMTP_USERNAME') ?: 'dreambotjornal@gmail.com';
    $from = getenv('NOTIFICATION_FROM') ?: '';
    if ($smtpPassword !== '' && $from === '') $from = $smtpUser;
    if ($from === '' || ($smtpPassword === '' && $resendKey === '')) return false;

    if ($smtpPassword !== '') {
        require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
        require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';
        require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 15;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, 'Jornal do Vascao');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $text;
            $mail->send();
            return true;
        } catch (Throwable $error) {
            error_log('Transactional SMTP delivery failed: ' . $error->getMessage());
            return false;
        }
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'text' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer {$resendKey}\r\nContent-Type: application/json\r\nIdempotency-Key: {$idempotencyKey}\r\n",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents('https://api.resend.com/emails', false, $context);
    $result = json_decode($response ?: '{}', true);
    return !empty($result['id']);
}
