<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/SetupService.php';

final class SetupController extends BaseController
{
    private SetupService $setupService;

    public function __construct(?SetupService $setupService = null)
    {
        $this->setupService = $setupService ?? new SetupService();
    }

    public function status(array $request): void
    {
        $this->success(
            [$this->setupService->status()],
            'Installation status loaded.'
        );
    }

    public function createAdmin(array $request): void
    {
        $this->success(
            [$this->setupService->createInitialAdmin(
                $request['name'], $request['username'], $request['mobile'], $request['email'], $request['password']
            )],
            'Initial administrator created.',
            201
        );
    }
}
