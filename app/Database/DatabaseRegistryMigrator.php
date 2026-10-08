<?php

require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/DatabaseCredentialResolver.php';
require_once __DIR__ . '/DatabaseRegistry.php';

/**
 * Converts the V2 single-database configuration (database/config/database.json,
 * or the same entry in a backup archive) into a V3 registry document: server
 * profile "default" receives the connection and credentials, database "default"
 * receives the physical catalog.
 */
final class DatabaseRegistryMigrator
{
    /** Registry document equivalent to a stored V2 configuration (sealed, plaintext, or password-only). */
    public static function documentFromLegacyStored(array $stored, ?DatabaseCredentialEncryption $encryption = null): array
    {
        $resolved = DatabaseConfigurationResolver::resolve($stored);
        if (array_key_exists('password', $resolved)) {
            $resolved['password'] = DatabaseCredentialResolver::resolve($resolved['password']);
        }
        $split = self::splitLegacyConfiguration($resolved);
        DatabaseRegistry::validateConnection($split['connection']);
        DatabaseRegistry::validatePhysicalName($split['catalog']);

        $encryption ??= new DatabaseCredentialEncryption();
        $id = DatabaseRegistry::DEFAULT_ID;
        $document = [
            'version' => DatabaseRegistry::VERSION,
            'defaultDatabase' => $id,
            'servers' => [$id => [
                'name' => 'Default',
                'enabled' => true,
                'connection' => $encryption->encryptBound($split['connection'], DatabaseRegistry::serverBinding($id)),
            ]],
            'databases' => [$id => [
                'name' => 'Default',
                'server' => $id,
                'enabled' => true,
                'catalog' => $encryption->encryptBound(['database' => $split['catalog']], DatabaseRegistry::databaseBinding($id)),
            ]],
        ];
        DatabaseRegistry::validateDocument($document);
        return $document;
    }

    /**
     * Split a resolved V2 configuration into the profile connection and the
     * physical catalog. Keys the SQL Server driver never reads are dropped.
     */
    public static function splitLegacyConfiguration(array $configuration): array
    {
        $catalog = $configuration['database'] ?? null;
        if (!is_string($catalog)) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
        $connection = [];
        foreach ($configuration as $key => $value) {
            if (in_array($key, DatabaseRegistry::CONNECTION_KEYS, true)) $connection[$key] = $value;
        }
        return ['connection' => $connection, 'catalog' => $catalog];
    }
}
