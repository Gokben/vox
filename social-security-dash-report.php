<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php';
$stmt=db()->prepare("SELECT full_name,record_date FROM patients WHERE TRIM(COALESCE(social_security,''))='-' ORDER BY record_date,full_name");$stmt->execute();$rows=$stmt->fetchAll();
echo 'TOPLAM='.count($rows).PHP_EOL;foreach($rows as $row)echo ($row['record_date']?:'Tarih yok').' | '.$row['full_name'].PHP_EOL;
