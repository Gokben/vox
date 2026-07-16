<?php
declare(strict_types=1);
putenv('APP_ENV=local');
require __DIR__.'/config.php';
require __DIR__.'/social-security-bootstrap.php';
social_security_definitions();
$pdo=db();
$changed=0;$removed=0;
$patients=$pdo->query('SELECT id,social_security FROM patients')->fetchAll();
$update=$pdo->prepare('UPDATE patients SET social_security=? WHERE id=?');
foreach($patients as $patient){$normalized=mb_strtolower(str_replace(['İ','I'],['i','i'],trim((string)$patient['social_security'])),'UTF-8');if(in_array($normalized,['emek','emekli'],true)&&$patient['social_security']!=='Emekli'){$update->execute(['Emekli',$patient['id']]);$changed++;}}
$definitions=$pdo->query('SELECT id,name FROM social_security_definitions')->fetchAll();$delete=$pdo->prepare('DELETE FROM social_security_definitions WHERE id=?');
foreach($definitions as $definition){$normalized=mb_strtolower(str_replace(['İ','I'],['i','i'],trim((string)$definition['name'])),'UTF-8');if($definition['name']!=='Emekli'&&in_array($normalized,['emek','emekli'],true)){$delete->execute([$definition['id']]);$removed++;}}
echo "{$changed} hasta kaydı Emekli olarak birleştirildi; {$removed} eski tanım kaldırıldı.\n";
