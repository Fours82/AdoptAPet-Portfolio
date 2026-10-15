<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit(1);
}

require_once __DIR__ . '/first-super-admin-service.php';

function firstBootstrapUsage(): string
{
    return "Usage:\n"
        . "  php tools/first-super-admin.php status --host=HOST --port=PORT --database=NEW_DB --db-user=USER --confirm-database=NEW_DB\n"
        . "  php tools/first-super-admin.php prepare --host=HOST --port=PORT --database=NEW_DB --db-user=USER --user-id=ID --confirm-database=NEW_DB\n"
        . "  php tools/first-super-admin.php finalize --host=HOST --port=PORT --database=NEW_DB --db-user=USER --user-id=ID --confirm-database=NEW_DB\n";
}

function firstBootstrapReadPassword(): string
{
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
        throw new FirstBootstrapRefusal('A hidden PowerShell password prompt is required on Windows; no password argument or echoing input is accepted.');
    }
    fwrite(STDOUT, "Enter the database password in the hidden PowerShell prompt.\n");
    $script = '$ErrorActionPreference = "Stop"; $s = Read-Host -Prompt "Database password" -AsSecureString; '
        . '$p = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
        . 'try { $v = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($p); '
        . '[Console]::Out.Write("ADOPTAPET_SECRET_BEGIN:" + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($v)) + ":END") } '
        . 'finally { if ($p -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p) } }';
    $pipes = [];
    $process = @proc_open(
        ['powershell.exe', '-NoLogo', '-NoProfile', '-Command', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new FirstBootstrapRefusal('The hidden password prompt could not be opened.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    // Never display or log PowerShell diagnostics, which may include local environment details.
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($output)
        || preg_match('/ADOPTAPET_SECRET_BEGIN:([A-Za-z0-9+\/=]+):END/', $output, $match) !== 1) {
        throw new FirstBootstrapRefusal('A non-empty database password is required.');
    }
    $password = base64_decode($match[1], true);
    unset($output, $match);
    if (!is_string($password) || $password === '') {
        throw new FirstBootstrapRefusal('A non-empty database password is required.');
    }
    return $password;
}

function firstBootstrapConnect(array $options, string $password): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $options['host'], (int) $options['port'], $options['database']
    );
    try {
        $pdo = new PDO($dsn, $options['db-user'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (Throwable $exception) {
        throw new FirstBootstrapRefusal('Database connection failed. Verify the explicit host, port, database, username, password, and PDO MySQL driver.');
    }
    $selectedDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($selectedDatabase) || !hash_equals($options['database'], $selectedDatabase)) {
        throw new FirstBootstrapRefusal('The server selected a different database than the explicitly named target.');
    }
    if (!firstBootstrapDatabaseNameAllowed($selectedDatabase)) {
        throw new FirstBootstrapRefusal('The protected development database cannot be used for bootstrap.');
    }
    return $pdo;
}

function firstBootstrapConfirmMutation(string $database): void
{
    fwrite(STDOUT, "No changes have been made. Type the exact database name '{$database}' to authorize this one-time mutation: ");
    $answer = fgets(STDIN);
    if (!is_string($answer) || !hash_equals($database, trim($answer))) {
        throw new FirstBootstrapRefusal('Database confirmation did not match; no changes were made.');
    }
}

function firstBootstrapMain(array $argv): int
{
    try {
        $command = (string) ($argv[1] ?? '');
        $options = firstBootstrapParseArguments(array_slice($argv, 2), $command);
        $password = firstBootstrapReadPassword();
        try {
            $pdo = firstBootstrapConnect($options, $password);
        } finally {
            if (is_string($password) && function_exists('sodium_memzero')) {
                sodium_memzero($password);
            }
            unset($password);
        }
        firstBootstrapVerifySchema($pdo);
        if ($command === 'status') {
            $marker = firstBootstrapReadMarker($pdo);
            $target = $marker['target_user_id'] === null ? 'none' : (string) $marker['target_user_id'];
            fwrite(STDOUT, "Database identity confirmed. Bootstrap state: {$marker['state']}; target user ID: {$target}. This command is read-only.\n");
            return 0;
        }
        firstBootstrapConfirmMutation($options['database']);
        firstBootstrapMutate($pdo, $command, (int) $options['user-id']);
        $next = $command === 'prepare'
            ? 'Prepare completed. The recorded account must now sign in normally and complete existing Administrator MFA before Finalize.'
            : 'Finalize completed. The first Super Administrator initialization is permanently marked complete.';
        fwrite(STDOUT, $next . "\n");
        return 0;
    } catch (FirstBootstrapRefusal $exception) {
        fwrite(STDERR, 'Refused: ' . $exception->getMessage() . "\n");
        return 2;
    } catch (FirstBootstrapCommitOutcomeUnknown $exception) {
        fwrite(STDERR, $exception->getMessage() . " Check status with the same explicit database settings before taking any further action.\n");
        return 3;
    } catch (FirstBootstrapRolledBack $exception) {
        fwrite(STDERR, $exception->getMessage() . " No change was committed. Check schema privileges and logs without exposing credentials.\n");
        return 4;
    } catch (Throwable $exception) {
        fwrite(STDERR, "The operation could not be completed safely. No diagnostic values were displayed. If the failure occurred during commit, use the read-only status command and do not retry.\n");
        return 5;
    }
}

if (($argv[1] ?? null) === null || in_array($argv[1], ['--help', '-h'], true)) {
    fwrite(STDOUT, firstBootstrapUsage());
    exit(0);
}
exit(firstBootstrapMain($argv));
