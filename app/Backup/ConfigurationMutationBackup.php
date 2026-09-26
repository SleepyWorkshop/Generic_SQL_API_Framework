<?php

require_once __DIR__ . '/BackupRecoveryService.php';

final class ConfigurationMutationBackup
{
    private static bool $active = false;
    private static $factory = null;

    public static function before(string $category): void
    {
        if (self::$active) return;
        self::$active = true;
        try {
            $service = self::$factory !== null ? (self::$factory)() : new BackupRecoveryService();
            if (!$service instanceof BackupRecoveryService) throw new RuntimeException('Pre-change backup service is invalid.');
            $service->createPreChange($category);
        } finally { self::$active = false; }
    }

    public static function setFactoryForTests(?callable $factory): void
    {
        self::$factory = $factory;
    }
}
