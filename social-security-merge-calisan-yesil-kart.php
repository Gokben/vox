<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php'; require __DIR__.'/social-security-bootstrap.php'; social_security_definitions();
$pdo=db();$changed=$pdo->prepare("UPDATE patients SET social_security='Çalışan Yeşil Kart' WHERE TRIM(social_security)='Çalışan - Yeşil Kart'");$changed->execute();
$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$insert=$pdo->prepare($driver==='sqlite'?'INSERT OR IGNORE INTO social_security_definitions(name,sort_order) VALUES(?,?)':'INSERT IGNORE INTO social_security_definitions(name,sort_order) VALUES(?,?)');$insert->execute(['Çalışan Yeşil Kart',100]);
$removed=$pdo->prepare("DELETE FROM social_security_definitions WHERE TRIM(name)='Çalışan - Yeşil Kart'");$removed->execute();
echo $changed->rowCount()." hasta kaydı Çalışan Yeşil Kart olarak birleştirildi; ".$removed->rowCount()." tireli tanım kaldırıldı.\n";
