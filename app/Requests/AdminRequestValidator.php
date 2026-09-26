<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/../Runtime/DatabaseAuthenticationSupport.php';
require_once __DIR__ . '/../Configuration/RuntimeControls.php';

final class AdminRequestValidator
{
    private DatabaseAuthenticationSupport $databaseAuthentication;

    public function __construct(?DatabaseAuthenticationSupport $databaseAuthentication = null)
    {
        $this->databaseAuthentication = $databaseAuthentication ?? new DatabaseAuthenticationSupport();
    }

    private const SIMPLE_ACTIONS = [
        'admin.status',
        'admin.health',
        'admin.system.info',
        'admin.api.start',
        'admin.api.stop',
        'admin.api.restart',
        'admin.sqlParser.start',
        'admin.sqlParser.stop',
        'admin.sqlParser.restart',
        'admin.database.get',
        'admin.database.connect',
        'admin.database.disconnect',
        'admin.database.restart',
        'admin.settings.get',
        'admin.backup.history',
        'admin.backup.create',
    ];

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if (in_array($action, self::SIMPLE_ACTIONS, true)) {
            $this->rejectUnknown($request, ['action']);
            return ['action' => $action];
        }
        if (in_array($action, ['admin.database.test', 'admin.database.save'], true)) {
            $this->rejectUnknown($request, ['action', 'database']);
            return ['action' => $action, 'database' => $this->database($request['database'] ?? null)];
        }
        if ($action === 'admin.server.save') {
            $this->rejectUnknown($request, ['action', 'server']);
            return ['action' => $action, 'server' => $this->server($request['server'] ?? null)];
        }
        if ($action === 'admin.cors.save') {
            $this->rejectUnknown($request, ['action', 'cors']);
            return ['action' => $action, 'cors' => $this->cors($request['cors'] ?? null)];
        }
        if ($action === 'admin.authentication.save') {
            $this->rejectUnknown($request, ['action', 'mode']);
            $mode = $request['mode'] ?? null;
            if (!in_array($mode, AdminConfigurationRepository::AUTHENTICATION_MODES, true)) {
                $this->invalid([['path' => 'mode', 'message' => 'Unsupported authentication mode.']]);
            }
            return ['action' => $action, 'mode' => $mode];
        }
        if ($action === 'admin.runtime.save') {
            $this->rejectUnknown($request, ['action', 'runtime']);
            $runtime = $request['runtime'] ?? null;
            if (!is_array($runtime) || array_is_list($runtime)) {
                $this->invalid([['path' => 'runtime', 'message' => 'Runtime configuration must be an object.']]);
            }
            try {
                RuntimeControls::validate($runtime);
            } catch (InvalidArgumentException $exception) {
                $path = str_starts_with($exception->getMessage(), 'runtime.')
                    ? explode(' ', $exception->getMessage(), 2)[0]
                    : 'runtime';
                $this->invalid([['path' => rtrim($path, '.'), 'message' => $exception->getMessage()]]);
            }
            return ['action' => $action, 'runtime' => $runtime];
        }
        if ($action === 'admin.backup.download') {
            $this->rejectUnknown($request, ['action', 'recoveryPointId']);
            $id = $request['recoveryPointId'] ?? null;
            if (!is_string($id) || preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $id) !== 1) $this->invalid([['path' => 'recoveryPointId', 'message' => 'Invalid recovery point.']]);
            return ['action' => $action, 'recoveryPointId' => $id];
        }
        if ($action === 'admin.backup.preview') {
            $this->rejectUnknown($request, ['action', 'filename', 'archive']);
            $filename = $request['filename'] ?? null;
            $archive = $request['archive'] ?? null;
            if (!is_string($filename) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.zip$/i', $filename) !== 1) $this->invalid([['path' => 'filename', 'message' => 'A ZIP backup file is required.']]);
            if (!is_string($archive) || $archive === '' || strlen($archive) > 28 * 1024 * 1024 || preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $archive) !== 1) $this->invalid([['path' => 'archive', 'message' => 'Backup upload is invalid or too large.']]);
            return ['action' => $action, 'filename' => $filename, 'archive' => $archive];
        }
        if ($action === 'admin.backup.restore') {
            $this->rejectUnknown($request, ['action', 'uploadToken', 'confirmed']);
            $token = $request['uploadToken'] ?? null;
            if (!is_string($token) || preg_match('/^[a-f0-9]{48}$/', $token) !== 1 || ($request['confirmed'] ?? null) !== true) $this->invalid([['path' => 'confirmed', 'message' => 'Verified restore confirmation is required.']]);
            return ['action' => $action, 'uploadToken' => $token, 'confirmed' => true];
        }
        if ($action === 'admin.operational.event') {
            $this->rejectUnknown($request, ['action', 'event', 'page', 'operation', 'errorCode', 'requestId']);
            $event = $request['event'] ?? null;
            $allowed = [
                'frontend.restore.confirm.opened', 'frontend.restore.confirmed',
                'frontend.restore.request.started', 'frontend.restore.request.failed',
                'frontend.restore.request.success', 'frontend.api.request.failed',
                'frontend.javascript.error',
            ];
            if (!is_string($event) || !in_array($event, $allowed, true)) {
                $this->invalid([['path' => 'event', 'message' => 'Unsupported frontend operational event.']]);
            }
            $validated = ['action' => $action, 'event' => $event];
            foreach (['page', 'operation', 'errorCode', 'requestId'] as $field) {
                $value = $request[$field] ?? null;
                if ($value !== null && (!is_string($value) || strlen($value) > 100 || preg_match('/^[A-Za-z0-9_.:\/-]+$/', $value) !== 1)) {
                    $this->invalid([['path' => $field, 'message' => 'Invalid operational event metadata.']]);
                }
                $validated[$field] = $value;
            }
            return $validated;
        }
        throw new ApiRequestException('Invalid admin request.', 'INVALID_ADMIN_REQUEST');
    }

    private function server($value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'server', 'message' => 'Server configuration must be an object.']]);
        }
        $this->rejectUnknown($value, ['apiPortMinimum', 'apiPortMaximum', 'parserPortMinimum', 'parserPortMaximum', 'adminPort', 'bindAddress'], 'server.');
        $errors = [];
        $normalized = [];
        foreach (['apiPortMinimum', 'apiPortMaximum', 'parserPortMinimum', 'parserPortMaximum', 'adminPort'] as $field) {
            $port = filter_var($value[$field] ?? null, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                $errors[] = ['path' => 'server.' . $field, 'message' => 'Port must be between 1 and 65535.'];
            } else {
                $normalized[$field] = (int)$port;
            }
        }
        if (isset($normalized['apiPortMinimum'], $normalized['apiPortMaximum'])
            && $normalized['apiPortMinimum'] > $normalized['apiPortMaximum']) {
            $errors[] = ['path' => 'server.apiPortMaximum', 'message' => 'Maximum port must be greater than or equal to minimum port.'];
        }
        if (isset($normalized['parserPortMinimum'], $normalized['parserPortMaximum'])
            && $normalized['parserPortMinimum'] > $normalized['parserPortMaximum']) {
            $errors[] = ['path' => 'server.parserPortMaximum', 'message' => 'Maximum port must be greater than or equal to minimum port.'];
        }
        if (($value['bindAddress'] ?? null) !== '127.0.0.1') {
            $errors[] = ['path' => 'server.bindAddress', 'message' => 'Only the loopback bind address is supported.'];
        }
        if ($errors !== []) $this->invalid($errors);
        return [
            'apiPortMinimum' => $normalized['apiPortMinimum'],
            'apiPortMaximum' => $normalized['apiPortMaximum'],
            'parserPortMinimum' => $normalized['parserPortMinimum'],
            'parserPortMaximum' => $normalized['parserPortMaximum'],
            'adminPort' => $normalized['adminPort'],
            'bindAddress' => '127.0.0.1',
        ];
    }

    private function database($value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'database', 'message' => 'Database configuration must be an object.']]);
        }
        $this->rejectUnknown($value, [
            'provider', 'driver', 'server', 'port', 'database', 'authentication',
            'username', 'password', 'encrypt', 'trustServerCertificate',
        ], 'database.');
        $errors = [];
        $provider = strtolower(trim((string)($value['provider'] ?? '')));
        if ($provider !== 'sqlserver') {
            $errors[] = ['path' => 'database.provider', 'message' => 'Only sqlserver is currently supported.'];
        }
        $driver = trim((string)($value['driver'] ?? ''));
        if ($driver !== 'auto' && !in_array($driver, SqlServerDriver::supportedDrivers(), true)) {
            $errors[] = ['path' => 'database.driver', 'message' => 'Unsupported SQL Server ODBC driver.'];
        }
        $server = $this->connectionStringValue($value['server'] ?? null, 'server', true, $errors);
        $database = $this->connectionStringValue($value['database'] ?? null, 'database', true, $errors);
        $username = $this->connectionStringValue($value['username'] ?? '', 'username', false, $errors);
        $authentication = strtolower(trim((string)($value['authentication'] ?? '')));
        try {
            $this->databaseAuthentication->validate($authentication);
        } catch (InvalidArgumentException $exception) {
            $errors[] = ['path' => 'database.authentication', 'message' => $exception->getMessage()];
        }
        if ($authentication === 'sql' && $username === '') {
            $errors[] = ['path' => 'database.username', 'message' => 'Username is required for SQL authentication.'];
        }
        $port = $value['port'] ?? null;
        if ($port === '') $port = null;
        if ($port !== null && (filter_var($port, FILTER_VALIDATE_INT) === false
            || (int)$port < 1 || (int)$port > 65535)) {
            $errors[] = ['path' => 'database.port', 'message' => 'Port must be between 1 and 65535.'];
        }
        $password = $value['password'] ?? null;
        if ($password !== null && (!is_string($password) || strlen($password) > 4096)) {
            $errors[] = ['path' => 'database.password', 'message' => 'Password must be a string of at most 4096 bytes.'];
        }
        foreach (['encrypt', 'trustServerCertificate'] as $field) {
            if (!is_bool($value[$field] ?? null)) {
                $errors[] = ['path' => 'database.' . $field, 'message' => 'Value must be boolean.'];
            }
        }
        if ($errors !== []) $this->invalid($errors);

        return [
            'provider' => $provider,
            'driver' => $driver,
            'server' => $server,
            'database' => $database,
            'authentication' => $authentication,
            'username' => $username,
            'password' => $password,
            'port' => $port === null ? null : (string)(int)$port,
            'options' => [
                'encrypt' => $value['encrypt'],
                'trustServerCertificate' => $value['trustServerCertificate'],
            ],
        ];
    }

    private function cors($value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid([['path' => 'cors', 'message' => 'CORS configuration must be an object.']]);
        }
        $this->rejectUnknown($value, ['allowedOrigins', 'credentialsEnabled', 'allowedMethods'], 'cors.');
        $errors = [];
        $origins = $value['allowedOrigins'] ?? null;
        $normalized = [];
        if (!is_array($origins) || !array_is_list($origins)) {
            $errors[] = ['path' => 'cors.allowedOrigins', 'message' => 'Allowed origins must be an array.'];
        } else {
            foreach ($origins as $index => $origin) {
                try {
                    if (!is_string($origin)) throw new InvalidArgumentException();
                    $origin = AdminConfigurationRepository::normalizeOrigin($origin);
                    if (in_array($origin, $normalized, true)) throw new InvalidArgumentException();
                    $normalized[] = $origin;
                } catch (InvalidArgumentException $exception) {
                    $errors[] = ['path' => "cors.allowedOrigins.{$index}", 'message' => 'Origin must be a unique exact HTTP or HTTPS origin.'];
                }
            }
        }
        $credentials = $value['credentialsEnabled'] ?? null;
        if (!is_bool($credentials)) {
            $errors[] = ['path' => 'cors.credentialsEnabled', 'message' => 'Value must be boolean.'];
        }
        $methods = $value['allowedMethods'] ?? null;
        if (!is_array($methods) || !array_is_list($methods) || $methods === []) {
            $errors[] = ['path' => 'cors.allowedMethods', 'message' => 'Allowed methods must be a non-empty array.'];
            $methods = [];
        } else {
            $methods = array_values(array_unique(array_map(fn ($method) => strtoupper((string)$method), $methods)));
            if (array_diff($methods, AdminConfigurationRepository::ALLOWED_CORS_METHODS) !== []) {
                $errors[] = ['path' => 'cors.allowedMethods', 'message' => 'Unsupported CORS method.'];
            }
        }
        if ($errors !== []) $this->invalid($errors);
        return [
            'allowedOrigins' => $normalized,
            'credentialsEnabled' => $credentials,
            'allowedMethods' => $methods,
        ];
    }

    private function connectionStringValue($value, string $field, bool $required, array &$errors): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (($required && $value === '') || strlen($value) > 255
            || preg_match('/[;{}\r\n\x00]/', $value) === 1) {
            $errors[] = ['path' => 'database.' . $field, 'message' => 'Invalid database connection value.'];
        }
        return $value;
    }

    private function rejectUnknown(array $value, array $allowed, string $prefix = ''): void
    {
        $details = [];
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                $details[] = ['path' => $prefix . $key, 'message' => 'Unknown property.'];
            }
        }
        if ($details !== []) $this->invalid($details);
    }

    private function invalid(array $details): never
    {
        throw new ApiRequestException('Invalid admin request.', 'INVALID_ADMIN_REQUEST', $details);
    }
}
