<?php
declare(strict_types=1);

putenv('APP_ENV=local');
require dirname(__DIR__) . '/config.php';

$pdo = db();
$pdo->beginTransaction();

try {
    $brandStatement = $pdo->prepare('SELECT id FROM brands WHERE name=?');
    $brandStatement->execute(['Coselgi']);
    $brandId = (int)$brandStatement->fetchColumn();
    if ($brandId === 0) {
        $pdo->prepare('INSERT INTO brands(name) VALUES(?)')->execute(['Coselgi']);
        $brandId = (int)$pdo->lastInsertId();
    }

    $models = [
        'E-FA-E1',
        'E-FP E1',
        'MOJO M2 - MBB 3D',
        'MOJO M2 - MRB0',
        'MOJO M2 - MBB2',
        'MOJO M2 - MRB 2D',
        'MOJO M2 CIC',
        'MOJO M3 - MRB0',
        'MOJO M3 - MRB 2D',
        'MOJO M3 - MRR 2D',
        'MOJO M3 - MBR 3D',
        'MOJO M3 M-CIC',
        'MOJO M4 - MRB 2D',
        'MOJO M4 - MRR 2D',
        'MOJO M4 - MBR 3D',
        'MOJO M5 - MRB 2D',
        'MOJO M5 - MBB 3D',
        'MOJO M5 - MRR 2D',
        'MOJO M6 - MRR 2D',
        'MOJO M6 - MRB 2D',
        'MOJO M6 MBB 3D',
        'MOJO M7 - MRR 2D',
        'MOJO M7 - MRB 2D',
        'MOJO M7 MBB 3D',
        '28539',
        '534884',
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
    echo "Coselgi modelleri hazır. Eklenen: {$added}, zaten kayıtlı: {$existing}, toplam: " . count($models) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
