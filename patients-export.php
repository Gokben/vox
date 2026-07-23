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

function patient_export_value(mixed $value, bool $identifier = false): string
{
    $text = trim((string)$value);
    if ($text === '') return '';
    if ($identifier || preg_match('/^[=+\-@]/u', $text)) return "'" . $text;
    return $text;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="hasta-kayitlari-' . $year . '.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'No', 'Tarih', 'Ad Soyad', 'T.C. Kimlik No', 'Telefon 1', 'Telefon 2',
    'Doğum Tarihi', 'Adres', 'Sosyal Güvence', 'Rapor', 'Hizmet Yeri',
    'Başvuru Detayı', 'Kaynak', 'Açıklama', 'Sonuç', 'İlgili',
], ';', '"', '');

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
    fputcsv($output, [
        patient_export_value($row['import_order']),
        patient_export_value($row['record_date']),
        patient_export_value($row['full_name']),
        patient_export_value($row['national_id'], true),
        patient_export_value($row['phone_primary'], true),
        patient_export_value($row['phone_secondary'], true),
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
    ], ';', '"', '');
}
fclose($output);
