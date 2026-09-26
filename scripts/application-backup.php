<?php

require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';

$operation = $argv[1] ?? '';
$source = $argv[2] ?? '';
$target = $argv[3] ?? '';

try {
    $manager = new ApplicationBackupManager();
    if ($operation === 'create' && $source !== '' && $target === '') {
        $manifest = $manager->create($source);
        echo 'BACKUP_READY recoveryPoint=' . $manifest['recoveryPointId'] . ' files=' . count($manifest['files']) . PHP_EOL;
        exit(0);
    }
    if ($operation === 'verify' && $source !== '' && $target === '') {
        $manifest = $manager->verify($source, true);
        echo 'BACKUP_VALID recoveryPoint=' . $manifest['recoveryPointId'] . ' files=' . count($manifest['files']) . PHP_EOL;
        exit(0);
    }
    if ($operation === 'stage-restore' && $source !== '' && $target !== '') {
        $result = $manager->stageRestore($source, $target);
        echo 'RESTORE_STAGED files=' . $result['files'] . PHP_EOL;
        exit(0);
    }
    throw new InvalidArgumentException(
        'Usage: php scripts/application-backup.php create|verify <absolute-backup.zip> '
        . 'or stage-restore <absolute-backup.zip> <absolute-new-target>'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
