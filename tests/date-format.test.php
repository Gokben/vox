<?php
require __DIR__.'/../date-format.php';
function expect($actual, $expected): void { if ($actual !== $expected) throw new RuntimeException('Date regression'); }
expect(vox_date_to_iso('29.02.2024'), '2024-02-29');
expect(vox_date_to_iso('13.09.2026 14:30', true), '2026-09-13 14:30:00');
foreach (['29.02.2023','31.04.2026','13/09/2026'] as $invalid) {
    try { vox_date_to_iso($invalid); throw new RuntimeException('Invalid date accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$data=vox_normalize_dates(['movement_date'=>'13.09.2026','term_date'=>['14.09.2026','15.09.2026'],'sales_details'=>'{"delivery_date":"16.09.2026"}']);
expect($data['movement_date'],'2026-09-13');
expect($data['term_date'],['2026-09-14','2026-09-15']);
expect(json_decode($data['sales_details'],true)['delivery_date'],'2026-09-16');
// Invoice parser must accept the ISO value produced by request normalization.
$invoice=file_get_contents(__DIR__.'/../invoice-entry-v2.php');
preg_match('/function inv2_date\(.*?\n/', $invoice, $match);
eval($match[0]);
expect(inv2_date($data['movement_date']),'2026-09-13');
expect(inv2_date('13.09.2026'),'2026-09-13');
echo "Server date and invoice regression tests passed\n";

expect(vox_date_to_iso('1.9.2026'),'2026-09-01');
expect(vox_date_to_iso('1.09.2026'),'2026-09-01');
