<?php

require_once __DIR__ . "/../drivers/SqlServerDriver.php";
require_once __DIR__ . "/../../app/Database/DatabaseRegistry.php";

class DriverFactory
{
    public static function create()
    {
        // The default database of the registry (or of a not yet migrated
        // V2 database.json).
        $config = (new DatabaseRegistry())->connectionConfiguration();

        switch (strtolower($config["provider"])) {

            case "sqlserver":
                return new SqlServerDriver($config);

            default:
                throw new Exception("Unsupported database provider.");
        }
    }
}
