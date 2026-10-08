<?php

require_once __DIR__ . '/DatabaseRegistry.php';
require_once __DIR__ . '/DatabaseRegistryReader.php';
require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/DatabaseServerProfile.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Security/DatabaseCredentialException.php';

/**
 * Database id → DatabaseContext → DatabaseServerProfile, from the registry
 * only. A database is configured (in the registry), enabled (registry flag,
 * together with its server profile), and available (runtime gate opened by a
 * verified connection); each is checked separately.
 */
final class DatabaseContextResolver
{
    private DatabaseRegistryReader $registry;
    private ?DatabaseAvailabilityManager $availability;

    public function __construct(?DatabaseRegistryReader $registry = null, ?DatabaseAvailabilityManager $availability = null)
    {
        $this->registry = $registry ?? new DatabaseRegistry();
        $this->availability = $availability;
    }

    /** The registry default database id, or null when no database is configured. */
    public function defaultDatabaseId(): ?string
    {
        return $this->metadata()['defaultDatabase'];
    }

    /**
     * Resolve a configured, enabled database (the default when null) without
     * evaluating availability.
     */
    public function resolve(?string $databaseId = null): DatabaseContext
    {
        $metadata = $this->metadata();
        $databaseId = $this->selectId($databaseId, $metadata);
        if ($databaseId === null) {
            throw new DatabaseCredentialException('Database configuration has not been saved.');
        }
        $database = $this->assertUsable($databaseId, $metadata);
        return $this->context($databaseId, $database, $metadata['servers'][$database['server']]);
    }

    /**
     * Resolve a database that may serve requests now: configured, enabled, and
     * with its availability gate open. With nothing configured the gate has
     * never been opened, so the answer is DATABASE_UNAVAILABLE, as in V2.
     */
    public function resolveAvailable(?string $databaseId = null): DatabaseContext
    {
        $metadata = $this->metadata();
        $databaseId = $this->assertServable($databaseId, $metadata);
        $database = $metadata['databases'][$databaseId];
        return $this->context($databaseId, $database, $metadata['servers'][$database['server']]);
    }

    /**
     * The checks of resolveAvailable() without decrypting anything: returns the
     * database id that would serve the request.
     */
    public function assertRequestable(?string $databaseId = null): string
    {
        return $this->assertServable($databaseId, $this->metadata());
    }

    private function assertServable(?string $databaseId, array $metadata): string
    {
        $databaseId = $this->selectId($databaseId, $metadata);
        if ($databaseId === null) throw self::unavailable();
        $this->assertUsable($databaseId, $metadata);
        if (!$this->availability()->available($databaseId)) throw self::unavailable();
        return $databaseId;
    }

    /** Open availability gate check for an already resolved database. */
    public function assertAvailable(DatabaseContext $context): void
    {
        if (!$this->availability()->available($context->id)) throw self::unavailable();
    }

    public static function unavailable(): ApiRequestException
    {
        return new ApiRequestException('Database access is currently unavailable.', 'DATABASE_UNAVAILABLE', [], 503);
    }

    private function metadata(): array
    {
        try {
            return $this->registry->metadata();
        } catch (DatabaseCredentialException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
    }

    private function selectId(?string $databaseId, array $metadata): ?string
    {
        if ($databaseId === null) return $metadata['defaultDatabase'];
        if (!DatabaseRegistry::isValidId($databaseId)) {
            throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', [
                ['path' => 'database', 'message' => 'Database must be a configured database id.'],
            ]);
        }
        return $databaseId;
    }

    private function assertUsable(string $databaseId, array $metadata): array
    {
        $database = $metadata['databases'][$databaseId] ?? null;
        if ($database === null) {
            throw new ApiRequestException('Database not found.', 'DATABASE_NOT_FOUND', [
                ['path' => 'database', 'message' => 'The database is not configured.'],
            ], 404);
        }
        if (!$database['enabled']) {
            throw new ApiRequestException('Database is disabled.', 'DATABASE_DISABLED', [
                ['path' => 'database', 'message' => 'The database is disabled.'],
            ], 403);
        }
        $server = $metadata['servers'][$database['server']] ?? null;
        if ($server === null) {
            // The registry rejects dangling references; this guards other readers.
            throw new ApiRequestException('Database server profile not found.', 'SERVER_PROFILE_NOT_FOUND', [], 503);
        }
        if (!$server['enabled']) {
            throw new ApiRequestException('Database server profile is disabled.', 'SERVER_PROFILE_DISABLED', [
                ['path' => 'database', 'message' => 'The database server profile is disabled.'],
            ], 403);
        }
        return $database;
    }

    private function context(string $databaseId, array $database, array $server): DatabaseContext
    {
        try {
            $profile = new DatabaseServerProfile($database['server'], $server['name'], $server['enabled'],
                $this->registry->serverConnection($database['server']));
            return new DatabaseContext($databaseId, $database['name'], $database['enabled'],
                $this->registry->databaseCatalog($databaseId), $profile);
        } catch (DatabaseCredentialException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Invalid database configuration.');
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
