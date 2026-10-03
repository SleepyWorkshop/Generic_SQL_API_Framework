<?php

final class RoleModel
{
    public const READ_ONLY = 'read-only';
    public const DATA_OPERATOR = 'data-operator';
    public const API_ADMINISTRATOR = 'api-administrator';
    public const SYSTEM_ADMINISTRATOR = 'system-administrator';
    public const APPLICATION_ADMINISTRATOR = 'application-administrator';

    public static function backendRoles(): array
    {
        return [self::READ_ONLY, self::DATA_OPERATOR, self::SYSTEM_ADMINISTRATOR];
    }

    public static function frontendRoles(): array
    {
        return [self::APPLICATION_ADMINISTRATOR];
    }

    public static function apiKeyRoles(): array
    {
        return [self::READ_ONLY, self::DATA_OPERATOR, self::API_ADMINISTRATOR];
    }

    public static function migrateLegacyBackendRoles(array $roles): ?string
    {
        if (array_intersect($roles, ['admin', self::SYSTEM_ADMINISTRATOR]) !== []) {
            return self::SYSTEM_ADMINISTRATOR;
        }
        if (array_intersect($roles, ['data-editor', self::DATA_OPERATOR]) !== []) {
            return self::DATA_OPERATOR;
        }
        if (array_intersect($roles, ['viewer', 'developer', self::READ_ONLY]) !== []) {
            return self::READ_ONLY;
        }
        return null;
    }
}
