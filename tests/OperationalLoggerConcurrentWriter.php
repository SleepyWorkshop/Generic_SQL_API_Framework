<?php

if ($argc !== 5) exit(2);
define('API_REQUEST_ID', $argv[2]);
require_once __DIR__ . '/../core/OperationalLogger.php';

$logger = new OperationalLogger($argv[1]);
for ($index = 0; $index < (int)$argv[4]; $index++) {
    $logger->info($argv[3], 'concurrent.write', ['sequence' => $index]);
}
