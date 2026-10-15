<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/private-storage.php';
require_once __DIR__ . '/transactional-presentation.php';

final class AdoptAPetPdf extends FPDF
{
    private string $documentTitle;

    public function __construct(string $documentTitle)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->documentTitle = $documentTitle;
        $this->SetMargins(18, 36, 18);
        $this->SetAutoPageBreak(true, 20);
        $this->SetTitle(pdfText($documentTitle));
        $this->SetAuthor('AdoptAPet Web-Based Pet Adoption System');
        $this->SetCreator('AdoptAPet');
    }

    public function Header(): void
    {
        $this->SetFillColor(255, 249, 237);
        $this->Rect(0, 0, 210, 30, 'F');

        $logoPath = dirname(__DIR__) . '/assets/images/adoptapet-logo.png';
        if (is_file($logoPath)) {
            $this->Image($logoPath, 18, 7, 48);
        } else {
            $this->SetTextColor(61, 48, 39);
            $this->SetFont('Arial', 'B', 17);
            $this->SetXY(18, 10);
            $this->Cell(70, 8, 'AdoptAPet', 0, 0, 'L');
        }

        $this->SetTextColor(117, 102, 91);
        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(105, 10);
        $this->Cell(87, 7, strtoupper(pdfText($this->documentTitle)), 0, 0, 'R');
        $this->SetDrawColor(232, 137, 58);
        $this->SetLineWidth(0.5);
        $this->Line(18, 29, 192, 29);
        $this->SetY(36);
    }

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetDrawColor(238, 216, 188);
        $this->SetLineWidth(0.3);
        $this->Line(20, $this->GetY(), 190, $this->GetY());
        $this->SetY(-13);
        $this->SetTextColor(117, 102, 91);
        $this->SetFont('Arial', '', 8);
        $this->Cell(120, 6, 'AdoptAPet Web-Based Pet Adoption System', 0, 0, 'L');
        $this->Cell(50, 6, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

function pdfText(string $value): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) {
            return $converted;
        }
    }

    return preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
}

function pdfStorageDirectory(): string
{
    return adoptAPetGeneratedDocumentStorageDirectory();
}

function pdfStoredAbsolutePath(string $relativePath): ?string
{
    if (!preg_match('#^storage/generated-documents/[A-Za-z0-9._-]+\.pdf$#', $relativePath)) {
        return null;
    }

    $filename = basename($relativePath);
    return pdfStorageDirectory() . DIRECTORY_SEPARATOR . $filename;
}

function isRealPdfFile(string $path): bool
{
    if (!is_file($path) || filesize($path) === false || filesize($path) < 100) {
        return false;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $signature = fread($handle, 5);
    fclose($handle);

    return $signature === '%PDF-';
}

function pdfSectionLabel(FPDF $pdf, string $label, float $width = 174): void
{
    $pdf->SetFillColor(251, 232, 210);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($width, 9, pdfText($label), 0, 1, 'L', true);
}

function pdfLabelValue(
    FPDF $pdf,
    string $label,
    string $value,
    float $labelWidth = 48,
    float $valueWidth = 126,
    bool $drawRule = true
): void {
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $lineHeight = 6.2;

    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetTextColor(117, 102, 91);
    $pdf->MultiCell($labelWidth, $lineHeight, pdfText($label), 0, 'L');
    $labelBottom = $pdf->GetY();

    $pdf->SetXY($x + $labelWidth, $y);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->MultiCell($valueWidth, $lineHeight, pdfText($value), 0, 'L');
    $valueBottom = $pdf->GetY();

    $bottom = max($labelBottom, $valueBottom) + 2.2;
    if ($drawRule) {
        $pdf->SetDrawColor(238, 216, 188);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($x, $bottom, $x + $labelWidth + $valueWidth, $bottom);
    }
    $pdf->SetXY($x, $bottom + 1.8);
}

function pdfCompletedStatusRow(FPDF $pdf): void
{
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetTextColor(117, 102, 91);
    $pdf->Cell(48, 8, 'Payment status', 0, 0, 'L');

    $pdf->SetXY($x + 48, $y + 0.5);
    $pdf->SetFillColor(218, 240, 223);
    $pdf->SetTextColor(31, 101, 54);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(28, 7, 'Completed', 0, 0, 'C', true);
    $pdf->SetXY($x, $y + 10);
}

function writeDonationReceiptPdf(string $path, string $documentNumber, array $donation): void
{
    $pdf = new AdoptAPetPdf('Official Donation Receipt');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 25);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->Cell(0, 12, 'Donation Receipt', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10.5);
    $pdf->SetTextColor(117, 102, 91);
    $pdf->Cell(0, 7, pdfText('Thank you for supporting rescued animals.'), 0, 1, 'L');
    $pdf->Ln(7);

    $amountX = $pdf->GetX();
    $amountY = $pdf->GetY();
    $pdf->SetFillColor(255, 249, 237);
    $pdf->SetDrawColor(238, 216, 188);
    $pdf->Rect($amountX, $amountY, 174, 28, 'DF');
    $pdf->SetTextColor(117, 102, 91);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetXY($amountX + 6, $amountY + 4);
    $pdf->Cell(162, 5, 'TOTAL DONATION AMOUNT', 0, 1, 'C');
    $pdf->SetTextColor(216, 117, 38);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetXY($amountX + 6, $amountY + 10);
    $pdf->Cell(162, 12, pdfText($donation['currency'] . ' ' . number_format((float) $donation['amount'], 2)), 0, 1, 'C');
    $pdf->SetXY($amountX, $amountY + 35);

    pdfSectionLabel($pdf, 'Receipt Details');
    $pdf->Ln(4);

    pdfLabelValue($pdf, 'Receipt number', $documentNumber);
    pdfLabelValue($pdf, 'Donor', (string) $donation['donor_name']);
    pdfLabelValue($pdf, 'Donation date', formatTransactionalDate((string) $donation['donation_date']));
    pdfLabelValue($pdf, 'Transaction reference', (string) $donation['transaction_reference']);
    if (!empty($donation['demo_payment_method_label'])) {
        pdfLabelValue($pdf, 'Payment method', (string) $donation['demo_payment_method_label']);
    }
    pdfCompletedStatusRow($pdf);

    $pdf->Ln(5);
    $pdf->SetDrawColor(238, 216, 188);
    $pdf->SetFillColor(255, 249, 237);
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->MultiCell(0, 6.5, pdfText('This receipt confirms that AdoptAPet recorded the donation shown above. No real card, bank, or wallet credentials were collected or stored by this development system.'), 1, 'L', true);
    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->Cell(0, 7, 'With gratitude,', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, 'The AdoptAPet Team', 0, 1, 'L');

    $pdf->Output('F', $path);
}

function writeAdoptionCertificatePdf(string $path, string $documentNumber, array $application): void
{
    $pdf = new AdoptAPetPdf('Official Adoption Certificate');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetDrawColor(232, 137, 58);
    $pdf->SetLineWidth(0.8);
    $pdf->Rect(12, 34, 186, 238);
    $pdf->SetDrawColor(248, 217, 138);
    $pdf->SetLineWidth(0.35);
    $pdf->Rect(15, 37, 180, 232);

    $pdf->SetY(46);
    $pdf->SetFont('Arial', 'B', 28);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->Cell(0, 14, 'Certificate of Adoption', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(117, 102, 91);
    $pdf->Cell(0, 7, pdfText('Certificate number: ' . $documentNumber), 0, 1, 'C');
    $pdf->SetDrawColor(232, 137, 58);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(67, $pdf->GetY() + 5, 143, $pdf->GetY() + 5);
    $pdf->Ln(16);

    $pdf->SetFont('Arial', '', 12);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->Cell(0, 9, 'This certificate proudly recognizes', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 23);
    $pdf->SetTextColor(216, 117, 38);
    $pdf->MultiCell(0, 11, pdfText((string) $application['adopter_name']), 0, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->Cell(0, 9, 'as the approved adopter of', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 25);
    $pdf->SetTextColor(216, 117, 38);
    $pdf->MultiCell(0, 12, pdfText((string) $application['pet_name']), 0, 'C');
    $pdf->Ln(8);

    $pdf->SetFont('Arial', '', 10.5);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->SetX(29);
    $pdf->MultiCell(152, 7, pdfText('AdoptAPet confirms that the adoption application was approved and celebrates the beginning of a safe, responsible, and loving forever home.'), 0, 'C');
    $pdf->Ln(8);

    $panelX = 29;
    $panelY = $pdf->GetY();
    $pdf->SetFillColor(255, 249, 237);
    $pdf->SetDrawColor(238, 216, 188);
    $pdf->Rect($panelX, $panelY, 152, 45, 'DF');
    $pdf->SetXY($panelX + 6, $panelY + 5);
    pdfLabelValue($pdf, 'Approval date', formatTransactionalDate((string) $application['approval_date']), 50, 90, false);
    pdfLabelValue($pdf, 'Application reference', 'APP-' . str_pad((string) $application['application_id'], 6, '0', STR_PAD_LEFT), 50, 90, false);
    pdfLabelValue($pdf, 'Pet', (string) $application['pet_name'], 50, 90, false);
    pdfLabelValue($pdf, 'Adopter', (string) $application['adopter_name'], 50, 90, false);

    $issuedY = max($panelY + 54, $pdf->GetY() + 5);
    $pdf->SetY($issuedY);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(61, 48, 39);
    $pdf->Cell(0, 8, 'Issued by AdoptAPet', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(117, 102, 91);
    $pdf->Cell(0, 7, 'Web-Based Pet Adoption System', 0, 1, 'C');

    $pdf->Output('F', $path);
}

function documentNumber(string $type, int $sourceId, string $sourceDate): string
{
    $timestamp = strtotime($sourceDate);
    $datePart = $timestamp === false ? date('Ymd') : date('Ymd', $timestamp);
    $prefix = $type === 'donation_receipt' ? 'DR' : 'AC';

    return $prefix . '-' . $datePart . '-' . str_pad((string) $sourceId, 6, '0', STR_PAD_LEFT);
}

/** Reuses an eligible private document or writes one for the authenticated owner. */
function createOrReusePdfDocument(PDO $pdo, int $userId, string $type, int $sourceId, array $source): array
{
    $sourceColumn = $type === 'donation_receipt' ? 'donation_id' : 'application_id';
    $sourceTable = $type === 'donation_receipt' ? 'donations' : 'adoption_applications';
    $ownerColumn = $type === 'donation_receipt' ? 'user_id' : 'applicant_user_id';
    $statusColumn = $type === 'donation_receipt' ? 'donation_status' : 'application_status';
    $requiredStatus = $type === 'donation_receipt' ? 'completed' : 'approved';
    $sourceDate = $type === 'donation_receipt' ? (string) $source['donation_date'] : (string) $source['approval_date'];
    $number = documentNumber($type, $sourceId, $sourceDate);
    $prefix = $type === 'donation_receipt' ? 'donation-receipt' : 'adoption-certificate';
    $relativePath = 'storage/generated-documents/' . $prefix . '-' . str_pad((string) $sourceId, 6, '0', STR_PAD_LEFT) . '.pdf';
    $finalPath = pdfStoredAbsolutePath($relativePath);
    if ($finalPath === null) {
        throw new RuntimeException('Document storage path is invalid.');
    }

    $temporaryPath = pdfStorageDirectory() . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(12)) . '.pdf';
    $newFileInstalled = false;

    try {
        $pdo->beginTransaction();
        $sourceLock = $pdo->prepare(
            "SELECT id FROM {$sourceTable}
             WHERE id = :id AND {$ownerColumn} = :user_id AND {$statusColumn} = :status
             FOR UPDATE"
        );
        $sourceLock->execute(['id' => $sourceId, 'user_id' => $userId, 'status' => $requiredStatus]);
        if (!$sourceLock->fetch()) {
            $pdo->rollBack();
            throw new DomainException('The source record is no longer eligible for this document.');
        }

        $existing = $pdo->prepare("SELECT id, document_number, file_path FROM generated_documents WHERE {$sourceColumn} = :source_id LIMIT 1");
        $existing->execute(['source_id' => $sourceId]);
        $document = $existing->fetch();
        if ($document) {
            $existingPath = pdfStoredAbsolutePath((string) $document['file_path']);
            if ($existingPath === null) {
                $pdo->rollBack();
                throw new RuntimeException('Stored document path is invalid.');
            }
            if (isRealPdfFile($existingPath)) {
                $pdo->commit();
                return ['id' => (int) $document['id'], 'document_number' => $document['document_number'], 'file_path' => $existingPath];
            }
            $finalPath = $existingPath;
            $number = (string) $document['document_number'];
        }

        if ($type === 'donation_receipt') {
            writeDonationReceiptPdf($temporaryPath, $number, $source);
        } else {
            writeAdoptionCertificatePdf($temporaryPath, $number, $source);
        }
        if (!isRealPdfFile($temporaryPath)) {
            throw new RuntimeException('Generated document validation failed.');
        }

        if (is_file($finalPath) && !unlink($finalPath)) {
            throw new RuntimeException('Existing document file could not be replaced.');
        }
        if (!rename($temporaryPath, $finalPath)) {
            throw new RuntimeException('Generated document could not be stored.');
        }
        @chmod($finalPath, 0640);
        $newFileInstalled = true;

        if (!$document) {
            $insert = $pdo->prepare(
                "INSERT INTO generated_documents
                 (document_type, document_number, donation_id, application_id, file_path, generated_by_user_id)
                 VALUES (:document_type, :document_number, :donation_id, :application_id, :file_path, :generated_by_user_id)"
            );
            $insert->execute([
                'document_type' => $type,
                'document_number' => $number,
                'donation_id' => $type === 'donation_receipt' ? $sourceId : null,
                'application_id' => $type === 'adoption_certificate' ? $sourceId : null,
                'file_path' => $relativePath,
                'generated_by_user_id' => $userId,
            ]);
            $documentId = (int) $pdo->lastInsertId();
        } else {
            $documentId = (int) $document['id'];
        }

        $pdo->commit();
        return ['id' => $documentId, 'document_number' => $number, 'file_path' => $finalPath];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
        if ($newFileInstalled && is_file($finalPath)) {
            @unlink($finalPath);
        }
        throw $exception;
    }
}
