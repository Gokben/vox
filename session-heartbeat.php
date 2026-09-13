<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'expires_in' => SESSION_IDLE_TIMEOUT], JSON_UNESCAPED_UNICODE);
