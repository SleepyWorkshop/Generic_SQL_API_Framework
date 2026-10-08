<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';

// Replaces a V2 database/config/database.json with the V3 database registry.
// Requires GENERIC_SQL_API_ENCRYPTION_KEY. Running it again is harmless.
try {
    $result = (new DatabaseRegistry())->migrateLegacy();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if ($result['migrated'] && !$result['legacyRetired']) {
        fwrite(STDERR, '[WARNING] The registry was written, but the legacy database configuration could not be removed.' . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] Database registry migration failed.' . PHP_EOL);
    exit(1);
}
