<?php
declare(strict_types=1);

function ensure_patient_creator_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $columns = array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name');
        if (!in_array('created_by', $columns, true)) {
            $pdo->exec('ALTER TABLE patients ADD COLUMN created_by INTEGER NULL');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_patients_created_by ON patients(created_by)');
        return;
    }

    if (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'created_by'")->fetch()) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN created_by INT UNSIGNED NULL');
    }
    if (!$pdo->query("SHOW INDEX FROM patients WHERE Key_name='idx_patients_created_by'")->fetch()) {
        $pdo->exec('ALTER TABLE patients ADD KEY idx_patients_created_by (created_by)');
    }
}

ensure_patient_creator_schema();
