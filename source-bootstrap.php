<?php
declare(strict_types=1);

/** Başvuru kaynakları için kalıcı seçim listesi ve hasta bağlantısı. */
function source_definitions(): array
{
    static $initialized = false;
    $pdo = db();

    if (!$initialized) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($driver === 'sqlite'
            ? 'CREATE TABLE IF NOT EXISTS source_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
            : 'CREATE TABLE IF NOT EXISTS source_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 2025 MERKEZ çalışma kitabındaki BNU sütunundan gelen ilk tanımlar.
        // Yalnızca tablo ilk kez oluştuğunda eklenir; sonradan silinen kayıtlar geri gelmez.
        if ((int)$pdo->query('SELECT COUNT(*) FROM source_definitions')->fetchColumn() === 0) {
            $insert = $pdo->prepare('INSERT INTO source_definitions(name,active,sort_order) VALUES(?,?,?)');
            foreach (['Pazarlama', 'Tabela', 'Belma Baysan', 'Tavsiye', 'Tanıdık'] as $order => $name) {
                $insert->execute([$name, 1, $order + 1]);
            }
        }
        $initialized = true;
    }

    return $pdo->query('SELECT * FROM source_definitions ORDER BY sort_order, name')->fetchAll();
}

function ensure_patient_source_schema(): void
{
    static $initialized = false;
    if ($initialized) return;

    source_definitions();
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = $driver === 'sqlite'
        ? array_column($pdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM patients')->fetchAll(), 'Field');

    if (!in_array('source_id', $columns, true)) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN source_id INTEGER NULL'
            : 'ALTER TABLE patients ADD COLUMN source_id INT UNSIGNED NULL AFTER source_primary');
    }
    $initialized = true;
}
