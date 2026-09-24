<?php
declare(strict_types=1);
require __DIR__ . '/../cash-company.php';
require __DIR__ . '/../cash-payment-records.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE current_accounts(id INTEGER PRIMARY KEY,code TEXT); INSERT INTO current_accounts VALUES(3,'CR-03'),(11,'CR-00'); CREATE TABLE cash_transactions(id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_date TEXT,description TEXT,amount NUMERIC,payment_type TEXT,installment_count INTEGER,bank_name TEXT,commission_rate NUMERIC,current_account_id INTEGER,term_schedule TEXT,transaction_type TEXT,source_url TEXT,created_by INTEGER,cash_register TEXT,category_id INTEGER)");
$base = ['transaction_date'=>'13.09.2026','description'=>'Test payment','amount'=>'1.250,50','payment_type'=>'cash'];
check(cash_payment_money('1.250')===1250.0 && cash_payment_money('1.250.000')===1250000.0, 'Turkish thousands');
$first = cash_payment_record($base);
$second = cash_payment_record(array_replace($base,['transaction_date'=>'15.09.2026','amount'=>'249.50']));
cash_payment_save_batch($pdo, [['record'=>$first],['record'=>$second]], '/patient-test', 1);
$rows=$pdo->query('SELECT * FROM cash_transactions ORDER BY id')->fetchAll();
check($rows[0]['transaction_date']==='2026-09-13' && $rows[1]['transaction_date']==='2026-09-15','Independent dates on insert');
check((float)$rows[0]['amount']===1250.50 && (float)$rows[1]['amount']===249.50,'Decimal precision');
check((float)$pdo->query("SELECT SUM(amount) FROM cash_transactions WHERE transaction_date<='2026-09-13'")->fetchColumn()===1250.50,'Cash follows each payment date');
$second['transaction_date']='2026-09-11';
cash_payment_save_batch($pdo,[['id'=>1,'record'=>$first],['id'=>2,'record'=>$second]],'/patient-test',1);
check($pdo->query('SELECT transaction_date FROM cash_transactions WHERE id=2')->fetchColumn()==='2026-09-11','Independent date on update');
foreach(['cash','eft_transfer','credit_card','mail_order','term'] as $type){
 $data=array_replace($base,['payment_type'=>$type,'current_account_id'=>3,'bank_name'=>'Test Bank','term_schedule'=>[['date'=>'2026-09-15','amount'=>'250,50','paid'=>true],['date'=>'2026-10-15','amount'=>'1.000,00','paid'=>false]]]);
 $record=cash_payment_record($data); check($record['payment_type']===$type,'All payment types');
 if($type==='eft_transfer')check($record['current_account_id']===3,'EFT counterparty preserved');
 if($type==='credit_card')check($record['current_account_id']===null,'Credit card uses company ownership without counterparty');
 if($type==='cash')check($record['current_account_id']===null && $record['bank_name']===null,'Cash clears bank and counterparty');
 if($type==='term')check($record['amount']===250.50 && count(json_decode($record['term_schedule'],true))===2,'Only paid installments affect cash');
}
foreach(['','31.02.2026'] as $date){try{cash_payment_record(array_replace($base,['transaction_date'=>$date]));throw new Exception('Invalid date accepted');}catch(InvalidArgumentException|RuntimeException $expected){}}
try{cash_payment_posted($base+['extra_payment_type'=>'cash','extra_amount'=>'10','extra_description'=>'Extra'],'extra_');throw new Exception('Missing second date accepted');}catch(RuntimeException $expected){}
$changed=$first;$changed['amount']=999;
try{cash_payment_save_batch($pdo,[['id'=>1,'record'=>$changed],['id'=>999,'record'=>$second]],'/patient-test',1);throw new Exception('Foreign payment accepted');}catch(RuntimeException $expected){}
check((float)$pdo->query('SELECT amount FROM cash_transactions WHERE id=1')->fetchColumn()===1250.50,'Failed batch rolls back first update');
$post=['transaction_date'=>'2026-09-13','payment_type'=>'cash','description'=>'First','amount'=>'100','extra_transaction_date'=>'2026-09-18','extra_payment_type'=>'term','extra_description'=>'Second','extra_amount'=>'300','extra_term_schedule_json'=>json_encode([['date'=>'2026-09-18','amount'=>'100,50','paid'=>false],['date'=>'2026-10-18','amount'=>'199,50','paid'=>true]])];
$posted=cash_payment_posted($post,'extra_');
check($posted['transaction_date']==='2026-09-18' && $posted['amount']===199.50,'Second date and paid installment index preserved');
$plan=json_decode($posted['term_schedule'],true);
check($plan[1]['amount']==='199,50','Installment amount compatible with cash reports');
echo "PASS: independent dates, daily cash totals, five payment types, installments, precision and atomic rollback\n";

$eft=cash_payment_record(array_replace($base,['payment_type'=>'eft_transfer','current_account_id'=>11,'bank_name'=>'Test Bank']));
cash_payment_save_batch($pdo,[['record'=>$eft]],'/eft-owner-test',1);
$saved=$pdo->query("SELECT * FROM cash_transactions WHERE source_url='/eft-owner-test'")->fetch();
check((int)$saved['current_account_id']===11 && $saved['bank_name']==='Test Bank','Selected EFT receiving company account is preserved');
check($saved['transaction_date']==='2026-09-13' && (float)$saved['amount']===1250.50,'EFT cash date and amount preserved');
echo "PASS: EFT receiving business account normalization\n";
