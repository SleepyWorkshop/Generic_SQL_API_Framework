<?php

require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/AuthSessionService.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../Security/LoginRateLimiter.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$.ZDQbluvjYCUXzPdpk4XxeI6c5A/kw5XFuSoh7OWgjT7S4U/pneOK';

    private AuthRepository $authRepository;
    private PasswordHasher $passwordHasher;
    private AuthSessionService $sessionService;
    private LoginRateLimiter $rateLimiter;
    private Logger $logger;

    public function __construct(
        ?AuthRepository $authRepository = null,
        ?PasswordHasher $passwordHasher = null,
        ?AuthSessionService $sessionService = null,
        ?LoginRateLimiter $rateLimiter = null,
        ?Logger $logger = null
    ) {
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->passwordHasher = $passwordHasher ?? new PasswordHasher();
        $this->sessionService = $sessionService ?? new AuthSessionService();
        $this->rateLimiter = $rateLimiter ?? new LoginRateLimiter();
        $this->logger = $logger ?? new Logger();
    }

    public function login(string $username, string $password): array
    {
        try {
            $sourceIp = SecurityConfiguration::clientIp();
            try {
                $this->rateLimiter->assertAllowed($sourceIp, $username);
            } catch (ApiRequestException $exception) {
                $this->logger->audit('auth.login', 'rejected', 'WARNING', [
                    'actorType' => 'anonymous',
                    'targetUsername' => $username,
                    'sourceIp' => $sourceIp,
                    'reason' => 'rate_limited',
                    'component' => 'authentication',
                ]);
                throw $exception;
            }

            $user = $this->authRepository->findUser($username);
            $verified = $this->passwordHasher->verify(
                $password,
                $user['passwordHash'] ?? self::DUMMY_PASSWORD_HASH
            );
            if ($user === null || !$verified || $user['enabled'] !== true) {
                $failureStatus = $this->rateLimiter->recordFailureStatus($sourceIp, $username);
                $this->logger->audit('auth.login', 'failure', 'NOTICE', [
                    'actorType' => 'anonymous',
                    'targetUsername' => $username,
                    'sourceIp' => $sourceIp,
                    'reason' => $user !== null && $verified && $user['enabled'] !== true
                        ? 'account_disabled' : 'invalid_credentials',
                    'component' => 'authentication',
                ]);
                if ($failureStatus['locked']) {
                    $this->logger->audit('auth.login', 'rejected', 'WARNING', [
                        'actorType' => 'anonymous',
                        'targetUsername' => $username,
                        'sourceIp' => $sourceIp,
                        'reason' => 'rate_limit_activated',
                        'component' => 'authentication',
                    ]);
                    $this->rateLimiter->throwRateLimited($failureStatus['retryAfterSeconds']);
                }
                throw new ApiRequestException(
                    'Invalid username or password.',
                    'INVALID_CREDENTIALS',
                    is_int($failureStatus['attemptsRemaining']) ? [[
                            'path' => 'authentication',
                            'message' => 'Attempts remaining: ' . $failureStatus['attemptsRemaining'],
                            'locked' => false,
                            'attemptsRemaining' => $failureStatus['attemptsRemaining'],
                        ]] : [],
                    401
                );
            }

            $this->rateLimiter->reset($sourceIp, $username);
            if ($this->passwordHasher->needsRehash($user['passwordHash'])) {
                try {
                    $this->authRepository->replacePasswordHash(
                        $user['username'],
                        $user['passwordHash'],
                        $this->passwordHasher->hash($password)
                    );
                } catch (Throwable $exception) {
                    $this->logger->audit('auth.password_rehash', 'failure', 'WARNING', [
                        'actorType' => 'user',
                        'actorId' => $user['id'],
                        'actorUsername' => $user['username'],
                        'sourceIp' => $sourceIp,
                        'reason' => 'storage_failure',
                        'component' => 'authentication',
                    ]);
                }
            }
            $this->sessionService->establish(
                $user['username'],
                $user['id'],
                $user['authVersion']
            );
            $this->logger->audit('auth.login', 'success', 'INFO', [
                'actorType' => 'user',
                'actorId' => $user['id'],
                'actorUsername' => $user['username'],
                'role' => $user['backendRole'] ?? $user['frontendRole'],
                'authenticationMethod' => 'session',
                'sourceIp' => $sourceIp,
                'component' => 'authentication',
            ]);
            return $this->authenticatedSnapshot($user);
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function session(): array
    {
        try {
            if (!$this->sessionService->resume() || !$this->sessionService->isAuthenticated()) {
                return $this->unauthenticatedSnapshot();
            }
            $username = (string)$this->sessionService->authenticatedUsername();
            $user = $this->authRepository->findUserById((string)$this->sessionService->authenticatedUserId());
            if ($user === null || $user['enabled'] !== true) {
                $this->logger->audit('auth.session', 'invalidated', 'NOTICE', [
                    'actorType' => 'user',
                    'actorId' => $this->sessionService->authenticatedUserId(),
                    'actorUsername' => $username,
                    'reason' => $user === null ? 'identity_missing' : 'account_disabled',
                    'component' => 'authentication',
                ]);
                $this->sessionService->destroy();
                return $this->unauthenticatedSnapshot();
            }
            if ($user['username'] !== $username
                || $user['authVersion'] !== $this->sessionService->authenticatedAuthVersion()) {
                $this->logger->audit('auth.session', 'invalidated', 'NOTICE', [
                    'actorType' => 'user',
                    'actorId' => $user['id'],
                    'actorUsername' => $username,
                    'reason' => $user['username'] !== $username ? 'identity_changed' : 'auth_version_changed',
                    'component' => 'authentication',
                ]);
                $this->sessionService->destroy();
                return $this->unauthenticatedSnapshot();
            }
            return $this->authenticatedSnapshot($user);
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function logout(): array
    {
        try {
            $username = $this->sessionService->resume()
                ? $this->sessionService->authenticatedUsername()
                : null;
            $this->sessionService->destroy();
            $this->logger->audit('auth.logout', 'success', 'INFO', [
                'actorType' => $username === null ? 'anonymous' : 'user',
                'actorUsername' => $username,
                'sourceIp' => SecurityConfiguration::clientIp(),
                'component' => 'authentication',
            ]);
            return $this->unauthenticatedSnapshot();
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    private function authenticatedSnapshot(array $user): array
    {
        return [
            'authenticated' => true,
            'user' => [
                'username' => $user['username'],
                'backendRole' => $user['backendRole'],
                'frontendAccess' => $user['frontendAccess'],
                'frontendRole' => $user['frontendRole'],
            ],
        ];
    }

    private function unauthenticatedSnapshot(): array
    {
        return ['authenticated' => false, 'user' => null];
    }

    private function fail(Throwable $exception): never
    {
        $this->logger->audit('auth.request', 'failure', 'ERROR', [
            'actorType' => 'anonymous',
            'reason' => get_class($exception),
            'component' => 'authentication',
        ]);
        throw new ApiRequestException(
            'Unable to complete authentication request.',
            'AUTHENTICATION_FAILED',
            [],
            500
        );
    }
}
