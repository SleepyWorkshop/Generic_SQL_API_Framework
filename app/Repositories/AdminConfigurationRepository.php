<?php

require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Configuration/RuntimeControls.php';
require_once __DIR__ . '/../Backup/ConfigurationMutationBackup.php';

final class AdminConfigurationRepository
{
    public const AUTHENTICATION_MODES = ['none', 'session', 'api_key', 'session+api_key'];
    public const ALLOWED_CORS_METHODS = ['POST', 'OPTIONS'];

    private string $path;
    private bool $runtimePath;

    public function __construct(?string $path = null)
    {
        $configuredPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
        $this->runtimePath = $path === null && ($configuredPath === false || trim($configuredPath) === '');
        $this->path = $path ?? ($this->runtimePath
            ? RuntimeConfiguration::path(RuntimeConfiguration::ADMIN_FILE)
            : trim($configuredPath));
    }

    public function load(): array
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
        $configuration = JsonFileStore::load($this->path);
        if (in_array($configuration['version'] ?? null, [1, 2, 3, 4], true)) {
            $configuration = $this->migrate($configuration);
            JsonFileStore::save($this->path, $configuration);
        }
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
        $this->validate($configuration);
        JsonFileStore::save($this->path, $configuration);
    }

    public function update(callable $operation)
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
        $stream = @fopen($this->path . '.lock', 'c');
        if ($stream === false) throw new RuntimeException('Admin configuration lock is unavailable.');
        @chmod($this->path . '.lock', 0600);
        try {
            if (!flock($stream, LOCK_EX)) throw new RuntimeException('Admin configuration lock could not be acquired.');
            $configuration = JsonFileStore::load($this->path);
            if (in_array($configuration['version'] ?? null, [1, 2, 3, 4], true)) $configuration = $this->migrate($configuration);
            $this->validate($configuration);
            $result = $operation($configuration);
            $this->validate($configuration);
            if ($this->runtimePath) ConfigurationMutationBackup::before('runtime_security_configuration');
            JsonFileStore::save($this->path, $configuration);
            return $result;
        } finally {
            @flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    public static function defaults(): array
    {
        return RuntimeConfiguration::adminDefaults();
    }

    public static function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '' || $origin === '*'
            || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Allowed origins must be absolute HTTP or HTTPS origins.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new InvalidArgumentException('Allowed origins must not contain paths, credentials, queries, or fragments.');
        }
        $normalized = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':' . (int)$parts['port'];
        }
        return $normalized;
    }

    public function validate(array $configuration): void
    {
        if (array_keys($configuration) !== ['version', 'server', 'cors', 'authentication', 'runtime']
            || ($configuration['version'] ?? null) !== 5
            || !is_array($configuration['server'] ?? null)
            || !is_array($configuration['cors'] ?? null)
            || !is_array($configuration['authentication'] ?? null)
            || !is_array($configuration['runtime'] ?? null)) {
            throw new RuntimeException('Invalid admin configuration.');
        }
        $server = $configuration['server'];
        if (array_keys($server) !== ['apiPortMinimum', 'apiPortMaximum', 'parserPortMinimum', 'parserPortMaximum', 'adminPort', 'bindAddress']
            || !is_int($server['apiPortMinimum'] ?? null)
            || !is_int($server['apiPortMaximum'] ?? null)
            || !is_int($server['parserPortMinimum'] ?? null)
            || !is_int($server['parserPortMaximum'] ?? null)
            || !is_int($server['adminPort'] ?? null)
            || $server['apiPortMinimum'] < 1 || $server['apiPortMaximum'] > 65535
            || $server['apiPortMinimum'] > $server['apiPortMaximum']
            || $server['parserPortMinimum'] < 1 || $server['parserPortMaximum'] > 65535
            || $server['parserPortMinimum'] > $server['parserPortMaximum']
            || $server['adminPort'] < 1 || $server['adminPort'] > 65535
            || ($server['bindAddress'] ?? null) !== '127.0.0.1') {
            throw new RuntimeException('Invalid server configuration.');
        }
        $cors = $configuration['cors'];
        if (array_diff(array_keys($cors), ['allowedOrigins', 'credentialsEnabled', 'allowedMethods']) !== []
            || !is_array($cors['allowedOrigins'] ?? null)
            || !array_is_list($cors['allowedOrigins'])
            || !is_bool($cors['credentialsEnabled'] ?? null)
            || !is_array($cors['allowedMethods'] ?? null)
            || !array_is_list($cors['allowedMethods'])
            || $cors['allowedMethods'] === []) {
            throw new RuntimeException('Invalid CORS configuration.');
        }
        $origins = [];
        foreach ($cors['allowedOrigins'] as $origin) {
            if (!is_string($origin)) throw new RuntimeException('Invalid CORS configuration.');
            try {
                $normalized = self::normalizeOrigin($origin);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Invalid CORS configuration.');
            }
            if ($normalized !== $origin || isset($origins[$origin])) {
                throw new RuntimeException('Invalid CORS configuration.');
            }
            $origins[$origin] = true;
        }
        $methods = array_values(array_unique(array_map('strtoupper', $cors['allowedMethods'])));
        if ($methods !== $cors['allowedMethods']
            || array_diff($methods, self::ALLOWED_CORS_METHODS) !== []) {
            throw new RuntimeException('Invalid CORS configuration.');
        }
        $authentication = $configuration['authentication'];
        if (array_keys($authentication) !== ['mode']
            || !in_array($authentication['mode'] ?? null, self::AUTHENTICATION_MODES, true)) {
            throw new RuntimeException('Invalid authentication configuration.');
        }
        try {
            RuntimeControls::validate($configuration['runtime']);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    private function migrate(array $configuration): array
    {
        $version = $configuration['version'] ?? null;
        if ($version === 4) {
            if (!is_array($configuration['server'] ?? null)
                || !is_array($configuration['cors'] ?? null)
                || !is_array($configuration['authentication'] ?? null)) {
                throw new RuntimeException('Invalid admin configuration.');
            }
            return [
                'version' => 5,
                'server' => $configuration['server'],
                'cors' => $configuration['cors'],
                'authentication' => $configuration['authentication'],
                'runtime' => RuntimeControls::defaults(),
            ];
        }
        if ($version === 3) {
            if (!is_array($configuration['server'] ?? null)
                || !is_array($configuration['cors'] ?? null)
                || !is_array($configuration['authentication'] ?? null)) {
                throw new RuntimeException('Invalid admin configuration.');
            }
            return [
                'version' => 5,
                'server' => $configuration['server'],
                'cors' => $configuration['cors'],
                'authentication' => $configuration['authentication'],
                'runtime' => RuntimeControls::defaults(),
            ];
        }
        if ($version === 2) {
            $this->validateVersionTwo($configuration);
            $defaults = self::defaults();
            return [
                'version' => 5,
                'server' => [
                    'apiPortMinimum' => $configuration['server']['apiPortMinimum'],
                    'apiPortMaximum' => $configuration['server']['apiPortMaximum'],
                    'parserPortMinimum' => $defaults['server']['parserPortMinimum'],
                    'parserPortMaximum' => $defaults['server']['parserPortMaximum'],
                    'adminPort' => $configuration['server']['adminPort'],
                    'bindAddress' => $configuration['server']['bindAddress'],
                ],
                'cors' => $configuration['cors'],
                'authentication' => $configuration['authentication'],
                'runtime' => RuntimeControls::defaults(),
            ];
        }
        if (array_diff(array_keys($configuration), ['version', 'cors', 'authentication']) !== []
            || !is_array($configuration['cors'] ?? null)
            || !is_array($configuration['authentication'] ?? null)) {
            throw new RuntimeException('Invalid admin configuration.');
        }
        $defaults = self::defaults();
        return [
            'version' => 5,
            'server' => $defaults['server'],
            'cors' => $configuration['cors'],
            'authentication' => $configuration['authentication'],
            'runtime' => RuntimeControls::defaults(),
        ];
    }

    private function validateVersionTwo(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'server', 'features', 'cors', 'authentication']) !== []
            || array_diff(['version', 'server', 'features', 'cors', 'authentication'], array_keys($configuration)) !== []
            || !is_array($configuration['server'] ?? null)
            || array_keys($configuration['server']) !== ['apiPortMinimum', 'apiPortMaximum', 'adminPort', 'bindAddress']) {
            throw new RuntimeException('Invalid admin configuration.');
        }
    }
}
