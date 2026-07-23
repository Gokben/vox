<?php
declare(strict_types=1);

putenv('APP_ENV=local');
require dirname(__DIR__) . '/config.php';

$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS brands (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS models (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        brand_id INTEGER NOT NULL,
        name VARCHAR(190) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (name),
        FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS models_name_unique ON models(name COLLATE NOCASE)');
} else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS brands (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS models (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brand_id INT UNSIGNED NOT NULL,
        name VARCHAR(190) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY models_name_unique (name),
        CONSTRAINT models_brand_fk FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

$pdo->beginTransaction();
try {
    $brandStatement = $pdo->prepare('SELECT id FROM brands WHERE name=?');
    $brandStatement->execute(['Resound']);
    $brandId = (int)$brandStatement->fetchColumn();
    if ($brandId === 0) {
        $pdo->prepare('INSERT INTO brands(name) VALUES(?)')->execute(['Resound']);
        $brandId = (int)$pdo->lastInsertId();
    }

    $models = [
        'KE261',
        'KE267',
        'KE277',
        'KE288',
        'KE298',
        'KE461',
        'KE461 DRWC',
        'KE4CIC',
        'KE488',
        'KE498',
        'KE477',
        'KE478',
        'RU761',
        'RU761 DRWC',
        'RU760 DRWC',
        'RU777 DRWC',
        'RU788',
        'RU788 DRWC',
        'RU960 DRW',
        'RU960',
        'RU960 DRWC',
        'RU988',
        'EQ788',
        'EQ798',
        'EQ799',
        'EQ7100',
        'EQ7101',
        'EQ7102',
        'EQ988',
        'EQ998',
    ];

    $find = $pdo->prepare('SELECT id FROM models WHERE LOWER(name)=LOWER(?)');
    $insert = $pdo->prepare('INSERT INTO models(brand_id,name) VALUES(?,?)');
    $added = 0;
    $existing = 0;
    foreach ($models as $model) {
        $find->execute([$model]);
        if ($find->fetchColumn()) {
            $existing++;
            continue;
        }
        $insert->execute([$brandId, $model]);
        $added++;
    }
    $pdo->commit();
    echo "Resound modelleri hazır. Eklenen: {$added}, zaten kayıtlı: {$existing}, toplam: " . count($models) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
