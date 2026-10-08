<?php

require_once __DIR__ . '/DatabaseQueryPlan.php';

/** The database query plan of the current request, set after planning. */
final class DatabaseQueryPlanContext
{
    private static ?DatabaseQueryPlan $plan = null;

    public static function set(DatabaseQueryPlan $plan): void { self::$plan = $plan; }
    public static function current(): ?DatabaseQueryPlan { return self::$plan; }
    public static function clear(): void { self::$plan = null; }
}
