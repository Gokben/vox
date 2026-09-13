<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(vox_app_release(), JSON_THROW_ON_ERROR);
