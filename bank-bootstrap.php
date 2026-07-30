<?php
declare(strict_types=1);

function bank_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS bank_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS bank_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ((int)$pdo->query('SELECT COUNT(*) FROM bank_definitions')->fetchColumn() === 0) {
        $insert = $sqlite ? $pdo->prepare('INSERT OR IGNORE INTO bank_definitions(name,active,sort_order) VALUES(?,?,?)') : $pdo->prepare('INSERT IGNORE INTO bank_definitions(name,active,sort_order) VALUES(?,?,?)');
        foreach (['Ziraat Bankası','VakıfBank','Türkiye İş Bankası','Halkbank','Garanti','Akbank','Yapı Kredi','QNB Bank','DenizBank','Kuveyt Türk','Türk'] as $index => $name) $insert->execute([$name,1,$index+1]);
    }
    return $pdo->query('SELECT * FROM bank_definitions ORDER BY sort_order,name')->fetchAll();
}
