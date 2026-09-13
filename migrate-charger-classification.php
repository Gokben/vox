<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/config.php';
$p=db();$p->beginTransaction();
try {
    $update=$p->prepare('UPDATE brands SET stock_type=? WHERE id=?');
    foreach($p->query('SELECT id,stock_type FROM brands')->fetchAll() as $brand){
        $types=array_values(array_filter(array_map('trim',explode(',',(string)$brand['stock_type']))));
        if(in_array('İşitme Cihazı',$types,true)&&!in_array('Şarj Cihazı',$types,true)){
            $types[]='Şarj Cihazı';$update->execute([implode(',',$types),$brand['id']]);
        }
    }
    $model=$p->prepare("UPDATE models SET stock_type='Şarj Cihazı' WHERE brand_id IN (SELECT id FROM brands WHERE name='Signia') AND name=? AND stock_type='Sarf Malzeme'");
    $card=$p->prepare("UPDATE stock_cards SET stock_type='Şarj Cihazı',brand='Signia',model=? WHERE stock_code=? AND stock_name=? AND COALESCE(brand,'') IN ('','Signia') AND stock_type='Sarf Malzeme'");
    foreach(['Charger (X-AX-IX)','Multicharger (P-SP)'] as $name){$model->execute([$name]);$card->execute([$name,$name,'Signia '.$name]);}
    $p->commit();echo "Charger classification migration completed.\n";
}catch(Throwable $error){$p->rollBack();throw $error;}
