<?php

require_once __DIR__ . '/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/DatabaseCredentialResolver.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

class DatabaseConfigurationResolver
{
    public static function load(string $configPath): array
    {
        return self::resolve(self::readStored($configPath));
    }

    public static function readStored(string $configPath): array
    {
        try {
            return JsonFileStore::load($configPath);
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
    }

    public static function resolve(array $configuration): array
    {
        if (self::usesEncryption($configuration)) {
            $configuration = (new DatabaseCredentialEncryption())->decryptConfiguration($configuration);
        }

        self::validate($configuration);
        return $configuration;
    }

    public static function usesEncryption(array $configuration): bool
    {
        return ($configuration['encrypted'] ?? null) === true;
    }

    public static function encryptionKeyIsAvailable(): bool
    {
        $key = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);

        return $key !== false && trim($key) !== '';
    }

    /** Type checks shared by legacy files and decrypted registry entries. */
    public static function validateResolved(array $configuration): void
    {
        self::validate($configuration);
    }

    private static function validate(array $configuration): void
    {
        if (!is_string($configuration['provider'] ?? null)
            || trim($configuration['provider']) === '') {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }

        foreach (['server', 'database', 'authentication', 'username', 'driver'] as $field) {
            if (array_key_exists($field, $configuration) && !is_string($configuration[$field])) {
                throw new DatabaseCredentialException('Invalid database configuration.');
            }
        }
        if (array_key_exists('port', $configuration)
            && !is_string($configuration['port'])
            && !is_int($configuration['port'])
            && $configuration['port'] !== null) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
        if (array_key_exists('password', $configuration)
            && !is_string($configuration['password'])
            && !DatabaseCredentialResolver::usesEncryption($configuration['password'])) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
        if (array_key_exists('options', $configuration) && !is_array($configuration['options'])) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
    }
}
