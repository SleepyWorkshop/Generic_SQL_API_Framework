<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';

final class ApiKeyRequestValidator
{
    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if ($action === 'auth.apiKeys.list') {
            $this->exact($request, ['action']);
            return ['action' => $action];
        }
        if ($action === 'auth.apiKeys.create') {
            return $this->validateCreate($request);
        }
        if (in_array($action, ['auth.apiKeys.enable', 'auth.apiKeys.disable', 'auth.apiKeys.revoke'], true)) {
            $this->exact($request, ['action', 'id']);
            $id = $request['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
                $this->invalid([['path' => 'id', 'message' => 'A valid API key identifier is required.']]);
            }
            return ['action' => $action, 'id' => $id];
        }
        throw new ApiRequestException('Invalid API key request.', 'INVALID_API_KEY_REQUEST');
    }

    private function validateCreate(array $request): array
    {
        $this->exact($request, ['action', 'name', 'ownerUsername', 'roles']);
        $errors = [];
        $name = is_string($request['name'] ?? null) ? trim($request['name']) : '';
        if ($name === '' || strlen($name) > 100) {
            $errors[] = ['path' => 'name', 'message' => 'Name must contain 1 to 100 characters.'];
        }
        $owner = is_string($request['ownerUsername'] ?? null) ? trim($request['ownerUsername']) : '';
        if ($owner === '') {
            $errors[] = ['path' => 'ownerUsername', 'message' => 'Owner is required.'];
        }
        $roles = $request['roles'] ?? null;
        if (!is_array($roles)
            || !array_is_list($roles)
            || count($roles) !== 1
            || !is_string($roles[0] ?? null)
            || !in_array($roles[0], RoleModel::apiKeyRoles(), true)) {
            $errors[] = [
                'path' => 'roles',
                'message' => 'Exactly one supported API key role is required.',
            ];
        }
        if ($errors !== []) {
            $this->invalid($errors);
        }
        return [
            'action' => $request['action'],
            'name' => $name,
            'ownerUsername' => $owner,
            'roles' => $roles,
        ];
    }

    private function exact(array $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request), $allowed);
        if ($unknown !== []) {
            $this->invalid(array_map(
                fn ($key) => ['path' => $key, 'message' => 'Unknown property.'],
                $unknown
            ));
        }
    }

    private function invalid(array $details): never
    {
        throw new ApiRequestException('Invalid API key request.', 'INVALID_API_KEY_REQUEST', $details);
    }
}
