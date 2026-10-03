<?php

require_once __DIR__ . '/../app/Runtime/SqlParserProcessManager.php';

$operation = $argv[1] ?? '';
if (!in_array($operation, ['start', 'stop', 'restart', 'status'], true)) {
    fwrite(STDERR, '[FAILED] Expected start, stop, restart, or status.' . PHP_EOL);
    exit(2);
}
try {
    $manager = new SqlParserProcessManager();
    $result = $operation === 'start' ? $manager->start()
        : ($operation === 'stop' ? $manager->stop()
        : ($operation === 'restart' ? $manager->restart() : $manager->status()));
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (in_array($operation, ['start', 'restart'], true)
        && (($result['running'] ?? false) !== true || ($result['healthy'] ?? false) !== true)) {
        exit(1);
    }
    if ($operation === 'stop' && ($result['running'] ?? true) !== false) exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
