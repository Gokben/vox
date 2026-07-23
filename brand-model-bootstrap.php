<?php
declare(strict_types=1);

function seed_brand_models_once(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    $migrationKey = '20260724_brand_models_v1';
    $check = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');
    $check->execute([$migrationKey]);
    if ($check->fetchColumn()) {
        return;
    }

    $catalog = [
        'Resound' => [
            'KE261', 'KE267', 'KE277', 'KE288', 'KE298', 'KE461', 'KE461 DRWC', 'KE4CIC', 'KE488', 'KE498',
            'KE477', 'KE478', 'RU761', 'RU761 DRWC', 'RU760 DRWC', 'RU777 DRWC', 'RU788', 'RU788 DRWC',
            'RU960 DRW', 'RU960', 'RU960 DRWC', 'RU988', 'EQ788', 'EQ798', 'EQ799', 'EQ7100', 'EQ7101',
            'EQ7102', 'EQ988', 'EQ998',
        ],
        'Beltone' => [
            'RLY276', 'RLY286', 'RLY295', 'RLY463', 'RLY476', 'RLY486', 'RLY495', 'BBU695', 'BBU995', 'IMP1763',
        ],
        'Coselgi' => [
            'E-FA-E1', 'E-FP E1', 'MOJO M2 - MBB 3D', 'MOJO M2 - MRB0', 'MOJO M2 - MBB2',
            'MOJO M2 - MRB 2D', 'MOJO M2 CIC', 'MOJO M3 - MRB0', 'MOJO M3 - MRB 2D', 'MOJO M3 - MRR 2D',
            'MOJO M3 - MBR 3D', 'MOJO M3 M-CIC', 'MOJO M4 - MRB 2D', 'MOJO M4 - MRR 2D',
            'MOJO M4 - MBR 3D', 'MOJO M5 - MRB 2D', 'MOJO M5 - MBB 3D', 'MOJO M5 - MRR 2D',
            'MOJO M6 - MRR 2D', 'MOJO M6 - MRB 2D', 'MOJO M6 MBB 3D', 'MOJO M7 - MRR 2D',
            'MOJO M7 - MRB 2D', 'MOJO M7 MBB 3D', '28539', '534884',
        ],
        'Signia' => [
            'MOTION 13', 'PURE C&G', 'SILK C&G', 'INTUIS M', 'ACTIVE PRO C&G', 'STYLETTO', 'SILK X',
            'SILK 3X', 'SILK 3X - L', 'CROS SILK X', 'PURE 1 AX', 'PURE 3 AX', 'PURE 1 IX',
        ],
        'Rexton' => ['BICORE B M 10', 'BICORE B P 10'],
        'Nuear' => ['SAVANT AL 2400'],
    ];

    $findBrand = $pdo->prepare('SELECT id FROM brands WHERE LOWER(name)=LOWER(?)');
    $insertBrand = $pdo->prepare('INSERT INTO brands(name) VALUES(?)');
    $findModel = $pdo->prepare('SELECT id FROM models WHERE LOWER(name)=LOWER(?)');
    $insertModel = $pdo->prepare('INSERT INTO models(brand_id,name) VALUES(?,?)');

    $pdo->beginTransaction();
    try {
        foreach ($catalog as $brandName => $models) {
            $findBrand->execute([$brandName]);
            $brandId = (int)$findBrand->fetchColumn();
            if ($brandId === 0) {
                $insertBrand->execute([$brandName]);
                $brandId = (int)$pdo->lastInsertId();
            }

            foreach ($models as $modelName) {
                $findModel->execute([$modelName]);
                if (!$findModel->fetchColumn()) {
                    $insertModel->execute([$brandId, $modelName]);
                }
            }
        }

        $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$migrationKey]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
