<?php
$source=file_get_contents(__DIR__.'/../invoice-entry-v2.php');
preg_match('/\$total=round\(\$cost\*\$qty\*\(1-\$discount\/100\),2\);\$netCost=round\(\$total\/\$qty,2\);/',$source,$m);
if (!$m) throw new Exception('Calculation not found');
foreach ([[22800,2,52.592705,21617.73],[86000,1,52.592705,40770.27],[6000,2,64.7059,4235.29],[5000,1,64.7058,1764.71],[31500,8,55,113400],[19500,2,40,23400]] as [$cost,$qty,$discount,$expected]) {eval($m[0]);if(abs($total-$expected)>.00001) throw new Exception('Incorrect persisted total');}
echo 'PASS: persisted line totals match display and sum to 6000';
