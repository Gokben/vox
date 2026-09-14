<?php
if (!is_file(__DIR__ . '/../config.local.php')) { echo "SKIP: local MySQL configuration required\n"; exit(0); }
require __DIR__ . '/../config.local.php';
require __DIR__ . '/../cash-bootstrap.php';
require __DIR__ . '/../cash-payment-records.php';
$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Connection-scoped temporary tables shadow the real tables; no business data is changed.
$pdo->exec("CREATE TEMPORARY TABLE current_accounts (id INT PRIMARY KEY,code VARCHAR(20))");
$pdo->exec("CREATE TEMPORARY TABLE cash_transactions (id INT AUTO_INCREMENT PRIMARY KEY, payment_type ENUM('cash','credit_card','mail_order','term') NOT NULL, amount DECIMAL(14,2),transaction_type VARCHAR(20),source_url VARCHAR(255),created_by INT,cash_register VARCHAR(20),category_id INT) ENGINE=InnoDB");
$pdo->exec("SET SESSION sql_mode=''");
$pdo->exec("INSERT INTO cash_transactions(payment_type) VALUES('eft_transfer')");
if($pdo->query('SELECT payment_type FROM cash_transactions')->fetchColumn()!=='') throw new Exception('Reproduction failed');
echo "PASS: old enum reproduces lost EFT value\n";
$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
ensure_cash_payment_type_schema($pdo);
ensure_cash_payment_type_schema($pdo);
if($pdo->query('SELECT payment_type FROM cash_transactions')->fetchColumn()!=='') throw new Exception('Legacy row changed');
$pdo->exec('DELETE FROM cash_transactions');
$payments=[];
foreach(['cash','eft_transfer','eft_transfer'] as $i=>$type) $payments[]=['record'=>['payment_type'=>$type,'amount'=>$i===2?45000:50000]];
cash_payment_save_batch($pdo,$payments,'test-payment',1);
ensure_cash_payment_type_schema($pdo);
$rows=$pdo->query('SELECT id,payment_type,amount FROM cash_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if(array_column($rows,'payment_type')!==['cash','eft_transfer','eft_transfer'])throw new Exception('Payment types lost');
if(array_sum(array_column($rows,'amount'))!=145000)throw new Exception('Amounts changed');
foreach($payments as $i=>&$payment) $payment['id']=(int)$rows[$i]['id'];
unset($payment);
cash_payment_save_batch($pdo,$payments,'test-payment',1);
if($pdo->query('SELECT COUNT(*) FROM cash_transactions')->fetchColumn()!=3)throw new Exception('Duplicate payment');
echo "PASS: migration preserves legacy blank; repeated migration, insert/update retain cash + EFT + EFT and 145000 total\n";
$pdo->exec('DELETE FROM cash_transactions');
$pdo->exec("ALTER TABLE cash_transactions MODIFY payment_type ENUM('cash','credit_card','mail_order','term') NOT NULL");
$pdo->exec("SET SESSION sql_mode=''");
try {
    cash_payment_save_batch($pdo,array_map(static fn($p)=>['record'=>$p['record']],$payments),'test-payment',1);
    throw new Exception('Silent enum truncation was accepted');
} catch (RuntimeException $e) {
    if(!str_contains($e->getMessage(),'veritabanına kaydedilemedi'))throw $e;
}
if($pdo->query('SELECT COUNT(*) FROM cash_transactions')->fetchColumn()!=0)throw new Exception('Partial batch committed');
echo "PASS: unsupported enum rolls back the entire batch instead of reporting success\n";
