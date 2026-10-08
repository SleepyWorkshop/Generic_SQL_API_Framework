<?php

require_once __DIR__ . "/../drivers/SqlServerDriver.php";
require_once __DIR__ . "/../../app/Database/DatabaseContextResolver.php";

class DriverFactory
{
    /**
     * A driver for one resolved database configuration (see
     * DatabaseContext::driverConfiguration()); without one, the registry's
     * default database.
     */
    public static function create(?array $configuration = null)
    {
        $config = $configuration
            ?? (new DatabaseContextResolver())->resolve()->driverConfiguration();

        switch (strtolower($config["provider"])) {

            case "sqlserver":
                return new SqlServerDriver($config);

            default:
                throw new Exception("Unsupported database provider.");
        }
    }
}
