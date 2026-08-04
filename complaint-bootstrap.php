<?php
declare(strict_types=1);

function complaint_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS complaint_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS complaint_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ($sqlite) {
        $pdo->exec('DELETE FROM complaint_definitions WHERE id NOT IN (SELECT MIN(id) FROM complaint_definitions GROUP BY name)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_complaint_definitions_name ON complaint_definitions(name)');
    } else {
        $pdo->exec('CREATE TEMPORARY TABLE complaint_definition_kept (id INT UNSIGNED PRIMARY KEY)');
        $pdo->exec('INSERT INTO complaint_definition_kept(id) SELECT MIN(id) FROM complaint_definitions GROUP BY name');
        $pdo->exec('DELETE duplicate_row FROM complaint_definitions AS duplicate_row LEFT JOIN complaint_definition_kept AS kept_row ON kept_row.id=duplicate_row.id WHERE kept_row.id IS NULL');
        $pdo->exec('DROP TEMPORARY TABLE complaint_definition_kept');
        $index = $pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='complaint_definitions' AND index_name='uq_complaint_definitions_name' LIMIT 1");
        if (!$index->fetchColumn()) $pdo->exec('ALTER TABLE complaint_definitions ADD UNIQUE KEY uq_complaint_definitions_name (name)');
    }
    $insert = $sqlite
        ? $pdo->prepare('INSERT OR IGNORE INTO complaint_definitions(name,active,sort_order) VALUES(?,?,?)')
        : $pdo->prepare('INSERT IGNORE INTO complaint_definitions(name,active,sort_order) VALUES(?,?,?)');
    foreach (['Çalışmıyor', 'Ara ara kesiliyor', 'Açma-kapama anahtarı arızalı', 'Ses kontrol düğmesi arızalı', 'Yüksek pil tüketimi', 'Feedback (çınlama)', 'Program geçişi yapmıyor'] as $index => $name) {
        $insert->execute([$name, 1, $index + 1]);
    }
    return $pdo->query('SELECT * FROM complaint_definitions ORDER BY sort_order,name')->fetchAll();
}
