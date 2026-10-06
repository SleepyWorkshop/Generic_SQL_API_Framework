<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

/** Server-owned, deny-by-default registry of readable tables and views. */
class QuerySourceRegistry
{
    public const FRONTEND_ACCESS = 'frontend-access';

    private array $sources = [];

    public function __construct(?array $sources = null)
    {
        $sources ??= require ROOT_PATH . '/config/query-sources.php';
        if (!is_array($sources) || ($sources !== [] && array_is_list($sources))) {
            throw new RuntimeException('Invalid query source registry.');
        }
        $allowedRoles = [
            ...RoleModel::backendRoles(), RoleModel::API_ADMINISTRATOR,
            ...RoleModel::frontendRoles(), self::FRONTEND_ACCESS,
        ];
        foreach ($sources as $name => $definition) {
            if (!is_string($name)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1
                || !is_array($definition)
                || array_diff(array_keys($definition), ['roles']) !== []
                || (array_key_exists('roles', $definition) && (
                    !is_array($definition['roles'])
                    || !array_is_list($definition['roles'])
                    || $definition['roles'] === []
                    || array_diff($definition['roles'], $allowedRoles) !== []))) {
                throw new RuntimeException("Invalid query source registry entry: {$name}");
            }
            $canonical = strtolower($name);
            if (isset($this->sources[$canonical])) {
                throw new RuntimeException("Duplicate query source registry entry: {$name}");
            }
            $this->sources[$canonical] = ['name' => $name, 'roles' => $definition['roles'] ?? null];
        }
    }

    public function find(string $name): ?array
    {
        return $this->sources[strtolower($name)] ?? null;
    }
}
