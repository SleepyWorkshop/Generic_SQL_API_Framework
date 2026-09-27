<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/SafeZipArchive.php';
require_once __DIR__ . '/BackupSigningKey.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../Repositories/ApiKeyRepository.php';

final class ApplicationBackupManager
{
    public const FORMAT_VERSION = 3;
    public const MANIFEST_FILE = 'manifest.json';
    public const SIGNATURE_FILE = 'signature.json';
    private string $applicationRoot;
    private array $sources;
    private string $applicationVersion;
    private BackupSigningKey $signingKey;
    private string $lockPath;

    public function __construct(?string $applicationRoot = null, ?array $sources = null, ?string $applicationVersion = null, ?BackupSigningKey $signingKey = null, ?string $lockPath = null)
    {
        $this->applicationRoot = rtrim($applicationRoot ?? dirname(__DIR__, 2), '/\\');
        $this->sources = $sources ?? [
            'config/auth.json' => RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE),
            'config/installation.json' => RuntimeConfiguration::path(RuntimeConfiguration::INSTALLATION_FILE),
            'config/admin.json' => RuntimeConfiguration::path(RuntimeConfiguration::ADMIN_FILE),
            'config/authorization.json' => RuntimeConfiguration::path(RuntimeConfiguration::AUTHORIZATION_FILE),
            'config/api-keys.json' => RuntimeConfiguration::path(RuntimeConfiguration::API_KEYS_FILE),
            'database/config/database.json' => $this->applicationRoot . '/database/config/database.json',
        ];
        $this->applicationVersion = $applicationVersion ?? $this->readApplicationVersion();
        $this->signingKey = $signingKey ?? new BackupSigningKey($this->applicationRoot);
        $this->lockPath = $lockPath ?? $this->applicationRoot . '/runtime/.backup-recovery.lock';
        $this->validateSourceMap();
    }

    public function isReady(): bool
    {
        if (!$this->hasCompleteSourceSet()) return false;
        try {
            foreach ($this->sources as $logical => $path) $this->loadAndValidateSource($logical, $path, true);
            return true;
        } catch (Throwable $exception) { return false; }
    }

    public function hasCompleteSourceSet(): bool
    {
        foreach ($this->sources as $path) if (!is_file($path)) return false;
        return true;
    }

    public function create(string $archivePath, ?string $recoveryPointId = null, string $trigger = 'manual', string $createdBy = 'user'): array
    {
        if (!in_array($trigger, ['manual', 'scheduled'], true)
            || !in_array($createdBy, ['user', 'scheduler'], true)
            || ($trigger === 'manual' && $createdBy !== 'user')
            || ($trigger === 'scheduled' && $createdBy !== 'scheduler')) {
            throw new InvalidArgumentException('Backup creation metadata is invalid.');
        }
        $archivePath = $this->absoluteTargetPath($archivePath);
        $this->assertOutsideApplicationRoot($archivePath);
        if (file_exists($archivePath)) throw new RuntimeException('Backup destination already exists.');
        return $this->withLock(LOCK_EX, function () use ($archivePath, $recoveryPointId, $trigger, $createdBy): array {
            $this->ensureDirectory(dirname($archivePath));
            $temporaryPath = dirname($archivePath) . DIRECTORY_SEPARATOR . '.' . basename($archivePath) . '.tmp.' . bin2hex(random_bytes(8));
            $complete = false;
            try {
                $entries = [];
                $files = [];
                foreach ($this->sources as $logicalPath => $sourcePath) {
                    $value = $this->loadAndValidateSource($logicalPath, $sourcePath, true);
                    $contents = $this->encodeJson($value);
                    $entries[$logicalPath] = $contents;
                    $files[] = ['path' => $logicalPath, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents), 'schemaVersion' => $value['version'] ?? null];
                }
                $manifest = [
                    'formatVersion' => self::FORMAT_VERSION,
                    'recoveryPointId' => $this->validateOrCreateRecoveryPointId($recoveryPointId),
                    'createdAt' => gmdate(DATE_ATOM),
                    'application' => ['name' => 'Generic SQL API Framework', 'version' => $this->applicationVersion],
                    'scope' => 'application-configuration',
                    'trigger' => $trigger,
                    'createdBy' => $createdBy,
                    'files' => $files,
                    'excluded' => ['encryption_key', 'backup_signing_key', 'sessions', 'runtime_process_state', 'database_availability_state', 'application_runtime_state', 'rate_limit_state', 'logs', 'exports', 'uploads', 'temporary_files', 'backup_files', 'sql_server_data'],
                ];
                $manifestContents = $this->canonicalJson($manifest) . PHP_EOL;
                $signature = [
                    'version' => 1,
                    'algorithm' => 'HMAC-SHA256',
                    'manifestSha256' => hash('sha256', $manifestContents),
                    'signature' => hash_hmac('sha256', $manifestContents, $this->signingKey->load(true)),
                ];
                SafeZipArchive::create($temporaryPath, [self::MANIFEST_FILE => $manifestContents, self::SIGNATURE_FILE => $this->encodeJson($signature), ...$entries]);
                $verified = $this->verifyArchive($temporaryPath, true);
                if (!hash_equals($manifest['recoveryPointId'], $verified['recoveryPointId'])) throw new RuntimeException('Generated backup recovery point could not be verified.');
                if (!@rename($temporaryPath, $archivePath)) throw new RuntimeException('Backup ZIP could not be finalized atomically.');
                @chmod($archivePath, 0600);
                $complete = true;
                return $manifest;
            } finally { if (!$complete) @unlink($temporaryPath); }
        });
    }

    public function verify(string $archivePath, bool $requireEncryptionKey = true, bool $enforceExternalLocation = true): array
    {
        $archivePath = $this->absoluteExistingFile($archivePath);
        if ($enforceExternalLocation) $this->assertOutsideApplicationRoot($archivePath);
        return $this->verifyArchive($archivePath, $requireEncryptionKey);
    }

    public function preview(string $archivePath): array
    {
        $archivePath = $this->absoluteExistingFile($archivePath);
        $this->assertOutsideApplicationRoot($archivePath);
        $manifest = $this->verifyArchive($archivePath, true);
        $changed = [];
        $unchanged = [];
        foreach ($manifest['files'] as $file) {
            $logical = $file['path'];
            $current = $this->loadAndValidateSource($logical, $this->sources[$logical], true);
            $same = hash_equals(hash('sha256', $this->encodeJson($current)), $file['sha256']);
            if ($same) $unchanged[] = $logical; else $changed[] = $logical;
        }
        return [
            'recoveryPointId' => $manifest['recoveryPointId'], 'createdAt' => $manifest['createdAt'],
            'applicationVersion' => $manifest['application']['version'], 'formatVersion' => $manifest['formatVersion'],
            'trigger' => $manifest['trigger'] ?? 'legacy', 'createdBy' => $manifest['createdBy'] ?? null,
            'files' => array_column($manifest['files'], 'path'), 'changedFiles' => $changed, 'unchangedFiles' => $unchanged,
            'schemaCompatible' => true, 'encryptionKeyAvailable' => true,
            'verification' => 'valid', 'authenticity' => 'valid', 'warnings' => [],
        ];
    }

    public function stageRestore(string $archivePath, string $targetDirectory): array
    {
        $archivePath = $this->absoluteExistingFile($archivePath);
        $this->assertOutsideApplicationRoot($archivePath);
        $targetDirectory = $this->absoluteTargetPath($targetDirectory);
        $this->assertOutsideApplicationRoot($targetDirectory);
        if (file_exists($targetDirectory)) throw new RuntimeException('Restore staging destination must not already exist.');
        $manifest = $this->verifyArchive($archivePath, true);
        $entries = SafeZipArchive::read($archivePath);
        $this->ensureDirectory(dirname($targetDirectory));
        $temporaryPath = dirname($targetDirectory) . DIRECTORY_SEPARATOR . '.' . basename($targetDirectory) . '.tmp.' . bin2hex(random_bytes(8));
        if (!@mkdir($temporaryPath, 0700)) throw new RuntimeException('Restore staging directory could not be created.');
        $complete = false;
        try {
            foreach ([self::MANIFEST_FILE, self::SIGNATURE_FILE, ...array_column($manifest['files'], 'path')] as $logicalPath) {
                $target = $temporaryPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
                $this->ensureDirectory(dirname($target));
                $this->writeContents($target, $entries[$logicalPath]);
            }
            if (!@rename($temporaryPath, $targetDirectory)) throw new RuntimeException('Restore staging directory could not be finalized atomically.');
            $complete = true;
            return ['ready' => true, 'path' => $targetDirectory, 'files' => count($manifest['files']), 'manifest' => $manifest];
        } finally { if (!$complete) $this->removeDirectory($temporaryPath); }
    }

    public function activateRestore(string $archivePath, ?callable $healthCheck = null): array
    {
        $archivePath = $this->absoluteExistingFile($archivePath);
        $this->assertOutsideApplicationRoot($archivePath);
        return $this->withLock(LOCK_EX, function () use ($archivePath, $healthCheck): array {
            $manifest = $this->verifyArchive($archivePath, true);
            $entries = SafeZipArchive::read($archivePath);
            $previous = [];
            $activated = [];
            foreach ($this->sources as $logical => $path) $previous[$logical] = $this->loadJsonFile($path, 'Live configuration is unavailable.');
            try {
                foreach ($manifest['files'] as $file) {
                    $logical = $file['path'];
                    $value = $this->decodeJson($entries[$logical], "Backup JSON is invalid: {$logical}.");
                    $this->validateConfiguration($logical, $value, true);
                    JsonFileStore::save($this->sources[$logical], $value);
                    $activated[] = $logical;
                }
                foreach ($this->sources as $logical => $path) $this->loadAndValidateSource($logical, $path, true);
                if ($healthCheck !== null && $healthCheck() !== true) throw new RuntimeException('Post-restore health validation failed.');
            } catch (Throwable $exception) {
                $rollbackFailure = false;
                foreach ($previous as $logical => $value) {
                    try { JsonFileStore::save($this->sources[$logical], $value); } catch (Throwable $rollbackException) { $rollbackFailure = true; }
                }
                if ($rollbackFailure) throw new RuntimeException('Restore activation failed and automatic recovery was incomplete.');
                throw new RuntimeException('Restore activation failed; the previous configuration was recovered.');
            }
            return ['restored' => true, 'recoveryPointId' => $manifest['recoveryPointId'], 'files' => count($activated), 'health' => 'healthy'];
        });
    }

    private function verifyArchive(string $archivePath, bool $requireEncryptionKey): array
    {
        $entries = SafeZipArchive::read($archivePath);
        $expected = [self::MANIFEST_FILE, self::SIGNATURE_FILE, ...array_keys($this->sources)];
        if (array_keys($entries) !== $expected) throw new RuntimeException('Backup ZIP contains missing, unexpected, or out-of-order entries.');
        $manifest = $this->decodeJson($entries[self::MANIFEST_FILE], 'Backup manifest is missing or invalid.');
        $this->validateManifest($manifest);
        $signature = $this->decodeJson($entries[self::SIGNATURE_FILE], 'Backup signature is missing or invalid.');
        if (array_keys($signature) !== ['version', 'algorithm', 'manifestSha256', 'signature']
            || ($signature['version'] ?? null) !== 1 || ($signature['algorithm'] ?? null) !== 'HMAC-SHA256'
            || preg_match('/^[a-f0-9]{64}$/', $signature['manifestSha256'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature['signature'] ?? '') !== 1
            || !hash_equals(hash('sha256', $entries[self::MANIFEST_FILE]), $signature['manifestSha256'])
            || !hash_equals(hash_hmac('sha256', $entries[self::MANIFEST_FILE], $this->signingKey->load(false)), $signature['signature'])) {
            throw new RuntimeException('Backup authenticity verification failed.');
        }
        foreach ($manifest['files'] as $file) {
            $logical = $file['path'];
            $contents = $entries[$logical];
            if (strlen($contents) !== $file['size'] || !hash_equals(hash('sha256', $contents), $file['sha256'])) throw new RuntimeException("Backup integrity verification failed: {$logical}.");
            $value = $this->decodeJson($contents, "Backup JSON is invalid: {$logical}.");
            if (($value['version'] ?? null) !== $file['schemaVersion']) throw new RuntimeException("Backup schema metadata is invalid: {$logical}.");
            $this->validateConfiguration($logical, $value, $requireEncryptionKey);
        }
        return $manifest;
    }

    private function loadAndValidateSource(string $logicalPath, string $sourcePath, bool $requireEncryptionKey): array
    {
        try { $value = JsonFileStore::load($sourcePath); }
        catch (Throwable $exception) { throw new RuntimeException("Required backup source is missing or invalid: {$logicalPath}."); }
        $this->validateConfiguration($logicalPath, $value, $requireEncryptionKey);
        return $value;
    }

    private function validateConfiguration(string $logicalPath, array $value, bool $requireEncryptionKey): void
    {
        try {
            match ($logicalPath) {
                'config/auth.json' => (new AuthRepository($this->sources[$logicalPath]))->validate($value),
                'config/installation.json' => (new InstallationRepository($this->sources[$logicalPath]))->validate($value),
                'config/admin.json' => (new AdminConfigurationRepository($this->sources[$logicalPath]))->validate($value),
                'config/authorization.json' => (new AuthorizationRepository($this->sources[$logicalPath]))->validate($value),
                'config/api-keys.json' => (new ApiKeyRepository($this->sources[$logicalPath]))->validate($value),
                'database/config/database.json' => DatabaseConfigurationResolver::usesEncryption($value)
                    ? null : throw new RuntimeException('Plaintext database configuration is not recoverable.'),
                default => throw new RuntimeException('Unsupported backup configuration.'),
            };
        } catch (Throwable $exception) {
            throw new RuntimeException("Backup configuration schema is invalid: {$logicalPath}.");
        }
        if ($logicalPath === 'database/config/database.json' && $requireEncryptionKey) {
            try { DatabaseConfigurationResolver::resolve($value); }
            catch (Throwable $exception) { throw new RuntimeException('Encrypted database configuration cannot be recovered with the available key.'); }
        }
    }

    private function validateManifest(array $manifest): void
    {
        $formatVersion = $manifest['formatVersion'] ?? null;
        $expectedKeys = $formatVersion === 2
            ? ['formatVersion', 'recoveryPointId', 'createdAt', 'application', 'scope', 'files', 'excluded']
            : ['formatVersion', 'recoveryPointId', 'createdAt', 'application', 'scope', 'trigger', 'createdBy', 'files', 'excluded'];
        if (!in_array($formatVersion, [2, self::FORMAT_VERSION], true)
            || count($manifest) !== count($expectedKeys)
            || array_diff(array_keys($manifest), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($manifest)) !== []
            || ($formatVersion === self::FORMAT_VERSION
                && (!in_array($manifest['trigger'] ?? null, ['manual', 'scheduled'], true)
                    || !in_array($manifest['createdBy'] ?? null, ['user', 'scheduler'], true)
                    || (($manifest['trigger'] ?? null) === 'manual' && ($manifest['createdBy'] ?? null) !== 'user')
                    || (($manifest['trigger'] ?? null) === 'scheduled' && ($manifest['createdBy'] ?? null) !== 'scheduler')))
            || preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $manifest['recoveryPointId'] ?? '') !== 1
            || !is_string($manifest['createdAt'] ?? null) || strtotime($manifest['createdAt']) === false
            || !is_array($manifest['application'] ?? null) || count($manifest['application']) !== 2
            || array_diff(array_keys($manifest['application']), ['name', 'version']) !== []
            || ($manifest['application']['name'] ?? null) !== 'Generic SQL API Framework'
            || !is_string($manifest['application']['version'] ?? null) || ($manifest['scope'] ?? null) !== 'application-configuration'
            || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])
            || !is_array($manifest['excluded'] ?? null) || !array_is_list($manifest['excluded'])
            || $manifest['excluded'] !== ['encryption_key', 'backup_signing_key', 'sessions', 'runtime_process_state', 'database_availability_state', 'application_runtime_state', 'rate_limit_state', 'logs', 'exports', 'uploads', 'temporary_files', 'backup_files', 'sql_server_data']) throw new RuntimeException('Backup manifest is invalid or unsupported.');
        $paths = [];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || count($file) !== 4
                || array_diff(array_keys($file), ['path', 'size', 'sha256', 'schemaVersion']) !== []
                || !is_string($file['path'] ?? null) || !array_key_exists($file['path'], $this->sources)
                || !is_int($file['size'] ?? null) || $file['size'] < 3 || $file['size'] > SafeZipArchive::MAX_ENTRY_BYTES
                || preg_match('/^[a-f0-9]{64}$/', $file['sha256'] ?? '') !== 1 || !is_int($file['schemaVersion'] ?? null)) throw new RuntimeException('Backup manifest file metadata is invalid.');
            $paths[] = $file['path'];
        }
        if ($paths !== array_keys($this->sources)) throw new RuntimeException('Backup manifest file set is incomplete or out of order.');
    }

    private function validateSourceMap(): void
    {
        $expected = ['config/auth.json', 'config/installation.json', 'config/admin.json', 'config/authorization.json', 'config/api-keys.json', 'database/config/database.json'];
        if (array_keys($this->sources) !== $expected) throw new InvalidArgumentException('Unsupported backup source map.');
        foreach ($this->sources as $source) if (!is_string($source) || trim($source) === '') throw new InvalidArgumentException('Invalid backup source path.');
    }

    private function validateOrCreateRecoveryPointId(?string $id): string
    {
        $id ??= gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(6));
        if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $id) !== 1) throw new InvalidArgumentException('Recovery-point identifier is invalid.');
        return $id;
    }

    private function encodeJson(array $value): string
    {
        try { return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL; }
        catch (Throwable $exception) { throw new RuntimeException('Backup JSON could not be encoded.'); }
    }

    private function canonicalJson(array $value): string
    {
        $sort = function ($item) use (&$sort) {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        try { return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
        catch (Throwable $exception) { throw new RuntimeException('Backup manifest could not be canonicalized.'); }
    }

    private function decodeJson(string $contents, string $message): array
    {
        try { $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $exception) { throw new RuntimeException($message); }
        if (!is_array($value) || array_is_list($value)) throw new RuntimeException($message);
        return $value;
    }

    private function loadJsonFile(string $path, string $message): array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) throw new RuntimeException($message);
        return $this->decodeJson($contents, $message);
    }

    private function writeContents(string $path, string $contents): void
    {
        $stream = @fopen($path, 'x+b');
        if ($stream === false) throw new RuntimeException('Restore staging file could not be created.');
        $complete = false;
        try {
            @chmod($path, 0600);
            $written = 0;
            while ($written < strlen($contents)) {
                $bytes = fwrite($stream, substr($contents, $written));
                if ($bytes === false || $bytes === 0) throw new RuntimeException('Restore staging file write failed.');
                $written += $bytes;
            }
            if (!fflush($stream)) throw new RuntimeException('Restore staging file flush failed.');
            $complete = true;
        } finally { fclose($stream); if (!$complete) @unlink($path); }
    }

    private function withLock(int $operation, callable $callback)
    {
        $this->ensureDirectory(dirname($this->lockPath));
        $lock = @fopen($this->lockPath, 'c');
        if ($lock === false) throw new RuntimeException('Backup and recovery lock is unavailable.');
        @chmod($this->lockPath, 0600);
        try {
            if (!flock($lock, $operation)) throw new RuntimeException('Backup and recovery lock could not be acquired.');
            return $callback();
        } finally { @flock($lock, LOCK_UN); fclose($lock); }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Backup directory could not be created.');
        @chmod($path, 0700);
    }

    private function absoluteExistingFile(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) throw new RuntimeException('Backup ZIP was not found.');
        return $resolved;
    }

    private function absoluteTargetPath(string $path): string
    {
        if (trim($path) === '' || str_contains($path, "\0") || !$this->isAbsolutePath($path)) throw new InvalidArgumentException('Backup path must be a valid absolute path.');
        $parent = realpath(dirname($path));
        if ($parent === false) throw new RuntimeException('Backup parent directory was not found.');
        return rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);
    }

    private function assertOutsideApplicationRoot(string $path): void
    {
        $root = realpath($this->applicationRoot);
        if ($root === false) throw new RuntimeException('Application root is unavailable.');
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/') . '/';
        if (str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedPath) : $normalizedPath, PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedRoot) : $normalizedRoot)) throw new RuntimeException('Backup and restore staging paths must be outside the application root.');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function readApplicationVersion(): string
    {
        $path = $this->applicationRoot . '/config/app.php';
        if (!is_file($path)) return 'unknown';
        $configuration = require $path;
        return is_array($configuration) && is_string($configuration['version'] ?? null) ? $configuration['version'] : 'unknown';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}
