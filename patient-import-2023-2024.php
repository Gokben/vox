<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Bu araç yalnızca komut satırından çalıştırılır.\n");
putenv('APP_ENV=local'); require __DIR__.'/config.php';
$xlsx = $argv[1] ?? 'F:\\2023-24 HASTA KAYIT MERKEZ ŞUBE (1).xlsx';
if (!is_file($xlsx) || !class_exists('ZipArchive')) exit("Excel yedeği veya ZipArchive bulunamadı.\n");
$zip = new ZipArchive(); if ($zip->open($xlsx) !== true) exit("Excel açılamadı.\n");
$shared=[]; if (($sharedXml=$zip->getFromName('xl/sharedStrings.xml'))!==false) { $sx=simplexml_load_string($sharedXml); foreach($sx->si as $si){$parts=[];if(isset($si->t))$parts[]=(string)$si->t;foreach($si->r as $run)$parts[]=(string)$run->t;$shared[]=implode('',$parts);} }
function import_sheet(ZipArchive $zip,int $number): SimpleXMLElement { $xml=$zip->getFromName("xl/worksheets/sheet{$number}.xml"); if($xml===false) throw new RuntimeException("Çalışma sayfası {$number} bulunamadı."); return simplexml_load_string($xml); }
function col_2324(string $ref):int {preg_match('/^[A-Z]+/',$ref,$m);$n=0;foreach(str_split($m[0])as$ch)$n=$n*26+ord($ch)-64;return$n;}
function date_2324(string $value):?string {$value=trim($value);if($value===''||!is_numeric($value))return null;return gmdate('Y-m-d',(int)(((float)$value-25569)*86400));}
function val_2324(SimpleXMLElement $row,array $shared):array {$v=array_fill(1,30,'');foreach($row->c as $cell){$i=col_2324((string)$cell['r']);$raw=(string)$cell->v;$v[$i]=(string)$cell['t']==='s'?($shared[(int)$raw]??''):$raw;}return$v;}
$sheets=[2023=>[import_sheet($zip,1),6],2024=>[import_sheet($zip,2),8]];$zip->close();$pdo=db();$order=(int)$pdo->query('SELECT COALESCE(MAX(import_order),0) FROM patients')->fetchColumn();
$insert=$pdo->prepare('INSERT INTO patients(import_order,record_date,full_name,national_id,phone_primary,phone_secondary,birth_date,address,social_security,report_info,service_location,service_type,source_primary,source_marketing,source_detail,anamnesis,notes,approval,considering,rejected,staff_cansu,staff_busra,staff_belma) VALUES('.implode(',',array_fill(0,23,'?')).')');$records=[];
foreach($sheets as $year=>[$sheet,$firstRow])foreach($sheet->sheetData->row as $row){if((int)$row['r']<$firstRow)continue;$v=val_2324($row,$shared);if(trim($v[2])==='')continue;$approval=mb_strtoupper(trim($year===2023?$v[19]:$v[18]));$records[]=[++$order,date_2324($v[1]),trim($v[2]),trim($v[3]),trim($v[4]),trim($v[5]),date_2324($v[6]),trim($v[7]),trim($v[8]),trim($v[9]),trim($year===2023?$v[17]:$v[16]),trim($v[10]),trim($v[10]),trim($v[11]),trim($v[12]),trim($v[13]),trim($year===2023?$v[12]:$v[11]),str_contains($approval,'ONAY')?1:0,0,str_contains($approval,'RED')?1:0,0,0,0];}
$pdo->beginTransaction();try{foreach($records as $r)$insert->execute($r);$pdo->commit();echo count($records)." adet 2023/2024 hasta kaydı eklendi.\n";}catch(Throwable $e){$pdo->rollBack();throw$e;}
