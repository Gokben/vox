<?php
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function db(): PDO { global $pdo; return $pdo; }
$pdo->exec('CREATE TABLE patients(id INTEGER PRIMARY KEY, created_by INTEGER)');
$pdo->exec('CREATE TABLE employees(id INTEGER PRIMARY KEY, full_name TEXT, active INTEGER DEFAULT 1)');
$pdo->exec("INSERT INTO employees(id,full_name) VALUES(7,'Test Employee')");
require dirname(__DIR__) . '/patient-creator-schema.php';
ensure_patient_opening_employee_schema($pdo);
ensure_patient_opening_employee_schema($pdo);
foreach (['', '7'] as $value) if (patient_opening_employee_error($pdo,$value) !== '') throw new RuntimeException('Valid selection rejected');
foreach (['9','-1','7abc','0'] as $value) if (patient_opening_employee_error($pdo,$value) === '') throw new RuntimeException('Invalid selection accepted');
$pdo->prepare('INSERT INTO patients(id,created_by,opening_employee_id) VALUES(?,?,?)')->execute([1,42,7]);
$row=$pdo->query('SELECT created_by,opening_employee_id FROM patients')->fetch(PDO::FETCH_ASSOC);
if ((int)$row['created_by']!==42 || (int)$row['opening_employee_id']!==7) throw new RuntimeException('Opening employee mixed with creator');
$pdo->exec('UPDATE patients SET opening_employee_id=NULL WHERE id=1');
if ($pdo->query('SELECT opening_employee_id FROM patients')->fetchColumn()!==null) throw new RuntimeException('Clear failed');
$pdo->exec('UPDATE employees SET active=0 WHERE id=7');
if (patient_opening_employee_error($pdo,'7') === '') throw new RuntimeException('Inactive selection accepted');
if (patient_opening_employee_error($pdo,'7',7) !== '') throw new RuntimeException('Existing inactive assignment lost');
echo "Employee selection, persistence, blank and separate creator checks passed.\n";
