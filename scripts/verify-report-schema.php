<?php
declare(strict_types=1);

putenv('APP_ENV=local');
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/patient-report-schema.php';

$rows = db()->query("SELECT COALESCE(report_status, 'Boş') AS durum, COUNT(*) AS total FROM patients GROUP BY report_status ORDER BY report_status")->fetchAll();
foreach ($rows as $row) {
    echo $row['durum'] . ':' . $row['total'] . PHP_EOL;
}
