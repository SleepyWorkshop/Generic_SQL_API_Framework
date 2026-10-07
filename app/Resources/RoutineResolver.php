<?php

require_once __DIR__ . '/DatabaseObjectName.php';
require_once __DIR__ . '/../Repositories/MetadataRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * Resolves a client-supplied routine name against live database metadata.
 *
 * Only user-defined routines of the configured database that match the requested
 * routine type are callable. System schemas, three-part names, and SQL Server
 * system-procedure prefixes are rejected before any metadata lookup. Argument
 * counts are checked against the routine's declared parameters.
 */
class RoutineResolver
{
    private const SYSTEM_PREFIXES = ['sp_', 'xp_'];

    public function __construct(private MetadataRepository $metadataRepository) {}

    /** Parses a routine name without touching the database. */
    public static function name($value, string $type): array
    {
        $path = 'source.' . ($type === 'procedure' ? 'procedure' : 'function');
        $name = DatabaseObjectName::parse($value, $path, 'INVALID_ROUTINE', 'Invalid routine.');
        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($name['name']), $prefix)) {
                throw new ApiRequestException('Invalid routine.', 'INVALID_ROUTINE', [['path' => $path, 'message' => 'System routines cannot be called.']]);
            }
        }
        return $name;
    }

    public function resolve($value, string $type, array $parameters): array
    {
        $name = self::name($value, $type);
        $definition = $this->metadataRepository->getRoutine($name['schema'], $name['name']);
        if ($definition === null || $definition['type'] !== $type) {
            $path = 'source.' . ($type === 'procedure' ? 'procedure' : 'function');
            throw new ApiRequestException('Invalid routine.', 'INVALID_ROUTINE', [['path' => $path, 'message' => 'Routine does not exist or has a different type.']]);
        }
        $declared = $definition['parameters'];
        $count = count($parameters);
        // Procedures may rely on parameter defaults for trailing arguments;
        // functions must receive every declared argument.
        $valid = array_is_list($parameters)
            && ($type === 'procedure' ? $count <= $declared : $count === $declared);
        if (!$valid) {
            $expected = $type === 'procedure' ? "at most {$declared}" : "exactly {$declared}";
            throw new ApiRequestException('Invalid routine parameters.', 'INVALID_ROUTINE_PARAMETERS', [[
                'path' => 'parameters',
                'message' => "Routine accepts {$expected} positional parameters.",
            ]]);
        }
        return ['schema' => $name['schema'], 'name' => $name['name'], 'type' => $type, 'parameters' => $declared];
    }
}
