<?php

require_once __DIR__ . "/DatabaseDriverInterface.php";
require_once __DIR__ . "/../../app/Security/DatabaseCredentialResolver.php";
require_once __DIR__ . "/../../app/Database/DatabaseRegistry.php";
require_once __DIR__ . "/../../app/Runtime/DatabaseAuthenticationSupport.php";
require_once __DIR__ . "/../../app/Security/DatabaseTransportSecurity.php";
require_once __DIR__ . "/../../app/Security/SecurityConfiguration.php";

class SqlServerDriver implements DatabaseDriverInterface
{
    private $connection = null;
    private ?array $configuration;
    private ?bool $production;

    public function __construct(?array $configuration = null, ?bool $production = null)
    {
        $this->configuration = $configuration;
        $this->production = $production;
    }

    /**
     * Drivers tried by automatic selection. Production uses only modern
     * drivers with current TLS support; development keeps the full list.
     */
    public static function autoDetectionDrivers(bool $production): array
    {
        return $production ? DatabaseTransportSecurity::PRODUCTION_AUTO_DRIVERS : self::supportedDrivers();
    }

    /**
     * SQL Server ODBC drivers.
     *
     * Drivers are tested from newest to oldest.
     */
    public static function supportedDrivers(): array
    {
        return [
            // Modern Microsoft ODBC Drivers
            "ODBC Driver 19 for SQL Server",
            "ODBC Driver 18 for SQL Server",
            "ODBC Driver 17 for SQL Server",
            "ODBC Driver 13.1 for SQL Server",
            "ODBC Driver 13 for SQL Server",
            "ODBC Driver 11 for SQL Server",
            "ODBC Driver 10 for SQL Server",

            // SQL Server Native Client
            "SQL Server Native Client 11.0",
            "SQL Server Native Client 10.0",
            "SQL Server Native Client 9.0",

            // Legacy SQL Native Client
            "SQL Native Client",

            // Legacy SQL Server ODBC driver
            "SQL Server"
        ];
    }

    public static function cursorMode(?string $operatingSystem = null): int
    {
        $operatingSystem ??= PHP_OS_FAMILY;
        if ($operatingSystem === 'Windows') {
            return defined('SQL_CUR_USE_ODBC') ? constant('SQL_CUR_USE_ODBC') : 1;
        }
        return defined('SQL_CUR_USE_DRIVER') ? constant('SQL_CUR_USE_DRIVER') : 2;
    }

    /**
     * Build SQL Server address.
     */
    private function buildServerAddress(
        string $server,
        $port
    ): string {

        $serverAddress = $server;

        if (
            !empty($port)
            && strpos($server, ",") === false
        ) {
            $serverAddress .= "," . $port;
        }

        return $serverAddress;
    }

    /**
     * Build ODBC connection string.
     */
    private function buildDsn(
        string $driver,
        string $serverAddress,
        string $database,
        string $authentication,
        string $encrypt,
        string $trust
    ): string {

        $dsn =
            "Driver={" . $driver . "};"
            . "Server={$serverAddress};"
            . "Database={$database};"
            . "Encrypt={$encrypt};"
            . "TrustServerCertificate={$trust};";

        /*
         * Windows Authentication
         */
        if ($authentication === "windows") {

            $dsn .=
                "Trusted_Connection=yes;";
        }

        return $dsn;
    }

    /**
     * Connect using authentication mode.
     */
    protected function openConnection(
        string $dsn,
        string $authentication,
        string $username,
        string $password
    ) {

        /*
         * Windows Authentication
         */
        if ($authentication === "windows") {

            return @odbc_connect(
                $dsn,
                "",
                "",
                self::cursorMode()
            );
        }

        /*
         * SQL Server Authentication
         */
        return @odbc_connect(
            $dsn,
            $username,
            $password,
            self::cursorMode()
        );
    }

    /**
     * Automatically detect a working SQL Server driver.
     *
     * IMPORTANT:
     * The successful connection is kept open.
     */
    private function detectDriver(
        string $server,
        $port,
        string $database,
        string $username,
        string $password,
        string $authentication,
        string $encrypt,
        string $trust
    ): array {

        $drivers = self::autoDetectionDrivers($this->production ?? SecurityConfiguration::isProduction());

        $serverAddress =
            $this->buildServerAddress(
                $server,
                $port
            );

        foreach ($drivers as $driver) {

            $dsn =
                $this->buildDsn(
                    $driver,
                    $serverAddress,
                    $database,
                    $authentication,
                    $encrypt,
                    $trust
                );

            $connection =
                $this->openConnection(
                    $dsn,
                    $authentication,
                    $username,
                    $password
                );

            /*
             * Driver + database connection succeeded.
             *
             * DO NOT CLOSE THIS CONNECTION.
             */
            if ($connection) {

                return [
                    "driver" => $driver,
                    "connection" => $connection
                ];
            }

            /*
             * A TLS or certificate failure is never retried with another
             * (possibly weaker) driver.
             */
            if (DatabaseTransportSecurity::isTlsFailure($this->connectionError())) {
                throw new Exception('ODBC TLS or certificate validation failed; automatic driver fallback was stopped.');
            }

        }

        throw new Exception('No compatible SQL Server ODBC driver could establish a connection.');
    }

    /**
     * Connect to SQL Server.
     */
    public function connect()
    {
        $config = $this->configuration
            ?? (new DatabaseRegistry())->connectionConfiguration();

        /*
         * Database configuration.
         */
        $server =
            $config["server"] ?? null;

        $port =
            $config["port"] ?? null;

        $database =
            $config["database"] ?? null;

        $username =
            $config["username"] ?? "";

        $password =
            DatabaseCredentialResolver::resolve(
                $config["password"] ?? ""
            );

        $authentication = strtolower(trim((string)($config["authentication"] ?? "sql")));

        /*
         * Validate authentication mode.
         */
        (new DatabaseAuthenticationSupport())->validate($authentication);

        if (empty($server)) {

            throw new Exception(
                "Database server is not configured."
            );
        }

        if (empty($database)) {

            throw new Exception(
                "Database name is not configured."
            );
        }

        /*
         * Connection options.
         */
        $encrypt =
            !empty(
                $config["options"]["encrypt"]
            )
                ? "yes"
                : "no";

        $trust =
            !empty(
                $config["options"]
                    ["trustServerCertificate"]
            )
                ? "yes"
                : "no";

        /*
         * Driver configuration.
         */
        $configuredDriver = trim((string)($config["driver"] ?? "auto"));

        /*
         * AUTO DRIVER
         */
        if (
            strtolower(
                $configuredDriver
            ) === "auto"
        ) {

            $detected =
                $this->detectDriver(
                    $server,
                    $port,
                    $database,
                    $username,
                    $password,
                    $authentication,
                    $encrypt,
                    $trust
                );

            /*
             * Keep the successful connection.
             */
            $this->connection =
                $detected["connection"];

            return $this->connection;
        }

        /*
         * MANUAL DRIVER
         */
        $driver =
            $configuredDriver;

        $serverAddress =
            $this->buildServerAddress(
                $server,
                $port
            );

        $dsn =
            $this->buildDsn(
                $driver,
                $serverAddress,
                $database,
                $authentication,
                $encrypt,
                $trust
            );

        $this->connection =
            $this->openConnection(
                $dsn,
                $authentication,
                $username,
                $password
            );

        if (!$this->connection) {

            throw new Exception('SQL Server connection failed.');
        }

        return $this->connection;
    }

    /** Last ODBC connection error, used only to classify failures. */
    protected function connectionError(): string
    {
        return trim((string)@odbc_error() . ' ' . (string)@odbc_errormsg());
    }

    /**
     * Disconnect from SQL Server.
     */
    public function disconnect()
    {
        if ($this->connection) {

            @odbc_close(
                $this->connection
            );

            $this->connection = null;
        }
    }

    /**
     * Execute SQL query.
     */
    public function query($sql)
    {
        if (!$this->connection) {

            throw new Exception(
                "Database connection is not available."
            );
        }

        return odbc_exec(
            $this->connection,
            $sql
        );
    }

    /**
     * Execute SQL.
     */
    public function execute($sql)
    {
        return $this->query($sql);
    }

    /**
     * Fetch a row.
     */
    public function fetch($result)
    {
        return odbc_fetch_array(
            $result
        );
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction()
    {
        odbc_autocommit(
            $this->connection,
            false
        );
    }

    /**
     * Commit transaction.
     */
    public function commit()
    {
        odbc_commit(
            $this->connection
        );

        odbc_autocommit(
            $this->connection,
            true
        );
    }

    /**
     * Rollback transaction.
     */
    public function rollback()
    {
        odbc_rollback(
            $this->connection
        );

        odbc_autocommit(
            $this->connection,
            true
        );
    }

    /**
     * Get active database connection.
     */
    public function getConnection()
    {
        return $this->connection;
    }
}
