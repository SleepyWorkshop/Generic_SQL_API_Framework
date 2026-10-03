<?php

final class BackupSigningKey
{
    public const ENVIRONMENT_VARIABLE = 'GENERIC_BACKUP_SIGNING_KEY';
    public const FILE_ENVIRONMENT_VARIABLE = 'GENERIC_BACKUP_SIGNING_KEY_FILE';

    public function __construct(private string $applicationRoot)
    {
    }

    public function load(bool $create = false): string
    {
        $environment = getenv(self::ENVIRONMENT_VARIABLE);
        if (is_string($environment) && trim($environment) !== '') return $this->decode($environment);
        $path = $this->path();
        if (!is_file($path) && $create) $this->create($path);
        if (PHP_OS_FAMILY !== 'Windows' && is_file($path)) {
            clearstatcache(true, $path);
            if ((@fileperms($path) & 0777) !== 0600) {
                throw new RuntimeException('Backup signing key permissions are unsafe.');
            }
        }
        $encoded = @file_get_contents($path);
        if (!is_string($encoded)) throw new RuntimeException('Backup signing key is unavailable.');
        return $this->decode(trim($encoded));
    }

    public function available(): bool
    {
        try { $this->load(false); return true; }
        catch (Throwable $exception) { return false; }
    }

    private function path(): string
    {
        $override = getenv(self::FILE_ENVIRONMENT_VARIABLE);
        return is_string($override) && trim($override) !== ''
            ? trim($override)
            : $this->applicationRoot . '/runtime/secrets/backup-signing.key';
    }

    private function create(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Backup signing key directory is unavailable.');
        }
        @chmod($directory, 0700);
        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            if (is_file($path)) return;
            throw new RuntimeException('Backup signing key could not be created.');
        }
        try {
            @chmod($path, 0600);
            $encoded = base64_encode(random_bytes(32)) . PHP_EOL;
            if (fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream)) {
                throw new RuntimeException('Backup signing key could not be written.');
            }
        } catch (Throwable $exception) {
            fclose($stream);
            @unlink($path);
            throw $exception;
        }
        fclose($stream);
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $path);
            if ((@fileperms($path) & 0777) !== 0600) throw new RuntimeException('Backup signing key permissions are unsafe.');
        }
    }

    private function decode(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Backup signing key is invalid.');
        return $key;
    }
}
