<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

final class AuthorizationRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? RuntimeConfiguration::path(RuntimeConfiguration::AUTHORIZATION_FILE);
    }

    public function load(): array
    {
        RuntimeConfiguration::ensure();
        $value = JsonFileStore::load($this->path);
        if (($value['version'] ?? null) === 1) {
            $value = RuntimeConfiguration::authorizationDefaults();
            JsonFileStore::save($this->path, $value);
        } elseif (in_array($value['version'] ?? null, [2, 3], true)) {
            if ($value['version'] === 2) $value = $this->migrateVersionTwo($value);
            $value = $this->migrateVersionThree($value);
            JsonFileStore::save($this->path, $value);
        }
        $this->validate($value);
        return $value;
    }

    public function save(array $value): void
    {
        $this->validate($value);
        JsonFileStore::save($this->path, $value);
    }

    public function roleIds(): array
    {
        return array_keys($this->load()['roles']);
    }

    private function migrateVersionTwo(array $value): array
    {
        if (!is_array($value['roles'] ?? null) || array_is_list($value['roles'])) {
            throw new RuntimeException('Invalid authorization configuration.');
        }
        $operator = $value['roles'][RoleModel::DATA_OPERATOR] ?? null;
        if (!is_array($operator)) {
            throw new RuntimeException('Required authorization roles are unavailable.');
        }
        $value['version'] = 3;
        $value['roles'][RoleModel::API_ADMINISTRATOR] = [
            'name' => 'Admin',
            'domain' => 'backend',
            'permissions' => ['data.read', 'data.write', 'metadata.read', 'sql.execute', 'routine.execute'],
        ];
        return $value;
    }

    /**
     * Version 4 removes per-role SQL Resource and write-resource scopes.
     * Authorization is decided by role permissions only.
     */
    private function migrateVersionThree(array $value): array
    {
        if (!is_array($value['roles'] ?? null) || array_is_list($value['roles'])) {
            throw new RuntimeException('Invalid authorization configuration.');
        }
        foreach ($value['roles'] as $id => $role) {
            if (is_array($role)) {
                unset($role['sqlResources'], $role['writeResources']);
                $value['roles'][$id] = $role;
            }
        }
        $value['version'] = 4;
        return $value;
    }

    public function validate(array $value): void
    {
        if (($value['version'] ?? null) !== 4
            || array_diff(array_keys($value), ['version', 'publicRoles', 'legacyApiKeyRoles', 'roles']) !== []
            || !is_array($value['roles'] ?? null)
            || array_is_list($value['roles'])
            || $value['roles'] === []) {
            throw new RuntimeException('Invalid authorization configuration.');
        }
        foreach (['publicRoles', 'legacyApiKeyRoles'] as $field) {
            if (!is_array($value[$field] ?? null) || !array_is_list($value[$field])) {
                throw new RuntimeException('Invalid authorization role assignment.');
            }
        }
        $allowedPermissions = [
            'admin.manage', 'frontend.read', 'frontend.users.manage', 'data.read',
            'data.write', 'metadata.read', 'sql.execute', 'routine.execute',
        ];
        foreach ($value['roles'] as $id => $role) {
            if (!is_string($id)
                || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1
                || !is_array($role)
                || array_diff(array_keys($role), ['name', 'domain', 'permissions']) !== []
                || !is_string($role['name'] ?? null)
                || trim($role['name']) === '') {
                throw new RuntimeException('Invalid authorization role.');
            }
            if (!in_array($role['domain'] ?? null, ['backend', 'frontend'], true)) {
                throw new RuntimeException('Invalid authorization role domain.');
            }
            if (!is_array($role['permissions'] ?? null)
                || !array_is_list($role['permissions'])
                || count(array_unique($role['permissions'])) !== count($role['permissions'])) {
                throw new RuntimeException('Invalid authorization role.');
            }
            if (array_diff($role['permissions'], $allowedPermissions) !== []) {
                throw new RuntimeException('Invalid authorization permission.');
            }
        }
        foreach (array_merge($value['publicRoles'], $value['legacyApiKeyRoles']) as $role) {
            if (!is_string($role) || !in_array($role, RoleModel::backendRoles(), true)) {
                throw new RuntimeException('Unknown authorization role.');
            }
            // Anonymous (mode "none") and legacy shared-key principals never
            // carry the System Administrator identity.
            if ($role === RoleModel::SYSTEM_ADMINISTRATOR) {
                throw new RuntimeException('Unsafe authorization role assignment.');
            }
        }
        $apiAdministrator = $value['roles'][RoleModel::API_ADMINISTRATOR] ?? null;
        if (array_diff(RoleModel::backendRoles(), array_keys($value['roles'])) !== []
            || !isset($value['roles'][RoleModel::APPLICATION_ADMINISTRATOR])
            || !is_array($apiAdministrator)
            || count($value['roles']) !== 5
            || $value['roles'][RoleModel::READ_ONLY]['domain'] !== 'backend'
            || $value['roles'][RoleModel::DATA_OPERATOR]['domain'] !== 'backend'
            || $value['roles'][RoleModel::SYSTEM_ADMINISTRATOR]['domain'] !== 'backend'
            || $apiAdministrator['domain'] !== 'backend'
            || $value['roles'][RoleModel::APPLICATION_ADMINISTRATOR]['domain'] !== 'frontend'
            || in_array('data.write', $value['roles'][RoleModel::READ_ONLY]['permissions'], true)
            || in_array('admin.manage', $value['roles'][RoleModel::READ_ONLY]['permissions'], true)
            || in_array('admin.manage', $value['roles'][RoleModel::DATA_OPERATOR]['permissions'], true)
            || !in_array('data.write', $value['roles'][RoleModel::DATA_OPERATOR]['permissions'], true)
            || !in_array('admin.manage', $value['roles'][RoleModel::SYSTEM_ADMINISTRATOR]['permissions'], true)
            || !in_array('data.write', $apiAdministrator['permissions'], true)
            || in_array('admin.manage', $apiAdministrator['permissions'], true)
            || in_array('frontend.users.manage', $apiAdministrator['permissions'], true)
            || !in_array('frontend.users.manage', $value['roles'][RoleModel::APPLICATION_ADMINISTRATOR]['permissions'], true)
            || in_array('admin.manage', $value['roles'][RoleModel::APPLICATION_ADMINISTRATOR]['permissions'], true)
            || in_array('data.write', $value['roles'][RoleModel::APPLICATION_ADMINISTRATOR]['permissions'], true)) {
            throw new RuntimeException('Required authorization roles are unavailable.');
        }
    }
}
