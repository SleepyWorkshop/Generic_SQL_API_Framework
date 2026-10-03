<?php

require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Services/AdminService.php';

$operation = $argv[1] ?? '';
if (!in_array($operation, ['connect', 'disconnect', 'restart', 'status'], true)) {
    fwrite(STDERR, '[FAILED] Expected connect, disconnect, restart, or status.' . PHP_EOL);
    exit(2);
}

try {
    if ($operation === 'status') {
        $result = (new DatabaseAvailabilityManager())->status();
    } elseif ($operation === 'disconnect') {
        $result = (new DatabaseAvailabilityManager())->setAvailable(false);
    } else {
        $result = (new AdminService())->controlDatabase($operation);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (in_array($operation, ['connect', 'restart'], true)
        && ($result['available'] ?? false) !== true) exit(1);
    if ($operation === 'disconnect' && ($result['available'] ?? true) !== false) exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] Database runtime operation failed.' . PHP_EOL);
    exit(1);
}
