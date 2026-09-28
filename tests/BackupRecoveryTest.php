<?php

require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Authorization/RoleModel.php';
require_once __DIR__ . '/../app/Backup/BackupRecoveryService.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../core/JsonFileStore.php';

function backupAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function backupFailure(callable $operation, string $message): Throwable
{
    try { $operation(); } catch (Throwable $exception) { return $exception; }
    throw new RuntimeException($message);
}
function backupRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}
function backupWriteFixture(string $path, array $value): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    JsonFileStore::save($path, $value);
}
function backupRewriteZip(string $path, callable $change): void
{
    $entries = SafeZipArchive::read($path);
    $change($entries);
    unlink($path);
    SafeZipArchive::create($path, $entries);
}
function backupCanonicalJson(array $value): string
{
    $sort = function ($item) use (&$sort) {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $sort($child);
        return $item;
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function backupCreateLegacyV2(string $source, string $target, string $encodedSigningKey): void
{
    $entries = SafeZipArchive::read($source);
    $manifest = json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $manifest['formatVersion'] = 2;
    unset($manifest['trigger'], $manifest['createdBy']);
    $manifestContents = backupCanonicalJson($manifest) . PHP_EOL;
    $key = base64_decode(trim($encodedSigningKey), true);
    backupAssert(is_string($key) && strlen($key) === 32, 'Legacy fixture signing key is invalid.');
    $entries['manifest.json'] = $manifestContents;
    $entries['signature.json'] = json_encode([
        'version' => 1,
        'algorithm' => 'HMAC-SHA256',
        'manifestSha256' => hash('sha256', $manifestContents),
        'signature' => hash_hmac('sha256', $manifestContents, $key),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    SafeZipArchive::create($target, $entries);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-backup-recovery-' . bin2hex(random_bytes(8));
$applicationRoot = $directory . '/application';
$backupParent = $applicationRoot . '/backups';
$runtimePath = $applicationRoot . '/config';
$databasePath = $applicationRoot . '/database/config/database.json';
$key = base64_encode(random_bytes(32));
$wrongKey = base64_encode(random_bytes(32));
$oldKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
$oldSigningKey = getenv(BackupSigningKey::ENVIRONMENT_VARIABLE);
$oldSigningPath = getenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE);
$oldRuntimeDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');

try {
    mkdir($runtimePath, 0700, true);
    mkdir(dirname($databasePath), 0700, true);
    mkdir($backupParent, 0700, true);
    file_put_contents($applicationRoot . '/config/app.php', "<?php return ['version' => 'test'];\n");
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    putenv(BackupSigningKey::ENVIRONMENT_VARIABLE);
    putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE);

    $passwordHash = password_hash('fake-user-password', PASSWORD_DEFAULT);
    $apiSecretHash = password_hash('fake-api-secret', PASSWORD_DEFAULT);
    backupWriteFixture($runtimePath . '/auth.json', ['version' => 4, 'users' => [[
        'id' => str_repeat('a', 32), 'username' => 'Backup.Admin', 'passwordHash' => $passwordHash,
        'enabled' => true, 'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR, 'frontendAccess' => true,
        'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR, 'createdAt' => gmdate(DATE_ATOM), 'authVersion' => 7,
    ]]]);
    backupWriteFixture($runtimePath . '/installation.json', ['version' => 1, 'installationId' => str_repeat('b', 64), 'initialized' => true]);
    backupWriteFixture($runtimePath . '/admin.json', AdminConfigurationRepository::defaults());
    backupWriteFixture($runtimePath . '/authorization.json', RuntimeConfiguration::authorizationDefaults());
    backupWriteFixture($runtimePath . '/api-keys.json', ['version' => 3, 'keys' => [[
        'id' => str_repeat('c', 16), 'name' => 'Backup key', 'ownerUserId' => str_repeat('a', 32),
        'roles' => [RoleModel::READ_ONLY], 'secretHash' => $apiSecretHash, 'fingerprint' => str_repeat('d', 12),
        'enabled' => true, 'revokedAt' => null, 'createdAt' => gmdate(DATE_ATOM), 'lastUsedAt' => null,
    ]]]);
    backupWriteFixture($runtimePath . '/database-state.json', ['version' => 1, 'available' => true, 'updatedAt' => gmdate(DATE_ATOM)]);
    backupWriteFixture($runtimePath . '/application-runtime-state.json', ['version' => 1, 'generation' => 7, 'services' => []]);
    $database = ['provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'backup-db.internal', 'port' => '1433',
        'database' => 'BackupTest', 'authentication' => 'sql', 'username' => 'backup_user', 'password' => 'fake-database-password',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false]];
    backupWriteFixture($databasePath, (new DatabaseCredentialEncryption())->encryptConfiguration($database));
    mkdir($applicationRoot . '/sessions', 0700, true);
    file_put_contents($applicationRoot . '/sessions/sess_fake', 'authenticated-session-data');

    $sources = [
        'config/auth.json' => $runtimePath . '/auth.json', 'config/installation.json' => $runtimePath . '/installation.json',
        'config/admin.json' => $runtimePath . '/admin.json', 'config/authorization.json' => $runtimePath . '/authorization.json',
        'config/api-keys.json' => $runtimePath . '/api-keys.json', 'database/config/database.json' => $databasePath,
    ];
    $manager = new ApplicationBackupManager($applicationRoot, $sources, 'test-version');
    $bundlePath = $backupParent . '/backup-one.zip';
    $manifest = $manager->create($bundlePath);
    backupAssert($manifest['formatVersion'] === 3 && count($manifest['files']) === 6, 'ZIP backup manifest is incomplete.');
    backupAssert($manifest['trigger'] === 'manual' && $manifest['createdBy'] === 'user', 'Manual backup metadata is incorrect.');
    backupAssert(preg_match('/^backup-|\.zip$/', basename($bundlePath)) === 1, 'ZIP filename is invalid.');
    $entries = SafeZipArchive::read($bundlePath);
    backupAssert(array_keys($entries) === ['manifest.json', 'signature.json', ...array_keys($sources)], 'ZIP contains missing or unexpected entries.');
    backupAssert($manager->verify($bundlePath)['recoveryPointId'] === $manifest['recoveryPointId'], 'Generated ZIP did not re-verify.');
    $signature = json_decode($entries['signature.json'], true, 512, JSON_THROW_ON_ERROR);
    backupAssert($signature['algorithm'] === 'HMAC-SHA256' && strlen($signature['signature']) === 64, 'Cryptographic signature is missing.');
    foreach ($manifest['files'] as $file) {
        backupAssert(strlen($entries[$file['path']]) === $file['size'] && hash_equals(hash('sha256', $entries[$file['path']]), $file['sha256']), 'Manifest integrity metadata is incorrect.');
    }
    foreach ([$key, 'fake-user-password', 'fake-api-secret', 'fake-database-password', $passwordHash, $apiSecretHash] as $secret) {
        backupAssert(!str_contains($entries['manifest.json'] . $entries['signature.json'], $secret), 'ZIP metadata exposed secret material.');
    }
    backupAssert(str_contains($entries['database/config/database.json'], '"encrypted": true'), 'Database configuration is not encrypted.');
    foreach (['fake-database-password', 'backup-db.internal', 'backup_user'] as $secret) backupAssert(!str_contains($entries['database/config/database.json'], $secret), 'Database backup exposed plaintext.');
    backupAssert(!isset($entries['sessions/sess_fake'], $entries['runtime/secrets/database-encryption.key'], $entries['config/database-state.json'], $entries['config/application-runtime-state.json']), 'Excluded state entered the ZIP.');
    backupAssert(in_array('backup_signing_key', $manifest['excluded'], true) && in_array('sql_server_data', $manifest['excluded'], true), 'Manifest exclusions are incomplete.');
    if (PHP_OS_FAMILY !== 'Windows') backupAssert((fileperms($bundlePath) & 0777) === 0600, 'ZIP permissions are not owner-only.');

    $signingPath = $applicationRoot . '/runtime/secrets/backup-signing.key';
    $signingContents = file_get_contents($signingPath);
    unlink($signingPath);
    backupFailure(fn () => $manager->verify($bundlePath), 'Backup verified without its signing key.');
    file_put_contents($signingPath, $signingContents); chmod($signingPath, 0600);
    putenv(BackupSigningKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    backupFailure(fn () => $manager->verify($bundlePath), 'Backup verified with the wrong signing key.');
    putenv(BackupSigningKey::ENVIRONMENT_VARIABLE);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    backupFailure(fn () => $manager->verify($bundlePath, true), 'Backup verified without its encryption key.');
    backupAssert($manager->verify($bundlePath, false)['formatVersion'] === 3, 'Integrity/authenticity verification incorrectly required the encryption key.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $wrongKey);
    backupFailure(fn () => $manager->verify($bundlePath, true), 'Backup verified with the wrong encryption key.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    $tamperedFile = $backupParent . '/tampered-file.zip'; copy($bundlePath, $tamperedFile);
    backupRewriteZip($tamperedFile, function (array &$value): void { $value['config/admin.json'] .= " "; });
    backupFailure(fn () => $manager->verify($tamperedFile), 'Modified backup file was accepted.');
    $tamperedManifest = $backupParent . '/tampered-manifest.zip'; copy($bundlePath, $tamperedManifest);
    backupRewriteZip($tamperedManifest, function (array &$value): void { $value['manifest.json'] = str_replace('test-version', 'evil-version', $value['manifest.json']); });
    backupFailure(fn () => $manager->verify($tamperedManifest), 'Modified manifest was accepted.');
    $tamperedSignature = $backupParent . '/tampered-signature.zip'; copy($bundlePath, $tamperedSignature);
    backupRewriteZip($tamperedSignature, function (array &$value): void { $signature = json_decode($value['signature.json'], true); $signature['signature'] = str_repeat('0', 64); $value['signature.json'] = json_encode($signature); });
    backupFailure(fn () => $manager->verify($tamperedSignature), 'Invalid signature was accepted.');
    $missingSignature = $backupParent . '/missing-signature.zip'; copy($bundlePath, $missingSignature);
    backupRewriteZip($missingSignature, function (array &$value): void { unset($value['signature.json']); });
    backupFailure(fn () => $manager->verify($missingSignature), 'Missing signature was accepted.');
    $unexpected = $backupParent . '/unexpected.zip'; copy($bundlePath, $unexpected);
    backupRewriteZip($unexpected, function (array &$value): void { $value['unexpected.json'] = '{}'; });
    backupFailure(fn () => $manager->verify($unexpected), 'Unexpected ZIP entry was accepted.');
    $missing = $backupParent . '/missing.zip'; copy($bundlePath, $missing);
    backupRewriteZip($missing, function (array &$value): void { unset($value['config/authorization.json']); });
    backupFailure(fn () => $manager->verify($missing), 'Missing configuration entry was accepted.');
    $corrupt = $backupParent . '/corrupt.zip'; file_put_contents($corrupt, 'not-a-zip');
    backupFailure(fn () => $manager->verify($corrupt), 'Corrupt ZIP was accepted.');
    foreach (['../escape.json', '/absolute.json', 'C:/windows.json', '//server/share.json', 'dir\\evil.json'] as $unsafe) {
        backupFailure(fn () => SafeZipArchive::create($backupParent . '/unsafe-' . md5($unsafe) . '.zip', [$unsafe => '{}']), "Unsafe ZIP entry {$unsafe} was accepted.");
    }
    backupFailure(fn () => SafeZipArchive::create($backupParent . '/duplicate-too-large.zip', ['large.bin' => str_repeat('x', SafeZipArchive::MAX_ENTRY_BYTES + 1)]), 'Oversized ZIP entry was accepted.');

    $preview = $manager->preview($bundlePath);
    backupAssert($preview['verification'] === 'valid' && $preview['authenticity'] === 'valid'
        && $preview['configurationFiles'] === 6 && $preview['filesChanging'] === 0
        && $preview['changedFiles'] === [] && $preview['changes'] === [], 'Restore preview is invalid.');
    $restorePath = $backupParent . '/restore-stage';
    $restore = $manager->stageRestore($bundlePath, $restorePath);
    backupAssert($restore['ready'] && $restore['files'] === 6 && is_file($restorePath . '/signature.json'), 'ZIP restore staging failed.');
    backupAssert(!is_dir($restorePath . '/sessions') && !is_file($restorePath . '/config/database-state.json'), 'Restore staging revived excluded state.');

    $changedAdmin = AdminConfigurationRepository::defaults();
    $changedAdmin['server']['adminPort'] = 8099;
    backupWriteFixture($runtimePath . '/admin.json', $changedAdmin);
    $changedPreview = $manager->preview($bundlePath);
    backupAssert($changedPreview['filesChanging'] === 1
        && $changedPreview['changedFiles'] === ['config/admin.json']
        && $changedPreview['changes'] === [['path' => 'config/admin.json', 'type' => 'modified']], 'Preview did not report exact changed configuration metadata.');
    $previewMetadata = json_encode($changedPreview['changes'], JSON_THROW_ON_ERROR);
    foreach (['fake-user-password', 'fake-api-secret', 'fake-database-password', $key, $signingContents] as $secret) {
        backupAssert(!str_contains($previewMetadata, $secret), 'Restore preview change metadata exposed secret material.');
    }
    $activated = $manager->activateRestore($bundlePath, static fn (): bool => true);
    backupAssert($activated['restored'] && JsonFileStore::load($runtimePath . '/admin.json')['server']['adminPort'] !== 8099, 'Atomic restore activation failed.');
    $changedAdmin['server']['adminPort'] = 8098;
    backupWriteFixture($runtimePath . '/admin.json', $changedAdmin);
    backupFailure(fn () => $manager->activateRestore($bundlePath, static fn (): bool => false), 'Failed health check did not fail restore.');
    backupAssert(JsonFileStore::load($runtimePath . '/admin.json')['server']['adminPort'] === 8098, 'Failed restore did not recover the previous configuration.');

    $managedDirectory = $backupParent . '/managed';
    $logDirectory = $directory . '/logs';
    $operationalDirectory = $directory . '/operational-logs';
    $operationalLogger = new OperationalLogger($operationalDirectory);
    $schedule = ['enabled' => true, 'frequency' => 'daily', 'time' => '02:00', 'retention' => 30];
    $scheduleProvider = static function () use (&$schedule): array { return $schedule; };
    $service = new BackupRecoveryService($manager, $managedDirectory, new Logger($logDirectory), static fn (): bool => true, $operationalLogger, $scheduleProvider);
    $created = $service->create();
    backupAssert(str_ends_with($created['filename'], '.zip') && $created['verification'] === 'valid' && $created['authenticity'] === 'valid', 'Admin backup orchestration did not create a verified recovery point.');
    backupAssert($created['trigger'] === 'manual' && $created['createdBy'] === 'user' && $created['type'] === 'manual', 'Manual recovery-point metadata is incorrect.');
    $legacyPath = $managedDirectory . '/backup-' . $manifest['recoveryPointId'] . '.zip';
    backupCreateLegacyV2($bundlePath, $legacyPath, $signingContents);
    $history = $service->history();
    backupAssert(count($history) === 2, 'Recovery-point history is incomplete.');
    $legacy = array_values(array_filter($history, static fn (array $point): bool => $point['type'] === 'legacy'));
    backupAssert(count($legacy) === 1 && $legacy[0]['formatVersion'] === 2, 'Valid version-2 recovery point was not represented as Legacy.');
    $download = $service->download($created['recoveryPointId']);
    backupAssert($download['mediaType'] === 'application/zip' && base64_decode($download['archive'], true) !== false, 'Backup download payload is invalid.');
    $previewUpload = $service->previewUpload('selected-backup.zip', $download['archive']);
    backupAssert($previewUpload['verification'] === 'valid' && preg_match('/^[a-f0-9]{48}$/', $previewUpload['uploadToken']) === 1, 'Uploaded ZIP was not verified and previewed.');
    $confirmationFailure = backupFailure(fn () => $service->restore($previewUpload['uploadToken'], false), 'Restore ran without explicit confirmation.');
    backupAssert($confirmationFailure instanceof ApiRequestException && $confirmationFailure->getErrorCode() === 'RESTORE_CONFIRMATION_REQUIRED', 'Restore confirmation failure was not sanitized.');
    $previewUpload = $service->previewUpload('selected-backup.zip', $download['archive']);
    $restoreResult = $service->restore($previewUpload['uploadToken'], true);
    backupAssert($restoreResult['restored'] === true && $restoreResult['health'] === 'healthy', 'Confirmed Admin restore did not activate safely.');
    $missingUploadFailure = backupFailure(fn () => $service->restore(str_repeat('f', 48), true), 'Missing staged restore upload was accepted.');
    backupAssert($missingUploadFailure instanceof ApiRequestException && $missingUploadFailure->getErrorCode() === 'RESTORE_UPLOAD_UNAVAILABLE', 'Missing staged restore returned the wrong structured error.');
    $beforeFailedRestore = AdminConfigurationRepository::defaults();
    $beforeFailedRestore['server']['adminPort'] = 8097;
    backupWriteFixture($runtimePath . '/admin.json', $beforeFailedRestore);
    $failedRestoreService = new BackupRecoveryService($manager, $managedDirectory, new Logger($logDirectory), static fn (): array => [
        'healthy' => false,
        'check' => 'configuration.load',
        'errorCode' => 'CONFIGURATION_INVALID',
        'reason' => 'configuration_invalid',
    ], $operationalLogger, $scheduleProvider);
    $failedPreview = $failedRestoreService->previewUpload('selected-backup.zip', $download['archive']);
    $healthFailure = backupFailure(fn () => $failedRestoreService->restore($failedPreview['uploadToken'], true), 'Post-restore health failure was reported as success.');
    backupAssert($healthFailure instanceof ApiRequestException && $healthFailure->getErrorCode() === 'RESTORE_FAILED', 'Post-restore health failure was not sanitized.');
    backupAssert(JsonFileStore::load($runtimePath . '/admin.json')['server']['adminPort'] === 8097, 'Failed restore did not preserve the complete previous configuration.');
    $operationalRestoreLog = (string)file_get_contents($operationalDirectory . '/admin/' . date('Y-m-d') . '.txt');
    foreach (['admin.restore.preview.start', 'admin.restore.preview.success', 'admin.restore.confirm.submitted',
        'admin.restore.start', 'admin.restore.activation.start', 'admin.restore.activation.success',
        'admin.restore.activation.failed', 'admin.restore.health_check.start',
        'admin.restore.health_check.success', 'admin.restore.health_check.failed'] as $event) {
        backupAssert(str_contains($operationalRestoreLog, $event), "Restore operational event {$event} is missing.");
    }
    foreach (['Check: configuration.load', 'Error Code: CONFIGURATION_INVALID', 'Reason: configuration_invalid', 'Rollback: preserved'] as $diagnostic) {
        backupAssert(str_contains($operationalRestoreLog, $diagnostic), "Restore diagnostic {$diagnostic} is missing.");
    }
    foreach (['fake-user-password', 'fake-api-secret', 'fake-database-password', $key, $signingContents] as $secret) {
        backupAssert(!str_contains($operationalRestoreLog, $secret), 'Restore diagnostics exposed secret material.');
    }

    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $runtimePath);
    $beforeMutations = count(glob($managedDirectory . '/backup-*.zip') ?: []);
    for ($index = 0; $index < 5; $index++) {
        (new AuthRepository())->update(function (array &$configuration) use ($index, $passwordHash): void {
            $configuration['users'][] = [
                'id' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT),
                'username' => 'Created.User.' . $index,
                'passwordHash' => $passwordHash,
                'enabled' => true,
                'backendRole' => RoleModel::READ_ONLY,
                'frontendAccess' => false,
                'frontendRole' => null,
                'createdAt' => gmdate(DATE_ATOM),
                'authVersion' => 1,
            ];
        });
    }
    $authorizationRepository = new AuthorizationRepository();
    $authorizationRepository->save($authorizationRepository->load());
    (new ApiKeyRepository())->update(function (array &$configuration) use ($apiSecretHash): void {
        $configuration['keys'][] = [
            'id' => str_repeat('e', 16), 'name' => 'Created key', 'ownerUserId' => str_repeat('a', 32),
            'roles' => [RoleModel::READ_ONLY], 'secretHash' => $apiSecretHash, 'fingerprint' => str_repeat('f', 12),
            'enabled' => true, 'revokedAt' => null, 'createdAt' => gmdate(DATE_ATOM), 'lastUsedAt' => null,
        ];
    });
    (new ApiKeyRepository())->update(function (array &$configuration): void {
        $configuration['keys'] = array_values(array_filter(
            $configuration['keys'], static fn (array $key): bool => $key['id'] !== str_repeat('e', 16)
        ));
    });
    (new AdminConfigurationRepository())->update(static function (array &$configuration): void {
        $configuration['cors']['credentialsEnabled'] = !$configuration['cors']['credentialsEnabled'];
    });
    backupAssert(count(glob($managedDirectory . '/backup-*.zip') ?: []) === $beforeMutations, 'Configuration mutations created automatic recovery points.');
    foreach (['AuthRepository.php', 'AuthorizationRepository.php', 'ApiKeyRepository.php', 'AdminConfigurationRepository.php'] as $sourceFile) {
        $sourceContents = (string)file_get_contents(__DIR__ . '/../app/Repositories/' . $sourceFile);
        backupAssert(!str_contains($sourceContents, 'ConfigurationMutationBackup')
            && !str_contains($sourceContents, 'BackupRecoveryService'), "{$sourceFile} retains an automatic backup hook.");
    }
    backupAssert(!str_contains((string)file_get_contents(__DIR__ . '/../app/Services/AdminService.php'), 'ConfigurationMutationBackup'), 'Database configuration retains an automatic backup hook.');
    $oldRuntimeDirectory === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntimeDirectory);

    $scheduled = $service->createScheduled();
    backupAssert($scheduled['trigger'] === 'scheduled' && $scheduled['createdBy'] === 'scheduler'
        && $scheduled['type'] === 'scheduled' && $scheduled['verification'] === 'valid', 'Scheduled backup metadata or verification is incorrect.');
    $scheduleInformation = $service->scheduleInformation();
    backupAssert($scheduleInformation['lastScheduledAttempt']['status'] === 'success'
        && is_string($scheduleInformation['nextBackupAt']), 'Scheduled backup status is incomplete.');
    $backupCli = (string)file_get_contents(__DIR__ . '/../scripts/application-backup.php');
    backupAssert(str_contains($backupCli, "\$operation === 'scheduled-create'")
        && str_contains($backupCli, '->createScheduled()'), 'Scheduled backup CLI does not reuse the recovery service.');

    $schedule['retention'] = 2;
    $retained = $service->createScheduled();
    $validHistory = array_values(array_filter($service->history(), static fn (array $point): bool => $point['verification'] === 'valid'));
    backupAssert(count($validHistory) === 2
        && in_array($retained['recoveryPointId'], array_column($validHistory, 'recoveryPointId'), true), 'Retention did not preserve the newest backup or configured count.');
    file_put_contents($managedDirectory . '/backup-unrelated.zip', 'not a managed backup');
    $service->createScheduled();
    backupAssert(is_file($managedDirectory . '/backup-unrelated.zip'), 'Retention deleted an unrelated file.');

    $validator = new AdminRequestValidator();
    foreach (['admin.backup.history', 'admin.backup.create', 'admin.backup.schedule'] as $action) backupAssert($validator->validate(['action' => $action])['action'] === $action, "Admin backup action {$action} was rejected.");
    $validatedSchedule = $validator->validate(['action' => 'admin.backup.schedule.save', 'backup' => $schedule]);
    backupAssert($validatedSchedule['backup'] === $schedule, 'Valid backup schedule was rejected.');
    foreach ([
        [...$schedule, 'frequency' => 'monthly'],
        [...$schedule, 'time' => '25:00'],
        [...$schedule, 'retention' => 0],
        [...$schedule, 'enabled' => 'yes'],
        [...$schedule, 'unexpected' => true],
    ] as $invalidSchedule) backupFailure(fn () => $validator->validate([
        'action' => 'admin.backup.schedule.save', 'backup' => $invalidSchedule,
    ]), 'Invalid backup schedule was accepted.');
    backupAssert($validator->validate(['action' => 'admin.backup.restore', 'uploadToken' => str_repeat('a', 48), 'confirmed' => true])['confirmed'] === true, 'Confirmed restore request was rejected.');
    backupFailure(fn () => $validator->validate(['action' => 'admin.backup.restore', 'uploadToken' => str_repeat('a', 48), 'confirmed' => false]), 'Unconfirmed restore request was accepted.');
    backupAssert($validator->validate([
        'action' => 'admin.operational.event', 'event' => 'frontend.restore.confirmed',
        'page' => 'backup-recovery', 'operation' => 'restore',
    ])['event'] === 'frontend.restore.confirmed', 'Allowlisted restore frontend event was rejected.');
    backupAssert($validator->validate([
        'action' => 'admin.operational.event', 'event' => 'frontend.restore.completed',
        'page' => 'backup-recovery', 'operation' => 'restore', 'requestId' => 'safe-request-id',
    ])['event'] === 'frontend.restore.completed', 'Restore completion event was rejected.');
    backupFailure(fn () => $validator->validate([
        'action' => 'admin.operational.event', 'event' => 'frontend.unrestricted.payload',
    ]), 'Unrestricted frontend log event was accepted.');
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['GENERIC_AUTH_PROVIDER']);
    foreach (['admin.backup.create', 'admin.backup.schedule.save', 'admin.backup.download', 'admin.backup.preview', 'admin.backup.restore', 'admin.operational.event'] as $action) {
        $failure = backupFailure(fn () => (new CsrfProtectionMiddleware())->handle(['action' => $action]), "{$action} bypassed CSRF protection.");
        backupAssert($failure instanceof ApiRequestException && $failure->getErrorCode() === 'CSRF_VALIDATION_FAILED', "{$action} returned the wrong CSRF error.");
    }
    $auditBrokenSources = $sources;
    $auditBrokenSources['config/auth.json'] = $applicationRoot . '/missing-for-audit.json';
    $auditBrokenService = new BackupRecoveryService(
        new ApplicationBackupManager($applicationRoot, $auditBrokenSources, 'test-version'),
        $managedDirectory,
        new Logger($logDirectory),
        static fn (): bool => true,
        $operationalLogger,
        $scheduleProvider
    );
    $pointsBeforeFailure = count($auditBrokenService->history());
    backupFailure(fn () => $auditBrokenService->createScheduled(), 'Failed scheduled backup was reported as successful.');
    backupAssert(count($auditBrokenService->history()) === $pointsBeforeFailure
        && $auditBrokenService->scheduleInformation()['lastScheduledAttempt']['status'] === 'failed', 'Failed scheduled backup created a recovery point or omitted failure status.');
    $audit = implode("\n", array_map(static fn (string $path): string => (string)file_get_contents($path), glob($logDirectory . '/*.log') ?: []));
    foreach (['backup.created', 'backup.verified', 'backup.failed', 'backup.scheduled.failed', 'restore.previewed', 'restore.started', 'restore.completed'] as $event) backupAssert(str_contains($audit, '"event":"' . $event . '"'), "Audit event {$event} is missing.");
    backupAssert(!str_contains($audit, 'backup.pre_change'), 'Obsolete pre-change backup audit event was emitted.');
    foreach (['fake-user-password', 'fake-api-secret', 'fake-database-password', $key, $signingContents] as $secret) backupAssert(!str_contains($audit, $secret), 'Backup audit log exposed secret material.');

    $validAuth = JsonFileStore::load($runtimePath . '/auth.json');
    $invalidAuth = $validAuth; $invalidAuth['version'] = 999;
    backupWriteFixture($runtimePath . '/auth.json', $invalidAuth);
    backupFailure(fn () => $manager->create($backupParent . '/invalid-schema.zip'), 'Invalid configuration schema was backed up.');
    backupWriteFixture($runtimePath . '/auth.json', $validAuth);
    $encryptedDatabase = JsonFileStore::load($databasePath);
    backupWriteFixture($databasePath, $database);
    backupFailure(fn () => $manager->create($backupParent . '/plaintext-database.zip'), 'Plaintext database credentials were backed up.');
    backupWriteFixture($databasePath, $encryptedDatabase);

    $brokenSources = $sources; $brokenSources['config/auth.json'] = $applicationRoot . '/missing-auth.json';
    $brokenManager = new ApplicationBackupManager($applicationRoot, $brokenSources, 'test-version');
    $failedBundle = $backupParent . '/backup-failed.zip';
    backupFailure(fn () => $brokenManager->create($failedBundle), 'Backup with a missing source succeeded.');
    backupAssert(!file_exists($failedBundle) && glob($backupParent . '/.backup-failed.zip.tmp.*') === [], 'Failed backup left temporary artifacts.');
    backupFailure(fn () => $manager->create($directory . '/outside-backup.zip'), 'Backup was allowed outside the project backup directory.');
    backupAssert(!file_exists($directory . '/outside-backup.zip'), 'Rejected external backup path created an artifact.');
    backupFailure(fn () => $manager->create($backupParent . '/../traversal-backup.zip'), 'Backup path traversal escaped the project backup directory.');
    backupAssert(!file_exists($applicationRoot . '/traversal-backup.zip'), 'Rejected traversal path created an artifact.');
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('symlink')) {
        $symlinkApplication = $directory . '/symlink-application';
        $symlinkTarget = $directory . '/symlink-target';
        mkdir($symlinkApplication, 0700, true);
        mkdir($symlinkTarget, 0700, true);
        symlink($symlinkTarget, $symlinkApplication . '/backups');
        $symlinkManager = new ApplicationBackupManager($symlinkApplication, $sources, 'test-version');
        backupFailure(fn () => $symlinkManager->create($symlinkApplication . '/backups/escaped.zip'), 'Symlinked backup root escaped the project directory.');
        backupAssert(!file_exists($symlinkTarget . '/escaped.zip'), 'Rejected symlink escape created an artifact.');
    }

    echo "Backup and recovery tests passed.\n";
} finally {
    $oldKey === false ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE) : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    $oldSigningKey === false ? putenv(BackupSigningKey::ENVIRONMENT_VARIABLE) : putenv(BackupSigningKey::ENVIRONMENT_VARIABLE . '=' . $oldSigningKey);
    $oldSigningPath === false ? putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE) : putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE . '=' . $oldSigningPath);
    $oldRuntimeDirectory === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntimeDirectory);
    backupRemoveDirectory($directory);
}
