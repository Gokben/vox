<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
try {
    verify_csrf();
    $movementId = filter_input(INPUT_POST, 'movement_id', FILTER_VALIDATE_INT);
    $serialIndex = filter_input(INPUT_POST, 'serial_index', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $serialNo = trim((string)($_POST['serial_no'] ?? ''));
    if (!$movementId || $serialIndex === false) throw new RuntimeException('Seri numarası kaydı bulunamadı.');
    $statement = db()->prepare("SELECT m.serial_numbers FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE m.id=? AND m.movement_type='Giriş' AND s.stock_type=?");
    $statement->execute([$movementId, 'İşitme Cihazı']);
    $storedSerials = $statement->fetchColumn();
    if ($storedSerials === false) throw new RuntimeException('Stok girişi bulunamadı.');
    $serials = json_decode((string)$storedSerials, true);
    if (!is_array($serials)) $serials = [];
    while (count($serials) <= $serialIndex) $serials[] = '';
    $serials[$serialIndex] = $serialNo;
    $normalized = array_values(array_map(static fn($value): string => trim((string)$value), $serials));
    $nonEmpty = array_values(array_filter($normalized, static fn(string $value): bool => $value !== ''));
    if (count($nonEmpty) !== count(array_unique($nonEmpty))) throw new RuntimeException('Aynı stok girişinde seri numarası tekrar edemez.');
    db()->prepare('UPDATE stock_movements SET serial_numbers=? WHERE id=?')->execute([json_encode($normalized, JSON_UNESCAPED_UNICODE), $movementId]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
