<?php

require_once __DIR__ . '/ApplicationBackupManager.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../../core/Logger.php';

final class BackupRecoveryService
{
    public const DIRECTORY_ENVIRONMENT_VARIABLE = 'GENERIC_BACKUP_DIR';
    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
    private ApplicationBackupManager $manager;
    private string $directory;
    private Logger $logger;
    private $healthCheck;

    public function __construct(?ApplicationBackupManager $manager = null, ?string $directory = null, ?Logger $logger = null, ?callable $healthCheck = null)
    {
        $this->manager = $manager ?? new ApplicationBackupManager();
        $configured = getenv(self::DIRECTORY_ENVIRONMENT_VARIABLE);
        $this->directory = rtrim($directory ?? (is_string($configured) && trim($configured) !== ''
            ? trim($configured) : dirname(dirname(__DIR__, 2)) . '/backups'), '/\\');
        $this->logger = $logger ?? new Logger();
        $this->healthCheck = $healthCheck ?? function (): bool {
            $checks = (new ApplicationHealthMonitor())->detailed([])['checks'] ?? [];
            return ($checks['configuration']['status'] ?? null) === 'healthy'
                && ($checks['encryption']['status'] ?? null) === 'healthy';
        };
    }

    public function create(bool $preChange = false, string $category = 'manual'): array
    {
        $this->ensureDirectory($this->directory);
        $id = gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(6));
        $prefix = $preChange ? 'backup-pre-change-' : 'backup-';
        $filename = $prefix . $id . '.zip';
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        try {
            $manifest = $this->manager->create($path, $id);
            $size = filesize($path);
            $this->logger->audit($preChange ? 'backup.pre_change' : 'backup.created', 'success', 'NOTICE', [
                'component' => 'backup', 'recoveryPointId' => $id, 'operation' => $category,
            ]);
            $this->logger->audit('backup.verified', 'success', 'INFO', [
                'component' => 'backup', 'recoveryPointId' => $id, 'operation' => $category,
                'verification' => 'valid', 'authenticity' => 'valid',
            ]);
            return $this->summary($manifest, $filename, is_int($size) ? $size : 0, $preChange);
        } catch (Throwable $exception) {
            $this->logger->audit('backup.failed', 'failure', 'ERROR', [
                'component' => 'backup', 'operation' => $category, 'reason' => 'creation_failed',
            ]);
            throw new ApiRequestException('Application backup could not be created.', 'BACKUP_CREATION_FAILED', [], 500);
        }
    }

    public function createPreChange(string $category): ?array
    {
        // Initial setup has no recoverable baseline yet. Once every allowlisted
        // source exists, validation/signing failures must block the mutation.
        if (!$this->manager->hasRecoverableBaseline()) return null;
        return $this->create(true, $category);
    }

    public function history(): array
    {
        $this->ensureDirectory($this->directory);
        $points = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . 'backup-*.zip') ?: [] as $path) {
            if (!is_file($path)) continue;
            try {
                $manifest = $this->manager->verify($path, true);
                $size = filesize($path);
                $points[] = $this->summary($manifest, basename($path), is_int($size) ? $size : 0, str_starts_with(basename($path), 'backup-pre-change-'));
            } catch (Throwable $exception) {
                $points[] = [
                    'recoveryPointId' => null, 'filename' => basename($path), 'createdAt' => null,
                    'size' => max(0, (int)@filesize($path)), 'applicationVersion' => null,
                    'formatVersion' => null, 'verification' => 'invalid', 'authenticity' => 'invalid',
                    'preChange' => str_starts_with(basename($path), 'backup-pre-change-'),
                ];
            }
        }
        usort($points, static fn (array $left, array $right): int => strcmp((string)($right['createdAt'] ?? ''), (string)($left['createdAt'] ?? '')));
        return $points;
    }

    public function download(string $recoveryPointId): array
    {
        $path = $this->findRecoveryPoint($recoveryPointId);
        try { $manifest = $this->manager->verify($path, true); }
        catch (Throwable $exception) { throw new ApiRequestException('Recovery point is invalid.', 'BACKUP_VERIFICATION_FAILED', [], 422); }
        $this->logger->audit('backup.verified', 'success', 'INFO', [
            'component' => 'backup', 'recoveryPointId' => $manifest['recoveryPointId'], 'operation' => 'download',
            'verification' => 'valid', 'authenticity' => 'valid',
        ]);
        $contents = @file_get_contents($path);
        if (!is_string($contents)) throw new ApiRequestException('Recovery point is unavailable.', 'BACKUP_UNAVAILABLE', [], 404);
        return ['filename' => basename($path), 'mediaType' => 'application/zip', 'archive' => base64_encode($contents), 'recoveryPointId' => $manifest['recoveryPointId']];
    }

    public function previewUpload(string $filename, string $encodedArchive): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.zip$/i', $filename) !== 1) {
            throw new ApiRequestException('Invalid application backup.', 'INVALID_BACKUP_UPLOAD', [], 422);
        }
        $contents = base64_decode($encodedArchive, true);
        if (!is_string($contents) || $contents === '' || strlen($contents) > self::MAX_UPLOAD_BYTES) {
            throw new ApiRequestException('Invalid application backup.', 'INVALID_BACKUP_UPLOAD', [], 422);
        }
        $uploadDirectory = $this->directory . DIRECTORY_SEPARATOR . '.restore-uploads';
        $this->ensureDirectory($uploadDirectory);
        $this->cleanupUploads($uploadDirectory);
        $token = bin2hex(random_bytes(24));
        $path = $uploadDirectory . DIRECTORY_SEPARATOR . $token . '.zip';
        $this->writeUpload($path, $contents);
        try {
            $preview = $this->manager->preview($path);
            $this->logger->audit('backup.verified', 'success', 'INFO', [
                'component' => 'backup', 'recoveryPointId' => $preview['recoveryPointId'], 'operation' => 'restore_upload',
            ]);
            $this->logger->audit('restore.previewed', 'success', 'INFO', [
                'component' => 'backup', 'recoveryPointId' => $preview['recoveryPointId'], 'operation' => 'restore',
            ]);
            return [...$preview, 'uploadToken' => $token, 'selectedFilename' => $filename];
        } catch (Throwable $exception) {
            @unlink($path);
            $this->logger->audit('backup.verified', 'failure', 'WARNING', ['component' => 'backup', 'operation' => 'restore_upload', 'reason' => 'verification_failed']);
            throw new ApiRequestException('Invalid application backup.', 'BACKUP_VERIFICATION_FAILED', [], 422);
        }
    }

    public function restore(string $uploadToken, bool $confirmed): array
    {
        if (!$confirmed) throw new ApiRequestException('Explicit restore confirmation is required.', 'RESTORE_CONFIRMATION_REQUIRED', [], 409);
        if (preg_match('/^[a-f0-9]{48}$/', $uploadToken) !== 1) throw new ApiRequestException('Restore upload is unavailable.', 'RESTORE_UPLOAD_UNAVAILABLE', [], 404);
        $path = $this->directory . DIRECTORY_SEPARATOR . '.restore-uploads' . DIRECTORY_SEPARATOR . $uploadToken . '.zip';
        if (!is_file($path)) throw new ApiRequestException('Restore upload is unavailable.', 'RESTORE_UPLOAD_UNAVAILABLE', [], 404);
        $recoveryPointId = null;
        try {
            $preview = $this->manager->preview($path);
            $recoveryPointId = $preview['recoveryPointId'];
            $this->logger->audit('restore.started', 'success', 'NOTICE', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore']);
            $this->createPreChange('restore');
            $result = $this->manager->activateRestore($path, $this->healthCheck);
            $this->logger->audit('restore.completed', 'success', 'NOTICE', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore']);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception;
        } catch (Throwable $exception) {
            $this->logger->audit('restore.activation_failed', 'failure', 'ERROR', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore', 'reason' => 'activation_failed']);
            $this->logger->audit('restore.failed', 'failure', 'ERROR', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore', 'reason' => 'activation_failed']);
            throw new ApiRequestException('Configuration restore failed and the previous configuration was preserved.', 'RESTORE_FAILED', [], 500);
        } finally { @unlink($path); }
    }

    private function findRecoveryPoint(string $id): string
    {
        if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $id) !== 1) throw new ApiRequestException('Recovery point was not found.', 'BACKUP_NOT_FOUND', [], 404);
        foreach (['backup-' . $id . '.zip', 'backup-pre-change-' . $id . '.zip'] as $filename) {
            $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) return $path;
        }
        throw new ApiRequestException('Recovery point was not found.', 'BACKUP_NOT_FOUND', [], 404);
    }

    private function summary(array $manifest, string $filename, int $size, bool $preChange): array
    {
        return [
            'recoveryPointId' => $manifest['recoveryPointId'], 'filename' => $filename,
            'createdAt' => $manifest['createdAt'], 'size' => $size,
            'applicationVersion' => $manifest['application']['version'], 'formatVersion' => $manifest['formatVersion'],
            'verification' => 'valid', 'authenticity' => 'valid', 'preChange' => $preChange,
        ];
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new ApiRequestException('Backup storage is unavailable.', 'BACKUP_STORAGE_UNAVAILABLE', [], 503);
        @chmod($directory, 0700);
    }

    private function writeUpload(string $path, string $contents): void
    {
        $stream = @fopen($path, 'x+b');
        if ($stream === false) throw new ApiRequestException('Backup upload could not be staged.', 'BACKUP_UPLOAD_FAILED', [], 500);
        try {
            @chmod($path, 0600);
            if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) throw new RuntimeException('write failed');
        } catch (Throwable $exception) {
            fclose($stream); @unlink($path);
            throw new ApiRequestException('Backup upload could not be staged.', 'BACKUP_UPLOAD_FAILED', [], 500);
        }
        fclose($stream);
    }

    private function cleanupUploads(string $directory): void
    {
        $cutoff = time() - 1800;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $path) if ((int)@filemtime($path) < $cutoff) @unlink($path);
    }
}
