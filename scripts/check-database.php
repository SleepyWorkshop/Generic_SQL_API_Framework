<?php

require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';

// Opens and closes one connection to a configured database (the default when
// no id is given). Prints only the outcome; never connection details.
try {
    $databaseId = $argv[1] ?? null;
    $context = (new DatabaseContextResolver())->resolve($databaseId);
    (new DatabaseConnectionManager())->test($context);

    echo "CONNECTED";

    exit(0);

} catch (DatabaseConnectionException $e) {

    echo "FAILED: " . $e->getMessage();

    exit(1);

} catch (ApiRequestException $e) {

    echo "FAILED: " . $e->getErrorCode();

    exit(1);

} catch (Throwable $e) {

    echo "FAILED: Database configuration is unavailable.";

    exit(1);
}
