<?php
declare(strict_types=1);

putenv('APP_ENV=local');
require dirname(__DIR__) . '/config.php';

$pdo = db();
$pdo->beginTransaction();

try {
    $brandStatement = $pdo->prepare('SELECT id FROM brands WHERE name=?');
    $brandStatement->execute(['Beltone']);
    $brandId = (int)$brandStatement->fetchColumn();
    if ($brandId === 0) {
        $pdo->prepare('INSERT INTO brands(name) VALUES(?)')->execute(['Beltone']);
        $brandId = (int)$pdo->lastInsertId();
    }

    $models = [
        'RLY276',
        'RLY286',
        'RLY295',
        'RLY463',
        'RLY476',
        'RLY486',
        'RLY495',
        'BBU695',
        'BBU995',
        'IMP1763',
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
    echo "Beltone modelleri hazır. Eklenen: {$added}, zaten kayıtlı: {$existing}, toplam: " . count($models) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
