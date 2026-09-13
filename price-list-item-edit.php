<?php
declare(strict_types=1);
function price_list_brand_models(PDO $pdo, string $brand): array
{
    $query=$pdo->prepare("SELECT DISTINCT m.name FROM models m JOIN brands b ON b.id=m.brand_id WHERE b.name=? ORDER BY m.name");
    $query->execute([$brand]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
}
function update_price_list_item(PDO $pdo, int $listId, int $stockId, array $input): void
{
    $name=trim((string)($input['stock_name']??''));
    $model=trim((string)($input['model']??''));
    $type=trim((string)($input['device_type']??''));
    $raw=trim((string)($input['list_price']??''));
    if($name==='' || mb_strlen($name)>190 || mb_strlen($model)>190 || mb_strlen($type)>190) throw new InvalidArgumentException('Stok adı zorunludur; metin alanları en fazla 190 karakter olabilir.');
    if(!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/D',$raw)) throw new InvalidArgumentException('Fiyatı 1.250,50 biçiminde, sıfır veya pozitif girin.');
    $price=str_replace(',', '.', str_replace('.', '', $raw));
    if((float)$price>9999999999.99)throw new InvalidArgumentException('Fiyat en fazla 9.999.999.999,99 olabilir.');
    $pdo->beginTransaction();
    try {
        $query=$pdo->prepare("SELECT s.id,s.model,s.brand FROM stock_cards s JOIN stock_price_lists l ON l.brand=s.brand WHERE l.id=? AND s.id=? AND (s.stock_type='İşitme Cihazı' OR EXISTS (SELECT 1 FROM stock_price_list_items i WHERE i.price_list_id=l.id AND i.stock_id=s.id))");
        $query->execute([$listId,$stockId]);
        $existing=$query->fetch(PDO::FETCH_ASSOC);
        if(!$existing)throw new InvalidArgumentException('Kalem bu fiyat listesine ait değil.');
        if($model!=='' && $model!==trim((string)$existing['model']) && !in_array($model,price_list_brand_models($pdo,$existing['brand']),true))throw new InvalidArgumentException('Seçilen model bu markanın modelleri arasında bulunmuyor.');
        $pdo->prepare('UPDATE stock_cards SET stock_name=?,model=?,device_type=? WHERE id=?')->execute([$name,$model,$type,$stockId]);
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? 'INSERT INTO stock_price_list_items(price_list_id,stock_id,list_price) VALUES(?,?,?) ON CONFLICT(price_list_id,stock_id) DO UPDATE SET list_price=excluded.list_price'
            : 'INSERT INTO stock_price_list_items(price_list_id,stock_id,list_price) VALUES(?,?,?) ON DUPLICATE KEY UPDATE list_price=VALUES(list_price)';
        $pdo->prepare($sql)->execute([$listId,$stockId,$price]);
        $pdo->commit();
    } catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();throw $exception;}
}
