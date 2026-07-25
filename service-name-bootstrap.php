<?php
declare(strict_types=1);

function service_name_definitions(): array
{
    static $initialized = false;
    $pdo = db();
    if (!$initialized) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($driver === 'sqlite'
            ? 'CREATE TABLE IF NOT EXISTS service_name_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
            : 'CREATE TABLE IF NOT EXISTS service_name_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if ((int)$pdo->query('SELECT COUNT(*) FROM service_name_definitions')->fetchColumn() === 0) {
            $insert = $pdo->prepare('INSERT INTO service_name_definitions(name,active,sort_order) VALUES(?,?,?)');
            foreach (['Ayar','Bilgilendirme','Deneme','Kalıp Alma','Kontrol','Satış','Tamir','Teknik Servise Gönderildi','Test'] as $order => $name) $insert->execute([$name, 1, $order + 1]);
        }
        $initialized = true;
    }
    return $pdo->query('SELECT * FROM service_name_definitions ORDER BY sort_order,name')->fetchAll();
}
