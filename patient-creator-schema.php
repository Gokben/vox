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

// The employee who attended the patient at card opening; independent of the login user.
function ensure_patient_opening_employee_schema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(patients)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('opening_employee_id', $columns, true)) $pdo->exec('ALTER TABLE patients ADD COLUMN opening_employee_id INTEGER NULL');
    } elseif (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'opening_employee_id'")->fetch()) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN opening_employee_id INT UNSIGNED NULL');
    }
}

function patient_opening_employee_error(PDO $pdo, string $value, int $previousId = 0): string
{
    if ($value === '') return '';
    if (!preg_match('/^[1-9][0-9]*$/D', $value)) return 'Geçerli bir ilgilenen kişi seçin.';
    if ((int)$value === $previousId && $previousId > 0) return '';
    $statement = $pdo->prepare('SELECT id FROM employees WHERE id=? AND active=1');
    $statement->execute([$value]);
    return $statement->fetchColumn() === false ? 'Seçilen çalışan bulunamadı.' : '';
}
