<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pdf-document-service.php';

function documentError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function requireVerifiedDocumentUser(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        documentError(401, 'Authentication is required.');
    }

    $statement = $pdo->prepare(
        'SELECT u.id
         FROM users u
         WHERE u.id = :id
           AND u.role = :role
           AND u.account_status = :status
           AND u.deleted_at IS NULL
           AND EXISTS (
               SELECT 1 FROM email_verifications ev
               WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
           )
         LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'role' => 'registered_user', 'status' => 'active']);
    if (!$statement->fetch()) {
        documentError(403, 'An active, verified registered-user account is required.');
    }

    return $userId;
}

function documentIdFromQuery(): int
{
    $value = $_GET['id'] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        documentError(422, 'A valid source record ID is required.');
    }

    return (int) $value;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        documentError(405, 'Method not allowed.');
    }
    if (array_diff(array_keys($_GET), ['type', 'id']) !== []) {
        documentError(422, 'Unsupported document query parameter.');
    }

    $type = $_GET['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['donation_receipt', 'adoption_certificate'], true)) {
        documentError(422, 'Select a valid document type.');
    }
    $sourceId = documentIdFromQuery();
    $pdo = getDatabaseConnection();
    $userId = requireVerifiedDocumentUser($pdo);

    if ($type === 'donation_receipt') {
        $statement = $pdo->prepare(
            'SELECT d.id, d.donor_name, d.is_anonymous, d.amount, d.currency,
                    d.donation_status, COALESCE(d.donated_at, d.created_at) AS donation_date,
                    (
                        SELECT CASE pt.payment_method
                            WHEN :demo_card THEN :card_label
                            WHEN :demo_online_banking THEN :bank_label
                            WHEN :demo_e_wallet THEN :wallet_label
                            ELSE :demo_label
                        END
                        FROM payment_transactions pt
                        WHERE pt.donation_id = d.id AND pt.transaction_status = :payment_method_status
                        ORDER BY pt.id DESC LIMIT 1
                    ) AS demo_payment_method_label,
                    (
                        SELECT pt.transaction_reference
                        FROM payment_transactions pt
                        WHERE pt.donation_id = d.id AND pt.transaction_status = :transaction_status
                        ORDER BY pt.id DESC LIMIT 1
                    ) AS transaction_reference
             FROM donations d
             WHERE d.id = :id AND d.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'demo_card' => 'demo_card',
            'card_label' => 'Card',
            'demo_online_banking' => 'demo_online_banking',
            'bank_label' => 'Online Banking',
            'demo_e_wallet' => 'demo_e_wallet',
            'wallet_label' => 'E-Wallet',
            'demo_label' => 'Payment',
            'payment_method_status' => 'successful',
            'transaction_status' => 'successful',
            'id' => $sourceId,
            'user_id' => $userId,
        ]);
        $source = $statement->fetch();
        if (!$source) {
            documentError(404, 'Donation receipt source not found.');
        }
        if ($source['donation_status'] !== 'completed' || !is_string($source['transaction_reference']) || $source['transaction_reference'] === '') {
            documentError(409, 'Only a completed Donation with a successful transaction can have a receipt.');
        }
    } else {
        $statement = $pdo->prepare(
            'SELECT a.id AS application_id, a.application_status, u.name AS adopter_name,
                    p.name AS pet_name,
                    COALESCE(
                        a.reviewed_at,
                        (SELECT MAX(h.changed_at) FROM application_status_history h WHERE h.application_id = a.id AND h.new_status = :approved_status),
                        a.submitted_at
                    ) AS approval_date
             FROM adoption_applications a
             INNER JOIN users u ON u.id = a.applicant_user_id
             INNER JOIN pets p ON p.id = a.pet_id
             WHERE a.id = :id AND a.applicant_user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['approved_status' => 'approved', 'id' => $sourceId, 'user_id' => $userId]);
        $source = $statement->fetch();
        if (!$source) {
            documentError(404, 'Adoption Certificate source not found.');
        }
        if ($source['application_status'] !== 'approved') {
            documentError(409, 'Only an approved Adoption Application can have a certificate.');
        }
    }

    $document = createOrReusePdfDocument($pdo, $userId, $type, $sourceId, $source);
    if (!isRealPdfFile($document['file_path'])) {
        throw new RuntimeException('Generated document is unavailable.');
    }

    $downloadName = strtolower((string) $document['document_number']) . '.pdf';
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($document['file_path']));
    readfile($document['file_path']);
} catch (DomainException $exception) {
    documentError(409, $exception->getMessage());
} catch (PDOException $exception) {
    documentError(500, 'Unable to prepare the requested document. Please try again later.');
} catch (Throwable $exception) {
    documentError(500, 'Unable to generate the requested document. Please try again later.');
}
