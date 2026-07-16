<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php'; require __DIR__.'/social-security-bootstrap.php'; social_security_definitions();
$pdo=db();$changed=$pdo->prepare("UPDATE patients SET social_security='Şehit Yakını' WHERE TRIM(social_security)='ŞEHİT YAKINI'");$changed->execute();
$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$insert=$pdo->prepare($driver==='sqlite'?'INSERT OR IGNORE INTO social_security_definitions(name,sort_order) VALUES(?,?)':'INSERT IGNORE INTO social_security_definitions(name,sort_order) VALUES(?,?)');$insert->execute(['Şehit Yakını',100]);
$removed=$pdo->prepare("DELETE FROM social_security_definitions WHERE TRIM(name)='ŞEHİT YAKINI'");$removed->execute();
echo $changed->rowCount()." hasta kaydı Şehit Yakını olarak birleştirildi; ".$removed->rowCount()." ŞEHİT YAKINI tanımı kaldırıldı.\n";
