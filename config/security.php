<?php
declare(strict_types=1);

function configurationBoolean(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function hcaptchaConfiguration(): array
{
    static $cachedConfiguration = null;
    if (is_array($cachedConfiguration)) {
        return $cachedConfiguration;
    }

    $configurationFile = __DIR__ . '/hcaptcha.php';
    $localConfigurationFile = __DIR__ . '/hcaptcha.local.php';
    $configuration = is_file($configurationFile) ? require $configurationFile : [];
    $localConfiguration = is_file($localConfigurationFile) ? require $localConfigurationFile : [];
    $configuration = array_replace(
        ['enabled' => false, 'site_key' => '', 'secret_key' => ''],
        is_array($configuration) ? $configuration : [],
        is_array($localConfiguration) ? $localConfiguration : []
    );

    $enabledEnvironment = getenv('HCAPTCHA_ENABLED');
    $siteKeyEnvironment = getenv('HCAPTCHA_SITE_KEY');
    $secretKeyEnvironment = getenv('HCAPTCHA_SECRET_KEY');
    $enabled = $enabledEnvironment !== false && trim($enabledEnvironment) !== ''
        ? filter_var($enabledEnvironment, FILTER_VALIDATE_BOOLEAN)
        : filter_var($configuration['enabled'], FILTER_VALIDATE_BOOLEAN);
    $siteKey = trim((string) (
        $siteKeyEnvironment !== false && trim($siteKeyEnvironment) !== ''
            ? $siteKeyEnvironment
            : $configuration['site_key']
    ));
    $secretKey = trim((string) (
        $secretKeyEnvironment !== false && trim($secretKeyEnvironment) !== ''
            ? $secretKeyEnvironment
            : $configuration['secret_key']
    ));
    $configured = $siteKey !== '' && $secretKey !== '';

    if ($enabled && !$configured) {
        $missing = [];
        if ($siteKey === '') {
            $missing[] = 'HCAPTCHA_SITE_KEY';
        }
        if ($secretKey === '') {
            $missing[] = 'HCAPTCHA_SECRET_KEY';
        }
        error_log('AdoptAPet hCaptcha is enabled but missing configuration: ' . implode(', ', $missing) . '.');
    }

    $cachedConfiguration = [
        'enabled' => $enabled,
        'configured' => $configured,
        'site_key' => $siteKey,
        'secret_key' => $secretKey,
    ];

    return $cachedConfiguration;
}

function verifyHcaptchaToken(?string $token, ?string $remoteAddress = null): array
{
    $configuration = hcaptchaConfiguration();
    if (!$configuration['enabled']) {
        return ['success' => true, 'required' => false, 'unavailable' => false];
    }

    if (!$configuration['configured']) {
        return ['success' => false, 'required' => true, 'unavailable' => true];
    }

    $token = trim((string) $token);
    if ($token === '') {
        return ['success' => false, 'required' => true, 'unavailable' => false];
    }

    $payload = [
        'secret' => $configuration['secret_key'],
        'response' => $token,
        'sitekey' => $configuration['site_key'],
    ];
    if ($remoteAddress !== null && filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        $payload['remoteip'] = $remoteAddress;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($payload),
            'timeout' => 6,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents('https://api.hcaptcha.com/siteverify', false, $context);
    if ($response === false) {
        error_log('AdoptAPet hCaptcha verification service could not be reached.');
        return ['success' => false, 'required' => true, 'unavailable' => true];
    }

    $httpStatus = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
            $httpStatus = (int) $matches[1];
        }
    }
    if ($httpStatus >= 400) {
        error_log('AdoptAPet hCaptcha verification service returned HTTP ' . $httpStatus . '.');
        return ['success' => false, 'required' => true, 'unavailable' => true];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        error_log('AdoptAPet hCaptcha verification service returned an unreadable response.');
        return ['success' => false, 'required' => true, 'unavailable' => true];
    }

    $errorCodes = isset($result['error-codes']) && is_array($result['error-codes'])
        ? array_values(array_filter($result['error-codes'], 'is_string'))
        : [];
    $configurationErrors = array_intersect(
        $errorCodes,
        ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch', 'not-using-dummy-passcode']
    );
    if ($configurationErrors !== []) {
        error_log('AdoptAPet hCaptcha verification configuration was rejected: ' . implode(', ', $configurationErrors) . '.');
        return ['success' => false, 'required' => true, 'unavailable' => true];
    }

    return [
        'success' => ($result['success'] ?? false) === true,
        'required' => true,
        'unavailable' => false,
    ];
}
