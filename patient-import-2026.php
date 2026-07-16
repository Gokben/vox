<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Bu araç yalnızca komut satırından çalıştırılır.\n");
putenv('APP_ENV=local');
require __DIR__ . '/config.php';

$xlsx = __DIR__ . '/storage/patients.xlsx';
if (!is_file($xlsx) || !class_exists('ZipArchive')) exit("2026 Excel yedeği veya ZipArchive bulunamadı.\n");
$zip = new ZipArchive();
if ($zip->open($xlsx) !== true) exit("2026 Excel yedeği açılamadı.\n");
$shared = [];
if (($sharedXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    $sx = simplexml_load_string($sharedXml);
    foreach ($sx->si as $si) { $parts = []; if (isset($si->t)) $parts[] = (string)$si->t; foreach ($si->r as $run) $parts[] = (string)$run->t; $shared[] = implode('', $parts); }
}
$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
if ($sheetXml === false) exit("2026 çalışma sayfası bulunamadı.\n");
$sheet = simplexml_load_string($sheetXml);
function patient_2026_column(string $ref): int { preg_match('/^[A-Z]+/', $ref, $m); $n = 0; foreach (str_split($m[0]) as $ch) $n = $n * 26 + ord($ch) - 64; return $n; }
function patient_2026_date(string $value): ?string { return $value === '' || !is_numeric($value) ? null : gmdate('Y-m-d', (int)(((float)$value - 25569) * 86400)); }

$pdo = db();
$startOrder = (int)$pdo->query('SELECT COALESCE(MAX(import_order),0) FROM patients')->fetchColumn();
$insert = $pdo->prepare('INSERT INTO patients(import_order,record_date,full_name,national_id,phone_primary,phone_secondary,birth_date,address,social_security,report_info,service_location,service_type,source_primary,source_marketing,source_detail,anamnesis,notes,approval,considering,rejected,staff_cansu,staff_busra,staff_belma) VALUES('.implode(',', array_fill(0,23,'?')).')');
$records = [];
foreach ($sheet->sheetData->row as $row) {
    if ((int)$row['r'] < 4) continue;
    $v = array_fill(1, 29, '');
    foreach ($row->c as $cell) { $index = patient_2026_column((string)$cell['r']); $raw = (string)$cell->v; $v[$index] = (string)$cell['t'] === 's' ? ($shared[(int)$raw] ?? '') : $raw; }
    if (trim($v[2]) === '') continue;
    $records[] = [$startOrder + count($records) + 1, patient_2026_date($v[1]), trim($v[2]), trim($v[3]), trim($v[4]), trim($v[5]), patient_2026_date($v[6]), trim($v[7]), trim($v[8]), trim($v[9]), trim($v[10]), trim($v[11]), trim($v[12]), trim($v[13]), trim($v[14]), trim($v[15]), trim($v[16]), $v[17] !== '' ? 1 : 0, $v[18] !== '' ? 1 : 0, $v[19] !== '' ? 1 : 0, $v[21] !== '' ? 1 : 0, $v[22] !== '' ? 1 : 0, $v[23] !== '' ? 1 : 0];
}
$pdo->beginTransaction();
try { foreach ($records as $record) $insert->execute($record); $pdo->commit(); echo count($records)." eski 2026 kaydı eklendi.\n"; }
catch (Throwable $e) { $pdo->rollBack(); throw $e; }
