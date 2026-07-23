<?php
declare(strict_types=1);

function service_type_definitions(): array
{
    static $initialized = false;
    $pdo = db();
    if (!$initialized) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($driver === 'sqlite'
            ? 'CREATE TABLE IF NOT EXISTS service_type_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
            : 'CREATE TABLE IF NOT EXISTS service_type_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        // Tanımlar yalnızca yönetici tarafından oluşturulur. Burada varsayılan
        // kayıt eklenmez; böylece silinen bir hizmet yeri sayfa yenilendiğinde
        // kendiliğinden geri gelmez.
        $initialized = true;
    }
    return $pdo->query('SELECT * FROM service_type_definitions ORDER BY sort_order, name')->fetchAll();
}

function ensure_patient_service_type_schema(): void
{
    static $initialized = false;
    if ($initialized) return;

    service_type_definitions();
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = $driver === 'sqlite'
        ? array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM patients')->fetchAll(), 'Field');

    if (!in_array('service_type_id', $columns, true)) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN service_type_id INTEGER NULL'
            : 'ALTER TABLE patients ADD COLUMN service_type_id INT UNSIGNED NULL AFTER service_type');
    }

    // Eski metin kayıtlarını mevcut tanımlara bağlar. Yalnızca ilişkisi boş kayıtlarda çalışır.
    $pdo->exec(
        'UPDATE patients SET service_type_id = (' .
        'SELECT id FROM service_type_definitions WHERE service_type_definitions.name = patients.service_type' .
        ') WHERE service_type_id IS NULL AND service_type IS NOT NULL AND service_type <> \'\''
    );
    $initialized = true;
}

function patient_service_type_name(array $patient): string
{
    return trim((string)($patient['service_type_name'] ?? $patient['service_type'] ?? ''));
}
