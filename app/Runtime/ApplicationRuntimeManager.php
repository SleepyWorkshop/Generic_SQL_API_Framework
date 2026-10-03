<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class ApplicationRuntimeManager
{
    private const SERVICES = ['api', 'sqlParser'];

    private string $path;
    private bool $usesDefaultPath;

    public function __construct(?string $path = null)
    {
        $this->usesDefaultPath = $path === null;
        $this->path = $path
            ?? RuntimeConfiguration::path(RuntimeConfiguration::APPLICATION_RUNTIME_STATE_FILE);
    }

    public function status(string $service): array
    {
        $this->assertService($service);
        $state = $this->load();
        $runtime = $state['services'][$service];

        return $this->publicStatus($service, $runtime);
    }

    public function control(string $service, string $operation): array
    {
        $this->assertService($service);
        if (!in_array($operation, ['start', 'stop', 'restart'], true)) {
            throw new InvalidArgumentException('Unsupported application runtime operation.');
        }

        return $this->withLock(function () use ($service, $operation): array {
            $state = $this->load();
            $now = gmdate(DATE_ATOM);
            $state['generation']++;
            $state['services'][$service]['enabled'] = $operation !== 'stop';
            $state['services'][$service]['updatedAt'] = $now;
            if ($operation === 'restart') {
                $state['services'][$service]['reloadedAt'] = $now;
            }
            JsonFileStore::save($this->path, $state);

            return $this->publicStatus($service, $state['services'][$service]);
        });
    }

    public function enabled(string $service): bool
    {
        return $this->status($service)['applicationRuntime']['enabled'];
    }

    private function load(): array
    {
        if ($this->usesDefaultPath) {
            RuntimeConfiguration::ensure();
        }
        $state = JsonFileStore::load($this->path);
        $this->validate($state);
        return $state;
    }

    private function validate(array $state): void
    {
        if (array_keys($state) !== ['version', 'generation', 'services']
            || ($state['version'] ?? null) !== 1
            || !is_int($state['generation'] ?? null)
            || $state['generation'] < 0
            || !is_array($state['services'] ?? null)
            || array_keys($state['services']) !== self::SERVICES) {
            throw new RuntimeException('Invalid application runtime state.');
        }
        foreach (self::SERVICES as $service) {
            $runtime = $state['services'][$service] ?? null;
            if (!is_array($runtime)
                || array_keys($runtime) !== ['enabled', 'updatedAt', 'reloadedAt']
                || !is_bool($runtime['enabled'] ?? null)
                || !$this->validTimestamp($runtime['updatedAt'] ?? null)
                || !$this->validTimestamp($runtime['reloadedAt'] ?? null)) {
                throw new RuntimeException('Invalid application runtime state.');
            }
        }
    }

    private function publicStatus(string $service, array $runtime): array
    {
        $enabled = $runtime['enabled'];
        return [
            'running' => $enabled,
            'service' => $service === 'sqlParser' ? 'sqlparser' : $service,
            'healthy' => $enabled,
            'status' => $enabled ? 'enabled' : 'disabled',
            'lifecycleManaged' => true,
            'controlMode' => 'application',
            'infrastructure' => [
                'managedExternally' => true,
                'status' => 'externally managed',
                'healthy' => null,
            ],
            'applicationRuntime' => [
                'enabled' => $enabled,
                'status' => $enabled ? 'enabled' : 'disabled',
                'updatedAt' => $runtime['updatedAt'],
                'reloadedAt' => $runtime['reloadedAt'],
            ],
            'port' => null,
            'pid' => null,
            'startedAt' => null,
            'uptimeSeconds' => null,
            'version' => null,
        ];
    }

    private function validTimestamp($value): bool
    {
        return $value === null || (is_string($value) && strtotime($value) !== false);
    }

    private function assertService(string $service): void
    {
        if (!in_array($service, self::SERVICES, true)) {
            throw new InvalidArgumentException('Unsupported application runtime service.');
        }
    }

    private function withLock(callable $operation): array
    {
        if ($this->usesDefaultPath) {
            RuntimeConfiguration::ensure();
        }
        $lockPath = $this->path . '.operation.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Application runtime lock is unavailable.');
        }
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Application runtime lock could not be acquired.');
            }
            return $operation();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
