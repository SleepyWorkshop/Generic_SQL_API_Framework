<?php

require_once __DIR__ . "/../database/factory/DriverFactory.php";

class Database
{
    private $driver;

    /** Opens one request-scoped connection; without a configuration, to the default database. */
    public function __construct(?array $configuration = null)
    {
        $this->driver = DriverFactory::create($configuration);
        $this->driver->connect();
    }

    public function getConnection()
    {
        return $this->driver->getConnection();
    }

    public function close()
    {
        $this->driver->disconnect();
    }
}
