<?php

require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Authorization/RoleModel.php';
require_once __DIR__ . '/../app/Backup/BackupRecoveryService.php';
require_once __DIR__ . '/../app/Backup/ConfigurationMutationBackup.php';
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

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-backup-recovery-' . bin2hex(random_bytes(8));
$applicationRoot = $directory . '/application';
$backupParent = $directory . '/protected-backups';
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
    backupAssert($manifest['formatVersion'] === 2 && count($manifest['files']) === 6, 'ZIP backup manifest is incomplete.');
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
    backupAssert($manager->verify($bundlePath, false)['formatVersion'] === 2, 'Integrity/authenticity verification incorrectly required the encryption key.');
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
    backupAssert($preview['verification'] === 'valid' && $preview['authenticity'] === 'valid' && $preview['changedFiles'] === [], 'Restore preview is invalid.');
    $restorePath = $backupParent . '/restore-stage';
    $restore = $manager->stageRestore($bundlePath, $restorePath);
    backupAssert($restore['ready'] && $restore['files'] === 6 && is_file($restorePath . '/signature.json'), 'ZIP restore staging failed.');
    backupAssert(!is_dir($restorePath . '/sessions') && !is_file($restorePath . '/config/database-state.json'), 'Restore staging revived excluded state.');

    $changedAdmin = AdminConfigurationRepository::defaults();
    $changedAdmin['server']['adminPort'] = 8099;
    backupWriteFixture($runtimePath . '/admin.json', $changedAdmin);
    backupAssert(in_array('config/admin.json', $manager->preview($bundlePath)['changedFiles'], true), 'Preview did not report changed configuration.');
    $activated = $manager->activateRestore($bundlePath, static fn (): bool => true);
    backupAssert($activated['restored'] && JsonFileStore::load($runtimePath . '/admin.json')['server']['adminPort'] !== 8099, 'Atomic restore activation failed.');
    $changedAdmin['server']['adminPort'] = 8098;
    backupWriteFixture($runtimePath . '/admin.json', $changedAdmin);
    backupFailure(fn () => $manager->activateRestore($bundlePath, static fn (): bool => false), 'Failed health check did not fail restore.');
    backupAssert(JsonFileStore::load($runtimePath . '/admin.json')['server']['adminPort'] === 8098, 'Failed restore did not recover the previous configuration.');

    $managedDirectory = $backupParent . '/managed';
    $logDirectory = $directory . '/logs';
    $service = new BackupRecoveryService($manager, $managedDirectory, new Logger($logDirectory), static fn (): bool => true);
    $created = $service->create();
    backupAssert(str_ends_with($created['filename'], '.zip') && $created['verification'] === 'valid' && $created['authenticity'] === 'valid', 'Admin backup orchestration did not create a verified recovery point.');
    $history = $service->history();
    backupAssert(count($history) === 1 && $history[0]['recoveryPointId'] === $created['recoveryPointId'], 'Recovery-point history is incomplete.');
    $download = $service->download($created['recoveryPointId']);
    backupAssert($download['mediaType'] === 'application/zip' && base64_decode($download['archive'], true) !== false, 'Backup download payload is invalid.');
    $previewUpload = $service->previewUpload('selected-backup.zip', $download['archive']);
    backupAssert($previewUpload['verification'] === 'valid' && preg_match('/^[a-f0-9]{48}$/', $previewUpload['uploadToken']) === 1, 'Uploaded ZIP was not verified and previewed.');
    $confirmationFailure = backupFailure(fn () => $service->restore($previewUpload['uploadToken'], false), 'Restore ran without explicit confirmation.');
    backupAssert($confirmationFailure instanceof ApiRequestException && $confirmationFailure->getErrorCode() === 'RESTORE_CONFIRMATION_REQUIRED', 'Restore confirmation failure was not sanitized.');
    $previewUpload = $service->previewUpload('selected-backup.zip', $download['archive']);
    $restoreResult = $service->restore($previewUpload['uploadToken'], true);
    backupAssert($restoreResult['restored'] === true && $restoreResult['health'] === 'healthy', 'Confirmed Admin restore did not activate safely.');

    ConfigurationMutationBackup::setFactoryForTests(static fn (): BackupRecoveryService => $service);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $runtimePath);
    $preChangeBefore = count(glob($managedDirectory . '/backup-pre-change-*.zip') ?: []);
    (new AuthRepository())->update(function (array &$configuration): void {
        $configuration['users'][0]['authVersion']++;
    });
    $preChangeAfter = count(glob($managedDirectory . '/backup-pre-change-*.zip') ?: []);
    backupAssert($preChangeAfter === $preChangeBefore + 1, 'Critical configuration mutation did not wait for a verified pre-change backup.');
    $versionBeforeBlockedMutation = JsonFileStore::load($runtimePath . '/auth.json')['users'][0]['authVersion'];
    ConfigurationMutationBackup::setFactoryForTests(static fn () => new stdClass());
    backupFailure(fn () => (new AuthRepository())->update(function (array &$configuration): void {
        $configuration['users'][0]['authVersion']++;
    }), 'Failed pre-change backup did not block the critical mutation.');
    backupAssert(JsonFileStore::load($runtimePath . '/auth.json')['users'][0]['authVersion'] === $versionBeforeBlockedMutation, 'Critical mutation was saved after pre-change backup failure.');
    ConfigurationMutationBackup::setFactoryForTests(null);
    $oldRuntimeDirectory === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntimeDirectory);

    $validator = new AdminRequestValidator();
    foreach (['admin.backup.history', 'admin.backup.create'] as $action) backupAssert($validator->validate(['action' => $action])['action'] === $action, "Admin backup action {$action} was rejected.");
    backupAssert($validator->validate(['action' => 'admin.backup.restore', 'uploadToken' => str_repeat('a', 48), 'confirmed' => true])['confirmed'] === true, 'Confirmed restore request was rejected.');
    backupFailure(fn () => $validator->validate(['action' => 'admin.backup.restore', 'uploadToken' => str_repeat('a', 48), 'confirmed' => false]), 'Unconfirmed restore request was accepted.');
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['GENERIC_AUTH_PROVIDER']);
    foreach (['admin.backup.create', 'admin.backup.download', 'admin.backup.preview', 'admin.backup.restore'] as $action) {
        $failure = backupFailure(fn () => (new CsrfProtectionMiddleware())->handle(['action' => $action]), "{$action} bypassed CSRF protection.");
        backupAssert($failure instanceof ApiRequestException && $failure->getErrorCode() === 'CSRF_VALIDATION_FAILED', "{$action} returned the wrong CSRF error.");
    }
    $auditBrokenSources = $sources;
    $auditBrokenSources['config/auth.json'] = $applicationRoot . '/missing-for-audit.json';
    $auditBrokenService = new BackupRecoveryService(
        new ApplicationBackupManager($applicationRoot, $auditBrokenSources, 'test-version'),
        $managedDirectory,
        new Logger($logDirectory),
        static fn (): bool => true
    );
    backupFailure(fn () => $auditBrokenService->create(), 'Failed Admin backup was reported as successful.');
    $audit = implode("\n", array_map(static fn (string $path): string => (string)file_get_contents($path), glob($logDirectory . '/*.log') ?: []));
    foreach (['backup.created', 'backup.verified', 'backup.failed', 'backup.pre_change', 'restore.previewed', 'restore.started', 'restore.completed'] as $event) backupAssert(str_contains($audit, '"event":"' . $event . '"'), "Audit event {$event} is missing.");
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
    backupFailure(fn () => $manager->create($applicationRoot . '/unsafe-backup.zip'), 'Backup was allowed inside the application root.');

    echo "Backup and recovery tests passed.\n";
} finally {
    ConfigurationMutationBackup::setFactoryForTests(null);
    $oldKey === false ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE) : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    $oldSigningKey === false ? putenv(BackupSigningKey::ENVIRONMENT_VARIABLE) : putenv(BackupSigningKey::ENVIRONMENT_VARIABLE . '=' . $oldSigningKey);
    $oldSigningPath === false ? putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE) : putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE . '=' . $oldSigningPath);
    $oldRuntimeDirectory === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntimeDirectory);
    backupRemoveDirectory($directory);
}
