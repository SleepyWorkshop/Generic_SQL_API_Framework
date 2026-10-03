<?php

require_once __DIR__ . '/../app/Services/SetupService.php';

if ($argc !== 6) {
    exit(3);
}

[$script, $authPath, $installationPath, $lockPath, $username, $password] = $argv;

$service = new SetupService(
    new AuthRepository($authPath),
    new InstallationRepository($installationPath),
    new PasswordHasher(),
    $lockPath
);

try {
    $service->createInitialAdmin($username . ' Name', $username, '+15550000001', null, $password);
    exit(0);
} catch (ApiRequestException $exception) {
    exit($exception->getErrorCode() === 'INSTALLATION_ALREADY_INITIALIZED' ? 2 : 4);
}
