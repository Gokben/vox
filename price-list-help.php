<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$rules = file_get_contents(__DIR__ . '/SIGNIA-IMPORT-RULES.md');
if ($rules === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Aktarım kuralları okunamadı.'], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['title' => 'Signia — Fiyat Listesi Aktarım Kuralları', 'text' => $rules], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
