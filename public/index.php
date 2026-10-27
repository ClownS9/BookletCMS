<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
if (! file_exists(__DIR__.'/../vendor/autoload.php')) {
    http_response_code(503);
    exit('This copy of Booklet is missing its vendor folder. Upload the whole download (the .zip from the website has it), or run “composer install”.');
}
require __DIR__.'/../vendor/autoload.php';

// Not installed yet (no .env): the install page, before Laravel starts.
if (App\Install\WebInstaller::needed(dirname(__DIR__))) {
    $page = (new App\Install\WebInstaller(dirname(__DIR__)))->handle($_SERVER, $_POST);
    http_response_code($page['status']);
    foreach ($page['headers'] as $name => $value) {
        header($name.': '.$value);
    }
    exit($page['body']);
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
