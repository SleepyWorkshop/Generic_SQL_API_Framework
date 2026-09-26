<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';

final class InstallationRepository
{
    private string $path;
    private bool $runtimePath;

    public function __construct(?string $path = null)
    {
        $this->runtimePath = $path === null;
        $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::INSTALLATION_FILE);
    }

    public function load(): array
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
        $configuration = JsonFileStore::load($this->path);
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
        $this->validate($configuration);
        JsonFileStore::save($this->path, $configuration);
    }

    public function validate(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'installationId', 'initialized']) !== []
            || ($configuration['version'] ?? null) !== 1
            || !is_string($configuration['installationId'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $configuration['installationId']) !== 1
            || !is_bool($configuration['initialized'] ?? null)) {
            throw new RuntimeException('Invalid installation configuration.');
        }
    }
}
