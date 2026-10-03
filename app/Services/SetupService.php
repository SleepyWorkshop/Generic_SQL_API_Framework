<?php

require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

final class SetupService
{
    private AuthRepository $authRepository;
    private InstallationRepository $installationRepository;
    private PasswordHasher $passwordHasher;
    private string $lockPath;

    public function __construct(
        ?AuthRepository $authRepository = null,
        ?InstallationRepository $installationRepository = null,
        ?PasswordHasher $passwordHasher = null,
        ?string $lockPath = null
    ) {
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->installationRepository = $installationRepository ?? new InstallationRepository();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->lockPath = $lockPath
            ?? RuntimeConfiguration::directory() . DIRECTORY_SEPARATOR . 'installation.lock';
    }

    public function status(): array
    {
        try {
            return $this->withLock(LOCK_EX, function (): array {
                $installation = $this->installationRepository->load();
                if (!$installation['initialized']) {
                    $authentication = $this->authRepository->load();
                    if ($this->isRecoverableInitialAdmin($authentication['users'])) {
                        $installation['initialized'] = true;
                        $this->installationRepository->save($installation);
                    }
                }
                return ['initialized' => $installation['initialized']];
            });
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure($exception);
            throw new ApiRequestException(
                'Unable to read installation status.',
                'SETUP_STATUS_UNAVAILABLE',
                [],
                500
            );
        }
    }

    public function createInitialAdmin(string $name, string $username, string $mobile, ?string $email, string $password): array
    {
        try {
            return $this->withLock(LOCK_EX, function () use ($name, $username, $mobile, $email, $password): array {
                $installation = $this->installationRepository->load();
                if ($installation['initialized']) {
                    $this->alreadyInitialized();
                }

                $authentication = $this->authRepository->load();
                if ($authentication['users'] !== []) {
                    // Recover the only safe interrupted state: the initial admin
                    // was atomically stored before the installation flag changed.
                    if ($this->isRecoverableInitialAdmin($authentication['users'])) {
                        $this->installationRepository->save([
                            ...$installation,
                            'initialized' => true,
                        ]);
                        $this->alreadyInitialized();
                    }
                    throw new ApiRequestException(
                        'Unable to complete initial setup.',
                        'SETUP_STATE_INVALID',
                        [],
                        409
                    );
                }

                $updatedAuthentication = [
                    'version' => 4,
                    'users' => [[
                        'id' => bin2hex(random_bytes(16)),
                        'name' => $name,
                        'username' => $username,
                        'mobile' => $mobile,
                        'email' => $email,
                        'passwordHash' => $this->passwordHasher->hash($password),
                        'enabled' => true,
                        'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR,
                        'frontendAccess' => true,
                        'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR,
                        'createdAt' => gmdate(DATE_ATOM),
                        'authVersion' => 1,
                    ]],
                ];

                $this->authRepository->save($updatedAuthentication);
                try {
                    $this->installationRepository->save([
                        ...$installation,
                        'initialized' => true,
                    ]);
                } catch (Throwable $exception) {
                    // Restore the pre-setup user file when the second atomic
                    // replacement fails. The setup lock prevents a second writer.
                    $this->authRepository->save($authentication);
                    throw $exception;
                }

                (new Logger())->security('initial_admin_created', [
                    'username' => $username,
                    'result' => 'created',
                ]);
                return ['initialized' => true];
            });
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure($exception);
            throw new ApiRequestException(
                'Unable to complete initial setup.',
                'SETUP_FAILED',
                [],
                500
            );
        }
    }

    private function isRecoverableInitialAdmin(array $users): bool
    {
        return count($users) === 1
            && $users[0]['enabled'] === true
            && $users[0]['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR;
    }

    private function alreadyInitialized(): never
    {
        throw new ApiRequestException(
            'Installation is already initialized.',
            'INSTALLATION_ALREADY_INITIALIZED',
            [],
            409
        );
    }

    private function withLock(int $operation, callable $callback)
    {
        $stream = @fopen($this->lockPath, 'c');
        if ($stream === false) {
            throw new RuntimeException('Setup lock is unavailable.');
        }
        @chmod($this->lockPath, 0600);
        try {
            if (!flock($stream, $operation)) {
                throw new RuntimeException('Setup lock could not be acquired.');
            }
            return $callback();
        } finally {
            @flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function logFailure(Throwable $exception): void
    {
        (new Logger())->write((string)json_encode([
            'timestamp' => date(DATE_ATOM),
            'requestId' => defined('API_REQUEST_ID') ? API_REQUEST_ID : null,
            'event' => 'setup_error',
            'errorType' => get_class($exception),
        ], JSON_UNESCAPED_SLASHES));
    }
}
