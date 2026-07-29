<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('patients.php');
verify_csrf();

$patientId = (int)($_POST['id'] ?? 0);
if ($patientId < 1) redirect('patients.php');

$pdo = db();
try {
    $serviceStatement = $pdo->prepare('SELECT 1 FROM patient_services WHERE patient_id=? LIMIT 1');
    $serviceStatement->execute([$patientId]);
    if ($serviceStatement->fetchColumn()) redirect('patients.php?delete_error=service_card');
} catch (Throwable $exception) {
    // Hizmet kartı tablosu henüz oluşturulmamışsa silme işlemi mevcut davranışla devam eder.
}

$pdo->prepare('DELETE FROM patients WHERE id=?')->execute([$patientId]);
redirect('patients.php');
