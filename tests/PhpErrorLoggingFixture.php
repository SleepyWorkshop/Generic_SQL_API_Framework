<?php

if ($argc !== 4) exit(64);

putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $argv[1]);
putenv('GENERIC_LOG_DIR=' . $argv[1]);
putenv('GENERIC_APP_ENV=production');
if (!defined('API_REQUEST_ID')) define('API_REQUEST_ID', 'req_php_' . $argv[2]);

require_once __DIR__ . '/../core/ExceptionHandler.php';

error_reporting(E_ALL);
ExceptionHandler::register($argv[2]);

if ($argv[3] === 'warning') {
    trigger_error('controlled warning', E_USER_WARNING);
    exit(0);
}
if ($argv[3] === 'exception') {
    throw new RuntimeException('controlled exception');
}
if ($argv[3] === 'fatal') {
    trigger_error('controlled fatal', E_USER_ERROR);
}

exit(65);
