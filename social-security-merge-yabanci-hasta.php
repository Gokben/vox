<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php'; require __DIR__.'/social-security-bootstrap.php'; social_security_definitions();
$pdo=db();$old=['YABANCI HASTA - EŞİ ÜZERİNDEN BAĞKUR FAYDANALIYOR','YABANCI HASTA'];$placeholders=implode(',',array_fill(0,count($old),'?'));
$changed=$pdo->prepare("UPDATE patients SET social_security='Yabancı Hasta' WHERE TRIM(social_security) IN ($placeholders)");$changed->execute($old);
$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$insert=$pdo->prepare($driver==='sqlite'?'INSERT OR IGNORE INTO social_security_definitions(name,sort_order) VALUES(?,?)':'INSERT IGNORE INTO social_security_definitions(name,sort_order) VALUES(?,?)');$insert->execute(['Yabancı Hasta',100]);
$removed=$pdo->prepare("DELETE FROM social_security_definitions WHERE TRIM(name) IN ($placeholders)");$removed->execute($old);
echo $changed->rowCount()." hasta kaydı Yabancı Hasta olarak birleştirildi; ".$removed->rowCount()." eski tanım kaldırıldı.\n";
