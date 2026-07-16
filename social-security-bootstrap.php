<?php
declare(strict_types=1);

function social_security_definitions(): array {
    static $definitions;
    if ($definitions !== null) return $definitions;
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->exec($driver === 'sqlite'
        ? 'CREATE TABLE IF NOT EXISTS social_security_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS social_security_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL UNIQUE,active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ($driver === 'sqlite') $pdo->exec('CREATE TRIGGER IF NOT EXISTS social_security_definition_rename AFTER UPDATE OF name ON social_security_definitions FOR EACH ROW BEGIN UPDATE patients SET social_security=NEW.name WHERE social_security=OLD.name; END');
    $insert = $pdo->prepare($driver === 'sqlite' ? 'INSERT OR IGNORE INTO social_security_definitions(name,sort_order) VALUES (?,?)' : 'INSERT IGNORE INTO social_security_definitions(name,sort_order) VALUES (?,?)');
    foreach (['Emekli','Çalışan','Çocuk Hasta','Yeşil Kart','Özel Sağlık Sigortası','TBMM','SGK yok','Özel Reçete','Bağkur','İşkur'] as $index => $name) $insert->execute([$name,$index + 1]);
    foreach ($pdo->query("SELECT DISTINCT TRIM(social_security) AS name FROM patients WHERE TRIM(COALESCE(social_security,''))<>''") as $row) $insert->execute([$row['name'],100]);
    return $definitions = $pdo->query('SELECT * FROM social_security_definitions WHERE active=1 ORDER BY sort_order,name')->fetchAll();
}
