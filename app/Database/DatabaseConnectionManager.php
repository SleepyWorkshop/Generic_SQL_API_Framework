<?php

require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/DatabaseConnectionException.php';
require_once __DIR__ . '/../../core/Database.php';

/**
 * Request-scoped SQL Server connections for resolved database contexts.
 *
 * Connections are keyed by server profile, the connection boundary: databases
 * on one profile share its credentials and can later share one connection.
 * Until cross-database execution exists, a profile connection serves only the
 * database it was opened for. Nothing is persistent or shared across requests.
 */
final class DatabaseConnectionManager
{
    /** @var array<string, array{database: string, connection: Database}> keyed by server profile id */
    private array $connections = [];
    private $connector;

    /** @param null|callable(array): Database $connector opens a connection for a driver configuration */
    public function __construct(?callable $connector = null)
    {
        $this->connector = $connector ?? static fn (array $configuration): Database => new Database($configuration);
    }

    public function __destruct()
    {
        $this->closeAll();
    }

    /**
     * The open connection of the context's server profile, opening it with the
     * context's physical catalog when needed.
     *
     * @throws DatabaseConnectionException when SQL Server cannot be reached or rejects the login
     */
    public function connection(DatabaseContext $context): Database
    {
        $profileId = $context->serverProfileId();
        if (isset($this->connections[$profileId])) {
            if ($this->connections[$profileId]['database'] !== $context->id) {
                throw new LogicException('A server profile connection serves only the database it was opened for.');
            }
            return $this->connections[$profileId]['connection'];
        }
        $connection = ($this->connector)($context->driverConfiguration());
        $this->connections[$profileId] = ['database' => $context->id, 'connection' => $connection];
        return $connection;
    }

    /** Open and immediately close a connection, without keeping it. */
    public function test(DatabaseContext $context): void
    {
        $connection = ($this->connector)($context->driverConfiguration());
        $connection->close();
    }

    public function hasConnection(string $serverProfileId): bool
    {
        return isset($this->connections[$serverProfileId]);
    }

    public function openConnectionCount(): int
    {
        return count($this->connections);
    }

    public function close(string $serverProfileId): void
    {
        if (!isset($this->connections[$serverProfileId])) return;
        $connection = $this->connections[$serverProfileId]['connection'];
        unset($this->connections[$serverProfileId]);
        $connection->close();
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->connections) as $serverProfileId) {
            $this->close((string)$serverProfileId);
        }
    }
}
