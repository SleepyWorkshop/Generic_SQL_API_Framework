<?php

require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';

$root = dirname(__DIR__);
$keyPath = $root . '/runtime/secrets/database-encryption.key';
$databaseRegistry = new DatabaseRegistry(
    $root . '/database/config/databases.json',
    $root . '/database/config/database.json'
);
$environmentKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);

function validatedKey(string $encodedKey): string
{
    $encodedKey = trim($encodedKey);
    new DatabaseCredentialEncryption($encodedKey);
    return $encodedKey;
}

try {
    if ($environmentKey !== false && trim($environmentKey) !== '') {
        echo validatedKey($environmentKey);
        exit(0);
    }
    $directory = dirname($keyPath);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the local secrets directory.');
    }
    @chmod($directory, 0700);

    $lock = @fopen($keyPath . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to lock the local encryption key.');
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Unable to lock the local encryption key.');
    }
    @chmod($keyPath . '.lock', 0600);
    try {
        if (is_file($keyPath)) {
            $storedKey = @file_get_contents($keyPath);
            if ($storedKey === false) throw new RuntimeException('Unable to read the local encryption key.');
            echo validatedKey($storedKey);
            exit(0);
        }
        // A new key cannot open the V3 registry or an encrypted V2 database.json.
        if ($databaseRegistry->requiresEncryptionKey()) {
            throw new RuntimeException('The database configuration is encrypted, but its encryption key is unavailable.');
        }

        $encodedKey = base64_encode(random_bytes(32));
        $stream = @fopen($keyPath, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the local encryption key.');
        }
        $complete = false;
        try {
            // Restrict access before writing the key. On Windows the deployer
            // must additionally apply an NTFS ACL for the worker identity.
            $permissionsRestricted = @chmod($keyPath, 0600);
            if (PHP_OS_FAMILY !== 'Windows') {
                clearstatcache(true, $keyPath);
                if (!$permissionsRestricted || (@fileperms($keyPath) & 0777) !== 0600) {
                    throw new RuntimeException('Unable to restrict the local encryption key permissions.');
                }
            }
            $contents = $encodedKey . PHP_EOL;
            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $bytes = fwrite($stream, substr($contents, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Unable to store the local encryption key.');
                }
                $written += $bytes;
            }
            if (!fflush($stream)) {
                throw new RuntimeException('Unable to flush the local encryption key.');
            }
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) @unlink($keyPath);
        }
        echo validatedKey($encodedKey);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
