<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Dev server PHP bawaan (`php artisan serve`) memetakan /app/<path> ke folder
// public/app yang punya index, sehingga SCRIPT_NAME berisi "/app/<index>" dan
// Symfony memotong prefix "/app" dari path -> route catch-all SPA (app/{any?})
// tidak cocok dan deep link seperti /app/login menghasilkan 404. Samakan dengan
// Apache/nginx: front controller selalu dianggap /index.php.
if (PHP_SAPI === 'cli-server' && '' !== ($scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '') && realpath($scriptFilename) !== __FILE__) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
