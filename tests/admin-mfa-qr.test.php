<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/admin-mfa-service.php';
require_once dirname(__DIR__) . '/public/api/admin-mfa-qr.php';

function checkQr(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('MFA QR test failed: ' . $label);
    }
}

// Public RFC 6238 sample material, never a live enrollment credential.
$secret = '12345678901234567890';
$setupKey = administratorMfaBase32($secret);
$uri = administratorMfaOtpAuthUri('administrator@example.test', $setupKey);
$parts = parse_url($uri);
checkQr(is_array($parts) && ($parts['scheme'] ?? '') === 'otpauth', 'URI scheme');
checkQr(($parts['host'] ?? '') === 'totp', 'TOTP URI type');
checkQr(($parts['path'] ?? '') === '/AdoptAPet:administrator%40example.test', 'account label');
parse_str($parts['query'] ?? '', $query);
checkQr(($query['secret'] ?? '') === $setupKey, 'same manual and QR setup key');
checkQr(($query['issuer'] ?? '') === 'AdoptAPet', 'issuer');
checkQr(($query['algorithm'] ?? '') === 'SHA1', 'SHA-1');
checkQr(($query['digits'] ?? '') === '6', 'six digits');
checkQr(($query['period'] ?? '') === '30', '30-second period');
checkQr(administratorMfaVerifyTotp($secret, '287082', 59) === 1, 'URI key works with existing TOTP verification');

$imageUri = administratorMfaQrSvgDataUri($uri);
$prefix = 'data:image/svg+xml;base64,';
checkQr(str_starts_with($imageUri, $prefix), 'local SVG data URI');
$svg = base64_decode(substr($imageUri, strlen($prefix)), true);
checkQr(is_string($svg) && str_contains($svg, '<svg') && str_contains($svg, '</svg>'), 'rendered SVG');
checkQr(!str_contains($svg, 'http://example.test') && !str_contains($svg, '<script'), 'no remote resource or script');

echo "MFA QR URI, local SVG, and TOTP compatibility tests passed.\n";
