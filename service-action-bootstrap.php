<?php
declare(strict_types=1);

function service_action_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS service_action_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS service_action_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $insert = $pdo->prepare('INSERT OR IGNORE INTO service_action_definitions(name,active,sort_order) VALUES(?,?,?)');
    if (!$sqlite) $insert = $pdo->prepare('INSERT IGNORE INTO service_action_definitions(name,active,sort_order) VALUES(?,?,?)');
    foreach (['Aranacak', 'Takip', 'Rapor Çıkartılacak'] as $index => $name) {
        $insert->execute([$name, 1, $index + 1]);
    }

    return $pdo->query('SELECT * FROM service_action_definitions ORDER BY sort_order,name')->fetchAll();
}
