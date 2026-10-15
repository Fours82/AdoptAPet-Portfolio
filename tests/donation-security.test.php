<?php
declare(strict_types=1);

function startAuthenticationSession(): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
}

require_once dirname(__DIR__) . '/public/api/payment-verification-service.php';

function donationSecurityCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Donation security check failed: ' . $description);
    }
}

$_SESSION = [];
$requestKey = '12345678-1234-4123-8123-123456789abc';
donationSecurityCheck(paymentVerificationContext('online_banking') === 'online_banking'
    && paymentVerificationContext('e_wallet') === 'e_wallet'
    && paymentVerificationContext('card') === null, 'OTP scope remains bank and wallet only');
donationSecurityCheck(paymentVerificationRequestKeyIsValid($requestKey)
    && !paymentVerificationRequestKeyIsValid('predictable-key'), 'request identifiers remain UUID v4 values');
donationSecurityCheck((bool) preg_match('/^\d{6}$/D', generatePaymentVerificationCode()), 'verification codes remain six digits');
donationSecurityCheck(maskPaymentEmail('supporter@example.test') === 'su****@example.test', 'payment email remains masked');

$validCode = generatePaymentVerificationCode();
$wrongCode = $validCode === '000000' ? '999999' : '000000';
storePaymentVerificationCode(7, 'e_wallet', $requestKey, $validCode);
$stored = paymentVerificationState();
donationSecurityCheck(is_array($stored)
    && !array_key_exists('code', $stored)
    && password_verify($validCode, (string) ($stored['code_hash'] ?? ''))
    && (int) $stored['expires_at'] > time(), 'OTP is hashed and expires');
donationSecurityCheck(!verifyPaymentVerificationCode(7, 'e_wallet', $requestKey, $wrongCode)
    && (int) (paymentVerificationState()['attempts'] ?? 0) === 1, 'failed verification increments attempts');
donationSecurityCheck(verifyPaymentVerificationCode(7, 'e_wallet', $requestKey, $validCode)
    && paymentVerificationIsAuthorised(7, 'e_wallet', $requestKey)
    && !paymentVerificationIsAuthorised(7, 'online_banking', $requestKey), 'successful OTP is bound to user, context and request');
donationSecurityCheck(!array_key_exists('code_hash', paymentVerificationState() ?? []), 'OTP hash is removed after verification');
consumePaymentVerification(7, 'e_wallet', $requestKey);
donationSecurityCheck(paymentVerificationState() === null, 'authorisation is consumed after use');

$expiredCode = generatePaymentVerificationCode();
storePaymentVerificationCode(7, 'online_banking', $requestKey, $expiredCode);
$_SESSION['donation_payment_verification']['expires_at'] = time() - 1;
donationSecurityCheck(!verifyPaymentVerificationCode(7, 'online_banking', $requestKey, $expiredCode)
    && paymentVerificationState() === null, 'expired OTP fails closed and is cleared');

$limitedCode = generatePaymentVerificationCode();
$limitedWrongCode = $limitedCode === '000000' ? '999999' : '000000';
storePaymentVerificationCode(7, 'e_wallet', $requestKey, $limitedCode);
for ($attempt = 0; $attempt < PAYMENT_VERIFICATION_MAX_ATTEMPTS; $attempt++) {
    verifyPaymentVerificationCode(7, 'e_wallet', $requestKey, $limitedWrongCode);
}
donationSecurityCheck(paymentVerificationState() === null, 'OTP is cleared at the retry limit');

$_SESSION['donation_payment_verification_last_sent_at'] = time();
donationSecurityCheck(paymentVerificationCooldownSeconds() > 0, 'OTP resend cooldown is active');
$_SESSION['donation_payment_verification_last_sent_at'] = time() - PAYMENT_VERIFICATION_RESEND_SECONDS - 1;
donationSecurityCheck(paymentVerificationCooldownSeconds() === 0, 'OTP resend cooldown expires');

clearBankProviderLoginAttempts();
for ($attempt = 0; $attempt < BANK_PROVIDER_LOGIN_MAX_ATTEMPTS; $attempt++) {
    recordBankProviderLoginFailure(7, $requestKey);
}
donationSecurityCheck(bankProviderLoginCooldownSeconds(7, $requestKey) > 0, 'bank login retry limit starts cooldown');
clearBankProviderLoginAttempts(7, $requestKey);
donationSecurityCheck(bankProviderLoginAttemptState(7, $requestKey) === null, 'bank login cooldown state can be cleared for the matching request');

$apiRoot = dirname(__DIR__) . '/public/api/';
$donations = file_get_contents($apiRoot . 'donations.php');
$verification = file_get_contents($apiRoot . 'payment-verification.php');
$documents = file_get_contents($apiRoot . 'documents.php');
$pdf = file_get_contents($apiRoot . 'pdf-document-service.php');
$delivery = file_get_contents($apiRoot . 'document-email-service.php');
foreach ([$donations, $verification, $documents, $pdf, $delivery] as $source) {
    donationSecurityCheck(is_string($source), 'required donation source is readable');
}
donationSecurityCheck(str_contains($donations, 'requireValidCsrfToken()')
    && str_contains($verification, 'requireValidCsrfToken()'), 'donation and OTP mutations retain CSRF validation');
donationSecurityCheck(str_contains($donations, 'This donation was already recorded. No duplicate transaction was created.')
    && strpos($donations, '$existingDonation = $existing->fetch();') < strpos($donations, '$pdo->beginTransaction();'), 'idempotency is checked before insertion');
donationSecurityCheck(str_contains($donations, "in_array(\$paymentMethod, ['online_banking', 'e_wallet'], true)")
    && str_contains($donations, 'paymentVerificationIsAuthorised('), 'bank and wallet donations require verified payment state');
donationSecurityCheck(str_contains($documents, 'WHERE d.id = :id AND d.user_id = :user_id')
    && str_contains($pdf, 'createOrReusePdfDocument('), 'receipt access remains owner-only and generation remains reusable');
donationSecurityCheck(str_contains($delivery, 'AND email_sent_at IS NULL')
    && str_contains($delivery, "'already_sent' => true"), 'receipt email delivery remains idempotent');

echo "Donation OTP, cooldown, idempotency, receipt ownership and email-delivery checks passed.\n";
