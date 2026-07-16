<?php
declare(strict_types=1);
putenv('APP_ENV=local'); require __DIR__.'/config.php';
$pdo=db();$changed=0;$update=$pdo->prepare('UPDATE patients SET social_security=? WHERE id=?');
foreach($pdo->query('SELECT id,social_security FROM patients') as $patient){$value=mb_strtolower(trim((string)$patient['social_security']),'UTF-8');if(in_array($value,['çocuk','cocuk'],true)&&$patient['social_security']!=='Çocuk'){$update->execute(['Çocuk',$patient['id']]);$changed++;}}
echo "{$changed} hasta kaydı Çocuk olarak standartlaştırıldı; tanım listesi değiştirilmedi.\n";
