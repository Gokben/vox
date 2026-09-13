<?php
declare(strict_types=1);
require __DIR__.'/../sale-consumables.php';
function expect_consumable(bool $ok): void { if (!$ok) throw new RuntimeException('Consumable assertion failed'); }
$legacy=['sales_consumable_stock_id'=>'10','sales_consumable_quantity'=>'2'];
expect_consumable(sale_consumable_lines($legacy)===[['stock_id'=>10,'quantity'=>2,'promotion'=>false]]);
$details=$legacy+['sales_consumable_items'=>json_encode([['stock_id'=>10],['stock_id'=>20]])];
$lines=sale_consumable_lines($details);
expect_consumable(array_column($lines,'stock_id')===[10,20] && array_sum(array_column($lines,'quantity'))===2);
expect_consumable(sale_consumable_lines($details+['sales_consumable_promotion'=>'Evet'])[1]['promotion']);
$p=new PDO('sqlite::memory:');$p->exec('CREATE TABLE exits(stock_id INTEGER,quantity INTEGER)');
$insert=$p->prepare('INSERT INTO exits VALUES(?,?)');foreach($lines as $line)$insert->execute([$line['stock_id'],$line['quantity']]);
expect_consumable((int)$p->query('SELECT SUM(quantity) FROM exits WHERE stock_id=10')->fetchColumn()===1);
expect_consumable((int)$p->query('SELECT SUM(quantity) FROM exits WHERE stock_id=20')->fetchColumn()===1);
foreach(['[]','broken','[{"stock_id":10},{"stock_id":0}]'] as $bad){try{sale_consumable_lines(array_replace($details,['sales_consumable_items'=>$bad]));throw new LogicException('Invalid selection accepted');}catch(RuntimeException $expected){}}
expect_consumable(sale_consumable_lines(array_replace($details,['sales_consumable_stock_id'=>'']))===[]);
echo "PASS: legacy quantity, separate stock exits, promotion, invalid/incomplete selections, removed product\n";
