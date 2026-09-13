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
            foreach (['Tabela', 'Belma Baysan', 'Tavsiye', 'Tanıdık'] as $order => $name) {
                $insert->execute([$name, 1, $order + 1]);
            }
        }
        // Eski tablolarda name UNIQUE olmayabilir; INSERT IGNORE tek başına yetmez.
        $locked = $driver !== 'sqlite';
        if ($locked && (int)$pdo->query("SELECT GET_LOCK('vox_source_seed', 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('Kaynak listesi kilidi alınamadı.');
        }
        try {
            $insertSource = $pdo->prepare('INSERT INTO source_definitions(name,active,sort_order) SELECT ?,1,? WHERE NOT EXISTS (SELECT 1 FROM source_definitions WHERE name=?)');
            foreach (['Kurumlar' => 20, 'Firmalar' => 21] as $name => $order) {
                $insertSource->execute([$name, $order, $name]);
            }
        } finally {
            if ($locked) $pdo->query("SELECT RELEASE_LOCK('vox_source_seed')");
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
    if (!in_array('source_unit_id', $columns, true)) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN source_unit_id INTEGER NULL'
            : 'ALTER TABLE patients ADD COLUMN source_unit_id INT UNSIGNED NULL AFTER source_id');
    }
    if (!in_array('source_referral_detail', $columns, true)) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN source_referral_detail TEXT NULL');
    }
    if (!in_array('source_account_id', $columns, true)) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN source_account_id INTEGER NULL');
    }
    if (!in_array('source_company_id', $columns, true)) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN source_company_id INTEGER NULL'
            : 'ALTER TABLE patients ADD COLUMN source_company_id INT UNSIGNED NULL AFTER source_unit_id');
    }
    if (!in_array('service_location', $columns, true)) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN service_location TEXT NULL'
            : 'ALTER TABLE patients ADD COLUMN service_location VARCHAR(150) NULL AFTER service_type_id');
    }
    $initialized = true;
}

function patient_source_account_error(PDO $pdo, string $sourceName, string $accountId): string
{
    if ($accountId === '') return '';
    $type = ['Kurumlar' => 'institution', 'Firmalar' => 'customer'][$sourceName] ?? null;
    if (!$type || !preg_match('/^[1-9][0-9]*$/D', $accountId)) return 'Geçerli bir kaynak cari kartı seçin.';
    $statement = $pdo->prepare('SELECT id FROM current_accounts WHERE id=? AND account_type=?');
    $statement->execute([$accountId, $type]);
    return $statement->fetchColumn() === false ? 'Cari kartın tipi seçilen kaynakla uyuşmuyor.' : '';
}

/** Aynı adlı seçenekleri bir kez gösterir; hastanın mevcut kaynak kimliğini korur. */
function patient_source_options(array $sources, int $selectedId): array
{
    $options = [];
    foreach ($sources as $source) {
        if (!(int)$source['active'] && (int)$source['id'] !== $selectedId) continue;
        $key = mb_strtolower(trim((string)$source['name']), 'UTF-8');
        if (!isset($options[$key]) || (int)$source['id'] === $selectedId) {
            $options[$key] = $source;
        }
    }
    return array_values($options);
}
