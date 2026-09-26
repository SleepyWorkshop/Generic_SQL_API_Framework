<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Backup/ConfigurationMutationBackup.php';

final class ApiKeyRepository
{
    private string $path;
    private bool $runtimePath;

    public function __construct(?string $path = null)
    {
        $this->runtimePath = $path === null;
        $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::API_KEYS_FILE);
    }

    public function load(): array
    {
        RuntimeConfiguration::ensure();
        $value = JsonFileStore::load($this->path);
        if (in_array($value['version'] ?? null, [1, 2], true)) {
            $value = $this->migrate($value);
            JsonFileStore::save($this->path, $value);
        }
        $this->validate($value);
        return $value;
    }

    public function update(callable $operation, bool $critical = true)
    {
        RuntimeConfiguration::ensure();
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('API key storage is unavailable.');
        }
        try {
            $value = JsonFileStore::load($this->path);
            if (in_array($value['version'] ?? null, [1, 2], true)) {
                $value = $this->migrate($value);
            }
            $this->validate($value);
            $result = $operation($value);
            $this->validate($value);
            if ($critical && $this->runtimePath) ConfigurationMutationBackup::before('api_keys');
            JsonFileStore::save($this->path, $value);
            return $result;
        } finally {
            @flock($lock, LOCK_UN);
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }

    public function findById(string $id): ?array
    {
        foreach ($this->load()['keys'] as $key) {
            if (hash_equals($key['id'], $id)) {
                return $key;
            }
        }
        return null;
    }

    public function validate(array $value): void
    {
        if (($value['version'] ?? null) !== 3
            || array_diff(array_keys($value), ['version', 'keys']) !== []
            || !is_array($value['keys'] ?? null)
            || !array_is_list($value['keys'])) {
            throw new RuntimeException('Invalid API key configuration.');
        }
        $ids = [];
        foreach ($value['keys'] as $key) {
            if (!$this->validRecord($key, $ids)) {
                throw new RuntimeException('Invalid API key record.');
            }
            $ids[$key['id']] = true;
        }
    }

    private function validRecord(mixed $key, array $ids): bool
    {
        if (!is_array($key)
            || array_diff(array_keys($key), [
                'id', 'name', 'ownerUserId', 'roles', 'secretHash', 'fingerprint',
                'enabled', 'revokedAt', 'createdAt', 'lastUsedAt',
            ]) !== []) {
            return false;
        }
        return preg_match('/^[a-f0-9]{16}$/', $key['id'] ?? '') === 1
            && !isset($ids[$key['id']])
            && is_string($key['name'] ?? null)
            && trim($key['name']) !== ''
            && preg_match('/^[a-f0-9]{32}$/', $key['ownerUserId'] ?? '') === 1
            && is_array($key['roles'] ?? null)
            && array_is_list($key['roles'])
            && count($key['roles']) === 1
            && in_array($key['roles'][0], RoleModel::apiKeyRoles(), true)
            && is_string($key['secretHash'] ?? null)
            && (password_get_info($key['secretHash'])['algoName'] ?? 'unknown') !== 'unknown'
            && preg_match('/^[a-f0-9]{12}$/', $key['fingerprint'] ?? '') === 1
            && is_bool($key['enabled'] ?? null)
            && is_string($key['createdAt'] ?? null)
            && strtotime($key['createdAt']) !== false
            && ($key['revokedAt'] === null
                || (is_string($key['revokedAt']) && strtotime($key['revokedAt']) !== false))
            && ($key['lastUsedAt'] === null
                || (is_string($key['lastUsedAt']) && strtotime($key['lastUsedAt']) !== false));
    }

    private function migrate(array $value): array
    {
        $version = $value['version'] ?? null;
        if (!in_array($version, [1, 2], true) || !is_array($value['keys'] ?? null)) {
            throw new RuntimeException('Invalid API key configuration.');
        }
        $value['version'] = 3;
        foreach ($value['keys'] as &$key) {
            $roles = is_array($key['roles'] ?? null) ? $key['roles'] : [];
            $role = $version === 1
                ? RoleModel::migrateLegacyBackendRoles($roles)
                : ($roles[0] ?? null);
            if ($role === RoleModel::SYSTEM_ADMINISTRATOR) {
                $role = RoleModel::API_ADMINISTRATOR;
            }
            $key['roles'] = [
                in_array($role, RoleModel::apiKeyRoles(), true) ? $role : RoleModel::READ_ONLY,
            ];
        }
        unset($key);
        return $value;
    }
}
