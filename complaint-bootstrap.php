<?php
declare(strict_types=1);

function complaint_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS complaint_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS complaint_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $insert = $sqlite
        ? $pdo->prepare('INSERT OR IGNORE INTO complaint_definitions(name,active,sort_order) VALUES(?,?,?)')
        : $pdo->prepare('INSERT IGNORE INTO complaint_definitions(name,active,sort_order) VALUES(?,?,?)');
    foreach (['Çalışmıyor', 'Ara ara kesiliyor', 'Açma-kapama anahtarı arızalı', 'Ses kontrol düğmesi arızalı', 'Yüksek pil tüketimi', 'Feedback (çınlama)', 'Program geçişi yapmıyor'] as $index => $name) {
        $insert->execute([$name, 1, $index + 1]);
    }
    return $pdo->query('SELECT * FROM complaint_definitions ORDER BY sort_order,name')->fetchAll();
}
