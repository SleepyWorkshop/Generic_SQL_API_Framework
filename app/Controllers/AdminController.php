<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/AdminService.php';

final class AdminController extends BaseController
{
    private AdminService $service;

    public function __construct(?AdminService $service = null)
    {
        $this->service = $service ?? new AdminService();
    }

    public function dispatch(array $request): void
    {
        $action = $request['action'];
        if ($action === 'admin.status' || $action === 'admin.health') $result = $this->service->status();
        elseif ($action === 'admin.system.info') $result = $this->service->systemInformation();
        elseif ($action === 'admin.api.start') $result = $this->service->controlApi('start');
        elseif ($action === 'admin.api.stop') $result = $this->service->controlApi('stop');
        elseif ($action === 'admin.api.restart') $result = $this->service->controlApi('restart');
        elseif ($action === 'admin.sqlParser.start') $result = $this->service->controlSqlParser('start');
        elseif ($action === 'admin.sqlParser.stop') $result = $this->service->controlSqlParser('stop');
        elseif ($action === 'admin.sqlParser.restart') $result = $this->service->controlSqlParser('restart');
        elseif ($action === 'admin.database.get') $result = $this->service->databaseConfiguration();
        elseif ($action === 'admin.database.connect') $result = $this->service->controlDatabase('connect');
        elseif ($action === 'admin.database.disconnect') $result = $this->service->controlDatabase('disconnect');
        elseif ($action === 'admin.database.restart') $result = $this->service->controlDatabase('restart');
        elseif ($action === 'admin.database.test') $result = $this->service->testDatabase($request['database']);
        elseif ($action === 'admin.database.save') $result = $this->service->saveDatabase($request['database']);
        elseif ($action === 'admin.settings.get') $result = $this->service->settings();
        elseif ($action === 'admin.server.save') $result = $this->service->saveServer($request['server']);
        elseif ($action === 'admin.cors.save') $result = $this->service->saveCors($request['cors']);
        elseif ($action === 'admin.authentication.save') $result = $this->service->saveAuthentication($request['mode']);
        elseif ($action === 'admin.runtime.save') $result = $this->service->saveRuntime($request['runtime']);
        elseif ($action === 'admin.backup.history') $result = ['recoveryPoints' => $this->service->backupHistory()];
        elseif ($action === 'admin.backup.create') $result = $this->service->createBackup();
        elseif ($action === 'admin.backup.download') $result = $this->service->downloadBackup($request['recoveryPointId']);
        elseif ($action === 'admin.backup.preview') $result = $this->service->previewBackupRestore($request['filename'], $request['archive']);
        elseif ($action === 'admin.backup.restore') $result = $this->service->restoreBackup($request['uploadToken'], $request['confirmed']);
        else $result = $this->service->saveAuthentication($request['mode']);

        $this->success([$result], 'Admin operation completed.');
    }
}
