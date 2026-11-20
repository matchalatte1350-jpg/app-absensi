<?php
$publicPath = __DIR__ . '/../public/index.php';
if (!file_exists($publicPath)) {
    $publicPath = __DIR__ . '/../../public/index.php';
}
require $publicPath;
