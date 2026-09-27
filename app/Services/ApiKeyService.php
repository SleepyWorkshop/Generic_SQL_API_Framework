<?php

require_once __DIR__ . '/../Repositories/ApiKeyRepository.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';

final class ApiKeyService
{
    public function __construct(
        private ?ApiKeyRepository $keys = null,
        private ?AuthRepository $users = null,
        private ?AuthorizationService $authorization = null,
        private ?Logger $logger = null
    ) {
        $this->keys ??= new ApiKeyRepository();
        $this->users ??= new AuthRepository();
        $this->authorization ??= new AuthorizationService();
        $this->logger ??= new Logger();
    }

    public function list(): array
    {
        return array_map(function (array $key): array {
            $owner = $this->users->findUserById($key['ownerUserId']);
            return [...$this->safe($key), 'ownerUsername' => $owner['username'] ?? 'Unavailable'];
        }, $this->keys->load()['keys']);
    }

    public function configured(): bool
    {
        return $this->keys->load()['keys'] !== [];
    }

    public function create(string $name, string $ownerUsername, array $roles): array
    {
        $owner = $this->users->findUser($ownerUsername);
        if ($owner === null || !$owner['enabled']) {
            throw new ApiRequestException('API key owner is unavailable.', 'API_KEY_OWNER_UNAVAILABLE', [], 409);
        }
        $this->validateRoles($roles);
        $id = bin2hex(random_bytes(8));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $raw = "gsk_{$id}_{$secret}";
        $record = [
            'id' => $id,
            'name' => $name,
            'ownerUserId' => $owner['id'],
            'roles' => array_values(array_unique($roles)),
            'secretHash' => password_hash($secret, PASSWORD_DEFAULT),
            'fingerprint' => substr(hash('sha256', $raw), 0, 12),
            'enabled' => true,
            'revokedAt' => null,
            'createdAt' => gmdate(DATE_ATOM),
            'lastUsedAt' => null,
        ];
        $safe = $this->keys->update(function (array &$value) use ($record): array {
            $value['keys'][] = $record;
            return $this->safe($record);
        });
        $this->logger->audit('api_key.created', 'success', 'INFO', [
            'keyId' => $id,
            'fingerprint' => $record['fingerprint'],
            'ownerId' => $owner['id'],
            'targetUsername' => $ownerUsername,
            'component' => 'api_keys',
        ]);
        return [...$safe, 'apiKey' => $raw];
    }

    public function setEnabled(string $id, bool $enabled): array
    {
        $result = $this->mutate($id, function (array &$key) use ($enabled): void {
            if ($key['revokedAt'] !== null && $enabled) {
                throw new ApiRequestException('Revoked API keys cannot be enabled.', 'API_KEY_REVOKED', [], 409);
            }
            $key['enabled'] = $enabled;
        });
        $this->logger->audit($enabled ? 'api_key.enabled' : 'api_key.disabled', 'success', 'INFO', [
            'keyId' => $id,
            'fingerprint' => $result['fingerprint'],
            'component' => 'api_keys',
        ]);
        return $result;
    }

    public function revoke(string $id): array
    {
        $result = $this->mutate($id, function (array &$key): void {
            if ($key['revokedAt'] === null) {
                $key['revokedAt'] = gmdate(DATE_ATOM);
            }
            $key['enabled'] = false;
        });
        $this->logger->audit('api_key.revoked', 'success', 'NOTICE', [
            'keyId' => $id,
            'fingerprint' => $result['fingerprint'],
            'component' => 'api_keys',
        ]);
        return $result;
    }

    public function authenticate(string $raw): ?array
    {
        if (preg_match('/^gsk_([a-f0-9]{16})_([A-Za-z0-9_-]{43})$/', $raw, $match) !== 1) {
            return null;
        }
        $key = $this->keys->findById($match[1]);
        if ($key === null
            || !$key['enabled']
            || $key['revokedAt'] !== null
            || !password_verify($match[2], $key['secretHash'])) {
            return null;
        }
        $owner = $this->users->findUserById($key['ownerUserId']);
        if ($owner === null || !$owner['enabled']) {
            return null;
        }
        $now = gmdate(DATE_ATOM);
        $this->keys->update(function (array &$value) use ($key, $now): void {
            foreach ($value['keys'] as &$item) {
                if (hash_equals($item['id'], $key['id'])) {
                    $item['lastUsedAt'] = $now;
                    break;
                }
            }
            unset($item);
        });
        return ['key' => $key, 'owner' => $owner];
    }

    private function mutate(string $id, callable $operation): array
    {
        return $this->keys->update(function (array &$value) use ($id, $operation): array {
            foreach ($value['keys'] as &$key) {
                if (hash_equals($key['id'], $id)) {
                    $operation($key);
                    return $this->safe($key);
                }
            }
            throw new ApiRequestException('API key was not found.', 'API_KEY_NOT_FOUND', [], 404);
        });
    }

    private function validateRoles(array $roles): void
    {
        if (count($roles) !== 1
            || !is_string($roles[0] ?? null)
            || !$this->authorization->apiKeyRoleExists($roles[0])) {
            throw new ApiRequestException(
                'Invalid API key request.',
                'INVALID_API_KEY_REQUEST',
                [['path' => 'roles', 'message' => 'Exactly one supported API key role is required.']]
            );
        }
    }

    private function safe(array $key): array
    {
        return [
            'id' => $key['id'],
            'name' => $key['name'],
            'roles' => $key['roles'],
            'fingerprint' => $key['fingerprint'],
            'enabled' => $key['enabled'],
            'revoked' => $key['revokedAt'] !== null,
            'revokedAt' => $key['revokedAt'],
            'createdAt' => $key['createdAt'],
            'lastUsedAt' => $key['lastUsedAt'],
        ];
    }
}
