<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

final class RoleController extends BaseController
{
    public function list(array $request): void
    {
        $roles = [];
        foreach ((new AuthorizationService())->roles() as $id => $role) {
            $permissions = $role['permissions'];
            $roles[] = [
                'id' => $id,
                'name' => $role['name'],
                'domain' => $role['domain'],
                'apiKeyAssignable' => in_array($id, RoleModel::apiKeyRoles(), true),
                'read' => array_intersect($permissions, ['data.read', 'frontend.read']) !== [],
                'write' => in_array('data.write', $permissions, true),
                'backendAdministration' => in_array('admin.manage', $permissions, true),
                'frontendUserManagement' => in_array('frontend.users.manage', $permissions, true),
                'writeNote' => $id === RoleModel::APPLICATION_ADMINISTRATOR
                    ? 'Unavailable because the frontend is read-only.'
                    : null,
            ];
        }
        $this->success($roles, 'Roles and permissions loaded.');
    }
}
