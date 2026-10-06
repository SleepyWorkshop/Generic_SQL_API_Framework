<?php

require_once __DIR__ . '/../Authorization/Principal.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

final class AuthorizationService
{
    public function __construct(private ?AuthorizationRepository $repository = null, private ?Logger $logger = null) { $this->repository ??= new AuthorizationRepository(); $this->logger ??= new Logger(); }

    public function roles(): array { return $this->repository->load()['roles']; }
    public function publicRoles(): array { return $this->repository->load()['publicRoles']; }
    public function legacyApiKeyRoles(): array { return $this->repository->load()['legacyApiKeyRoles']; }
    public function roleExists(string $role): bool { return isset($this->roles()[$role]); }
    public function backendRoleExists(?string $role): bool { return $role === null || in_array($role, RoleModel::backendRoles(), true); }
    public function apiKeyRoleExists(?string $role): bool { return $role !== null && in_array($role, RoleModel::apiKeyRoles(), true); }
    public function frontendRoleExists(?string $role): bool { return $role === null || in_array($role, RoleModel::frontendRoles(), true); }
    public function permissionsForRoles(array $roles): array
    {
        $definitions=$this->roles();$permissions=[];
        foreach($roles as $role)foreach($definitions[$role]['permissions']??[] as $permission)$permissions[$permission]=true;
        return array_keys($permissions);
    }

    public function authorize(Principal $principal, string $permission, ?string $resource = null, ?string $scope = null): void
    {
        $this->authorizeInternal($principal, $permission, $resource, $scope, true);
    }

    private function authorizeInternal(Principal $principal, string $permission, ?string $resource, ?string $scope, bool $audit): void
    {
        if (!$principal->enabled) $this->deny($principal, $permission, false, 'principal_disabled', $audit);
        // Frontend access is the base read entitlement in the frontend domain;
        // Application Administrator adds management, not a second read model.
        if ($permission === 'frontend.read' && $principal->frontendAccess) return;
        $configuration = $this->repository->load();
        $allowed = false;
        $resourceAllowed = $resource === null;
        foreach ($principal->roles() as $roleId) {
            $role = $configuration['roles'][$roleId] ?? null;
            if ($role === null || !in_array($permission, $role['permissions'], true)) continue;
            $allowed = true;
            if ($resource === null) { $resourceAllowed = true; continue; }
            $resources = $scope === 'write' ? $role['writeResources'] : $role['sqlResources'];
            if (in_array('*', $resources, true) || in_array($resource, $resources, true)) $resourceAllowed = true;
        }
        if (!$allowed || !$resourceAllowed) {
            $this->deny($principal, $permission, $resource !== null, $allowed ? 'resource_scope_denied' : 'permission_denied', $audit, $resource);
        }
        (new OperationalLogger())->info('api', 'API authorization accepted', [
            'permission' => $permission,
            'resource' => $resource,
        ]);
    }

    /**
     * Routines are callable only when registered, when the principal holds
     * routine.execute (frontend.read never qualifies), when one of its roles is
     * listed by the registry entry, and, for write routines, data.write.
     */
    public function authorizeRoutine(Principal $principal, array $routine): void
    {
        $this->authorizeInternal($principal, 'routine.execute', null, null, true);
        if (array_intersect($principal->roles(), $routine['roles']) === []) {
            $this->deny($principal, 'routine.execute', true, 'resource_scope_denied', true, 'routine:' . $routine['id']);
        }
        if ($routine['access'] === 'write') {
            $this->authorizeInternal($principal, 'data.write', null, null, true);
        }
    }

    public function authorizeAny(Principal $principal, array $permissions, ?string $resource = null, ?string $scope = null): void
    {
        foreach ($permissions as $permission) {
            try { $this->authorizeInternal($principal, $permission, $resource, $scope, false); return; }
            catch (ApiRequestException $exception) {
                if (!in_array($exception->getErrorCode(), ['AUTHORIZATION_DENIED', 'RESOURCE_ACCESS_DENIED'], true)) throw $exception;
            }
        }
        $this->deny($principal, implode('|', $permissions), $resource !== null, 'permission_denied', true, $resource);
    }

    private function deny(Principal $principal, string $permission, bool $resourceDenied, string $reason, bool $audit, ?string $resource = null): never
    {
        if ($audit) {
            (new OperationalLogger())->warning('api', 'API authorization denied', [
                'permission' => $permission,
                'resource' => $resource,
                'error_code' => $resourceDenied ? 'RESOURCE_ACCESS_DENIED' : 'AUTHORIZATION_DENIED',
            ]);
            $this->logger->audit('authorization.denied', 'denied', 'NOTICE', [
                'actorType' => $principal->authenticationType === 'api_key' ? 'api_key' : 'user',
                'actorId' => $principal->userId,
                'actorUsername' => $principal->username,
                'role' => $principal->backendRole ?? $principal->frontendRole,
                'authenticationMethod' => $principal->authenticationType,
                'action' => $permission,
                'resource' => $resource,
                'reason' => $reason,
                'component' => 'authorization',
            ]);
        }
        throw new ApiRequestException(
            $resourceDenied ? 'Resource access is denied.' : 'Authorization denied.',
            $resourceDenied ? 'RESOURCE_ACCESS_DENIED' : 'AUTHORIZATION_DENIED', [], 403
        );
    }
}
