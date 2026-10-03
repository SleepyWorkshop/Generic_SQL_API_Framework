<?php

require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';
require_once __DIR__ . '/../app/Backup/BackupRecoveryService.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

ExceptionHandler::register('admin');

try {
    if (!getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)) {
        $keyScript = __DIR__ . '/prepare-local-encryption-key.php';

        if (!is_file($keyScript)) {
            throw new RuntimeException('Local encryption key preparation is unavailable.');
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($keyScript);
        $key = trim((string) shell_exec($command));

        if ($key === '') {
            throw new RuntimeException('Local encryption key could not be prepared.');
        }

        putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    }

    $operation = $argv[1] ?? '';
    $source = $argv[2] ?? '';
    $target = $argv[3] ?? '';

    $manager = new ApplicationBackupManager();

    if ($operation === 'scheduled-create' && $source === '' && $target === '') {
        $result = (new BackupRecoveryService($manager))->createScheduled();

        echo 'BACKUP_READY recoveryPoint=' . $result['recoveryPointId']
            . ' trigger=scheduled files=' . ($result['files'] ?? 0)
            . PHP_EOL;

        exit(0);
    }

    if ($operation === 'create' && $source !== '' && $target === '') {
        $manifest = $manager->create($source);

        echo 'BACKUP_READY recoveryPoint=' . $manifest['recoveryPointId']
            . ' files=' . count($manifest['files'])
            . PHP_EOL;

        exit(0);
    }

    if ($operation === 'verify' && $source !== '' && $target === '') {
        $manifest = $manager->verify($source, true);

        echo 'BACKUP_VALID recoveryPoint=' . $manifest['recoveryPointId']
            . ' files=' . count($manifest['files'])
            . PHP_EOL;

        exit(0);
    }

    if ($operation === 'stage-restore' && $source !== '' && $target !== '') {
        $result = $manager->stageRestore($source, $target);

        echo 'RESTORE_STAGED files=' . $result['files'] . PHP_EOL;

        exit(0);
    }

    throw new InvalidArgumentException(
        'Usage: php scripts/application-backup.php scheduled-create, '
        . 'create|verify <absolute-backup.zip>, or '
        . 'stage-restore <absolute-backup.zip> <absolute-new-target>'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}