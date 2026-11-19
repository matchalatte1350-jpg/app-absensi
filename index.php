<?php

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
}

if (file_exists(__DIR__.'/bootstrap/app.php')) {
    $app = require_once __DIR__.'/bootstrap/app.php';
} elseif (file_exists(__DIR__.'/app.php')) {
    $app = require_once __DIR__.'/app.php';
} else {
    http_response_code(500);
    exit('File entry point Laravel tidak ditemukan di root repository.');
}
