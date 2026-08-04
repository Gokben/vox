<?php
declare(strict_types=1);

function service_action_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS service_action_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS service_action_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Eski tablolarda UNIQUE kısıtı bulunmadığından, varsayılan aksiyonlar
    // her ekran açılışında tekrar eklenebiliyordu. Önce mevcut kayıtları
    // tekilleştir, ardından benzersizliği kalıcı olarak zorunlu tut.
    if ($sqlite) {
        $pdo->exec('DELETE FROM service_action_definitions WHERE id NOT IN (SELECT MIN(id) FROM service_action_definitions GROUP BY name)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_service_action_definitions_name ON service_action_definitions(name)');
    } else {
        $pdo->exec('CREATE TEMPORARY TABLE service_action_definition_kept (id INT UNSIGNED PRIMARY KEY)');
        $pdo->exec('INSERT INTO service_action_definition_kept(id) SELECT MIN(id) FROM service_action_definitions GROUP BY name');
        $pdo->exec('DELETE duplicate_row FROM service_action_definitions AS duplicate_row LEFT JOIN service_action_definition_kept AS kept_row ON kept_row.id=duplicate_row.id WHERE kept_row.id IS NULL');
        $pdo->exec('DROP TEMPORARY TABLE service_action_definition_kept');
        $index = $pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='service_action_definitions' AND index_name='uq_service_action_definitions_name' LIMIT 1");
        if (!$index->fetchColumn()) {
            $pdo->exec('ALTER TABLE service_action_definitions ADD UNIQUE KEY uq_service_action_definitions_name (name)');
        }
    }

    $insert = $sqlite
        ? $pdo->prepare('INSERT OR IGNORE INTO service_action_definitions(name,active,sort_order) VALUES(?,?,?)')
        : $pdo->prepare('INSERT IGNORE INTO service_action_definitions(name,active,sort_order) VALUES(?,?,?)');
    foreach (['Aranacak', 'Takip', 'Rapor Çıkartılacak'] as $index => $name) {
        $insert->execute([$name, 1, $index + 1]);
    }

    return $pdo->query('SELECT * FROM service_action_definitions ORDER BY sort_order,name')->fetchAll();
}
