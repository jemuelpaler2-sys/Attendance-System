<?php

declare(strict_types=1);

/**
 * Global application configuration.
 * Local and online database settings live in config.local.php and config.online.php.
 */

$smartrollEnvironment = strtolower(trim((string) getenv('SMARTROLL_ENV')));
if (!in_array($smartrollEnvironment, ['local', 'online'], true)) {
    $smartrollHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $smartrollHost = preg_replace('/:\d+$/', '', $smartrollHost) ?? '';
    $smartrollHost = trim($smartrollHost, '[]');

    if ($smartrollHost === '') {
        $isLocalHost = in_array(PHP_SAPI, ['cli', 'cli-server'], true);
    } else {
        $isLocalHost = $smartrollHost === 'localhost'
            || $smartrollHost === '127.0.0.1'
            || $smartrollHost === '::1'
            || str_ends_with($smartrollHost, '.local')
            || str_ends_with($smartrollHost, '.test')
            || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $smartrollHost) === 1;
    }

    $smartrollEnvironment = $isLocalHost ? 'local' : 'online';
}

$smartrollConfigFile = __DIR__ . '/config.' . $smartrollEnvironment . '.php';
if (!is_file($smartrollConfigFile)) {
    http_response_code(500);
    exit('Application configuration is missing.');
}

$smartrollConfig = require $smartrollConfigFile;
$smartrollDb = $smartrollConfig['db'] ?? [];

define('APP_NAME', 'SmartRoll');
define('SCHOOL_NAME', 'Nonok Norte National High School');
define('APP_VERSION', '1.0.0');
define('APP_ENV', (string) ($smartrollConfig['environment'] ?? $smartrollEnvironment));
define('APP_DEBUG', (bool) ($smartrollConfig['debug'] ?? false));

define('DB_HOST', (string) ($smartrollDb['host'] ?? ''));
define('DB_PORT', (string) ($smartrollDb['port'] ?? '3306'));
define('DB_NAME', (string) ($smartrollDb['name'] ?? ''));
define('DB_USER', (string) ($smartrollDb['user'] ?? ''));
define('DB_PASS', (string) ($smartrollDb['pass'] ?? ''));

unset($smartrollEnvironment, $smartrollHost, $isLocalHost, $smartrollConfigFile, $smartrollConfig, $smartrollDb);

date_default_timezone_set('Asia/Manila');

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('smartroll_session');
    session_start();
}

/**
 * Detect the application base URL. This lets the project work from any
 * folder name inside htdocs without editing links.
 */
$appRoot = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
$docRoot = isset($_SERVER['DOCUMENT_ROOT'])
    ? str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT']))
    : '';

$basePath = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $basePath = substr($appRoot, strlen($docRoot));
}
define('BASE_URL', rtrim(str_replace('\\', '/', $basePath), '/'));
