<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Bu araç yalnızca komut satırından çalıştırılır.\n");
putenv('APP_ENV=local');
require __DIR__ . '/config.php';

$xlsx = __DIR__ . '/storage/vox2026.xlsx';
if (!is_file($xlsx)) exit("Excel bulunamadı: $xlsx\n");
if (!class_exists('ZipArchive')) exit("PHP ZipArchive eklentisi gerekli.\n");

$zip = new ZipArchive();
if ($zip->open($xlsx) !== true) exit("Excel açılamadı.\n");
$shared = [];
$sharedXml = $zip->getFromName('xl/sharedStrings.xml');
if ($sharedXml !== false) {
    $sx = simplexml_load_string($sharedXml);
    foreach ($sx->si as $si) {
        $parts = [];
        if (isset($si->t)) $parts[] = (string)$si->t;
        foreach ($si->r as $run) $parts[] = (string)$run->t;
        $shared[] = implode('', $parts);
    }
}
$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
if ($sheetXml === false) exit("Çalışma sayfası bulunamadı.\n");
$sheet = simplexml_load_string($sheetXml);

function vox_col_index(string $ref): int {
    preg_match('/^[A-Z]+/', $ref, $m); $n = 0;
    foreach (str_split($m[0]) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n;
}
function vox_excel_date(string $value): ?string {
    if ($value === '' || !is_numeric($value)) return null;
    return gmdate('Y-m-d', (int)(($value - 25569) * 86400));
}

$records = [];
foreach ($sheet->sheetData->row as $row) {
    $rowNumber = (int)$row['r'];
    if ($rowNumber < 4) continue;
    $values = array_fill(1, 29, '');
    foreach ($row->c as $cell) {
        $index = vox_col_index((string)$cell['r']);
        $raw = (string)$cell->v;
        $values[$index] = (string)$cell['t'] === 's' ? ($shared[(int)$raw] ?? '') : $raw;
    }
    if (trim($values[2]) === '') continue;
    $records[] = [
        count($records) + 1, vox_excel_date($values[1]), trim($values[2]), trim($values[3]),
        trim($values[4]), trim($values[5]), vox_excel_date($values[6]), trim($values[7]),
        trim($values[8]), trim($values[9]), trim($values[10]), trim($values[11]),
        trim($values[12]), trim($values[13]), trim($values[14]), trim($values[15]), trim($values[16]),
        $values[17] !== '' ? 1 : 0, $values[18] !== '' ? 1 : 0, $values[19] !== '' ? 1 : 0,
        $values[21] !== '' ? 1 : 0, $values[22] !== '' ? 1 : 0, $values[23] !== '' ? 1 : 0
    ];
}

$pdo = db();
$insert = $pdo->prepare('INSERT INTO patients(import_order,record_date,full_name,national_id,phone_primary,phone_secondary,birth_date,address,social_security,report_info,service_location,service_type,source_primary,source_marketing,source_detail,anamnesis,notes,approval,considering,rejected,staff_cansu,staff_busra,staff_belma) VALUES('.implode(',', array_fill(0,23,'?')).')');
$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM patients');
    foreach ($records as $record) $insert->execute($record);
    $pdo->commit();
    echo count($records) . " hasta kaydı yeniden aktarıldı.\n";
} catch (Throwable $e) {
    $pdo->rollBack(); throw $e;
}
