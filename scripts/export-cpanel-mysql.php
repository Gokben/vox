<?php
declare(strict_types=1);
$source=dirname(__DIR__).'/storage/vox.sqlite';$target=dirname(__DIR__).'/vox-cpanel-full-import.sql';
if(!is_file($source)){fwrite(STDERR,"SQLite bulunamadı: $source\n");exit(1);} $pdo=new PDO('sqlite:'.$source);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function q(?string $v):string{return $v===null?'NULL':"'".str_replace(["\\","'","\0","\n","\r"],["\\\\","''","\\0","\\n","\\r"],$v)."'";}
$schema=<<<'SQL'
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS branches;
CREATE TABLE branches (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL UNIQUE,code VARCHAR(50),phone VARCHAR(50),address TEXT,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role ENUM('Admin','User') NOT NULL DEFAULT 'User',active TINYINT(1) NOT NULL DEFAULT 1,branch_id INT UNSIGNED NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,KEY idx_users_branch(branch_id),CONSTRAINT fk_users_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE settings (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL UNIQUE,setting_value TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE patients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,import_order INT UNSIGNED NOT NULL,branch_id INT UNSIGNED NULL,record_date DATE NULL,full_name VARCHAR(190) NOT NULL,national_id VARCHAR(30),phone_primary VARCHAR(50),phone_secondary VARCHAR(50),birth_date DATE NULL,address TEXT,social_security VARCHAR(100),report_info VARCHAR(150),service_location VARCHAR(150),service_type VARCHAR(150),source_primary VARCHAR(150),source_marketing VARCHAR(150),source_detail VARCHAR(255),anamnesis TEXT,notes TEXT,approval TINYINT(1) NOT NULL DEFAULT 0,considering TINYINT(1) NOT NULL DEFAULT 0,rejected TINYINT(1) NOT NULL DEFAULT 0,staff_cansu TINYINT(1) NOT NULL DEFAULT 0,staff_busra TINYINT(1) NOT NULL DEFAULT 0,staff_belma TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_patients_import_order(import_order),KEY idx_patients_branch(branch_id),KEY idx_patients_name(full_name),CONSTRAINT fk_patients_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
$out=$schema."\n";
$tables=[
 'branches'=>['id','name','code','phone','address','active','created_at'],
 'users'=>['id','name','email','password_hash','role','active','branch_id','created_at'],
 'settings'=>['id','setting_key','setting_value'],
 'patients'=>['id','import_order','branch_id','record_date','full_name','national_id','phone_primary','phone_secondary','birth_date','address','social_security','report_info','service_location','service_type','source_primary','source_marketing','source_detail','anamnesis','notes','approval','considering','rejected','staff_cansu','staff_busra','staff_belma','created_at','updated_at']
];
foreach($tables as $table=>$columns){$available=array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(),'name');$cols=array_values(array_intersect($columns,$available));if(!$cols)continue;$rows=$pdo->query('SELECT '.implode(',',$cols).' FROM '.$table.' ORDER BY id')->fetchAll();foreach($rows as $row){$values=[];foreach($cols as $col)$values[]=q($row[$col]===null?null:(string)$row[$col]);$out.='INSERT INTO '.$table.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',$values).");\n";}}
$out.="SET FOREIGN_KEY_CHECKS=1;\n";file_put_contents($target,$out);echo $target."\n";foreach(array_keys($tables) as $table){try{echo $table.'='.(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn()."\n";}catch(Throwable $e){echo $table."=0\n";}}
