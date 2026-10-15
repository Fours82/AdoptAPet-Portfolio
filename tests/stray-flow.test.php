<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/stray-status-service.php';
require_once dirname(__DIR__) . '/public/api/image-upload.php';

function strayCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Stray flow check failed: ' . $description);
    }
}

strayCheck(strayStatusTransitionAllowed('submitted', 'under_review')
    && strayStatusTransitionAllowed('submitted', 'resolved')
    && strayStatusTransitionAllowed('closed', 'assigned')
    && strayStatusTransitionAllowed('resolved', 'under_review'), 'existing direct actions and reopening remain possible');
strayCheck(!strayStatusTransitionAllowed('assigned', 'submitted')
    && !strayStatusTransitionAllowed('resolved', 'submitted')
    && !strayStatusTransitionAllowed('unknown', 'assigned'), 'receipt state and unknown statuses fail closed');
strayCheck(strayResolvedTimestampMode('assigned', 'resolved') === ['set_resolved' => 1, 'keep_resolved' => 0]
    && strayResolvedTimestampMode('resolved', 'resolved') === ['set_resolved' => 0, 'keep_resolved' => 1]
    && strayResolvedTimestampMode('resolved', 'assigned') === ['set_resolved' => 0, 'keep_resolved' => 0],
    'resolution timestamp sets, persists on note edit, and clears on reopening');

$root = dirname(__DIR__);
$public = file_get_contents($root . '/public/api/stray-reports.php');
$admin = file_get_contents($root . '/public/api/admin-stray-reports.php');
strayCheck(is_string($public) && is_string($admin), 'endpoint sources readable');
strayCheck(str_contains($public, 'MAX_STRAY_REPORT_IMAGES = 3')
    && str_contains($public, "storeRequestedImages('strays', MAX_STRAY_REPORT_IMAGES)")
    && str_contains($public, "'registered_user'")
    && str_contains($public, 'ev.verified_at IS NOT NULL')
    && str_contains($public, 'requireValidCsrfToken()')
    && str_contains($public, 'FOR UPDATE'), 'reporter eligibility, CSRF and three-image limit');
strayCheck(str_contains($public, "unset(\$report['admin_notes'])")
    && str_contains($public, "unset(\$report['reporter_user_id'])")
    && str_contains($public, "\$viewerId === (int) \$report['reporter_user_id']")
    && str_contains($public, 'isOperationalAdministratorRole('), 'public-safe detail and owner/Admin note access');
strayCheck(str_contains($public, 'SELECT file_path, sort_order')
    && !str_contains($public, "'original_filename' => \$image['original_filename']"),
    'public report detail does not reveal uploaded original filenames');
strayCheck(str_contains($public, 'is_numeric($latitudeRaw)')
    && str_contains($public, '$latitude < -90 || $latitude > 90')
    && str_contains($public, '$longitude < -180 || $longitude > 180'), 'server coordinate validation');
strayCheck(str_contains($public, "'stray_report_status'")
    && str_contains($public, 'createNotificationRecord(')
    && str_contains($public, '$pdo->commit();'), 'submission receipt notification inside transaction');
strayCheck(str_contains($admin, 'isOperationalAdministratorRole(')
    && str_contains($admin, 'requireValidCsrfToken()')
    && str_contains($admin, 'LIMIT 1 FOR UPDATE')
    && str_contains($admin, 'expected_status')
    && str_contains($admin, 'expected_admin_notes')
    && str_contains($admin, 'strayStatusTransitionAllowed('), 'privileged locked review and stale/transition guards');
strayCheck(str_contains($admin, 'strayResolvedTimestampMode(')
    && str_contains($admin, 'array_key_exists(\'admin_notes\', $input)')
    && str_contains($admin, 'CASE WHEN :update_notes = 1 THEN :admin_notes ELSE admin_notes END')
    && str_contains($admin, 'adminAuditWrite(')
    && str_contains($admin, 'if ($statusChanged)')
    && str_contains($admin, 'createNotificationRecord('), 'resolution, audit and status-only notification');

$_FILES = [];
strayCheck(uploadedImageFiles('images') === [] && storeRequestedImages('strays', 3) === [],
    'zero images are optional');
foreach ([1, 3, 4] as $count) {
    $_FILES['images'] = [
        'name' => array_fill(0, $count, 'sighting.jpg'),
        'type' => array_fill(0, $count, 'image/jpeg'),
        'tmp_name' => array_fill(0, $count, 'not-an-upload'),
        'error' => array_fill(0, $count, UPLOAD_ERR_OK),
        'size' => array_fill(0, $count, 12),
    ];
    strayCheck(count(uploadedImageFiles('images')) === $count, "{$count}-image batch is counted correctly");
}
try {
    storeRequestedImages('strays', 3);
    throw new RuntimeException('Four images were unexpectedly accepted.');
} catch (ImageUploadException $exception) {
    strayCheck(str_contains($exception->getMessage(), 'no more than 3'), 'four images rejected before storage');
}
$_FILES = [];

echo "Stray workflow and image-boundary checks passed.\n";
