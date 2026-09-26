<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Security/UserProfilePolicy.php';
require_once __DIR__ . '/../Backup/ConfigurationMutationBackup.php';

final class AuthRepository
{
    private string $path;
    private bool $runtimePath;

    public function __construct(?string $path = null)
    {
        $this->runtimePath = $path === null;
        $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE);
    }

    public function load(): array
    {
        $this->bootstrapRuntimeConfiguration();
        $configuration = JsonFileStore::load($this->path);
        if (in_array($configuration['version'] ?? null, [1, 2, 3], true)) {
            return $this->withLock(function (): array {
                $configuration = JsonFileStore::load($this->path);
                if (in_array($configuration['version'] ?? null, [1, 2, 3], true)) {
                    $configuration = $this->migrateLegacyConfiguration($configuration);
                    JsonFileStore::save($this->path, $configuration);
                }
                $this->validate($configuration);
                return $configuration;
            });
        }
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        $this->bootstrapRuntimeConfiguration();
        if (in_array($configuration['version'] ?? null, [1, 2, 3], true)) {
            $configuration = $this->migrateLegacyConfiguration($configuration);
        }
        $this->validate($configuration);
        JsonFileStore::save($this->path, $configuration);
    }

    public function findEnabledUser(string $username): ?array
    {
        $user = $this->findUser($username);
        return $user !== null && $user['enabled'] ? $user : null;
    }

    public function findUser(string $username): ?array
    {
        foreach ($this->load()['users'] as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    public function findUserById(string $id): ?array
    {
        foreach ($this->load()['users'] as $user) {
            if (hash_equals($user['id'], $id)) return $user;
        }
        return null;
    }

    public function replacePasswordHash(string $username, string $expectedHash, string $newHash): bool
    {
        return $this->update(function (array &$configuration) use ($username, $expectedHash, $newHash): bool {
            foreach ($configuration['users'] as &$user) {
                if (strcasecmp($user['username'], $username) === 0
                    && hash_equals($user['passwordHash'], $expectedHash)) {
                    $user['passwordHash'] = $newHash;
                    return true;
                }
            }
            return false;
        }, false);
    }

    public function update(callable $operation, bool $critical = true)
    {
        $this->bootstrapRuntimeConfiguration();
        return $this->withLock(function () use ($operation, $critical) {
            $configuration = JsonFileStore::load($this->path);
            if (in_array($configuration['version'] ?? null, [1, 2, 3], true)) {
                $configuration = $this->migrateLegacyConfiguration($configuration);
            }
            $this->validate($configuration);
            $result = $operation($configuration);
            if ($critical && $this->runtimePath) ConfigurationMutationBackup::before('authentication_users');
            $this->save($configuration);
            return $result;
        });
    }

    public function validate(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'users']) !== []
            || ($configuration['version'] ?? null) !== 4
            || !is_array($configuration['users'] ?? null)
            || !array_is_list($configuration['users'])) {
            throw new RuntimeException('Invalid authentication configuration.');
        }

        $usernames = [];
        $userIds = [];
        foreach ($configuration['users'] as $user) {
            if (!is_array($user) || array_is_list($user)
                || array_diff(array_keys($user), [
                    'id', 'username', 'passwordHash', 'enabled', 'backendRole',
                    'frontendAccess', 'frontendRole', 'createdAt', 'authVersion',
                    'name', 'mobile', 'email'
                ]) !== []
                || !is_string($user['id'] ?? null)
                || preg_match('/^[a-f0-9]{32}$/', $user['id']) !== 1
                || !is_string($user['username'] ?? null)
                || trim($user['username']) === ''
                || !is_string($user['passwordHash'] ?? null)
                || ($user['passwordHash'] ?? '') === ''
                || (password_get_info($user['passwordHash'])['algoName'] ?? 'unknown') === 'unknown'
                || !is_bool($user['enabled'] ?? null)
                || ($user['backendRole'] !== null && !in_array($user['backendRole'], RoleModel::backendRoles(), true))
                || !is_bool($user['frontendAccess'] ?? null)
                || ($user['frontendRole'] !== null && !in_array($user['frontendRole'], RoleModel::frontendRoles(), true))
                || ($user['frontendRole'] !== null && $user['frontendAccess'] !== true)
                || ($user['enabled'] === true && $user['backendRole'] === null && $user['frontendAccess'] !== true)
                || !is_string($user['createdAt'] ?? null)
                || strtotime($user['createdAt']) === false
                || !is_int($user['authVersion'] ?? null)
                || $user['authVersion'] < 1
                || !$this->validOptionalProfile($user)) {
                throw new RuntimeException('Invalid authentication user configuration.');
            }

            $canonicalUsername = strtolower($user['username']);
            if (isset($usernames[$canonicalUsername]) || isset($userIds[$user['id']])) {
                throw new RuntimeException('Authentication usernames must be unique.');
            }
            $usernames[$canonicalUsername] = true;
            $userIds[$user['id']] = true;
        }
    }

    private function validOptionalProfile(array $user): bool
    {
        try {
            if (array_key_exists('name', $user) && $user['name'] !== null) UserProfilePolicy::name($user['name']);
            if (array_key_exists('mobile', $user) && $user['mobile'] !== null) UserProfilePolicy::mobile($user['mobile']);
            if (array_key_exists('email', $user)) UserProfilePolicy::email($user['email']);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function migrateLegacyConfiguration(array $configuration): array
    {
        if (!in_array($configuration['version'] ?? null, [1, 2, 3], true)
            || !is_array($configuration['users'] ?? null)
            || !array_is_list($configuration['users'])) {
            throw new RuntimeException('Invalid authentication configuration.');
        }
        $createdAt = gmdate(DATE_ATOM, (int)(@filemtime($this->path) ?: time()));
        $sourceVersion = $configuration['version'];
        $configuration['version'] = 4;
        foreach ($configuration['users'] as &$user) {
            if (!is_array($user) || array_is_list($user)) {
                throw new RuntimeException('Invalid authentication user configuration.');
            }
            $isAdmin = ($user['isAdmin'] ?? null) === true;
            $legacyRoles = is_array($user['roles'] ?? null)
                ? $user['roles']
                : [$isAdmin ? 'admin' : 'viewer'];
            $backendRole = RoleModel::migrateLegacyBackendRoles($legacyRoles);
            $user = [
                'id' => $sourceVersion >= 2 ? ($user['id'] ?? null) : bin2hex(random_bytes(16)),
                'username' => $user['username'] ?? null,
                'passwordHash' => $user['passwordHash'] ?? null,
                'enabled' => $user['enabled'] ?? null,
                'backendRole' => $backendRole,
                'frontendAccess' => true,
                'frontendRole' => $isAdmin ? RoleModel::APPLICATION_ADMINISTRATOR : null,
                'createdAt' => $sourceVersion >= 2 ? ($user['createdAt'] ?? null) : $createdAt,
                'authVersion' => $sourceVersion >= 2 ? ($user['authVersion'] ?? null) + 1 : 1,
            ];
        }
        unset($user);
        $this->validate($configuration);
        return $configuration;
    }

    private function withLock(callable $operation)
    {
        $lockPath = $this->path . '.lock';
        $stream = @fopen($lockPath, 'c');
        if ($stream === false) throw new RuntimeException('Authentication storage lock is unavailable.');
        @chmod($lockPath, 0600);
        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException('Authentication storage lock could not be acquired.');
            }
            return $operation();
        } finally {
            @flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function bootstrapRuntimeConfiguration(): void
    {
        if ($this->runtimePath) RuntimeConfiguration::ensure();
    }
}
