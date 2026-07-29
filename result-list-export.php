<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('XLSX oluşturmak için ZipArchive eklentisi gereklidir.');
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$year = (int)($_GET['year_from'] ?? 0);
$selectedResults = array_values(array_intersect(['approved', 'considering', 'rejected', 'none'], array_map('strval', (array)($_GET['results'] ?? []))));
$resultConditions = [
    'approved' => 'COALESCE(p.approval,0)=1',
    'considering' => 'COALESCE(p.approval,0)=0 AND COALESCE(p.considering,0)=1',
    'rejected' => 'COALESCE(p.approval,0)=0 AND COALESCE(p.considering,0)=0 AND COALESCE(p.rejected,0)=1',
    'none' => 'COALESCE(p.approval,0)=0 AND COALESCE(p.considering,0)=0 AND COALESCE(p.rejected,0)=0',
];
$where = [];
$params = [];
if ($dateFrom !== '' || $dateTo !== '') {
    if ($dateFrom !== '') { $where[] = 'p.record_date >= ?'; $params[] = $dateFrom; }
    if ($dateTo !== '') { $where[] = 'p.record_date <= ?'; $params[] = $dateTo; }
} elseif ($year) {
    $where[] = 'p.record_date >= ?';
    $where[] = 'p.record_date < ?';
    $params[] = sprintf('%04d-01-01', $year);
    $params[] = sprintf('%04d-01-01', $year + 1);
}
if ($selectedResults) $where[] = '(' . implode(' OR ', array_map(static fn(string $key): string => '(' . $resultConditions[$key] . ')', $selectedResults)) . ')';
$statement = db()->prepare('SELECT p.* FROM patients p' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.record_date DESC,p.import_order ASC,p.id ASC');
$statement->execute($params);

$rows = [['No', 'Kayıt Tarihi', 'Sonuç', 'Ad Soyad', 'T.C. Kimlik No', 'Telefon', 'Açıklama']];
foreach ($statement->fetchAll() as $patient) {
    $result = !empty($patient['approval']) ? 'Onay' : (!empty($patient['considering']) ? 'Düşünecek' : (!empty($patient['rejected']) ? 'Ret' : 'Sonuç Yok'));
    $rows[] = [(string)$patient['import_order'], (string)$patient['record_date'], $result, (string)$patient['full_name'], (string)$patient['national_id'], (string)$patient['phone_primary'], (string)$patient['notes']];
}

$columnName = static function (int $index): string { $name = ''; while ($index > 0) { $index--; $name = chr(65 + ($index % 26)) . $name; $index = intdiv($index, 26); } return $name; };
$xmlValue = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$sheetRows = '';
foreach ($rows as $rowNumber => $row) {
    $cells = '';
    foreach ($row as $columnIndex => $value) {
        $style = $rowNumber === 0 ? ' s="1"' : '';
        $cells .= '<c r="' . $columnName($columnIndex + 1) . ($rowNumber + 1) . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $xmlValue(trim((string)$value)) . '</t></is></c>';
    }
    $sheetRows .= '<row r="' . ($rowNumber + 1) . '">' . $cells . '</row>';
}
$lastRow = count($rows);
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:G' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="2" width="15" customWidth="1"/><col min="3" max="3" width="16" customWidth="1"/><col min="4" max="4" width="30" customWidth="1"/><col min="5" max="6" width="20" customWidth="1"/><col min="7" max="7" width="55" customWidth="1"/></cols><sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A1:G' . $lastRow . '"/></worksheet>';
$file = tempnam(sys_get_temp_dir(), 'vox-result-xlsx-');
if ($file === false) exit('Geçici XLSX dosyası oluşturulamadı.');
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) exit('XLSX paketi oluşturulamadı.');
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sonuç Listesi" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
$zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF16883D"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center"/></xf></cellXfs></styleSheet>');
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="sonuc-listesi.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');
readfile($file);
@unlink($file);
