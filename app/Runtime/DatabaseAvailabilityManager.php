<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Database/DatabaseRegistry.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

/**
 * Per-database request-availability gate (config/database-state.json).
 *
 * Version 2 stores {available, updatedAt} per database id. A version 1 file
 * ({available, updatedAt}) described the single V2 database and is read as the
 * state of the default database; the next change rewrites it as version 2.
 * Methods called without a database id act on the registry's default database.
 */
final class DatabaseAvailabilityManager
{
    public const VERSION = 2;

    private string $path;
    private ?DatabaseRegistry $registry;

    public function __construct(?string $path = null, ?DatabaseRegistry $registry = null)
    {
        $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE);
        $this->registry = $registry;
    }

    public static function defaultState(): array
    {
        return ['version' => self::VERSION, 'databases' => []];
    }

    public static function isValidState(array $value): bool
    {
        if (($value['version'] ?? null) === 1) {
            return array_diff(array_keys($value), ['version', 'available', 'updatedAt']) === []
                && is_bool($value['available'] ?? null) && self::isTimestamp($value['updatedAt'] ?? null);
        }
        if (array_keys($value) !== ['version', 'databases'] || $value['version'] !== self::VERSION
            || !is_array($value['databases']) || ($value['databases'] !== [] && array_is_list($value['databases']))) {
            return false;
        }
        foreach ($value['databases'] as $id => $state) {
            if (!DatabaseRegistry::isValidId((string)$id) || !is_array($state)
                || array_keys($state) !== ['available', 'updatedAt']
                || !is_bool($state['available']) || !self::isTimestamp($state['updatedAt'])) {
                return false;
            }
        }
        return true;
    }

    public function status(?string $databaseId = null): array
    {
        $databaseId = $this->databaseId($databaseId);
        $state = $this->states()[$databaseId] ?? ['available' => false, 'updatedAt' => null];
        return ['version' => self::VERSION, 'database' => $databaseId, ...$state];
    }

    public function available(?string $databaseId = null): bool
    {
        return $this->status($databaseId)['available'];
    }

    public function setAvailable(bool $available, ?string $databaseId = null): array
    {
        $databaseId = $this->databaseId($databaseId);
        $states = $this->states();
        $states[$databaseId] = ['available' => $available, 'updatedAt' => gmdate(DATE_ATOM)];
        $this->save($states);
        return ['version' => self::VERSION, 'database' => $databaseId, ...$states[$databaseId]];
    }

    /** Remove the state of a database that no longer exists. */
    public function forget(string $databaseId): void
    {
        $states = $this->states();
        if (!array_key_exists($databaseId, $states)) return;
        unset($states[$databaseId]);
        $this->save($states);
    }

    private function states(): array
    {
        RuntimeConfiguration::ensure();
        $value = JsonFileStore::load($this->path);
        if (!self::isValidState($value)) throw new RuntimeException('Invalid database availability state.');
        if ($value['version'] === 1) {
            return [$this->defaultDatabaseId() => ['available' => $value['available'], 'updatedAt' => $value['updatedAt'] ?? null]];
        }
        return $value['databases'];
    }

    private function save(array $states): void
    {
        JsonFileStore::save($this->path, ['version' => self::VERSION, 'databases' => $states === [] ? new stdClass() : $states]);
    }

    private function databaseId(?string $databaseId): string
    {
        if ($databaseId === null) return $this->defaultDatabaseId();
        if (!DatabaseRegistry::isValidId($databaseId)) throw new InvalidArgumentException('Invalid database id.');
        return $databaseId;
    }

    private function defaultDatabaseId(): string
    {
        return ($this->registry ?? new DatabaseRegistry())->defaultDatabaseId() ?? DatabaseRegistry::DEFAULT_ID;
    }

    private static function isTimestamp($value): bool
    {
        return $value === null || (is_string($value) && strtotime($value) !== false);
    }
}
