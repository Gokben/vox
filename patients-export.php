<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/patient-report-schema.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/source-bootstrap.php';
require_login();

$year = (int)($_GET['year'] ?? 2026);
if (!in_array($year, [2023, 2024, 2025, 2026], true)) $year = 2026;

$extendedSchemaReady = true;
try {
    if (function_exists('ensure_branch_schema')) ensure_branch_schema();
    ensure_patient_service_type_schema();
    ensure_patient_source_schema();
    ensure_patient_report_schema();
} catch (Throwable $exception) {
    $extendedSchemaReady = false;
    error_log('patients-export.php schema: ' . $exception->getMessage());
}

$where = $year === 2025
    ? "(patients.record_date LIKE '2025%' OR patients.record_date IS NULL OR patients.record_date='')"
    : 'patients.record_date LIKE ?';
$args = $year === 2025 ? [] : [$year . '%'];
$sql = $extendedSchemaReady
    ? 'SELECT patients.*,service_type_definitions.name AS service_type_name,source_definitions.name AS source_name
       FROM patients
       LEFT JOIN service_type_definitions ON service_type_definitions.id=patients.service_type_id
       LEFT JOIN source_definitions ON source_definitions.id=patients.source_id'
    : 'SELECT patients.*,patients.service_type AS service_type_name,NULL AS source_name FROM patients';
$sql .= ' WHERE ' . $where . ' ORDER BY patients.import_order,patients.id';

try {
    $statement = db()->prepare($sql);
    $statement->execute($args);
    $rows = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('patients-export.php query: ' . $exception->getMessage());
    $fallback = db()->prepare(
        'SELECT patients.*,patients.service_type AS service_type_name,NULL AS source_name
         FROM patients WHERE ' . $where . ' ORDER BY patients.import_order,patients.id'
    );
    $fallback->execute($args);
    $rows = $fallback->fetchAll();
}

function patient_export_value(mixed $value): string
{
    $text = trim((string)$value);
    return preg_replace('/[^\P{C}\t\r\n]/u', '', $text) ?? $text;
}

function patient_export_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function patient_export_column(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

$exportRows = [[
    'No', 'Tarih', 'Ad Soyad', 'T.C. Kimlik No', 'Telefon 1', 'Telefon 2',
    'Doğum Tarihi', 'Adres', 'Sosyal Güvence', 'Rapor', 'Hizmet Yeri',
    'Başvuru Detayı', 'Kaynak', 'Açıklama', 'Sonuç', 'İlgili',
]];

foreach ($rows as $row) {
    $result = $row['approval'] ? 'Onay' : ($row['considering'] ? 'Düşünecek' : ($row['rejected'] ? 'Red' : ''));
    $staff = implode(', ', array_filter([
        $row['staff_cansu'] ? 'Cansu' : '',
        $row['staff_busra'] ? 'Büşra' : '',
        $row['staff_belma'] ? 'Belma Baysan' : '',
        !empty($row['staff_yeliz']) ? 'Yeliz' : '',
        !empty($row['staff_gunes']) ? 'Güneş' : '',
        !empty($row['staff_erva']) ? 'Erva' : '',
        !empty($row['staff_merve']) ? 'Merve' : '',
        !empty($row['staff_seyma']) ? 'Şeyma' : '',
    ]));
    $exportRows[] = [
        patient_export_value($row['import_order']),
        patient_export_value($row['record_date']),
        patient_export_value($row['full_name']),
        patient_export_value($row['national_id']),
        patient_export_value($row['phone_primary']),
        patient_export_value($row['phone_secondary']),
        patient_export_value($row['birth_date']),
        patient_export_value($row['address']),
        patient_export_value($row['social_security']),
        patient_export_value($row['report_status'] ?? $row['report_info'] ?? ''),
        patient_export_value(patient_service_type_name($row)),
        patient_export_value(trim($row['source_primary'] . ' ' . $row['source_marketing'] . ' ' . $row['source_detail'])),
        patient_export_value($row['source_name'] ?? ''),
        patient_export_value($row['notes']),
        $result,
        $staff,
    ];
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('XLSX oluşturmak için ZipArchive eklentisi gereklidir.');
}

$sheetRows = '';
foreach ($exportRows as $rowIndex => $values) {
    $excelRow = $rowIndex + 1;
    $cells = '';
    foreach ($values as $columnIndex => $value) {
        $reference = patient_export_column($columnIndex + 1) . $excelRow;
        $style = $rowIndex === 0 ? ' s="1"' : '';
        $cells .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' .
            patient_export_xml((string)$value) . '</t></is></c>';
    }
    $sheetRows .= '<row r="' . $excelRow . '">' . $cells . '</row>';
}
$lastRow = count($exportRows);
$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<dimension ref="A1:P' . $lastRow . '"/>' .
    '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
    '<cols><col min="1" max="1" width="10" customWidth="1"/><col min="2" max="2" width="13" customWidth="1"/>' .
    '<col min="3" max="3" width="28" customWidth="1"/><col min="4" max="6" width="20" customWidth="1"/>' .
    '<col min="7" max="7" width="13" customWidth="1"/><col min="8" max="8" width="42" customWidth="1"/>' .
    '<col min="9" max="13" width="20" customWidth="1"/><col min="14" max="14" width="55" customWidth="1"/>' .
    '<col min="15" max="16" width="18" customWidth="1"/></cols>' .
    '<sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A1:P' . $lastRow . '"/></worksheet>';

$temporaryFile = tempnam(sys_get_temp_dir(), 'vox-xlsx-');
if ($temporaryFile === false) {
    http_response_code(500);
    exit('Geçici XLSX dosyası oluşturulamadı.');
}
$zip = new ZipArchive();
if ($zip->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($temporaryFile);
    http_response_code(500);
    exit('XLSX paketi oluşturulamadı.');
}
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hasta Kayıtları ' . $year . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
$zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF16883D"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="hasta-kayitlari-' . $year . '.xlsx"');
header('Content-Length: ' . filesize($temporaryFile));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($temporaryFile);
@unlink($temporaryFile);
