<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/UserManagementService.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';

final class UserManagementController extends BaseController
{
    private UserManagementService $userService;
    private AuthSessionService $sessionService;

    public function __construct(
        ?UserManagementService $userService = null,
        ?AuthSessionService $sessionService = null
    ) {
        $this->userService = $userService ?? new UserManagementService();
        $this->sessionService = $sessionService ?? new AuthSessionService();
    }

    public function listUsers(array $request): void
    {
        $this->success($this->userService->listUsers(), 'Users loaded.');
    }

    public function createUser(array $request): void
    {
        $this->success(
            [$this->userService->createUser(
                $request['username'],
                $request['password'],
                $request['backendRole'],
                $request['frontendAccess'],
                $request['frontendRole'],
                $request['enabled'],
                $request['name'],
                $request['mobile'],
                $request['email']
            )],
            'User created.',
            201
        );
    }

    public function updateUser(array $request): void
    {
        $this->success(
            [$this->userService->updateUserProfile(
                $request['username'], $request['name'], $request['newUsername'], $request['mobile'], $request['email']
            )],
            'User profile updated.'
        );
    }

    public function enableUser(array $request): void
    {
        $this->success([$this->userService->setEnabled($request['username'], true)], 'User enabled.');
    }

    public function disableUser(array $request): void
    {
        $this->success([$this->userService->setEnabled($request['username'], false)], 'User disabled.');
    }

    public function deleteUser(array $request): void
    {
        $this->success([$this->userService->deleteUser(
            $request['username'],
            (string)$this->sessionService->authenticatedUsername()
        )], 'User deleted.');
    }

    public function changePassword(array $request): void
    {
        $this->success(
            [$this->userService->changePassword($request['username'], $request['newPassword'])],
            'Password changed.'
        );
    }

    public function assignAuthorization(array $request): void
    {
        $this->success([$this->userService->assignAuthorization(
            $request['username'],
            $request['backendRole'],
            $request['frontendAccess'],
            $request['frontendRole'],
            (string)$this->sessionService->authenticatedUserId()
        )], 'User authorization updated.');
    }
}
