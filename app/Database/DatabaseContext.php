<?php

require_once __DIR__ . '/DatabaseServerProfile.php';

/**
 * Runtime view of one configured database: the id clients use, its display
 * name and flag, its server profile, and the registry-controlled physical
 * catalog. The physical name is never taken from a request.
 */
final class DatabaseContext
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $enabled,
        private string $physicalName,
        public readonly DatabaseServerProfile $serverProfile
    ) {
        DatabaseRegistry::validatePhysicalName($physicalName);
    }

    public function physicalName(): string { return $this->physicalName; }
    public function serverProfileId(): string { return $this->serverProfile->id; }

    /** Databases on the same server profile can share one connection; hostnames are never compared. */
    public function sharesServerProfileWith(self $other): bool
    {
        return $this->serverProfile->id === $other->serverProfile->id;
    }

    /** Driver configuration for this database. Contains the password. */
    public function driverConfiguration(): array
    {
        return $this->serverProfile->driverConfiguration($this->physicalName);
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'enabled' => $this->enabled,
            'serverProfile' => $this->serverProfile->id, 'physicalName' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Database contexts cannot be serialized.');
    }
}
