<?php
require __DIR__.'/../patient-identity.php';
function check($ok){if(!$ok)throw new RuntimeException('Identity regression');}
foreach(['','12345678901','01234567890'] as $value)check(patient_identity_error($value)==='');
foreach(['1','1234567890','123456789012','1234567890a','12345678901 ',' 12345678901'] as $value)check(patient_identity_error($value)!=='');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach(['patients','external_technical_patients'] as $table)$pdo->exec("CREATE TABLE $table(id INTEGER PRIMARY KEY, full_name TEXT, national_id TEXT, passport_no TEXT)");
$pdo->exec("INSERT INTO patients(id,full_name,national_id) VALUES(1,'Test','12345678901'),(2,'Blank',''),(3,'Invalid','123'),(4,'Legacy duplicate','12345678901')");
check(patient_identity_validate($pdo,'12345678901','patients',0)!=='');
check(patient_identity_validate($pdo,'12345678901','external_technical_patients',1)!=='');
check(patient_identity_validate($pdo,'','patients',0)==='');
check(patient_identity_validate($pdo,'12345678902','patients',0)==='');
check(count(patient_identity_audit(patient_identity_rows($pdo)))===3);
$pdo->exec('DELETE FROM patients WHERE id=4');
check(patient_identity_validate($pdo,'12345678901','patients',1)==='');
echo "Identity format, blank, duplicate, self-edit and audit tests passed\n";

ensure_patient_passport_schema($pdo);
$pdo->prepare('UPDATE patients SET passport_no=? WHERE id=2')->execute(['P1234567']);
check($pdo->query('SELECT passport_no FROM patients WHERE id=2')->fetchColumn()==='P1234567');
$passportIssues=patient_identity_audit(patient_identity_rows($pdo));
check(!in_array(2,array_column($passportIssues,'id')));
check(patient_passport_error('P1234567')==='');
check(patient_passport_error(str_repeat('A',65))!=='');
echo "Passport persistence and audit tests passed\n";

foreach(['123','123456789012','12345678901',''] as $number){
 check(patient_identity_validate($pdo,$number,'patients',0,'P1234567')==='');
 check(patient_identity_validate($pdo,$number,'external_technical_patients',0,'P1234567')==='');
}
check(patient_identity_validate($pdo,'123','patients',0,'   ')!=='');
$pdo->exec("UPDATE patients SET passport_no='P9876543' WHERE id=1");
check(patient_identity_validate($pdo,'12345678901','patients',0)==='');
$pdo->exec("UPDATE patients SET passport_no='P7654321' WHERE id=3");
check(count(patient_identity_audit(patient_identity_rows($pdo)))===0);
$pdo->exec("UPDATE patients SET passport_no='' WHERE id=1");
check(patient_identity_validate($pdo,'12345678901','patients',0)!=='');
echo "Passport bypass, duplicate exclusion and restored T.C. validation tests passed\n";

check(patient_identity_audit([["id"=>99,"national_id"=>"   ","passport_no"=>""]])===[]);

// The unknown-identity placeholder can be reused in both patient sources.
check(patient_identity_error('00000000000')==='');
foreach(['patients','external_technical_patients'] as $table){
 foreach([10,11] as $id){
  check(patient_identity_validate($pdo,'00000000000',$table,0)==='');
  $pdo->exec("INSERT INTO $table(id,full_name,national_id) VALUES($id,'Unknown identity','00000000000')");
 }
 check(patient_identity_validate($pdo,'00000000000',$table,10)==='');
 foreach(['0000000000','000000000000','0000000000a',' 00000000000'] as $invalid){
  check(patient_identity_validate($pdo,$invalid,$table,0)!=='');
 }
 // Real identifiers remain unique, including across patient sources.
 check(patient_identity_validate($pdo,'12345678901',$table,0)!=='');
}
check(patient_identity_audit(patient_identity_rows($pdo))===[]);
$pdo->exec("INSERT INTO external_technical_patients(id,full_name,national_id) VALUES(12,'Real duplicate','12345678901')");
check(count(patient_identity_audit(patient_identity_rows($pdo)))===2);
check(count(patient_identity_audit([['id'=>99,'national_id'=>' 00000000000','passport_no'=>'']]))===1);
echo "Unknown identity reuse, format boundaries and preserved duplicate checks passed\n";