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
$eventType = (string)($_POST['event_type'] ?? '');
if (!$recordId || !in_array($recordType, ['appointment', 'patient_service'], true) || ($recordType === 'appointment' && !in_array($eventType, ['appointment', 'daily_event'], true))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Geçerli bir randevu seçin.']);
    exit;
}

$pdo = db();
if ($recordType === 'appointment') {
    $delete = $pdo->prepare('DELETE FROM appointments WHERE id=? AND event_type=?');
    $delete->execute([$recordId, $eventType]);
    if ($delete->rowCount() !== 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı veya silinemez.']);
        exit;
    }
} else {
    $service = $pdo->prepare('SELECT id,patient_id,record_no FROM patient_services WHERE id=? LIMIT 1');
    $service->execute([$recordId]);
    $serviceCard = $service->fetch();
    if (!$serviceCard) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Hizmet kartı bulunamadı.']);
        exit;
    }
    $sourceUrl = url('patient-followup.php?id=' . (int)$serviceCard['patient_id']);
    $cashCheck = $pdo->prepare('SELECT 1 FROM cash_transactions WHERE source_url LIKE ? LIMIT 1');
    $cashCheck->execute([$sourceUrl . '%']);
    if ($cashCheck->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Bu hastaya bağlı ödeme kaydı olduğu için hizmet kartı takvimden silinemez.']);
        exit;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM stock_movements WHERE service_id=?')->execute([$recordId]);
        $pdo->prepare('DELETE FROM patient_services WHERE id=?')->execute([$recordId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
