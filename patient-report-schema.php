<?php
declare(strict_types=1);

const REPORT_STATUSES = ['Rapor getirdi', 'Rapor getirecek', 'Rapor gerekmedi', 'Özel reçete getirdi', 'Özel reçete getirecek'];

function ensure_patient_report_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $columns = array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name');
        $tableSql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'patients'")->fetchColumn();
        if (in_array('report_status', $columns, true) && !str_contains($tableSql, "'Özel reçete getirecek'")) {
            $pdo->exec('ALTER TABLE patients RENAME COLUMN report_status TO report_status_legacy');
            $columns = array_values(array_diff($columns, ['report_status']));
            $columns[] = 'report_status_legacy';
        }
        if (!in_array('report_status', $columns, true)) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN report_status TEXT NULL CHECK(report_status IN ('Rapor getirdi','Rapor getirecek','Rapor gerekmedi','Özel reçete getirdi','Özel reçete getirecek'))");
        }
    } else {
        if (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'report_status'")->fetch()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN report_status ENUM('Rapor getirdi','Rapor getirecek','Rapor gerekmedi','Özel reçete getirdi','Özel reçete getirecek') NULL AFTER report_info");
        } else {
            $pdo->exec("ALTER TABLE patients MODIFY COLUMN report_status ENUM('Var','Yok','Rapor getirdi','Rapor getirecek','Rapor gerekmedi','Özel Reçete','Özel reçete getirdi','Özel reçete getirecek') NULL");
        }
    }

    $legacyColumn = $driver === 'sqlite' && in_array('report_status_legacy', array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name'), true)
        ? 'report_status_legacy' : 'report_status';
    $pdo->exec("UPDATE patients SET report_status = CASE
        WHEN {$legacyColumn} = 'Var' OR {$legacyColumn} = 'Rapor getirdi' THEN 'Rapor getirdi'
        WHEN {$legacyColumn} = 'Yok' OR {$legacyColumn} = 'Rapor gerekmedi' THEN 'Rapor gerekmedi'
        WHEN {$legacyColumn} = 'Rapor getirecek' THEN 'Rapor getirecek'
        WHEN {$legacyColumn} = 'Özel Reçete' OR {$legacyColumn} = 'Özel reçete getirdi' THEN 'Özel reçete getirdi'
        WHEN {$legacyColumn} = 'Özel reçete getirecek' THEN 'Özel reçete getirecek'
        WHEN TRIM(COALESCE(report_info, '')) = '' THEN 'Rapor getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%ÖZEL REÇETE%' THEN 'Özel reçete getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%ÖZEL SATIŞ%' THEN 'Özel reçete getirdi'
        WHEN TRIM(UPPER(COALESCE(report_info, ''))) = 'ÖZEL' THEN 'Özel reçete getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPORLU%' THEN 'Rapor getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%TESTİ VAR%' THEN 'Rapor getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR GELDİ%' THEN 'Rapor getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR GETİRDİ%' THEN 'Rapor getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%GETİRECEK%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%ÇIKARILACAK%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%ÇIKARACAK%' THEN 'Rapor getirecek'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR VAR%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%RAPORU VAR%' THEN 'Rapor getirdi'
        WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR YOK%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%RAPORU YOK%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%GEREKMED%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%KULLANMAYACAK%'
          OR UPPER(COALESCE(report_info, '')) LIKE '%HAKKI YOK%' THEN 'Rapor gerekmedi'
        ELSE report_status END
        WHERE report_status IS NULL OR report_status = ''");

    if ($driver !== 'sqlite') {
        $pdo->exec("UPDATE patients SET report_status = 'Rapor getirdi' WHERE report_status = 'Var'");
        $pdo->exec("UPDATE patients SET report_status = 'Rapor gerekmedi' WHERE report_status = 'Yok'");
        $pdo->exec("UPDATE patients SET report_status = 'Özel reçete getirdi' WHERE report_status = 'Özel Reçete'");
        $pdo->exec("ALTER TABLE patients MODIFY COLUMN report_status ENUM('Rapor getirdi','Rapor getirecek','Rapor gerekmedi','Özel reçete getirdi','Özel reçete getirecek') NULL");
    } elseif ($legacyColumn === 'report_status_legacy') {
        try { $pdo->exec('ALTER TABLE patients DROP COLUMN report_status_legacy'); }
        catch (Throwable $e) { /* Eski SQLite sürümlerinde kolon saklanır; uygulama kullanmaz. */ }
    }
}

ensure_patient_report_schema();
