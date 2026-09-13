<?php
declare(strict_types=1);
require __DIR__.'/../cash-company.php';
$p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec("CREATE TABLE current_accounts(id INTEGER PRIMARY KEY,code TEXT,title TEXT,short_name TEXT);
INSERT INTO current_accounts VALUES(42,'CR-00','Vox','Vox'),(7,'CR-07','Supplier','Supplier');
CREATE TABLE cash_transactions(id INTEGER PRIMARY KEY,transaction_type TEXT,amount NUMERIC,current_account_id INTEGER,source_url TEXT,cash_register TEXT,bank_name TEXT);
INSERT INTO cash_transactions VALUES(1,'expense',125,7,'invoice.php?id=9','main','Bank');");
$before=$p->query('SELECT * FROM cash_transactions')->fetchAll(PDO::FETCH_ASSOC);
ensure_cash_company_schema($p);
ensure_cash_company_schema($p);
$after=$p->query('SELECT * FROM cash_transactions')->fetchAll(PDO::FETCH_ASSOC);
if($after[0]['company_account_id']!==42)throw new RuntimeException('Historical owner missing');
unset($after[0]['company_account_id']);
if($before!==$after)throw new RuntimeException('Existing financial data changed');
$p->exec("INSERT INTO cash_transactions(id,transaction_type,amount) VALUES(2,'income',100),(3,'expense',50)");
if((int)$p->query('SELECT COUNT(*) FROM cash_transactions WHERE company_account_id=42')->fetchColumn()!==3)throw new RuntimeException('Automatic owner missing');
try{$p->exec('UPDATE cash_transactions SET company_account_id=7 WHERE id=2');throw new RuntimeException('Wrong owner accepted');}catch(PDOException $expected){}
cash_validate_counterparty($p,7);cash_validate_counterparty($p,0);
foreach([42,999] as $id){try{cash_validate_counterparty($p,$id);throw new LogicException('Invalid counterparty accepted');}catch(RuntimeException $expected){}}
echo "PASS: preserved history, automatic CR-00 ownership, invalid owner rejected, separate counterparty.\n";

// Historical receivables/collections stay ledger entries, not new cash payments.
$p->exec("CREATE TABLE current_account_transactions(id INTEGER PRIMARY KEY,current_account_id INTEGER,movement_kind TEXT,amount NUMERIC,source_ref TEXT);
INSERT INTO current_account_transactions VALUES(1,7,'debit',250,'service:1'),(2,7,'collection',100,'service:1')");
$ledgerBefore=$p->query('SELECT * FROM current_account_transactions')->fetchAll(PDO::FETCH_ASSOC);
ensure_cash_company_schema($p);
$ledgerAfter=$p->query('SELECT * FROM current_account_transactions')->fetchAll(PDO::FETCH_ASSOC);
foreach($ledgerAfter as &$row){if($row['company_account_id']!==42)throw new RuntimeException('Ledger owner missing');unset($row['company_account_id']);}unset($row);
if($ledgerBefore!==$ledgerAfter)throw new RuntimeException('Ledger history changed');
if((int)$p->query('SELECT COUNT(*) FROM cash_transactions')->fetchColumn()!==3)throw new RuntimeException('Cash payment duplicated');
echo "PASS: historical ledger preserved without creating cash payments.\n";
