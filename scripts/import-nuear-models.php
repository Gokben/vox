<?php
declare(strict_types=1);

putenv('APP_ENV=local');
require dirname(__DIR__) . '/config.php';

$pdo = db();
$pdo->beginTransaction();

try {
    $brandStatement = $pdo->prepare('SELECT id FROM brands WHERE LOWER(name)=LOWER(?)');
    $brandStatement->execute(['Nuear']);
    $brandId = (int)$brandStatement->fetchColumn();
    if ($brandId === 0) {
        $pdo->prepare('INSERT INTO brands(name) VALUES(?)')->execute(['Nuear']);
        $brandId = (int)$pdo->lastInsertId();
    }

    $models = ['SAVANT AL 2400'];
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
    echo "Nuear modelleri hazır. Eklenen: {$added}, zaten kayıtlı: {$existing}, toplam: " . count($models) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
