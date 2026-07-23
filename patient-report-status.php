<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/patient-report-schema.php';
require_login();

$rawIds = explode(',', (string)($_GET['ids'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0)));
header('Content-Type: application/json; charset=utf-8');
if (!$ids) { echo '{}'; exit; }

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$statement = db()->prepare("SELECT id, report_status FROM patients WHERE id IN ($placeholders)");
$statement->execute($ids);
$result = [];
foreach ($statement->fetchAll() as $row) $result[(int)$row['id']] = (string)($row['report_status'] ?? '');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
