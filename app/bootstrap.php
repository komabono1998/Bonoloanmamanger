<?php
/** Application bootstrap: loads libraries and services, configures PHP, and starts the session. */

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('Africa/Kampala');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@mkdir(BASE_PATH . '/storage/logs', 0775, true);
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

foreach (['db', 'helpers', 'settings', 'perms', 'auth', 'install'] as $f) require_once BASE_PATH . "/app/lib/$f.php";
foreach (['calc', 'eod', 'loans', 'cash', 'payments', 'penalty', 'backup', 'reports'] as $f) require_once BASE_PATH . "/app/services/$f.php";

if (PHP_SAPI !== 'cli') start_session();
