<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Yalnızca POST isteği kabul edilir.']);
    exit;
}

if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Oturum doğrulanamadı.']);
    exit;
}

$recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
$recordType = (string)($_POST['record_type'] ?? '');
$appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
if (!$recordId || !in_array($recordType, ['appointment', 'patient_service'], true) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $appointmentDate)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Geçerli bir randevu tarihi seçin.']);
    exit;
}

$pdo = db();
$table = $recordType === 'patient_service' ? 'patient_services' : 'appointments';
$exists = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id=? LIMIT 1');
$exists->execute([$recordId]);
if (!$exists->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Hizmet kartı bulunamadı.']);
    exit;
}

$update = $pdo->prepare('UPDATE ' . $table . ' SET appointment_date=? WHERE id=?');
$update->execute([$appointmentDate, $recordId]);
echo json_encode(['success' => true, 'appointment_date' => $appointmentDate], JSON_UNESCAPED_UNICODE);
