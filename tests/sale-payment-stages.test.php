<?php
require __DIR__ . '/cash-payment-records.test.php';
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
try{cash_payment_decode_records(json_encode([...$input,$input[0]]));throw new Exception('Fifth record accepted');}catch(RuntimeException $expected){}
try{cash_payment_decode_records(json_encode($input),999);throw new Exception('Wrong total accepted');}catch(RuntimeException $expected){}
$bad=$input;$bad[3]['transaction_date']='';
try{cash_payment_save_batch($pdo,cash_payment_decode_records(json_encode($bad),1000),'/four-test',1);throw new Exception('Invalid fourth date accepted');}catch(RuntimeException $expected){}
echo "PASS: four inserts, re-save without duplicates, independent third date, fifth rejected and full sale total checked\n";
