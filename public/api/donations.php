<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/pdf-document-service.php';
require_once __DIR__ . '/document-email-service.php';
require_once __DIR__ . '/payment-verification-service.php';

function donationTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function requireVerifiedDonationUser(PDO $pdo): array
{
    $userId = authenticatedUserId();

    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.role, u.account_status, u.deleted_at,
         EXISTS (
             SELECT 1 FROM email_verifications ev
             WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
         ) AS email_verified
         FROM users u
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if (
        !$user
        || $user['role'] !== 'registered_user'
        || $user['account_status'] !== 'active'
        || $user['deleted_at'] !== null
        || !(bool) $user['email_verified']
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }

    return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
}

function donationIdFromQuery(): int
{
    $value = $_GET['id'] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid donation ID is required.']);
        exit;
    }

    return (int) $value;
}

function formatDonationAmount(mixed $value): ?string
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        return null;
    }

    $amount = trim((string) $value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
        return null;
    }

    $numericAmount = (float) $amount;
    if ($numericAmount < 1 || $numericAmount > 99999999.99) {
        return null;
    }

    return number_format($numericAmount, 2, '.', '');
}

function donationPaymentMethods(): array
{
    return [
        'card' => 'Card',
        'online_banking' => 'Online Banking',
        'e_wallet' => 'E-Wallet',
    ];
}

function donationPaymentMethodLabel(?string $storedMethod): string
{
    return [
        'demo_card' => 'Card',
        'demo_online_banking' => 'Online Banking',
        'demo_e_wallet' => 'E-Wallet',
        'development_demo' => 'Payment',
    ][$storedMethod ?? ''] ?? 'Payment';
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    if ($method === 'GET') {
        $unexpectedParameters = array_diff(array_keys($_GET), ['id']);
        if ($unexpectedParameters !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported donation query parameter.']);
            exit;
        }

        if (array_key_exists('id', $_GET)) {
            $user = requireVerifiedDonationUser($pdo);
            $donationId = donationIdFromQuery();
            $statement = $pdo->prepare(
                'SELECT d.id, d.donor_name, d.is_anonymous, d.amount, d.currency,
                        d.donation_status, d.donated_at, d.created_at,
                        (
                            SELECT pt.transaction_reference
                            FROM payment_transactions pt
                            WHERE pt.donation_id = d.id AND pt.transaction_status = :transaction_status
                            ORDER BY pt.id DESC LIMIT 1
                        ) AS transaction_reference,
                        (
                            SELECT pt.payment_method
                            FROM payment_transactions pt
                            WHERE pt.donation_id = d.id AND pt.transaction_status = :payment_method_status
                            ORDER BY pt.id DESC LIMIT 1
                        ) AS payment_method
                 FROM donations d
                 WHERE d.id = :id
                   AND d.user_id = :user_id
                   AND d.donation_status = :donation_status
                 LIMIT 1'
            );
            $statement->execute([
                'transaction_status' => 'successful',
                'payment_method_status' => 'successful',
                'id' => $donationId,
                'user_id' => $user['id'],
                'donation_status' => 'completed',
            ]);
            $donation = $statement->fetch();

            if (!$donation) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Donation confirmation not found.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'donation' => [
                    'id' => (int) $donation['id'],
                    'donor_name' => $donation['donor_name'],
                    'is_anonymous' => (bool) $donation['is_anonymous'],
                    'amount' => (float) $donation['amount'],
                    'currency' => $donation['currency'],
                    'donation_status' => $donation['donation_status'],
                    'demo_payment_method' => $donation['payment_method'],
                    'demo_payment_method_label' => donationPaymentMethodLabel($donation['payment_method']),
                    'donated_at' => $donation['donated_at'] ?? $donation['created_at'],
                    'transaction_reference' => $donation['transaction_reference'],
                ],
            ]);
            exit;
        }

        $summaryStatement = $pdo->prepare(
            'SELECT COUNT(*) AS contribution_count, COALESCE(SUM(amount), 0) AS donation_total
             FROM donations
             WHERE donation_status = :status'
        );
        $summaryStatement->execute(['status' => 'completed']);
        $summary = $summaryStatement->fetch();

        $statement = $pdo->prepare(
            'SELECT d.id,
                    CASE
                        WHEN d.is_anonymous = 1 THEN :anonymous_name
                        ELSE COALESCE(u.username, d.donor_name)
                    END AS donor_display_name,
                    d.is_anonymous, d.amount, d.currency, COALESCE(d.donated_at, d.created_at) AS donated_at
             FROM donations d
             LEFT JOIN users u ON u.id = d.user_id
             WHERE d.donation_status = :status
             ORDER BY COALESCE(d.donated_at, d.created_at) DESC, d.id DESC'
        );
        $statement->execute(['anonymous_name' => 'Anonymous Supporter', 'status' => 'completed']);
        $donations = array_map(
            static fn (array $donation): array => [
                'id' => (int) $donation['id'],
                'donor_display_name' => $donation['donor_display_name'],
                'is_anonymous' => (bool) $donation['is_anonymous'],
                'amount' => (float) $donation['amount'],
                'currency' => $donation['currency'],
                'donated_at' => $donation['donated_at'],
            ],
            $statement->fetchAll()
        );

        echo json_encode([
            'success' => true,
            'summary' => [
                'contribution_count' => (int) $summary['contribution_count'],
                'donation_total' => (float) $summary['donation_total'],
                'currency' => 'MYR',
            ],
            'donations' => $donations,
        ]);
        exit;
    }

    $user = requireVerifiedDonationUser($pdo);
    requireValidCsrfToken();
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }

    $unexpectedFields = array_diff(array_keys($input), ['amount', 'donor_name', 'is_anonymous', 'payment_method', 'idempotency_key']);
    if ($unexpectedFields !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported donation information was provided.']);
        exit;
    }

    $amount = formatDonationAmount($input['amount'] ?? null);
    $donorName = trim((string) ($input['donor_name'] ?? ''));
    $isAnonymous = $input['is_anonymous'] ?? null;
    $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? '')));
    $idempotencyKey = strtolower(trim((string) ($input['idempotency_key'] ?? '')));

    if ($amount === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a donation amount from MYR 1.00 to MYR 99,999,999.99 using no more than two decimal places.']);
        exit;
    }

    if ($donorName === '' || donationTextLength($donorName) > 150) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a contributor name of up to 150 characters.']);
        exit;
    }

    if (!is_bool($isAnonymous)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid anonymity preference.']);
        exit;
    }

    $paymentMethods = donationPaymentMethods();
    if (!isset($paymentMethods[$paymentMethod])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid payment method.']);
        exit;
    }

    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $idempotencyKey)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The donation request identifier is invalid. Refresh the form and try again.']);
        exit;
    }

    $transactionReference = 'DEV-' . strtoupper(substr(hash('sha256', $user['id'] . ':' . $idempotencyKey), 0, 32));
    $storedPaymentMethod = 'demo_' . $paymentMethod;
    $existing = $pdo->prepare(
        'SELECT d.id, d.amount, d.currency, d.donor_name, d.is_anonymous,
                COALESCE(d.donated_at, d.created_at) AS donated_at,
                pt.transaction_reference, pt.payment_method,
                gd.file_path
         FROM payment_transactions pt
         INNER JOIN donations d ON d.id = pt.donation_id
         LEFT JOIN generated_documents gd
             ON gd.donation_id = d.id AND gd.document_type = :document_type
         WHERE d.user_id = :user_id AND pt.transaction_reference = :transaction_reference
         LIMIT 1'
    );
    $existing->execute([
        'document_type' => 'donation_receipt',
        'user_id' => $user['id'],
        'transaction_reference' => $transactionReference,
    ]);
    $existingDonation = $existing->fetch();
    if ($existingDonation) {
        echo json_encode([
            'success' => true,
            'duplicate' => true,
            'message' => 'This donation was already recorded. No duplicate transaction was created.',
            'receipt_available' => ($existingPath = pdfStoredAbsolutePath((string) ($existingDonation['file_path'] ?? ''))) !== null && isRealPdfFile($existingPath),
            'receipt_email_sent' => false,
            'donation' => [
                'id' => (int) $existingDonation['id'],
                'amount' => (float) $existingDonation['amount'],
                'currency' => $existingDonation['currency'],
                'donor_display_name' => (bool) $existingDonation['is_anonymous'] ? 'Anonymous Supporter' : $existingDonation['donor_name'],
                'donated_at' => $existingDonation['donated_at'],
                'transaction_reference' => $existingDonation['transaction_reference'],
                'demo_payment_method' => $existingDonation['payment_method'],
                'demo_payment_method_label' => donationPaymentMethodLabel($existingDonation['payment_method']),
            ],
        ]);
        exit;
    }

    if (
        in_array($paymentMethod, ['online_banking', 'e_wallet'], true)
        && !paymentVerificationIsAuthorised($user['id'], $paymentMethod, $idempotencyKey)
    ) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Complete payment verification before recording this donation.']);
        exit;
    }

    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO donations
         (user_id, donor_name, is_anonymous, amount, currency, donation_status, donated_at)
         VALUES (:user_id, :donor_name, :is_anonymous, :amount, :currency, :donation_status, NOW())'
    );
    $statement->execute([
        'user_id' => $user['id'],
        'donor_name' => $donorName,
        'is_anonymous' => $isAnonymous ? 1 : 0,
        'amount' => $amount,
        'currency' => 'MYR',
        'donation_status' => 'completed',
    ]);
    $donationId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO payment_transactions
         (donation_id, transaction_reference, payment_method, amount, currency, transaction_status, processed_at)
         VALUES (:donation_id, :transaction_reference, :payment_method, :amount, :currency, :transaction_status, NOW())'
    );
    $statement->execute([
        'donation_id' => $donationId,
        'transaction_reference' => $transactionReference,
        'payment_method' => $storedPaymentMethod,
        'amount' => $amount,
        'currency' => 'MYR',
        'transaction_status' => 'successful',
    ]);

    $pdo->commit();

    if (in_array($paymentMethod, ['online_banking', 'e_wallet'], true)) {
        consumePaymentVerification($user['id'], $paymentMethod, $idempotencyKey);
    }

    $donationTimestamp = $pdo->prepare('SELECT COALESCE(donated_at, created_at) FROM donations WHERE id = :id');
    $donationTimestamp->execute(['id' => $donationId]);
    $donatedAt = (string) $donationTimestamp->fetchColumn();
    $receiptAvailable = false;
    $receiptEmailSent = false;
    try {
        $document = createOrReusePdfDocument($pdo, $user['id'], 'donation_receipt', $donationId, [
            'id' => $donationId,
            'donor_name' => $donorName,
            'is_anonymous' => $isAnonymous,
            'amount' => $amount,
            'currency' => 'MYR',
            'donation_status' => 'completed',
            'donation_date' => $donatedAt,
            'transaction_reference' => $transactionReference,
            'demo_payment_method_label' => $paymentMethods[$paymentMethod],
        ]);
        $receiptAvailable = isRealPdfFile($document['file_path']);
    } catch (Throwable $documentException) {
        $receiptAvailable = false;
    }

    if ($receiptAvailable) {
        try {
            $delivery = deliverGeneratedDocumentEmail(
                $pdo,
                $document,
                static function () use ($user, $amount, $donatedAt, $transactionReference, $document): void {
                    sendDonationReceiptEmail(
                        $user['email'],
                        $user['name'],
                        $amount,
                        'MYR',
                        $donatedAt,
                        $transactionReference,
                        $document['document_number'],
                        $document['file_path']
                    );
                }
            );
            $receiptEmailSent = $delivery['sent'];
        } catch (Throwable $mailException) {
            $receiptEmailSent = false;
        }
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => $receiptEmailSent
            ? 'Donation recorded successfully. A copy of your receipt was sent to your registered email address.'
            : ($receiptAvailable
                ? 'Your donation was recorded successfully. The receipt is available for download, but the email could not be sent.'
                : 'Your donation was recorded successfully, but the PDF receipt could not be prepared. Please try downloading it from My Activity.'),
        'receipt_available' => $receiptAvailable,
        'receipt_email_sent' => $receiptEmailSent,
        'donation' => [
            'id' => $donationId,
            'amount' => (float) $amount,
            'currency' => 'MYR',
            'donor_display_name' => $isAnonymous ? 'Anonymous Supporter' : $donorName,
            'donated_at' => $donatedAt,
            'transaction_reference' => $transactionReference,
            'demo_payment_method' => $paymentMethod,
            'demo_payment_method_label' => $paymentMethods[$paymentMethod],
        ],
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the donation. Please try again later.']);
}
