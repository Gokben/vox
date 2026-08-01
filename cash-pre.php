<?php
declare(strict_types=1);
// Ön Kasa, Kasa ekranının aynı arayüzünü kullanır; kapsam yalnızca Ön Kasa hareketleridir.
$_GET['cash_register'] = 'pre';
require __DIR__ . '/cash.php';
