<?php

final class BackupSchedule
{
    public const FREQUENCIES = ['hourly', 'daily', 'weekly'];
    public const MIN_RETENTION = 1;
    public const MAX_RETENTION = 365;

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'retention' => 30,
        ];
    }

    public static function validate(array $schedule): void
    {
        if (array_keys($schedule) !== ['enabled', 'frequency', 'time', 'retention']
            || !is_bool($schedule['enabled'] ?? null)
            || !in_array($schedule['frequency'] ?? null, self::FREQUENCIES, true)
            || !is_string($schedule['time'] ?? null)
            || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $schedule['time']) !== 1
            || !is_int($schedule['retention'] ?? null)
            || $schedule['retention'] < self::MIN_RETENTION
            || $schedule['retention'] > self::MAX_RETENTION) {
            throw new InvalidArgumentException('Invalid backup schedule configuration.');
        }
    }
}
