<?php
require __DIR__ . '/cash-payment-records.test.php';
check(cash_payment_sale_total([
    'sales_device_net_price'=>'15.472,00 ₺',
    'sales_device_2_net_price'=>'15.472,00 ₺',
    'sales_device_sgk'=>'3.028,00 ₺',
    'sales_device_2_sgk'=>'3.028,00 ₺',
    'sales_payment_amount'=>'18.500,00 ₺',
])===30944.0, 'Payment total follows product net amounts, not SGK or stale saved total');
check(cash_payment_sale_total(['sales_payment_amount'=>'500,00 ₺'])===500.0, 'Legacy sale without product amounts keeps saved total');
check(cash_payment_sale_total([
    'sales_device_net_price'=>'100,00 ₺',
    'sales_charger_net_price'=>'50,00 ₺',
    'sales_charger_promotion'=>'Evet',
    'sales_consumable_stock_id'=>'7',
    'sales_consumable_items'=>json_encode([['price'=>'12,50 ₺'],['price'=>'17,50 ₺']]),
])===130.0, 'Promotional charger is excluded and consumable lines are included');
foreach ([30943.99, 30944.01] as $wrongTotal) {
    try {
        cash_payment_decode_records(json_encode([array_replace($base,['amount'=>(string)$wrongTotal])]),30944);
        throw new Exception('Unequal payment total accepted');
    } catch (RuntimeException $expected) {}
}
$equalPayment=cash_payment_decode_records(json_encode([array_replace($base,['amount'=>'30.944,00'])]),30944);
check(count($equalPayment)===1, 'Exact payment total accepted');
$input=[];
for($i=0;$i<4;$i++)$input[]=array_replace($base,['transaction_date'=>'2026-09-'.(14+$i),'amount'=>'250,00','payment_type'=>$i===3?'eft_transfer':'cash']);
$four=cash_payment_decode_records(json_encode($input),1000);
cash_payment_save_batch($pdo,$four,'/four-test',1);
$read=$pdo->query("SELECT * FROM cash_transactions WHERE source_url='/four-test' ORDER BY id")->fetchAll();
check(count($read)===4 && array_column($read,'transaction_date')===['2026-09-14','2026-09-15','2026-09-16','2026-09-17'],'Four independent records persisted');
foreach($input as $i=>&$row)$row['id']=$read[$i]['id'];unset($row);
$input[2]['transaction_date']='2026-09-20';
cash_payment_save_batch($pdo,cash_payment_decode_records(json_encode($input),1000),'/four-test',1);
check((int)$pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE source_url='/four-test'")->fetchColumn()===4,'Resave updates without duplicates');
check($pdo->query("SELECT transaction_date FROM cash_transactions WHERE id=".$read[2]['id'])->fetchColumn()==='2026-09-20','Third date changed independently');
try{cash_payment_decode_records(json_encode([...$input,$input[0]]),1000);throw new Exception('Fifth record accepted');}catch(RuntimeException $expected){}
try{cash_payment_decode_records(json_encode($input),999);throw new Exception('Wrong total accepted');}catch(RuntimeException $expected){}
$bad=$input;$bad[3]['transaction_date']='';
try{cash_payment_save_batch($pdo,cash_payment_decode_records(json_encode($bad),1000),'/four-test',1);throw new Exception('Invalid fourth date accepted');}catch(RuntimeException $expected){}
echo "PASS: four inserts, re-save without duplicates, independent third date, fifth rejected and full sale total checked\n";

// Reverse cancellation must leave earlier payments and other patients untouched.
$otherCount=(int)$pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE source_url<>'/four-test'")->fetchColumn();
try { cash_payment_cancel_last($pdo, '/four-test', (int)$read[0]['id']); throw new Exception('Earlier stage cancelled'); } catch (RuntimeException $expected) {}
check((int)$pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE source_url='/four-test'")->fetchColumn()===4, 'Out of order cancellation changes nothing');
try { cash_payment_cancel_last($pdo, '/patient-test', (int)$read[3]['id']); throw new Exception('Foreign payment cancelled'); } catch (RuntimeException $expected) {}
for($i=3;$i>=0;$i--){
 $remaining=cash_payment_cancel_last($pdo,'/four-test',(int)$read[$i]['id']);
 check(count($remaining)===$i,'One stage cancelled at a time');
 check(array_column($remaining,'id')===array_column(array_slice($read,0,$i),'id'),'Earlier stages retained');
}
check((int)$pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE source_url<>'/four-test'")->fetchColumn()===$otherCount,'Unrelated records preserved');
try { cash_payment_cancel_last($pdo, '/four-test', (int)$read[3]['id']); throw new Exception('Stale cancellation accepted'); } catch (RuntimeException $expected) {}
echo "PASS: reverse 4-3-2-1 cancellation, ownership, stale requests and unrelated records\n";
