<?php

require_once __DIR__ . '/../app/Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../app/Runtime/SqlParserProcessManager.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';

try {
    $api = (new ApiProcessManager())->status();
    $parser = (new SqlParserProcessManager())->status();
    $database = (new DatabaseAvailabilityManager())->status();
    $result = [
        'api' => [
            'running' => ($api['running'] ?? false) === true,
            'healthy' => ($api['healthy'] ?? false) === true,
            'port' => is_int($api['port'] ?? null) ? $api['port'] : null,
        ],
        'sqlParser' => [
            'running' => ($parser['running'] ?? false) === true,
            'healthy' => ($parser['healthy'] ?? false) === true,
            'port' => is_int($parser['port'] ?? null) ? $parser['port'] : null,
        ],
        'database' => [
            'available' => ($database['available'] ?? false) === true,
        ],
    ];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (!$result['api']['running'] || !$result['api']['healthy']
        || !$result['sqlParser']['running'] || !$result['sqlParser']['healthy']
        || !$result['database']['available']) {
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] Development runtime verification failed.' . PHP_EOL);
    exit(1);
}
