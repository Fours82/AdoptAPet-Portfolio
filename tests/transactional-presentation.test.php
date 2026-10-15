<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/pdf-document-service.php';
require_once dirname(__DIR__) . '/public/api/transactional-presentation.php';
require_once dirname(__DIR__) . '/public/api/mailer.php';

function transactionalPresentationCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Transactional presentation check failed: ' . $label);
    }
}

$writePreviews = getenv('WRITE_TRANSACTIONAL_PREVIEWS') === '1';
$root = dirname(__DIR__);
$pdfDirectory = $writePreviews ? $root . '/output/pdf' : $root . '/tmp/pdfs';
$emailDirectory = $writePreviews ? $root . '/output/email' : $root . '/tmp/email-previews';
foreach ([$pdfDirectory, $emailDirectory] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create preview directory: ' . $directory);
    }
}

$receiptPath = $pdfDirectory . '/adoptapet-donation-receipt-sample.pdf';
$certificatePath = $pdfDirectory . '/adoptapet-adoption-certificate-sample.pdf';
$longReference = 'DEV-' . str_repeat('9F3A7B2C', 14);

writeDonationReceiptPdf($receiptPath, 'DR-20260922-000031', [
    'currency' => 'MYR',
    'amount' => '49.97',
    'donor_name' => 'Alexandra Tan Wei-Ling',
    'donation_date' => '2026-09-22 06:52:36',
    'transaction_reference' => $longReference,
    'demo_payment_method_label' => 'E-Wallet',
]);

writeAdoptionCertificatePdf($certificatePath, 'AC-20260914-000016', [
    'application_id' => 16,
    'adopter_name' => 'Alexandra Tan Wei-Ling',
    'pet_name' => 'Luna',
    'approval_date' => '2026-09-14 16:27:38',
]);

foreach ([$receiptPath, $certificatePath] as $pdfPath) {
    transactionalPresentationCheck(isRealPdfFile($pdfPath), basename($pdfPath) . ' is a valid PDF');
    $pdfSource = file_get_contents($pdfPath);
    transactionalPresentationCheck(is_string($pdfSource) && str_contains($pdfSource, '/Count 1'), basename($pdfPath) . ' remains one page');
}

transactionalPresentationCheck(formatTransactionalDate('2026-09-22 06:52:36') === '22 Sep 2026, 6:52 AM', 'readable date formatting');
transactionalPresentationCheck(formatTransactionalDate('not-a-date') === 'not-a-date', 'invalid date fallback');

$sampleName = 'Alexandra Tan Wei-Ling';
$sampleCode = '471258';
$sampleEmails = [
    'donation-receipt' => transactionalEmailLayout(
        'Thank you for supporting AdoptAPet',
        '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">Dear ' . transactionalEmailEscape($sampleName) . ',</p>'
        . transactionalEmailDetails([
            ['Donation amount', 'MYR 49.97'],
            ['Date', '22 Sep 2026, 6:52 AM'],
            ['Transaction reference', $longReference],
            ['Receipt number', 'DR-20260922-000031'],
        ])
        . transactionalEmailNotice('Your PDF receipt is attached. No real card, bank, or wallet credentials were collected or processed by this development system.')
    ),
    'adoption-certificate' => transactionalEmailLayout(
        'Your adoption has been approved',
        '<p style="margin:0;font-size:15px;line-height:24px;color:#3d3027;">Dear ' . transactionalEmailEscape($sampleName) . ', your adoption application for <strong>Luna</strong> has been approved.</p>'
        . transactionalEmailDetails([['Pet', 'Luna'], ['Certificate number', 'AC-20260914-000016']])
        . '<p style="font-size:15px;line-height:24px;color:#3d3027;">Your official adoption certificate is attached as a PDF.</p>'
    ),
    'payment-verification' => transactionalEmailLayout(
        'Payment verification',
        '<p style="font-size:15px;line-height:24px;color:#3d3027;">Your verification code is:</p>'
        . transactionalEmailCodeBlock($sampleCode)
        . transactionalEmailNotice('This code expires in 10 minutes and can be used only once. No banking or wallet credentials are required.', 'security')
    ),
    'email-address-changed' => transactionalEmailLayout(
        'Email address changed',
        '<p style="font-size:15px;line-height:24px;color:#3d3027;">The email address on your AdoptAPet account was changed.</p>'
        . transactionalEmailNotice('If you did not make this change, reset your password immediately.', 'security')
    ),
    'signup-verification' => transactionalEmailLayout(
        'Verify your email address',
        '<p style="font-size:15px;line-height:24px;color:#3d3027;">Use this code to verify your AdoptAPet email address:</p>'
        . transactionalEmailCodeBlock($sampleCode)
    ),
    'change-email-verification' => transactionalEmailLayout(
        'Change email verification',
        '<p style="font-size:15px;line-height:24px;color:#3d3027;">Use this 6-digit code to verify your new email address:</p>'
        . transactionalEmailCodeBlock($sampleCode)
        . transactionalEmailNotice('This code expires in 10 minutes. If you did not request this change, you can ignore this email.', 'security')
    ),
    'password-reset' => transactionalEmailLayout(
        'Reset your password',
        '<p style="font-size:15px;line-height:24px;color:#3d3027;">We received a request to reset your AdoptAPet password.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px 0;"><tr><td bgcolor="#e8893a">'
        . '<a href="https://example.invalid/reset-test" style="display:inline-block;padding:12px 20px;color:#ffffff;font-size:15px;line-height:20px;font-weight:700;text-decoration:none;">Reset Password</a>'
        . '</td></tr></table>'
        . transactionalEmailNotice('This link expires in 15 minutes and can be used only once.', 'security')
    ),
];

foreach ($sampleEmails as $name => $html) {
    transactionalPresentationCheck(str_starts_with($html, '<!doctype html>'), $name . ' has a complete HTML document');
    transactionalPresentationCheck(str_contains($html, 'width="100%"') && str_contains($html, 'max-width:600px'), $name . ' has a mobile-safe shell');
    transactionalPresentationCheck(str_contains($html, 'src="cid:adoptapet-logo"') && str_contains($html, 'alt="AdoptAPet"'), $name . ' uses the official CID logo with fallback text');
    transactionalPresentationCheck(str_contains($html, 'width="150"') && str_contains($html, 'max-width:100%') && str_contains($html, 'height:auto'), $name . ' keeps the logo responsive');
    transactionalPresentationCheck(!preg_match('#(?:https?://(?:localhost|adoptapet\.test)|file://)#i', $html), $name . ' does not expose an unreachable logo URL');
    transactionalPresentationCheck(!preg_match('/gradient|display\s*:\s*(grid|flex)|<script|facebook|instagram|youtube|View Your Account/i', $html), $name . ' avoids unsupported or invented content');
    if (class_exists(DOMDocument::class)) {
        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        transactionalPresentationCheck($loaded, $name . ' parses as HTML');
    }
    if ($writePreviews) {
        $logoBytes = file_get_contents($root . '/public/assets/images/adoptapet-logo.png');
        transactionalPresentationCheck(is_string($logoBytes), 'official logo is readable for local preview rendering');
        $previewHtml = str_replace(
            'cid:adoptapet-logo',
            'data:image/png;base64,' . base64_encode($logoBytes),
            $html
        );
        file_put_contents($emailDirectory . '/' . $name . '.html', $previewHtml);
    }
}

$escaped = transactionalEmailDetails([['Reference', '<script>alert(1)</script>']]);
transactionalPresentationCheck(!str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;'), 'dynamic email values are escaped');
transactionalPresentationCheck(str_contains(transactionalEmailCodeBlock($sampleCode), $sampleCode), 'OTP remains visible');

$mailerSource = file_get_contents($root . '/public/api/mailer.php');
transactionalPresentationCheck(is_string($mailerSource), 'mailer source is readable');
transactionalPresentationCheck(substr_count($mailerSource, 'transactionalEmailLayout(') >= 7, 'transactional messages use the shared email shell');
transactionalPresentationCheck(substr_count($mailerSource, 'createTransactionalMailer()') >= 8, 'all seven shared transactional messages use the CID mailer');
transactionalPresentationCheck(substr_count($mailerSource, 'addAttachment($pdfPath') === 2, 'receipt and certificate attachments remain configured');
transactionalPresentationCheck(str_contains($mailerSource, "formatTransactionalDate(\$donatedAt)"), 'donation email uses readable presentation-only dates');
transactionalPresentationCheck(str_contains($mailerSource, 'expires in 10 minutes and can be used only once'), 'payment OTP expiry and single-use wording remains');
transactionalPresentationCheck(str_contains($mailerSource, 'reset your password immediately'), 'email-change security warning remains');
transactionalPresentationCheck(!preg_match('/gradient|display\s*:\s*(grid|flex)|facebook|instagram|youtube|View Your Account/i', $mailerSource), 'production emails avoid unsupported or invented content');

$cidMailer = createTransactionalMailer();
$cidAttachments = $cidMailer->getAttachments();
transactionalPresentationCheck(count($cidAttachments) === 1, 'transactional mailer has one embedded brand image');
transactionalPresentationCheck(($cidAttachments[0][4] ?? null) === 'image/png'
    && ($cidAttachments[0][6] ?? null) === 'inline'
    && ($cidAttachments[0][7] ?? null) === 'adoptapet-logo', 'logo has PNG MIME type, inline disposition and expected CID');
$cidMailer->addAttachment($receiptPath, 'dr-20260922-000031.pdf');
$mixedAttachments = $cidMailer->getAttachments();
transactionalPresentationCheck(count($mixedAttachments) === 2
    && ($mixedAttachments[0][6] ?? null) === 'inline'
    && ($mixedAttachments[1][6] ?? null) === 'attachment'
    && ($mixedAttachments[1][2] ?? null) === 'dr-20260922-000031.pdf', 'inline logo and PDF attachment remain separate MIME parts');
$cidMailer->addAddress('recipient@example.test', 'Preview Recipient');
$cidMailer->Subject = 'Your AdoptAPet Donation Receipt';
$cidMailer->Body = $sampleEmails['donation-receipt'];
$cidMailer->AltBody = 'Your AdoptAPet donation receipt is attached.';
transactionalPresentationCheck($cidMailer->preSend(), 'representative CID and PDF email MIME can be built without sending');
$mime = $cidMailer->getSentMIMEMessage();
transactionalPresentationCheck(str_contains($mime, 'Content-ID: <adoptapet-logo>')
    && str_contains($mime, 'Content-Type: image/png')
    && str_contains($mime, 'Content-Disposition: inline'), 'MIME contains the inline PNG logo CID');
transactionalPresentationCheck(str_contains($mime, 'Content-Type: application/pdf')
    && str_contains($mime, 'dr-20260922-000031.pdf')
    && str_contains($mime, 'Content-Disposition: attachment'), 'MIME keeps the PDF as a named attachment');
transactionalPresentationCheck(!str_contains($mime, str_replace('\\', '/', $root))
    && !str_contains($mime, $root), 'MIME does not expose local filesystem paths');

echo "Transactional PDF and email presentation checks passed.\n";
