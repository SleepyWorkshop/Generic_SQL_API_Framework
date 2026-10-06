<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

/** Server-owned, deny-by-default registry of callable routines. */
class RoutineResourceRegistry
{
    public const TYPES = ['procedure', 'function', 'tableFunction'];
    private const MAXIMUM_PARAMETERS = 2100;

    private array $routines;

    public function __construct(?array $routines = null)
    {
        $this->routines = $routines ?? require ROOT_PATH . '/config/routine-resources.php';
        if (!is_array($this->routines)) {
            throw new RuntimeException('Invalid routine registry.');
        }
    }

    /** Resolve a registered routine of the requested type or reject it. */
    public function resolve($id, string $type): array
    {
        if (!is_string($id)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $id) !== 1
            || !array_key_exists($id, $this->routines)) {
            $this->unavailable($type);
        }
        $routine = $this->definition($id);
        if ($routine['type'] !== $type) {
            $this->unavailable($type);
        }
        return $routine;
    }

    public function assertParameters(array $routine, array $parameters): void
    {
        if (!array_is_list($parameters) || count($parameters) !== $routine['parameters']) {
            throw new ApiRequestException(
                'Invalid routine parameters.',
                'INVALID_ROUTINE_PARAMETERS',
                [['path' => 'parameters', 'message' => "Routine requires exactly {$routine['parameters']} positional parameters."]]
            );
        }
    }

    /** Registered routines of one type, for metadata listings. */
    public function definitions(string $type): array
    {
        $definitions = [];
        foreach (array_keys($this->routines) as $id) {
            $routine = $this->definition((string)$id);
            if ($routine['type'] === $type) {
                $definitions[] = $routine;
            }
        }
        return $definitions;
    }

    private function definition(string $id): array
    {
        $definition = $this->routines[$id];
        $allowedRoles = [...RoleModel::backendRoles(), RoleModel::API_ADMINISTRATOR, ...RoleModel::frontendRoles()];
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $id) !== 1
            || !is_array($definition)
            || array_keys($definition) !== ['type', 'schema', 'name', 'access', 'parameters', 'roles']
            || !in_array($definition['type'], self::TYPES, true)
            || !$this->isIdentifier($definition['schema'])
            || !$this->isIdentifier($definition['name'])
            || !in_array($definition['access'], ['read', 'write'], true)
            || !is_int($definition['parameters'])
            || $definition['parameters'] < 0
            || $definition['parameters'] > self::MAXIMUM_PARAMETERS
            || !is_array($definition['roles'])
            || !array_is_list($definition['roles'])
            || $definition['roles'] === []
            || count(array_unique($definition['roles'])) !== count($definition['roles'])
            || array_diff($definition['roles'], $allowedRoles) !== []) {
            throw new RuntimeException("Invalid routine registry entry: {$id}");
        }
        return ['id' => $id, ...$definition];
    }

    private function isIdentifier($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    private function unavailable(string $type): never
    {
        $key = $type === 'procedure' ? 'procedure' : 'function';
        throw new ApiRequestException(
            'Invalid routine.',
            'INVALID_ROUTINE',
            [['path' => "source.{$key}", 'message' => 'Routine is not registered.']]
        );
    }
}
