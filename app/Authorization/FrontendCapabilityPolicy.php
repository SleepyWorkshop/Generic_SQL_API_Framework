<?php

require_once __DIR__ . '/RoleModel.php';

final class FrontendCapabilityPolicy
{
    public const READ = true;
    public const WRITE = false;
    public const USER_MANAGEMENT = true;

    public static function assignableRoles(): array
    {
        $roles = [];
        if (self::USER_MANAGEMENT) $roles[] = RoleModel::APPLICATION_ADMINISTRATOR;
        if (self::WRITE) $roles[] = RoleModel::DATA_OPERATOR;
        if (self::READ) $roles[] = RoleModel::READ_ONLY;
        return $roles;
    }
}
