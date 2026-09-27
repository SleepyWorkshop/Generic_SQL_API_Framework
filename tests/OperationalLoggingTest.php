<?php

if (!defined('API_REQUEST_ID')) define('API_REQUEST_ID', 'req_operational_test');
require_once __DIR__ . '/../core/OperationalLogger.php';
require_once __DIR__ . '/../app/Middleware/LoggingMiddleware.php';
require_once __DIR__ . '/../sqlparser/src/SqlParserRequestHandler.php';

function operationalAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function operationalRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

$root = sys_get_temp_dir() . '/generic-operational-logging-' . bin2hex(random_bytes(6));
$oldDirectory = getenv('GENERIC_OPERATIONAL_LOG_DIR');
$oldStructuredDirectory = getenv('GENERIC_LOG_DIR');
try {
    $now = new DateTimeImmutable('2026-09-27 01:42:11');
    $logger = new OperationalLogger($root, static function () use (&$now): DateTimeImmutable { return $now; });
    foreach (['api', 'admin', 'database', 'sqlparser'] as $subsystem) {
        $logger->info($subsystem, $subsystem . '.test', ['request_id_copy' => RequestId::get()]);
        operationalAssert(is_file($root . '/' . $subsystem . '/2026-09-27.txt'), "{$subsystem} operational log was not created.");
    }
    $now = new DateTimeImmutable('2026-09-28 00:00:01');
    $logger->warning('api', 'next.day');
    operationalAssert(is_file($root . '/api/2026-09-28.txt'), 'Logger did not automatically select the next-day file.');

    $secrets = [
        'password' => 'operational-password', 'api_key' => 'gsk_operational-key',
        'csrf_token' => 'operational-csrf', 'session_id' => 'operational-session',
        'authorization' => 'Bearer operational-auth', 'encryption_key' => 'operational-encryption',
    ];
    $logger->error('admin', 'redaction.test', $secrets + [
        'details' => 'password=embedded-password x-api-key=embedded-key',
    ]);
    $adminLog = (string)file_get_contents($root . '/admin/2026-09-28.txt');
    foreach ([...array_values($secrets), 'embedded-password', 'embedded-key'] as $secret) {
        operationalAssert(!str_contains($adminLog, $secret), 'Operational log exposed secret material.');
    }
    operationalAssert(str_contains($adminLog, '[REDACTED]') && str_contains($adminLog, '[' . API_REQUEST_ID . ']'), 'Redaction or request correlation is missing.');
    operationalAssert(str_contains($adminLog, 'Details:') && !str_starts_with(ltrim($adminLog), '{'), 'Operational log is not human-readable text.');

    putenv('GENERIC_LOG_DIR=' . $root . '/structured');
    (new Logger())->audit('operational.audit.separation', 'success', 'INFO', ['component' => 'test']);
    $auditPath = $root . '/structured/audit/' . date('Y-m-d') . '.jsonl';
    operationalAssert(is_file($auditPath), 'Structured audit log was not separated under logs/audit.');
    operationalAssert(str_contains((string)file_get_contents($auditPath), 'operational.audit.separation'), 'Separated audit record is missing.');
    (new Logger())->write('centralized operational diagnostic');
    (new Logger())->timing('centralized_timing', 12.5);
    (new Logger())->error('SELECT * FROM Test WHERE Id = ?', [42], 'controlled database failure', 9.5);
    operationalAssert(!is_file($root . '/structured/' . date('Y-m-d') . '.log')
        && !is_file($root . '/structured/' . date('Y-m-d')), 'Default Logger created a root-level date log.');

    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $root);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    (new LoggingMiddleware())->handle(['action' => 'admin.health']);
    (new SqlParserRequestHandler())->handle('POST', '{not-json', 9);
    $today = date('Y-m-d');
    operationalAssert(is_file($root . '/api/' . $today . '.txt'), 'API lifecycle log was not written.');
    operationalAssert(is_file($root . '/admin/' . $today . '.txt'), 'Admin lifecycle log was not written.');
    operationalAssert(is_file($root . '/sqlparser/' . $today . '.txt'), 'SQL Parser lifecycle log was not written.');
    operationalAssert(str_contains((string)file_get_contents($root . '/sqlparser/' . $today . '.txt'), 'SQL parser request rejected'), 'Controlled SQL Parser failure was not logged.');

    foreach ([['api', 'warning'], ['admin', 'exception'], ['sqlparser', 'fatal']] as [$subsystem, $mode]) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false) $command[] = '-n';
        array_push($command, __DIR__ . '/PhpErrorLoggingFixture.php', $root, $subsystem, $mode);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        operationalAssert(is_resource($process), "Unable to start controlled PHP {$mode} fixture.");
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        operationalAssert($mode === 'fatal' ? $status !== 0 : $status === 0, "Controlled PHP {$mode} fixture returned an unexpected status.");
        $contents = (string)file_get_contents($root . '/' . $subsystem . '/' . $today . '.txt');
        $needle = $mode === 'warning' ? 'PHP warning' : ($mode === 'exception' ? 'Uncaught exception' : 'PHP fatal error');
        operationalAssert(substr_count($contents, $needle) === 1, "PHP {$mode} was not logged exactly once in {$subsystem}.");
        operationalAssert(str_contains($contents, '[req_php_' . $subsystem . ']'), "PHP {$mode} lost its request ID.");
    }
    operationalAssert(!is_file($root . '/' . $today . '.log') && !is_file($root . '/' . $today)
        && !is_file($root . '/php_errors.log'), 'PHP handling created a generic root log.');

    $workers = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false) $command[] = '-n';
        array_push($command, __DIR__ . '/OperationalLoggerConcurrentWriter.php', $root, 'req_worker_' . $worker, 'database', '25');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        operationalAssert(is_resource($process), 'Unable to start operational logger worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        operationalAssert(proc_close($process) === 0 && $stderr === '', 'Concurrent operational logger failed: ' . $stdout . $stderr);
    }
    $databaseLog = (string)file_get_contents($root . '/database/' . $today . '.txt');
    operationalAssert(substr_count($databaseLog, 'concurrent.write') === 100, 'Concurrent operational log entries were lost or corrupted.');
    foreach (range(0, 3) as $worker) operationalAssert(str_contains($databaseLog, '[req_worker_' . $worker . ']'), 'Worker request ID was lost.');

    $blocked = $root . '/blocked';
    file_put_contents($blocked, 'not-a-directory');
    (new OperationalLogger($blocked . '/child'))->error('api', 'write.failure.must.not.throw');

    if (PHP_OS_FAMILY !== 'Windows') {
        operationalAssert((fileperms($root . '/api') & 0777) === 0700, 'Operational log directory is not owner-only.');
        operationalAssert((fileperms($root . '/api/' . $today . '.txt') & 0777) === 0600, 'Operational log file is not owner-only.');
    }
    echo "Operational logging tests passed.\n";
} finally {
    $oldDirectory === false ? putenv('GENERIC_OPERATIONAL_LOG_DIR') : putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $oldDirectory);
    $oldStructuredDirectory === false ? putenv('GENERIC_LOG_DIR') : putenv('GENERIC_LOG_DIR=' . $oldStructuredDirectory);
    operationalRemoveDirectory($root);
}
