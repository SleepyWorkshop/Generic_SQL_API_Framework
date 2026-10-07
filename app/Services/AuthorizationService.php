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

    /**
     * The single authorization decision for every API principal. Sessions,
     * managed API keys, the legacy key, and anonymous mode all reach this
     * method with a Principal; the authentication method never changes the
     * outcome for the same roles.
     */
    public function authorize(Principal $principal, string $permission): void
    {
        $this->authorizeInternal($principal, $permission, true);
    }

    private function authorizeInternal(Principal $principal, string $permission, bool $audit): void
    {
        if (!$principal->enabled) $this->deny($principal, $permission, 'principal_disabled', $audit);
        // Frontend access is the base read entitlement in the frontend domain;
        // Application Administrator adds management, not a second read model.
        if ($permission === 'frontend.read' && $principal->frontendAccess) return;
        $configuration = $this->repository->load();
        foreach ($principal->roles() as $roleId) {
            if (in_array($permission, $configuration['roles'][$roleId]['permissions'] ?? [], true)) {
                (new OperationalLogger())->info('api', 'API authorization accepted', ['permission' => $permission]);
                return;
            }
        }
        $this->deny($principal, $permission, 'permission_denied', $audit);
    }

    /** Passes when the principal holds at least one of the permissions. */
    public function authorizeAny(Principal $principal, array $permissions): void
    {
        foreach ($permissions as $permission) {
            try { $this->authorizeInternal($principal, $permission, false); return; }
            catch (ApiRequestException $exception) {
                if ($exception->getErrorCode() !== 'AUTHORIZATION_DENIED') throw $exception;
            }
        }
        $this->deny($principal, implode('|', $permissions), 'permission_denied', true);
    }

    /** Passes only when the principal holds every permission. */
    public function authorizeAll(Principal $principal, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->authorizeInternal($principal, $permission, true);
        }
    }

    private function deny(Principal $principal, string $permission, string $reason, bool $audit): never
    {
        if ($audit) {
            (new OperationalLogger())->warning('api', 'API authorization denied', [
                'permission' => $permission,
                'error_code' => 'AUTHORIZATION_DENIED',
            ]);
            $this->logger->audit('authorization.denied', 'denied', 'NOTICE', [
                'actorType' => $principal->authenticationType === 'api_key' ? 'api_key' : 'user',
                'actorId' => $principal->userId,
                'actorUsername' => $principal->username,
                'role' => $principal->backendRole ?? $principal->frontendRole,
                'authenticationMethod' => $principal->authenticationType,
                'action' => $permission,
                'reason' => $reason,
                'component' => 'authorization',
            ]);
        }
        throw new ApiRequestException('Authorization denied.', 'AUTHORIZATION_DENIED', [], 403);
    }
}
