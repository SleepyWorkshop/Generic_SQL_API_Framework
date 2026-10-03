<?php

require_once __DIR__ . '/../app/Services/SetupService.php';
require_once __DIR__ . '/../app/Requests/SetupRequestValidator.php';
require_once __DIR__ . '/../core/JsonFileStore.php';

function setupAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function setupFailure(callable $operation, string $expectedCode): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        setupAssert($exception->getErrorCode() === $expectedCode, 'Unexpected setup error code.');
        return $exception;
    }
    throw new RuntimeException("Expected setup failure {$expectedCode}.");
}

function createSetupFixture(string $directory): array
{
    mkdir($directory, 0700, true);
    $authPath = $directory . DIRECTORY_SEPARATOR . 'auth.json';
    $installationPath = $directory . DIRECTORY_SEPARATOR . 'installation.json';
    $lockPath = $directory . DIRECTORY_SEPARATOR . 'installation.lock';
    $installationId = bin2hex(random_bytes(32));
    JsonFileStore::save($authPath, ['version' => 1, 'users' => []]);
    JsonFileStore::save($installationPath, [
        'version' => 1,
        'installationId' => $installationId,
        'initialized' => false,
    ]);
    $service = new SetupService(
        new AuthRepository($authPath),
        new InstallationRepository($installationPath),
        new PasswordHasher(),
        $lockPath
    );
    return [$service, $authPath, $installationPath, $lockPath, $installationId];
}

function removeSetupFixture(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-reporting-setup-test-' . bin2hex(random_bytes(8));
$mainDirectory = $root . DIRECTORY_SEPARATOR . 'main';
$concurrentDirectory = $root . DIRECTORY_SEPARATOR . 'concurrent';

try {
    mkdir($root, 0700, true);
    [$service, $authPath, $installationPath, $lockPath, $installationId] = createSetupFixture($mainDirectory);

    $status = $service->status();
    setupAssert($status === ['initialized' => false], 'Fresh installation status exposed extra data or was initialized.');

    $validator = new SetupRequestValidator();
    foreach ([
        ['request' => ['action' => 'setup.createAdmin', 'name' => 'Initial Admin', 'mobile' => '+15550000001', 'username' => "bad\nname", 'password' => 'valid-password-123', 'passwordConfirmation' => 'valid-password-123']],
        ['request' => ['action' => 'setup.createAdmin', 'name' => '', 'mobile' => '+15550000001', 'username' => 'admin', 'password' => 'valid-password-123', 'passwordConfirmation' => 'valid-password-123']],
        ['request' => ['action' => 'setup.createAdmin', 'name' => 'Initial Admin', 'mobile' => 'invalid', 'username' => 'admin', 'password' => 'valid-password-123', 'passwordConfirmation' => 'valid-password-123']],
        ['request' => ['action' => 'setup.createAdmin', 'name' => 'Initial Admin', 'mobile' => '+15550000001', 'username' => 'admin', 'password' => '', 'passwordConfirmation' => '']],
        ['request' => ['action' => 'setup.createAdmin', 'name' => 'Initial Admin', 'mobile' => '+15550000001', 'username' => 'admin', 'password' => 'short', 'passwordConfirmation' => 'short']],
        ['request' => ['action' => 'setup.createAdmin', 'name' => 'Initial Admin', 'mobile' => '+15550000001', 'username' => 'admin', 'email' => 'invalid', 'password' => 'valid-password-123', 'passwordConfirmation' => 'different-password']],
    ] as $invalid) {
        setupFailure(fn () => $validator->validate($invalid['request']), 'INVALID_SETUP_REQUEST');
    }

    $request = $validator->validate([
        'action' => 'setup.createAdmin',
        'name' => ' Initial Administrator ',
        'username' => ' Initial.Admin ',
        'mobile' => ' +1 555 000 0001 ',
        'email' => '',
        'password' => 'a secure password with spaces ',
        'passwordConfirmation' => 'a secure password with spaces ',
    ]);
    setupAssert($request['username'] === 'Initial.Admin', 'Username was not trimmed.');
    setupAssert(str_ends_with($request['password'], ' '), 'Password was silently trimmed.');

    $result = $service->createInitialAdmin($request['name'], $request['username'], $request['mobile'], $request['email'], $request['password']);
    setupAssert($result === ['initialized' => true], 'Setup result exposed extra data.');

    $authentication = (new AuthRepository($authPath))->load();
    setupAssert(count($authentication['users']) === 1, 'Initial setup did not create exactly one user.');
    $user = $authentication['users'][0];
    setupAssert($user['name'] === 'Initial Administrator', 'Initial name was not stored correctly.');
    setupAssert($user['username'] === 'Initial.Admin', 'Initial username was not stored correctly.');
    setupAssert($user['mobile'] === '+1 555 000 0001', 'Initial mobile number was not stored correctly.');
    setupAssert($user['email'] === null, 'Optional initial email was not normalized.');
    setupAssert($user['enabled'] === true, 'Initial user is not enabled.');
    setupAssert($user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR, 'Initial user is not a System Administrator.');
    setupAssert($user['frontendRole'] === RoleModel::APPLICATION_ADMINISTRATOR, 'Initial user is not an Application Administrator.');
    setupAssert(!array_key_exists('password', $user), 'Plaintext password field was stored.');
    setupAssert($user['passwordHash'] !== $request['password'], 'Password was stored as plaintext.');
    setupAssert(password_verify($request['password'], $user['passwordHash']), 'Stored password hash could not be verified.');

    $installation = (new InstallationRepository($installationPath))->load();
    setupAssert($installation['initialized'] === true, 'Installation was not marked initialized.');
    setupAssert($installation['installationId'] === $installationId, 'Installation ID changed during setup.');
    setupAssert($service->status() === ['initialized' => true], 'Initialized status exposed extra data.');

    (new InstallationRepository($installationPath))->save([
        ...$installation,
        'initialized' => false,
    ]);
    setupAssert(
        $service->status() === ['initialized' => true]
            && (new InstallationRepository($installationPath))->load()['initialized'] === true,
        'Interrupted initial-administrator state was not recovered.'
    );

    setupFailure(
        fn () => $service->createInitialAdmin('Second Admin', 'SecondAdmin', '+15550000002', null, 'another-secure-password'),
        'INSTALLATION_ALREADY_INITIALIZED'
    );
    setupAssert(count((new AuthRepository($authPath))->load()['users']) === 1, 'Second setup created another administrator.');

    [$concurrentService, $concurrentAuthPath, $concurrentInstallationPath, $concurrentLockPath]
        = createSetupFixture($concurrentDirectory);
    unset($concurrentService);
    $workers = [];
    foreach ([['AdminA', 'concurrent-password-a'], ['AdminB', 'concurrent-password-b']] as [$username, $password]) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/SetupConcurrentWorker.php', $concurrentAuthPath,
                $concurrentInstallationPath, $concurrentLockPath, $username, $password],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        setupAssert(is_resource($process), 'Unable to start concurrent setup worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }

    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $statuses[] = proc_close($process);
        setupAssert($stdout === '' && $stderr === '', 'Concurrent setup worker exposed output.');
    }
    sort($statuses);
    setupAssert($statuses === [0, 2], 'Concurrent setup did not permit exactly one initial administrator.');
    setupAssert(
        count((new AuthRepository($concurrentAuthPath))->load()['users']) === 1,
        'Concurrent setup created multiple administrators.'
    );
    setupAssert(
        (new InstallationRepository($concurrentInstallationPath))->load()['initialized'] === true,
        'Concurrent setup did not initialize the installation.'
    );

    $serializedResult = json_encode([$status, $result, $service->status()], JSON_THROW_ON_ERROR);
    setupAssert(!str_contains($serializedResult, $installationId), 'Setup response exposed installation ID.');
    setupAssert(!str_contains($serializedResult, $user['passwordHash']), 'Setup response exposed password hash.');

    echo "First-time setup tests passed.\n";
} finally {
    removeSetupFixture($mainDirectory);
    removeSetupFixture($concurrentDirectory);
    @rmdir($root);
}
