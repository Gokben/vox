<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php';
$stmt=db()->query("SELECT import_order,full_name,record_date FROM patients WHERE TRIM(COALESCE(social_security,''))='' ORDER BY import_order");foreach($stmt as $row)echo $row['import_order'].' | '.$row['full_name'].' | '.$row['record_date'].PHP_EOL;
