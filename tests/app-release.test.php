<?php
require dirname(__DIR__) . '/app-version.php';
function same($expected, $actual) { if ($expected !== $actual) throw new RuntimeException("Expected $expected, got $actual"); }
$date = new DateTimeImmutable('2026-09-13T12:00:00Z');
same('13096.01', vox_next_release_version(null, 'first', $date));
same('13096.02', vox_next_release_version(['build'=>'first','version'=>'13096.01'], 'second', $date));
same('13096.02', vox_next_release_version(['build'=>'second','version'=>'13096.02'], 'second', $date));
same('14096.01', vox_next_release_version(['build'=>'second','version'=>'13096.02'], 'third', new DateTimeImmutable('2026-09-13T22:00:00Z')));
same('13096.100', vox_next_release_version(['build'=>'first','version'=>'13096.99'], 'second', $date));
same('13096.01', vox_next_release_version(['version'=>'invalid'], 'first', $date));
$_SESSION = [];
$a = vox_versioned_path('assets/app-version.js?v=1#test');
if (!str_contains($a, '&_vox_v=') || !str_ends_with($a, '#test')) throw new RuntimeException('Asset URL broken');
same('patient-form.php?id=1', vox_versioned_path('patient-form.php?id=1'));
echo "Release numbering and asset URL checks passed.\n";
