<?php

require_once __DIR__ . '/DatabaseRegistry.php';
require_once __DIR__ . '/DatabaseRegistryReader.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Security/DatabaseCredentialException.php';

/**
 * The logical databases clients can name, for `metadata.databases`.
 *
 * Built from registry metadata and the runtime availability state only: it
 * never decrypts a connection or catalog and never connects. Each entry has
 * only logical fields; physical names, hosts, ports, logins, TLS options,
 * timeouts, and encryption envelopes stay server-side.
 */
final class DatabaseDirectory
{
    /** The action answered from the registry, without planning or a connection. */
    public const ACTION = 'metadata.databases';

    private DatabaseRegistryReader $registry;
    private ?DatabaseAvailabilityManager $availability;

    public function __construct(?DatabaseRegistryReader $registry = null, ?DatabaseAvailabilityManager $availability = null)
    {
        $this->registry = $registry ?? new DatabaseRegistry();
        $this->availability = $availability;
    }

    public static function isRegistryOnly(?string $action): bool
    {
        return $action === self::ACTION;
    }

    /**
     * @return list<array{id: string, name: string, default: bool, enabled: bool, available: bool, crossDatabaseGroup: string}>
     *   `enabled`: the database and its server profile are enabled in the
     *   registry. `available`: its runtime availability gate is open (no
     *   connectivity check). `crossDatabaseGroup`: its server profile id;
     *   databases of one group can be queried together.
     */
    public function databases(): array
    {
        try {
            $metadata = $this->registry->metadata();
        } catch (DatabaseCredentialException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
        $databases = [];
        foreach ($metadata['databases'] as $id => $database) {
            $id = (string)$id;
            $databases[] = [
                'id' => $id,
                'name' => $database['name'],
                'default' => $id === $metadata['defaultDatabase'],
                'enabled' => $database['enabled'] && ($metadata['servers'][$database['server']]['enabled'] ?? false) === true,
                'available' => $this->available($id),
                'crossDatabaseGroup' => $database['server'],
            ];
        }
        usort($databases, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $databases;
    }

    private function available(string $id): bool
    {
        try {
            return $this->availability()->available($id);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function availability(): DatabaseAvailabilityManager
    {
        return $this->availability ??= new DatabaseAvailabilityManager(
            null,
            $this->registry instanceof DatabaseRegistry ? $this->registry : null
        );
    }
}
