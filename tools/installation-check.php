<?php
declare(strict_types=1);

/** Read-only check for the untouched, SQL-seeded FYP demonstration database. */
function installationCheckExpectedTables(string $sql): array
{
    preg_match_all('/^\s*CREATE\s+TABLE\s+\x60([A-Za-z0-9_]+)\x60/mi', $sql, $matches);
    $tables = array_values(array_unique(array_map('strtolower', $matches[1])));
    sort($tables);
    return $tables;
}

function installationCheckCompareTables(array $expected, array $actual): array
{
    $expected = array_values(array_unique(array_map('strtolower', $expected)));
    $actual = array_values(array_unique(array_map('strtolower', $actual)));
    sort($expected);
    sort($actual);
    return [
        'missing' => array_values(array_diff($expected, $actual)),
        'unexpected' => array_values(array_diff($actual, $expected)),
        'matches' => $expected === $actual,
    ];
}

function installationCheckInvalidTableObjects(array $rows): array
{
    $invalid = [];
    foreach ($rows as $row) {
        $name = (string) ($row['TABLE_NAME'] ?? '');
        $type = strtoupper((string) ($row['TABLE_TYPE'] ?? ''));
        $engine = strtoupper((string) ($row['ENGINE'] ?? ''));
        if ($name === '' || $type !== 'BASE TABLE' || $engine !== 'INNODB') {
            $invalid[] = $name !== '' ? $name : '(unnamed object)';
        }
    }
    sort($invalid);
    return array_values(array_unique($invalid));
}

function installationCheckDemoSeedIsReady(
    array $markerRows,
    array $populated,
    array $users,
    array $profiles,
    array $verifications
): bool {
    if (count($markerRows) !== 1 || count($users) !== 1 || count($profiles) !== 1 || count($verifications) !== 1) {
        return false;
    }

    $marker = $markerRows[0];
    $user = $users[0];
    $profile = $profiles[0];
    $verification = $verifications[0];
    $userId = (int) ($user['id'] ?? 0);
    $expectedPopulated = ['email_verifications', 'user_profiles', 'users'];
    $actualPopulated = array_values(array_unique(array_map('strtolower', $populated)));
    sort($expectedPopulated);
    sort($actualPopulated);
    $passwordHash = $user['password_hash'] ?? null;

    return $userId === 1
        && $actualPopulated === $expectedPopulated
        && ($marker['state'] ?? null) === 'completed'
        && (int) ($marker['target_user_id'] ?? 0) === $userId
        && !empty($marker['prepared_at'])
        && !empty($marker['completed_at'])
        && ($user['username'] ?? null) === 'demo_superadmin'
        && ($user['name'] ?? null) === 'FYP Demonstration Administrator'
        && ($user['email'] ?? null) === 'demo.admin@example.invalid'
        && ($user['role'] ?? null) === 'super_administrator'
        && ($user['account_status'] ?? null) === 'active'
        && (int) ($user['failed_login_count'] ?? -1) === 0
        && ($user['locked_until'] ?? null) === null
        && ($user['last_login_at'] ?? null) === null
        && ($user['deleted_at'] ?? null) === null
        && is_string($passwordHash)
        && password_verify('DemoAdmin!2026', $passwordHash)
        && (int) ($profile['user_id'] ?? 0) === $userId
        && ($profile['phone'] ?? null) === null
        && ($profile['location'] ?? null) === null
        && ($profile['profile_image_path'] ?? null) === null
        && ($profile['preferences_json'] ?? null) === null
        && (int) ($verification['user_id'] ?? 0) === $userId
        && !empty($verification['verified_at']);
}

function installationCheckFailureCategory(?int $code): string
{
    if ($code === 1049) {
        return 'Database name not found. Create a new empty demo database before importing the schema.';
    }
    if ($code === 1045) {
        return 'Database server rejected the username or password. Check the dedicated local account; no password was displayed.';
    }
    if (in_array($code, [1044, 1142, 1143], true)) {
        return 'Database account lacks access or SELECT permission. Check its grants on this database.';
    }
    if (in_array($code, [2002, 2003, 2005], true)) {
        return 'Local database service, host, or port could not be reached. Check XAMPP and the configured port.';
    }
    return 'Database connection or read-only inspection failed. Check local settings and SELECT permission; diagnostic values were suppressed.';
}

function installationCheckPlaceholder(string $value): bool
{
    return preg_match('/^(your[-_ ]|replace[-_ ]|placeholder\b|example\b|change[-_ ]?me)/i', trim($value)) === 1;
}

function installationCheckSmtpStatus(array $defaults, array $local, array $environment): array
{
    $settings = array_replace($defaults, $local);
    $get = static function (string $environmentKey, string $settingKey, $fallback = '') use ($settings, $environment) {
        $value = $environment[$environmentKey] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : ($settings[$settingKey] ?? $fallback);
    };

    $host = $get('SMTP_HOST', 'host');
    $username = $get('SMTP_USERNAME', 'username');
    $password = $get('SMTP_PASSWORD', 'password');
    $from = $get('SMTP_FROM_EMAIL', 'from_email', $username);
    $portValue = $get('SMTP_PORT', 'port', 587);
    $port = is_int($portValue) || (is_string($portValue) && ctype_digit($portValue)) ? (int) $portValue : 0;

    foreach ([$host, $username, $password, $from] as $value) {
        if (!is_string($value) || trim($value) === '' || installationCheckPlaceholder($value)) {
            return [
                'ok' => false,
                'message' => 'SMTP is not configured with real values. The seeded demo administrator can sign in without SMTP; configure private SMTP settings before using email features.',
            ];
        }
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || $port < 1 || $port > 65535) {
        return ['ok' => false, 'message' => 'SMTP sender email or port is invalid. No email was sent.'];
    }
    return ['ok' => true, 'message' => 'SMTP settings have the required shape. Delivery was not tested and no email was sent.'];
}

function installationCheckHcaptchaStatus(array $defaults, array $local, array $environment, bool $urlFopen): array
{
    $settings = array_replace($defaults, $local);
    $enabled = $settings['enabled'] ?? false;
    $environmentEnabled = $environment['HCAPTCHA_ENABLED'] ?? null;
    if (is_string($environmentEnabled) && trim($environmentEnabled) !== '') {
        $enabled = $environmentEnabled;
    }
    if (!filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
        return ['ok' => true, 'message' => 'hCaptcha is disabled (optional for local setup).'];
    }

    $siteKey = $settings['site_key'] ?? '';
    $environmentSiteKey = $environment['HCAPTCHA_SITE_KEY'] ?? null;
    if (is_string($environmentSiteKey) && trim($environmentSiteKey) !== '') {
        $siteKey = $environmentSiteKey;
    }
    $secretKey = $settings['secret_key'] ?? '';
    $environmentSecretKey = $environment['HCAPTCHA_SECRET_KEY'] ?? null;
    if (is_string($environmentSecretKey) && trim($environmentSecretKey) !== '') {
        $secretKey = $environmentSecretKey;
    }
    if (!is_string($siteKey) || !is_string($secretKey) || trim($siteKey) === '' || trim($secretKey) === ''
        || installationCheckPlaceholder($siteKey) || installationCheckPlaceholder($secretKey)) {
        return ['ok' => false, 'message' => 'hCaptcha is enabled but real site and secret keys are missing.'];
    }
    if (!$urlFopen) {
        return ['ok' => false, 'message' => 'hCaptcha is enabled, but PHP allow_url_fopen is disabled.'];
    }
    return ['ok' => true, 'message' => 'hCaptcha keys are present. External verification was not attempted.'];
}

function installationCheckLine(string $status, string $message): void
{
    fwrite(STDOUT, '[' . $status . '] ' . $message . PHP_EOL);
}

function installationCheckDriverCode(Throwable $exception): ?int
{
    return $exception instanceof PDOException && isset($exception->errorInfo[1]) && is_numeric($exception->errorInfo[1])
        ? (int) $exception->errorInfo[1]
        : null;
}

function installationCheckMain(array $arguments, ?string $projectRoot = null): int
{
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(404);
        }
        return 1;
    }

    $root = rtrim($projectRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
    if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
        fwrite(STDOUT, "AdoptAPet FYP demo-install checker\nUsage: php tools/installation-check.php [--offline]\nRead-only. No schema import or record changes. Database inspection requires exact interactive confirmation and a loopback database connection.\n");
        return 0;
    }
    $offline = in_array('--offline', $arguments, true);
    if (array_diff($arguments, ['--offline']) !== []) {
        installationCheckLine('FAIL', 'Unsupported argument. Use --help for the supported options.');
        return 1;
    }

    fwrite(STDOUT, "AdoptAPet FYP demo-install readiness check (read-only)\nUse before the Demo account’s first sign-in in a new local installation.\n\n");
    $ready = true;
    if (PHP_VERSION_ID < 80100) {
        installationCheckLine('FAIL', 'PHP 8.1 or later is required.');
        $ready = false;
    } else {
        installationCheckLine('PASS', 'CLI PHP meets the minimum version.');
    }

    $extensions = ['PDO' => 'PDO', 'pdo_mysql' => 'PDO MySQL', 'session' => 'sessions', 'sodium' => 'Sodium MFA', 'fileinfo' => 'Fileinfo uploads', 'openssl' => 'OpenSSL SMTP TLS'];
    $loadedExtensions = array_map('strtolower', get_loaded_extensions());
    $missingExtensions = [];
    foreach ($extensions as $extension => $label) {
        if (!in_array(strtolower($extension), $loadedExtensions, true)) {
            $missingExtensions[] = $label;
        }
    }
    if ($missingExtensions !== []) {
        installationCheckLine('FAIL', 'Enable required PHP extensions: ' . implode(', ', $missingExtensions) . '.');
        $ready = false;
    } else {
        installationCheckLine('PASS', 'Required CLI PHP extensions are loaded.');
    }

    $requiredFiles = [
        'composer.json', 'composer.lock', 'config/database-public.sql', 'config/database.example.php',
        'config/application.php', 'config/mfa.php', 'config/mfa.example.php', 'config/private-storage.php',
        'config/security.php', 'config/smtp.php', 'config/smtp.example.php', 'config/hcaptcha.php',
        'config/hcaptcha.example.php', 'docs/INSTALLATION.md', 'docs/https-local-setup.md', 'docs/IMAGE_CREDITS.md',
        'public/index.php', 'public/api/db.php', 'public/api/mailer.php', 'public/assets/css/style.css',
        'public/assets/js/app.js', 'public/assets/images/adoptapet-logo.png', 'public/uploads/.htaccess',
        'tools/.htaccess', 'tools/first-super-admin.php', 'tools/first-super-admin-service.php',
        'tools/create-mfa-key.php', 'tools/installation-check.php', 'setup-helper.bat', 'vendor/autoload.php',
    ];
    $missingFiles = [];
    foreach ($requiredFiles as $relativePath) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            $missingFiles[] = $relativePath;
        }
    }
    if ($missingFiles !== []) {
        installationCheckLine('FAIL', 'Required release files are missing: ' . implode(', ', $missingFiles) . '.');
        $ready = false;
    } else {
        installationCheckLine('PASS', 'Required application, configuration, documentation, Composer, and setup files are present.');
    }

    $badDirectories = [];
    foreach (['pets', 'strays', 'community', 'profiles', 'news'] as $category) {
        $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $category;
        if (!is_dir($path) || !is_writable($path)) {
            $badDirectories[] = 'public/uploads/' . $category;
        }
    }
    $privateStorage = $root . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($privateStorage) || !is_writable($privateStorage)) {
        $badDirectories[] = 'storage';
    }
    if ($badDirectories !== []) {
        installationCheckLine('FAIL', 'Required runtime folders are missing or not writable by this CLI account: ' . implode(', ', $badDirectories) . '. Apache permissions must be checked separately.');
        $ready = false;
    } else {
        installationCheckLine('PASS', 'Upload and private-storage folders are writable by this CLI account.');
    }

    if (PHP_VERSION_ID >= 80100) {
        try {
            $smtpBase = is_file($root . '/config/smtp.php') ? require $root . '/config/smtp.php' : [];
            $smtpLocal = is_file($root . '/config/smtp.local.php') ? require $root . '/config/smtp.local.php' : [];
            if (!is_array($smtpBase) || !is_array($smtpLocal)) {
                throw new RuntimeException();
            }
            $smtpEnvironment = [];
            foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'] as $name) {
                $value = getenv($name);
                if (is_string($value)) {
                    $smtpEnvironment[$name] = $value;
                }
            }
            $smtp = installationCheckSmtpStatus($smtpBase, $smtpLocal, $smtpEnvironment);
            if ($smtp['ok']) {
                require_once $root . '/public/api/mailer.php';
                createMailer();
            }
        } catch (Throwable) {
            $smtp = ['ok' => false, 'message' => 'SMTP local configuration could not be read or accepted. Check it without sharing its contents; no email was sent.'];
        }
        installationCheckLine($smtp['ok'] ? 'PASS' : 'INFO', $smtp['message']);

        try {
            $captchaBase = is_file($root . '/config/hcaptcha.php') ? require $root . '/config/hcaptcha.php' : [];
            $captchaLocal = is_file($root . '/config/hcaptcha.local.php') ? require $root . '/config/hcaptcha.local.php' : [];
            if (!is_array($captchaBase) || !is_array($captchaLocal)) {
                throw new RuntimeException();
            }
            $captchaEnvironment = [];
            foreach (['HCAPTCHA_ENABLED', 'HCAPTCHA_SITE_KEY', 'HCAPTCHA_SECRET_KEY'] as $name) {
                $value = getenv($name);
                if (is_string($value)) {
                    $captchaEnvironment[$name] = $value;
                }
            }
            $captcha = installationCheckHcaptchaStatus(
                $captchaBase,
                $captchaLocal,
                $captchaEnvironment,
                (bool) filter_var((string) ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
            );
        } catch (Throwable) {
            $captcha = ['ok' => false, 'message' => 'hCaptcha local configuration could not be read.'];
        }
        installationCheckLine($captcha['ok'] ? 'PASS' : 'ACTION', $captcha['message']);
        if (!$captcha['ok']) {
            $ready = false;
        }

        $mfaReady = false;
        if (extension_loaded('sodium') && is_file($root . '/config/mfa.php')) {
            try {
                require_once $root . '/config/mfa.php';
                $mfaReady = is_string(administratorMfaEncryptionKey());
            } catch (Throwable) {
                $mfaReady = false;
            }
        }
        if ($mfaReady) {
            installationCheckLine('PASS', 'A valid MFA encryption key is configured.');
        } else {
            installationCheckLine('ACTION', 'The installation-specific MFA key is missing or invalid. Use setup-helper.bat option 1; an existing key is never replaced.');
            $ready = false;
        }

        try {
            require_once $root . '/config/application.php';
            $baseUrl = adoptAPetApplicationBaseUrl();
            $parts = parse_url($baseUrl);
            if (is_array($parts) && isset($parts['host']) && strcasecmp((string) $parts['host'], 'adoptapet.test') !== 0) {
                installationCheckLine('PASS', 'A local application base URL is configured; Apache routing was not tested.');
            } else {
                installationCheckLine('INFO', 'No localhost base URL override is configured. Relative browser routes still work; set ADOPTAPET_BASE_URL before relying on generated email links.');
            }
        } catch (Throwable) {
            installationCheckLine('INFO', 'The application base URL could not be parsed. Set ADOPTAPET_BASE_URL before relying on generated email links.');
        }
    }

    $databaseLocal = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.local.php';
    if (!is_file($databaseLocal)) {
        installationCheckLine('ACTION', 'config/database.local.php is missing. Copy database.example.php and enter the new dedicated database settings. No connection was attempted.');
        $ready = false;
    } else {
        $databaseEnvironmentPresent = false;
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
            if (getenv($name) !== false) {
                $databaseEnvironmentPresent = true;
            }
        }
        if ($databaseEnvironmentPresent) {
            installationCheckLine('FAIL', 'DB_* environment settings override the private local file. Clear or review them before checking this Demo database; no connection was attempted.');
            $ready = false;
        } elseif (!extension_loaded('PDO') || !extension_loaded('pdo_mysql')) {
            installationCheckLine('FAIL', 'PDO MySQL is unavailable. No connection was attempted.');
            $ready = false;
        } elseif ($offline) {
            installationCheckLine('INFO', 'Offline mode requested; database inspection was skipped.');
            $ready = false;
        } else {
            try {
                require_once $root . '/public/api/db.php';
                $settings = getDatabaseConnectionSettings();
                $host = strtolower(trim((string) $settings['host'], '[]'));
                $database = $settings['database'];
                $username = $settings['username'];
                $password = $settings['password'];
                $port = $settings['port'] ?? 3306;
                if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                    throw new RuntimeException('Only loopback database hosts are accepted.');
                }
                if (strtolower($username) === 'root') {
                    throw new RuntimeException('Use a dedicated database user.');
                }
                if (!is_string($database) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) !== 1
                    || !is_string($username) || $username === '' || !is_string($password) || $password === ''
                    || !is_int($port) || $port < 1 || $port > 65535) {
                    throw new RuntimeException('Local database settings are incomplete or invalid.');
                }
            } catch (Throwable $exception) {
                installationCheckLine('FAIL', $exception instanceof PDOException
                    ? installationCheckFailureCategory(installationCheckDriverCode($exception))
                    : 'Local database settings are invalid or unsafe. Check the private file without sharing its contents. No connection was attempted.');
                $ready = false;
                $settings = null;
            }

            if (is_array($settings ?? null)) {
                fwrite(STDOUT, PHP_EOL . 'Read-only check target: ' . $database . ' on loopback. Type this exact database name to continue; Enter cancels: ');
                if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
                    installationCheckLine('ACTION', 'Interactive confirmation is unavailable. No connection was attempted; run from the XAMPP console.');
                    $ready = false;
                } else {
                    $answer = fgets(STDIN);
                    if (!is_string($answer) || !hash_equals($database, trim($answer))) {
                        installationCheckLine('ACTION', 'Database confirmation did not match. No connection was attempted.');
                        $ready = false;
                    } else {
                        try {
                            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings['host'], $port, $database);
                            $pdo = new PDO($dsn, $username, $password, [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_TIMEOUT => 5,
                                PDO::ATTR_EMULATE_PREPARES => false,
                                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                            ]);
                            $selectedDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
                            if (!is_string($selectedDatabase) || !hash_equals($database, $selectedDatabase)) {
                                throw new RuntimeException();
                            }
                            installationCheckLine('PASS', 'Local connection succeeded and selected the confirmed database.');

                            $schema = @file_get_contents($root . '/config/database-public.sql');
                            $expectedTables = is_string($schema) ? installationCheckExpectedTables($schema) : [];
                            if (count($expectedTables) !== 31) {
                                installationCheckLine('FAIL', 'Public SQL is missing or does not define the expected 31-table schema.');
                                $ready = false;
                            } else {
                                $rows = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
                                $actualTables = [];
                                $baseTables = [];
                                foreach ($rows as $row) {
                                    if (!isset($row['TABLE_NAME'], $row['TABLE_TYPE'])) {
                                        continue;
                                    }
                                    $actualTables[] = (string) $row['TABLE_NAME'];
                                    if (strtoupper((string) $row['TABLE_TYPE']) === 'BASE TABLE') {
                                        $baseTables[] = (string) $row['TABLE_NAME'];
                                    }
                                }
                                if ($actualTables === []) {
                                    installationCheckLine('ACTION', 'Database has no tables. Import config/database-public.sql into this new empty database, then rerun the check.');
                                    $ready = false;
                                } else {
                                    $tableDiff = installationCheckCompareTables($expectedTables, $actualTables);
                                    $baseDiff = installationCheckCompareTables($expectedTables, $baseTables);
                                    $invalidObjects = installationCheckInvalidTableObjects($rows);
                                    if (!$tableDiff['matches'] || !$baseDiff['matches'] || $invalidObjects !== []) {
                                        $details = [];
                                        if ($tableDiff['missing'] !== []) {
                                            $details[] = 'missing: ' . implode(', ', $tableDiff['missing']);
                                        }
                                        if ($tableDiff['unexpected'] !== []) {
                                            $details[] = 'unexpected: ' . implode(', ', $tableDiff['unexpected']);
                                        }
                                        if ($invalidObjects !== []) {
                                            $details[] = 'not InnoDB base tables: ' . implode(', ', $invalidObjects);
                                        }
                                        installationCheckLine('FAIL', 'Table layout does not match the fresh schema (' . implode('; ', $details) . '). Stop; do not import over this database.');
                                        $ready = false;
                                    } else {
                                        $populated = [];
                                        foreach ($expectedTables as $table) {
                                            if ($table === 'first_super_admin_bootstrap') {
                                                continue;
                                            }
                                            $query = 'SELECT 1 FROM `' . $table . '` LIMIT 1';
                                            if ($pdo->query($query)->fetchColumn() !== false) {
                                                $populated[] = $table;
                                            }
                                        }
                                        sort($populated);
                                        $marker = $pdo->query('SELECT state, target_user_id, prepared_at, completed_at FROM `first_super_admin_bootstrap`')->fetchAll(PDO::FETCH_ASSOC);
                                        $users = $pdo->query('SELECT id, username, name, email, password_hash, role, account_status, failed_login_count, locked_until, last_login_at, deleted_at FROM `users`')->fetchAll(PDO::FETCH_ASSOC);
                                        $profiles = $pdo->query('SELECT user_id, phone, location, profile_image_path, preferences_json FROM `user_profiles`')->fetchAll(PDO::FETCH_ASSOC);
                                        $verifications = $pdo->query('SELECT user_id, verified_at FROM `email_verifications`')->fetchAll(PDO::FETCH_ASSOC);
                                        if (!installationCheckDemoSeedIsReady($marker, $populated, $users, $profiles, $verifications)) {
                                            installationCheckLine('FAIL', 'Database does not match the untouched FYP Demo seed. Stop and preserve it; do not re-import over it.');
                                            $ready = false;
                                        } else {
                                            installationCheckLine('PASS', 'All 31 InnoDB tables are present; the documented Demo account is active and verified, and all other application tables are empty.');
                                        }
                                    }
                                }
                            }
                        } catch (Throwable $exception) {
                            installationCheckLine('FAIL', installationCheckFailureCategory(installationCheckDriverCode($exception)));
                            $ready = false;
                        }
                    }
                }
            }
        }
    }

    installationCheckLine('INFO', 'No SQL import or data-changing query, configuration write, email, hCaptcha call, or service start was performed. Apache routing and Apache permissions were not verified.');
    if ($ready) {
        installationCheckLine('READY', 'Preflight completed. Continue with the documented browser sign-in and first-login MFA enrollment.');
        return 0;
    }
    installationCheckLine('NOT READY', 'Complete the blocking actions above and rerun this check before signing in.');
    return 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(installationCheckMain(array_slice($argv, 1)));
}
