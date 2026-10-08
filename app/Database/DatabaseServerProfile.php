<?php

require_once __DIR__ . '/DatabaseRegistry.php';

/**
 * Runtime view of one registry server profile: a SQL Server connection and the
 * credentials every database on it shares. The profile is also the boundary
 * for sharing one connection between databases.
 *
 * The decrypted connection (with the password) stays private. Only the
 * connection layer reads it, through driverConfiguration().
 */
final class DatabaseServerProfile
{
    /** The ODBC Driver for SQL Server's own login-timeout default. */
    public const DEFAULT_LOGIN_TIMEOUT_SECONDS = 15;
    public const MAX_LOGIN_TIMEOUT_SECONDS = 65534;

    private array $connection;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $enabled,
        array $connection
    ) {
        DatabaseRegistry::validateConnection($connection);
        $this->connection = $connection;
    }

    public function server(): string { return (string)$this->connection['server']; }
    public function port(): ?string { return isset($this->connection['port']) && $this->connection['port'] !== '' ? (string)$this->connection['port'] : null; }
    public function driver(): string { return trim((string)($this->connection['driver'] ?? 'auto')); }
    public function authentication(): string { return strtolower(trim((string)($this->connection['authentication'] ?? 'sql'))); }
    public function username(): string { return (string)($this->connection['username'] ?? ''); }
    public function hasPassword(): bool { return (string)($this->connection['password'] ?? '') !== ''; }
    public function encrypt(): bool { return !empty($this->connection['options']['encrypt']); }
    public function trustServerCertificate(): bool { return !empty($this->connection['options']['trustServerCertificate']); }

    /** Configured login timeout, or the driver default when none is configured. */
    public function loginTimeoutSeconds(): int
    {
        return self::loginTimeoutFrom($this->connection);
    }

    public static function loginTimeoutFrom(array $configuration): int
    {
        $timeout = $configuration['options']['loginTimeoutSeconds'] ?? null;
        return is_int($timeout) ? $timeout : self::DEFAULT_LOGIN_TIMEOUT_SECONDS;
    }

    public static function isValidLoginTimeout($value): bool
    {
        return is_int($value) && $value >= 1 && $value <= self::MAX_LOGIN_TIMEOUT_SECONDS;
    }

    /** Driver configuration for one physical catalog on this profile. Contains the password. */
    public function driverConfiguration(string $catalog): array
    {
        return DatabaseRegistry::mergeConnection($this->connection, $catalog);
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'enabled' => $this->enabled, 'connection' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Server profiles cannot be serialized.');
    }
}
