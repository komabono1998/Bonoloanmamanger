<?php
/** Application bootstrap: loads libraries and services, configures PHP, and starts the session. */

define('BASE_PATH', dirname(__DIR__));
if (!defined('DB_FILE') && getenv('BONO_DB')) define('DB_FILE', getenv('BONO_DB'));   // optional SQLite file override (testing / evaluation)
date_default_timezone_set('Africa/Kampala');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@mkdir(BASE_PATH . '/storage/logs', 0775, true);
ini_set('error_log', (getenv('LOG_TO_STDERR') === '1' && DIRECTORY_SEPARATOR === '/') ? '/dev/stderr' : BASE_PATH . '/storage/logs/php-error.log');

foreach (['config', 'triggers', 'db', 'helpers', 'settings', 'perms', 'mfa', 'storage', 'branch', 'brand', 'mail', 'auth', 'install', 'tenant'] as $f) require_once BASE_PATH . "/app/lib/$f.php";
foreach (['calc', 'approvals', 'eod', 'loans', 'cash', 'payments', 'penalty', 'backup', 'reports', 'system', 'pwreset', 'dashboard'] as $f) require_once BASE_PATH . "/app/services/$f.php";

tenant_boot();   // bind the request to an organisation before any data is read
if (PHP_SAPI !== 'cli') start_session();
