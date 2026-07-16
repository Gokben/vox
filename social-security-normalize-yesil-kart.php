<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php'; require __DIR__.'/social-security-bootstrap.php'; social_security_definitions();
function yesil_kart_key(string $value): string { return mb_strtolower(str_replace(['İ','I'],['i','ı'],trim($value)),'UTF-8'); }
$pdo=db();$changed=0;$removed=0;$keys=['yeşil kartlı','yeşilkart','yeşil kart'];$update=$pdo->prepare('UPDATE patients SET social_security=? WHERE id=?');
foreach($pdo->query('SELECT id,social_security FROM patients') as $patient){if(in_array(yesil_kart_key((string)$patient['social_security']),$keys,true)&&$patient['social_security']!=='Yeşil Kart'){$update->execute(['Yeşil Kart',$patient['id']]);$changed++;}}
$delete=$pdo->prepare('DELETE FROM social_security_definitions WHERE id=?');foreach($pdo->query('SELECT id,name FROM social_security_definitions') as $definition){if($definition['name']!=='Yeşil Kart'&&in_array(yesil_kart_key((string)$definition['name']),$keys,true)){$delete->execute([$definition['id']]);$removed++;}}
echo "{$changed} hasta kaydı Yeşil Kart olarak birleştirildi; {$removed} eski tanım kaldırıldı.\n";
