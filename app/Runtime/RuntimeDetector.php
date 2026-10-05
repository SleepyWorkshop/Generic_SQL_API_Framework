<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';

final class RuntimeDetector
{
    public function information(): array
    {
        $family = PHP_OS_FAMILY;
        $binary = $this->runtimeBinary($family);
        $application = require ROOT_PATH . '/config/app.php';
        return [
            'operatingSystem' => $family,
            'architecture' => php_uname('m'),
            'phpVersion' => PHP_VERSION,
            'phpRuntime' => str_starts_with($binary, ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime') ? 'Bundled PHP' : 'System PHP',
            'runtimePath' => $this->displayPath($binary),
            'frameworkVersion' => (string)($application['version'] ?? 'unknown'),
            'apiVersion' => (string)($application['version'] ?? 'unknown'),
            'adminConsoleVersion' => '2.1',
            'odbcAvailable' => extension_loaded('odbc'),
            'pdoOdbcAvailable' => extension_loaded('pdo_odbc'),
            'supportedSqlServerDrivers' => SqlServerDriver::supportedDrivers(),
            'debugMode' => ($application['debug'] ?? false) === true,
        ];
    }

    /**
     * Identify the production web server that owns listeners and PHP workers.
     * The supported models are IIS + FastCGI on Windows and Nginx + PHP-FPM on
     * Linux, so the operating system is the fallback when the server does not
     * identify itself.
     */
    public function productionWebServer(?string $serverSoftware = null, ?string $family = null): string
    {
        $software = strtolower($serverSoftware ?? (string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (str_contains($software, 'microsoft-iis')) return 'iis';
        if (str_contains($software, 'nginx')) return 'nginx';
        $family ??= PHP_OS_FAMILY;
        if ($family === 'Windows') return 'iis';
        if ($family === 'Linux') return 'nginx';
        return 'web-server';
    }

    public function runtimeBinary(?string $family = null): string
    {
        $family ??= PHP_OS_FAMILY;
        $candidate = $family === 'Windows'
            ? ROOT_PATH . '/runtime/windows/php/php.exe'
            : ROOT_PATH . '/runtime/linux/php/php';
        return is_file($candidate) && is_executable($candidate) ? $candidate : PHP_BINARY;
    }

    private function displayPath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root . '/')
            ? substr($normalized, strlen($root) + 1)
            : $normalized;
    }
}
