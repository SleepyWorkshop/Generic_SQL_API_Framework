<?php

require_once __DIR__ . '/ApplicationBackupManager.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

final class BackupRecoveryService
{
    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
    private ApplicationBackupManager $manager;
    private string $directory;
    private Logger $logger;
    private $healthCheck;
    private OperationalLogger $operationalLogger;
    private $scheduleProvider;

    public function __construct(?ApplicationBackupManager $manager = null, ?string $directory = null, ?Logger $logger = null, ?callable $healthCheck = null, ?OperationalLogger $operationalLogger = null, ?callable $scheduleProvider = null)
    {
        $this->manager = $manager ?? new ApplicationBackupManager();
        $this->directory = rtrim($directory ?? dirname(dirname(__DIR__, 2)) . '/backups', '/\\');
        $this->logger = $logger ?? new Logger();
        $this->operationalLogger = $operationalLogger ?? new OperationalLogger();
        $this->scheduleProvider = $scheduleProvider ?? static fn (): array => (new AdminConfigurationRepository())->load()['backup'];
        $this->healthCheck = $healthCheck ?? static fn (): array => (new ApplicationHealthMonitor())->restoreSafety();
    }

    public function create(string $trigger = 'manual', string $createdBy = 'user'): array
    {
        if (!in_array($trigger, ['manual', 'scheduled'], true)
            || !in_array($createdBy, ['user', 'scheduler'], true)) {
            throw new InvalidArgumentException('Invalid backup creation metadata.');
        }
        $this->ensureDirectory($this->directory);
        $id = gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(6));
        $filename = 'backup-' . $id . '.zip';
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        try {
            $manifest = $this->manager->create($path, $id, $trigger, $createdBy);
            $size = filesize($path);
            $this->logger->audit('backup.created', 'success', 'NOTICE', [
                'component' => 'backup', 'recoveryPointId' => $id, 'operation' => $trigger,
                'trigger' => $trigger, 'createdBy' => $createdBy,
            ]);
            $this->logger->audit('backup.verified', 'success', 'INFO', [
                'component' => 'backup', 'recoveryPointId' => $id, 'operation' => $trigger,
                'verification' => 'valid', 'authenticity' => 'valid',
            ]);
            $summary = $this->summary($manifest, $filename, is_int($size) ? $size : 0);
            if ($trigger === 'scheduled') $this->writeScheduleStatus('success', $summary, null);
            $this->applyRetention($this->schedule()['retention'], $path);
            return $summary;
        } catch (Throwable $exception) {
            $this->logger->audit('backup.failed', 'failure', 'ERROR', [
                'component' => 'backup', 'operation' => $trigger, 'reason' => 'creation_failed',
                'trigger' => $trigger, 'createdBy' => $createdBy,
            ]);
            if ($trigger === 'scheduled') {
                $this->logger->audit('backup.scheduled.failed', 'failure', 'ERROR', [
                    'component' => 'backup', 'operation' => 'scheduled', 'reason' => 'creation_failed',
                ]);
                $this->operationalLogger->error('admin', 'Scheduled backup failed', ['error_code' => 'BACKUP_CREATION_FAILED']);
                $this->writeScheduleStatus('failed', null, 'BACKUP_CREATION_FAILED');
            }
            throw new ApiRequestException('Application backup could not be created.', 'BACKUP_CREATION_FAILED', [], 500);
        }
    }

    public function createScheduled(): array
    {
        $schedule = $this->schedule();
        if (!$schedule['enabled']) {
            throw new ApiRequestException('Scheduled backups are disabled.', 'SCHEDULED_BACKUP_DISABLED', [], 409);
        }
        $this->operationalLogger->info('admin', 'Scheduled backup started', ['frequency' => $schedule['frequency']]);
        $result = $this->create('scheduled', 'scheduler');
        $this->operationalLogger->info('admin', 'Scheduled backup completed', ['recovery_point' => $result['recoveryPointId']]);
        return $result;
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
                $points[] = $this->summary($manifest, basename($path), is_int($size) ? $size : 0);
            } catch (Throwable $exception) {
                $points[] = [
                    'recoveryPointId' => null, 'filename' => basename($path), 'createdAt' => null,
                    'size' => max(0, (int)@filesize($path)), 'applicationVersion' => null,
                    'formatVersion' => null, 'verification' => 'invalid', 'authenticity' => 'invalid',
                    'type' => 'legacy', 'trigger' => 'legacy', 'createdBy' => null,
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
        $this->operationalLogger->info('admin', 'admin.restore.preview.start', ['filename_length' => strlen($filename)]);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.zip$/i', $filename) !== 1) {
            $this->operationalLogger->warning('admin', 'admin.restore.preview.failed', ['error_code' => 'INVALID_BACKUP_UPLOAD']);
            throw new ApiRequestException('Invalid application backup.', 'INVALID_BACKUP_UPLOAD', [], 422);
        }
        $contents = base64_decode($encodedArchive, true);
        if (!is_string($contents) || $contents === '' || strlen($contents) > self::MAX_UPLOAD_BYTES) {
            $this->operationalLogger->warning('admin', 'admin.restore.preview.failed', ['error_code' => 'INVALID_BACKUP_UPLOAD']);
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
            $this->operationalLogger->info('admin', 'admin.restore.preview.success', [
                'recovery_point' => $preview['recoveryPointId'],
            ]);
            return [...$preview, 'uploadToken' => $token, 'selectedFilename' => $filename];
        } catch (Throwable $exception) {
            @unlink($path);
            $this->logger->audit('backup.verified', 'failure', 'WARNING', ['component' => 'backup', 'operation' => 'restore_upload', 'reason' => 'verification_failed']);
            $this->operationalLogger->warning('admin', 'admin.restore.preview.failed', ['error_code' => 'BACKUP_VERIFICATION_FAILED']);
            throw new ApiRequestException('Invalid application backup.', 'BACKUP_VERIFICATION_FAILED', [], 422);
        }
    }

    public function restore(string $uploadToken, bool $confirmed): array
    {
        $this->operationalLogger->info('admin', 'admin.restore.confirm.submitted');
        if (!$confirmed) throw new ApiRequestException('Explicit restore confirmation is required.', 'RESTORE_CONFIRMATION_REQUIRED', [], 409);
        if (preg_match('/^[a-f0-9]{48}$/', $uploadToken) !== 1) throw new ApiRequestException('Restore upload is unavailable.', 'RESTORE_UPLOAD_UNAVAILABLE', [], 404);
        $path = $this->directory . DIRECTORY_SEPARATOR . '.restore-uploads' . DIRECTORY_SEPARATOR . $uploadToken . '.zip';
        if (!is_file($path)) throw new ApiRequestException('Restore upload is unavailable.', 'RESTORE_UPLOAD_UNAVAILABLE', [], 404);
        $recoveryPointId = null;
        try {
            $preview = $this->manager->preview($path);
            $recoveryPointId = $preview['recoveryPointId'];
            $this->logger->audit('restore.started', 'success', 'NOTICE', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore']);
            $this->operationalLogger->info('admin', 'admin.restore.start', ['recovery_point' => $recoveryPointId]);
            $this->operationalLogger->info('admin', 'admin.restore.activation.start', ['recovery_point' => $recoveryPointId]);
            $healthCheck = function () use ($recoveryPointId): bool {
                $this->operationalLogger->info('admin', 'admin.restore.health_check.start', ['recovery_point' => $recoveryPointId]);
                try {
                    $diagnostic = $this->healthDiagnostic(($this->healthCheck)());
                    $healthy = $diagnostic['healthy'];
                    $logMethod = $healthy ? 'info' : 'error';
                    $this->operationalLogger->{$logMethod}(
                        'admin',
                        $healthy ? 'admin.restore.health_check.success' : 'admin.restore.health_check.failed',
                        [
                            'recovery_point' => $recoveryPointId,
                            'check' => $diagnostic['check'],
                            'error_code' => $diagnostic['errorCode'],
                            'reason' => $diagnostic['reason'],
                        ]
                    );
                    if (!$healthy) {
                        $this->logger->audit('restore.health_check_failed', 'failure', 'ERROR', [
                            'component' => 'backup',
                            'recoveryPointId' => $recoveryPointId,
                            'operation' => 'restore',
                            'errorCode' => $diagnostic['errorCode'],
                            'reason' => $diagnostic['reason'],
                        ]);
                    }
                    return $healthy;
                } catch (Throwable $exception) {
                    $this->operationalLogger->error('admin', 'admin.restore.health_check.failed', [
                        'recovery_point' => $recoveryPointId,
                        'check' => 'health_check.execution',
                        'error_code' => 'HEALTH_CHECK_EXCEPTION',
                        'reason' => 'health_check_exception',
                    ]);
                    $this->logger->audit('restore.health_check_failed', 'failure', 'ERROR', [
                        'component' => 'backup',
                        'recoveryPointId' => $recoveryPointId,
                        'operation' => 'restore',
                        'errorCode' => 'HEALTH_CHECK_EXCEPTION',
                        'reason' => 'health_check_exception',
                    ]);
                    throw $exception;
                }
            };
            $result = $this->manager->activateRestore($path, $healthCheck);
            $this->operationalLogger->info('admin', 'admin.restore.activation.success', ['recovery_point' => $recoveryPointId]);
            $this->logger->audit('restore.completed', 'success', 'NOTICE', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore']);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception;
        } catch (Throwable $exception) {
            $rollbackFailed = str_contains($exception->getMessage(), 'automatic recovery was incomplete');
            $errorCode = $rollbackFailed ? 'RESTORE_ROLLBACK_FAILED' : 'CONFIG_ACTIVATION_FAILED';
            $this->operationalLogger->error('admin', 'admin.restore.activation.failed', [
                'recovery_point' => $recoveryPointId,
                'error_code' => $errorCode,
                'rollback' => $rollbackFailed ? 'incomplete' : 'preserved',
            ]);
            $reason = $rollbackFailed ? 'rollback_failed' : 'activation_failed';
            $this->logger->audit('restore.activation_failed', 'failure', 'ERROR', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore', 'reason' => $reason, 'errorCode' => $errorCode]);
            $this->logger->audit('restore.failed', 'failure', 'ERROR', ['component' => 'backup', 'recoveryPointId' => $recoveryPointId, 'operation' => 'restore', 'reason' => $reason, 'errorCode' => $errorCode]);
            throw new ApiRequestException(
                $rollbackFailed
                    ? 'Configuration restore failed and automatic rollback was incomplete.'
                    : 'Configuration restore failed and the previous configuration was preserved.',
                $rollbackFailed ? 'RESTORE_ROLLBACK_FAILED' : 'RESTORE_FAILED',
                [],
                500
            );
        } finally { @unlink($path); }
    }

    private function healthDiagnostic(mixed $result): array
    {
        if (is_bool($result)) {
            return [
                'healthy' => $result,
                'check' => 'configured.health_check',
                'errorCode' => $result ? null : 'HEALTH_CHECK_FAILED',
                'reason' => $result ? 'healthy' : 'reported_unhealthy',
            ];
        }
        if (!is_array($result)
            || !array_key_exists('healthy', $result)
            || !array_key_exists('check', $result)
            || !array_key_exists('errorCode', $result)
            || !array_key_exists('reason', $result)
            || !is_bool($result['healthy'] ?? null)
            || !is_string($result['check'] ?? null)
            || ($result['errorCode'] !== null && !is_string($result['errorCode']))
            || !is_string($result['reason'] ?? null)) {
            throw new RuntimeException('Restore health check returned an invalid result.');
        }
        return $result;
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

    public function scheduleInformation(): array
    {
        $schedule = $this->schedule();
        $history = $this->history();
        $last = $history[0] ?? null;
        $status = $this->readScheduleStatus();
        return [
            'configuration' => $schedule,
            'lastBackup' => $last,
            'lastScheduledAttempt' => $status,
            'nextBackupAt' => $schedule['enabled'] ? $this->nextRunAt($schedule) : null,
        ];
    }

    private function summary(array $manifest, string $filename, int $size): array
    {
        $trigger = in_array($manifest['trigger'] ?? null, ['manual', 'scheduled'], true)
            ? $manifest['trigger'] : 'legacy';
        return [
            'recoveryPointId' => $manifest['recoveryPointId'], 'filename' => $filename,
            'createdAt' => $manifest['createdAt'], 'size' => $size,
            'applicationVersion' => $manifest['application']['version'], 'formatVersion' => $manifest['formatVersion'],
            'files' => count($manifest['files']),
            'verification' => 'valid', 'authenticity' => 'valid',
            'trigger' => $trigger, 'type' => $trigger, 'createdBy' => $manifest['createdBy'] ?? null,
        ];
    }

    private function schedule(): array
    {
        $schedule = ($this->scheduleProvider)();
        if (!is_array($schedule)) throw new RuntimeException('Backup schedule is unavailable.');
        BackupSchedule::validate($schedule);
        return $schedule;
    }

    private function applyRetention(int $retention, string $newestPath): void
    {
        $valid = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . 'backup-*.zip') ?: [] as $path) {
            if (!is_file($path)) continue;
            try {
                $manifest = $this->manager->verify($path, true);
                $valid[] = [
                    'path' => $path,
                    'createdAt' => $manifest['createdAt'],
                    'newest' => $path === $newestPath,
                ];
            } catch (Throwable $exception) {
                // Invalid/unrelated artifacts are never deleted by retention.
            }
        }
        usort($valid, static function (array $left, array $right): int {
            if ($left['newest'] !== $right['newest']) return $left['newest'] ? -1 : 1;
            return strcmp($right['createdAt'], $left['createdAt']);
        });
        foreach (array_slice($valid, $retention) as $expired) {
            $basename = basename($expired['path']);
            if (preg_match('/^backup-(?:pre-change-)?[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}\.zip$/', $basename) === 1) {
                @unlink($expired['path']);
            }
        }
    }

    private function statusPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . '.schedule-status.json';
    }

    private function writeScheduleStatus(string $status, ?array $backup, ?string $errorCode): void
    {
        try {
            JsonFileStore::save($this->statusPath(), [
                'version' => 1,
                'attemptedAt' => gmdate(DATE_ATOM),
                'status' => $status,
                'recoveryPointId' => $backup['recoveryPointId'] ?? null,
                'errorCode' => $errorCode,
            ]);
        } catch (Throwable $exception) {
            // The primary backup result must not be replaced by status telemetry failure.
        }
    }

    private function readScheduleStatus(): ?array
    {
        try {
            $status = JsonFileStore::load($this->statusPath());
            if (($status['version'] ?? null) !== 1
                || !in_array($status['status'] ?? null, ['success', 'failed'], true)) return null;
            return $status;
        } catch (Throwable $exception) { return null; }
    }

    private function nextRunAt(array $schedule): string
    {
        $now = new DateTimeImmutable('now');
        [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));
        if ($schedule['frequency'] === 'hourly') {
            $next = $now->setTime((int)$now->format('H'), $minute);
            if ($next <= $now) $next = $next->modify('+1 hour');
        } elseif ($schedule['frequency'] === 'weekly') {
            $next = $now->modify('next monday')->setTime($hour, $minute);
        } else {
            $next = $now->setTime($hour, $minute);
            if ($next <= $now) $next = $next->modify('+1 day');
        }
        return $next->format(DATE_ATOM);
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
