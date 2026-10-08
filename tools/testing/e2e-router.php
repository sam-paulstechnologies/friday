<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/e2e-environment.php';
isolatedE2eEnvironment();
$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($public.'/'.rawurldecode($path));
if ($path !== '/' && $file && str_starts_with($file, realpath($public).DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->booted(fn () => Http::preventStrayRequests());
$app->handleRequest(Request::capture());
