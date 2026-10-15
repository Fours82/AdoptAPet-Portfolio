<?php
declare(strict_types=1);

/** Build an authenticator-compatible URI from the existing pending setup key. */
function administratorMfaOtpAuthUri(string $email, string $setupKey): string
{
    $label = rawurlencode('AdoptAPet') . ':' . rawurlencode($email);
    $query = http_build_query([
        'secret' => $setupKey,
        'issuer' => 'AdoptAPet',
        'algorithm' => 'SHA1',
        'digits' => '6',
        'period' => '30',
    ], '', '&', PHP_QUERY_RFC3986);

    return 'otpauth://totp/' . $label . '?' . $query;
}

/** Render locally; the URI never leaves this server for a QR service. */
function administratorMfaQrSvgDataUri(string $otpAuthUri): string
{
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $renderer = new \BaconQrCode\Renderer\ImageRenderer(
        new \BaconQrCode\Renderer\RendererStyle\RendererStyle(320, 4),
        new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
    );
    $svg = (new \BaconQrCode\Writer($renderer))->writeString($otpAuthUri);

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
