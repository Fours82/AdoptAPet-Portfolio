<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/transactional-presentation.php';

use PHPMailer\PHPMailer\PHPMailer;

function createMailer(): PHPMailer
{
    $configurationFile = __DIR__ . '/../../config/smtp.php';
    $localConfigurationFile = __DIR__ . '/../../config/smtp.local.php';
    $configuration = is_file($configurationFile) ? require $configurationFile : [];
    $localConfiguration = is_file($localConfigurationFile) ? require $localConfigurationFile : [];

    $configuration = array_replace(
        is_array($configuration) ? $configuration : [],
        is_array($localConfiguration) ? $localConfiguration : []
    );

    $host = getenv('SMTP_HOST') ?: ($configuration['host'] ?? '');
    $username = getenv('SMTP_USERNAME') ?: ($configuration['username'] ?? '');
    $password = getenv('SMTP_PASSWORD') ?: ($configuration['password'] ?? '');
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: ($configuration['from_email'] ?? $username);
    $fromName = getenv('SMTP_FROM_NAME') ?: ($configuration['from_name'] ?? 'AdoptAPet');
    $port = (int) (getenv('SMTP_PORT') ?: ($configuration['port'] ?? 587));

    if (!$host || !$username || !$password || !$fromEmail) {
        throw new RuntimeException('SMTP configuration is unavailable.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $port;
    $mail->setFrom($fromEmail, $fromName);
    $mail->isHTML(true);

    return $mail;
}

function createTransactionalMailer(): PHPMailer
{
    $mail = createMailer();
    $logoPath = dirname(__DIR__) . '/assets/images/adoptapet-logo.png';
    if (!is_file($logoPath)) {
        throw new RuntimeException('AdoptAPet email logo is unavailable.');
    }

    $mail->addEmbeddedImage(
        $logoPath,
        'adoptapet-logo',
        'adoptapet-logo.png',
        PHPMailer::ENCODING_BASE64,
        'image/png',
        'inline'
    );

    return $mail;
}

function mailFailureCategory(Throwable $exception): string
{
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'authenticate') || str_contains($message, 'authentication') || str_contains($message, '535')) {
        return 'authentication';
    }
    if (str_contains($message, 'recipient') || str_contains($message, 'invalid address') || str_contains($message, '550') || str_contains($message, '553')) {
        return 'recipient';
    }
    if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
        return 'timeout';
    }
    if (str_contains($message, 'connect') || str_contains($message, 'connection') || str_contains($message, 'network') || str_contains($message, 'getaddrinfo')) {
        return 'connection';
    }
    if (str_contains($message, 'smtp')) {
        return 'smtp_rejection';
    }

    return 'mail_send';
}

function safeMailFailureMessage(Throwable $exception): string
{
    return match (mailFailureCategory($exception)) {
        'authentication' => 'SMTP authentication failed.',
        'recipient' => 'The SMTP server rejected the recipient address.',
        'timeout' => 'The mail transport timed out.',
        'connection' => 'The SMTP connection could not be established.',
        'smtp_rejection' => 'The SMTP server rejected the delivery request.',
        default => 'Mail delivery failed before PHPMailer reported success.',
    };
}

function logMailSendFailure(string $operation, Throwable $exception): void
{
    $safeOperation = preg_replace('/[^a-z0-9_.-]/i', '_', $operation) ?: 'unknown';
    error_log(sprintf(
        'AdoptAPet mail failure [%s] category=%s exception=%s message=%s',
        $safeOperation,
        mailFailureCategory($exception),
        get_class($exception),
        safeMailFailureMessage($exception)
    ));
}

function sendMailWithFailureCleanup(string $operation, callable $send, callable $cleanup): void
{
    try {
        $send();
    } catch (Throwable $mailException) {
        logMailSendFailure($operation, $mailException);
        try {
            $cleanup();
        } catch (Throwable $cleanupException) {
            error_log(sprintf(
                'AdoptAPet mail-state cleanup failure [%s] exception=%s message=%s',
                preg_replace('/[^a-z0-9_.-]/i', '_', $operation) ?: 'unknown',
                get_class($cleanupException),
                safeMailFailureMessage($cleanupException)
            ));
        }
        throw $mailException;
    }
}

function sendVerificationEmail(string $email, string $code): void
{
    $mail = createTransactionalMailer();
    $mail->addAddress($email);
    $mail->Subject = 'Verify your AdoptAPet email address';
    $content = '<p style="margin:0 0 14px 0;font-size:15px;line-height:24px;color:#3d3027;">Use this code to verify your AdoptAPet email address:</p>'
        . transactionalEmailCodeBlock($code)
        . transactionalEmailNotice('This code expires in 10 minutes. If you did not create an AdoptAPet account, you can ignore this email.', 'security');
    $mail->Body = transactionalEmailLayout('Verify your email address', $content, 'Your AdoptAPet verification code expires in 10 minutes.');
    $mail->AltBody = "Your AdoptAPet verification code is {$code}. It expires in 10 minutes.";
    $mail->send();
}

function sendPasswordResetEmail(string $email, string $resetUrl): void
{
    $mail = createTransactionalMailer();
    $mail->addAddress($email);
    $mail->Subject = 'Reset your AdoptAPet password';
    $safeResetUrl = transactionalEmailEscape($resetUrl);
    $content = '<p style="margin:0 0 18px 0;font-size:15px;line-height:24px;color:#3d3027;">We received a request to reset your AdoptAPet password.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px 0;"><tr><td bgcolor="#e8893a">'
        . '<a href="' . $safeResetUrl . '" style="display:inline-block;padding:12px 20px;color:#ffffff;font-size:15px;line-height:20px;font-weight:700;text-decoration:none;">Reset Password</a>'
        . '</td></tr></table>'
        . transactionalEmailNotice('This link expires in 15 minutes and can be used only once. If you did not request a password reset, you can ignore this email.', 'security');
    $mail->Body = transactionalEmailLayout('Reset your password', $content, 'A password reset was requested for your AdoptAPet account.');
    $mail->AltBody = "Reset your AdoptAPet password using this link: {$resetUrl}\n\nThis link expires in 15 minutes and can only be used once. If you did not request it, you can ignore this email.";
    $mail->send();
}

function sendEmailChangeVerificationEmail(string $email, string $code): void
{
    $mail = createTransactionalMailer();
    $mail->addAddress($email);
    $mail->Subject = 'AdoptAPet Change Email Verification';
    $content = '<p style="margin:0 0 14px 0;font-size:15px;line-height:24px;color:#3d3027;">Use this 6-digit code to verify your new email address:</p>'
        . transactionalEmailCodeBlock($code)
        . transactionalEmailNotice('This code expires in 10 minutes. If you did not request this change, you can ignore this email.', 'security');
    $mail->Body = transactionalEmailLayout('Change email verification', $content, 'Your AdoptAPet change-email code expires in 10 minutes.');
    $mail->AltBody = "Use this 6-digit code to verify your new AdoptAPet email address: {$code}\n\nThis code expires in 10 minutes. If you did not request this change, you can ignore this email.";
    $mail->send();
}

function sendEmailChangeSecurityNotice(string $oldEmail): void
{
    $mail = createTransactionalMailer();
    $mail->addAddress($oldEmail);
    $mail->Subject = 'Your AdoptAPet email address was changed';
    $content = '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">The email address on your AdoptAPet account was changed.</p>'
        . transactionalEmailNotice('If you did not make this change, reset your password immediately.', 'security');
    $mail->Body = transactionalEmailLayout('Email address changed', $content, 'Security notice for your AdoptAPet account.');
    $mail->AltBody = "The email address on your AdoptAPet account was changed.\n\nIf you did not make this change, reset your password immediately.";
    $mail->send();
}

function sendPaymentVerificationEmail(string $email, string $name, string $code, string $purpose): void
{
    $mail = createTransactionalMailer();
    $mail->addAddress($email, $name);
    $safeName = transactionalEmailEscape($name);
    $safePurpose = transactionalEmailEscape($purpose);
    $mail->Subject = 'AdoptAPet Payment Verification Code';
    $content = '<p style="margin:0 0 14px 0;font-size:15px;line-height:24px;color:#3d3027;">Dear ' . $safeName . ',</p>'
        . '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">Your verification code is:</p>'
        . transactionalEmailCodeBlock($code)
        . '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">This code is for your simulated AdoptAPet ' . $safePurpose . '.</p>'
        . transactionalEmailNotice('This code expires in 10 minutes and can be used only once. No banking or wallet credentials are required.', 'security');
    $mail->Body = transactionalEmailLayout('Payment verification', $content, 'Your single-use AdoptAPet payment verification code.');
    $mail->AltBody = "Your AdoptAPet payment verification code is {$code}. It is for simulated {$purpose}, expires in 10 minutes, and can be used only once.";
    $mail->send();
}

function sendDonationReceiptEmail(
    string $email,
    string $name,
    string $amount,
    string $currency,
    string $donatedAt,
    string $transactionReference,
    string $documentNumber,
    string $pdfPath
): void {
    if (!is_file($pdfPath)) {
        throw new RuntimeException('Donation receipt PDF is unavailable.');
    }
    $mail = createTransactionalMailer();
    $mail->addAddress($email, $name);
    $mail->addAttachment($pdfPath, strtolower($documentNumber) . '.pdf');
    $safeName = transactionalEmailEscape($name);
    $displayDate = formatTransactionalDate($donatedAt);
    $mail->Subject = 'Your AdoptAPet Donation Receipt';
    $content = '<p style="margin:0 0 14px 0;font-size:15px;line-height:24px;color:#3d3027;">Dear ' . $safeName . ',</p>'
        . '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">Your simulated donation has been recorded successfully. Thank you for supporting rescued animals.</p>'
        . transactionalEmailDetails([
            ['Donation amount', $currency . ' ' . $amount],
            ['Date', $displayDate],
            ['Transaction reference', $transactionReference],
            ['Receipt number', $documentNumber],
        ])
        . transactionalEmailNotice('Your PDF receipt is attached. No real card, bank, or wallet credentials were collected or processed by this development system.');
    $mail->Body = transactionalEmailLayout('Thank you for supporting AdoptAPet', $content, 'Your AdoptAPet donation receipt is attached.');
    $mail->AltBody = "Thank you for supporting AdoptAPet. Amount: {$currency} {$amount}. Date: {$displayDate}. Transaction reference: {$transactionReference}. Receipt number: {$documentNumber}. Your PDF receipt is attached. No real card, bank, or wallet credentials were collected or processed by this development system.";
    $mail->send();
}

function sendAdoptionCertificateEmail(
    string $email,
    string $name,
    string $petName,
    string $documentNumber,
    string $pdfPath
): void {
    if (!is_file($pdfPath)) {
        throw new RuntimeException('Adoption certificate PDF is unavailable.');
    }
    $mail = createTransactionalMailer();
    $mail->addAddress($email, $name);
    $mail->addAttachment($pdfPath, strtolower($documentNumber) . '.pdf');
    $safeName = transactionalEmailEscape($name);
    $safePetName = transactionalEmailEscape($petName);
    $mail->Subject = 'Your AdoptAPet Adoption Certificate';
    $content = '<p style="margin:0 0 14px 0;font-size:15px;line-height:24px;color:#3d3027;">Dear ' . $safeName . ',</p>'
        . '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">Your adoption application for <strong>' . $safePetName . '</strong> has been approved.</p>'
        . transactionalEmailDetails([
            ['Pet', $petName],
            ['Certificate number', $documentNumber],
        ])
        . '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">Your official adoption certificate is attached as a PDF.</p>'
        . '<p style="margin:14px 0 0 0;font-size:15px;line-height:24px;color:#3d3027;">Congratulations from the AdoptAPet team.</p>';
    $mail->Body = transactionalEmailLayout('Your adoption has been approved', $content, 'Your official AdoptAPet adoption certificate is attached.');
    $mail->AltBody = "Your adoption application for {$petName} has been approved. Your official adoption certificate ({$documentNumber}) is attached as a PDF. Congratulations from the AdoptAPet team.";
    $mail->send();
}
