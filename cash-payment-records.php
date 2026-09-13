<?php
declare(strict_types=1);
require_once __DIR__ . '/date-format.php';

function cash_payment_money(mixed $value): float
{
    $text = preg_replace('/[^0-9,.-]/u', '', (string)$value) ?? '';
    if (str_contains($text, ',')) $text = str_replace(',', '.', str_replace('.', '', $text));
    elseif (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/D', $text)) $text = str_replace('.', '', $text);
    return round((float)$text, 2);
}

// A payment's date belongs to that payment, never to the preceding payment.
function cash_payment_record(array $data): array
{
    $date = vox_date_to_iso(trim((string)($data['transaction_date'] ?? '')));
    $type = (string)($data['payment_type'] ?? '');
    $description = trim((string)($data['description'] ?? ''));
    if ($date === '' || $description === '' || !in_array($type, ['cash','eft_transfer','credit_card','mail_order','term'], true)) {
        throw new RuntimeException('Her ödeme için işlem tarihi, ödeme şekli ve açıklama girin.');
    }
    $amount = cash_payment_money($data['amount'] ?? 0);
    $schedule = null;
    $total = $amount;
    if ($type === 'term') {
        $plan = $data['term_schedule'] ?? [];
        if (is_string($plan)) $plan = json_decode($plan, true);
        if (!is_array($plan) || !$plan) throw new RuntimeException('Vadeli ödeme için vade planını doldurun.');
        $total = $amount = 0.0;
        $schedule = [];
        foreach ($plan as $item) {
            $itemDate = vox_date_to_iso(trim((string)($item['date'] ?? '')));
            $itemAmount = cash_payment_money($item['amount'] ?? 0);
            if ($itemDate === '' || $itemAmount <= 0) throw new RuntimeException('Her vade için geçerli tarih ve tutar girin.');
            $paid = !empty($item['paid']);
            $schedule[] = ['date' => $itemDate, 'amount' => number_format($itemAmount, 2, ',', '.'), 'paid' => $paid];
            $total += $itemAmount;
            if ($paid) $amount += $itemAmount;
        }
    }
    if ($total <= 0) throw new RuntimeException('Ödeme tutarı sıfırdan büyük olmalıdır.');
    $account = in_array($type, ['mail_order','eft_transfer'], true) ? (int)($data['current_account_id'] ?? 0) : 0;
    if ($type === 'mail_order' && !$account) throw new RuntimeException('Mail Order için cari hesap seçmelisiniz.');
    return [
        'transaction_date' => $date, 'description' => $description,
        'amount' => round($amount, 2), 'payment_type' => $type,
        'installment_count' => in_array($type, ['credit_card','term'], true) ? max(1, (int)($data['installment_count'] ?? 1)) : 1,
        'bank_name' => in_array($type, ['eft_transfer','credit_card','mail_order'], true) ? (trim((string)($data['bank_name'] ?? '')) ?: null) : null,
        'commission_rate' => $type === 'credit_card' ? cash_payment_money($data['commission_rate'] ?? 0) : null,
        'current_account_id' => $account ?: null,
        'term_schedule' => $schedule === null ? null : json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
}

function cash_payment_posted(array $post, string $prefix = ''): array
{
    $data = [];
    foreach (['transaction_date','description','amount','payment_type','installment_count','bank_name','commission_rate','current_account_id'] as $field) {
        $data[$field] = $post[$prefix . $field] ?? '';
    }
    $data['term_schedule'] = $post[$prefix . 'term_schedule_json'] ?? null;
    if (!$data['term_schedule']) {
        $data['term_schedule'] = [];
        foreach ((array)($post[$prefix . 'term_amount'] ?? []) as $index => $amount) {
            $data['term_schedule'][] = ['date' => $post[$prefix . 'term_date'][$index] ?? '', 'amount' => $amount, 'paid' => isset($post[$prefix . 'term_paid'][$index])];
        }
    }
    return cash_payment_record($data);
}

// Validate the whole batch before writing, then commit it as one unit.
function cash_payment_save_batch(PDO $pdo, array $payments, string $source, int $userId, string $register = 'pre', string $type = 'income', ?int $category = null): void
{
    if (!in_array($type, ['income','expense'], true)) throw new RuntimeException('Geçersiz kasa işlem tipi.');
    foreach ($payments as &$payment) {
        $record = &$payment['record'];
        // CR-00 is the receiving business, not an EFT counterparty.
        if (($record['payment_type'] ?? '') === 'eft_transfer' && !empty($record['current_account_id'])) {
            $owner = $pdo->prepare("SELECT id FROM current_accounts WHERE id=? AND code='CR-00'");
            $owner->execute([(int)$record['current_account_id']]);
            if ($owner->fetchColumn()) $record['current_account_id'] = null;
        }
        cash_validate_counterparty($pdo, (int)($record['current_account_id'] ?? 0));
        unset($record);
    }
    unset($payment);
    $pdo->beginTransaction();
    try {
        if ($source !== '' && $type === 'income') {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $existing = $pdo->prepare('SELECT id FROM cash_transactions WHERE source_url=? AND transaction_type=?' . $lock);
            $existing->execute([$source, $type]);
            $existingCount = count($existing->fetchAll(PDO::FETCH_COLUMN));
            $newCount = count(array_filter($payments, static fn($payment) => empty($payment['id'])));
            if ($existingCount + $newCount > 4) throw new RuntimeException('Bir satış için en fazla 4 ödeme kaydı oluşturulabilir.');
        }
        foreach ($payments as $payment) {
            $record = $payment['record'];
            $id = (int)($payment['id'] ?? 0);
            if ($id) {
                $check = $pdo->prepare('SELECT id FROM cash_transactions WHERE id=? AND source_url=? AND transaction_type=?');
                $check->execute([$id, $source, $type]);
                if (!$check->fetchColumn()) throw new RuntimeException('Ödeme kaydı bu karta ait değil.');
                $sql = 'UPDATE cash_transactions SET ' . implode(',', array_map(static fn($key) => $key . '=?', array_keys($record))) . ' WHERE id=? AND source_url=? AND transaction_type=?';
                $pdo->prepare($sql)->execute([...array_values($record), $id, $source, $type]);
            } else {
                $record += ['transaction_type' => $type, 'source_url' => $source ?: null, 'created_by' => $userId, 'cash_register' => $register, 'category_id' => $category];
                $sql = 'INSERT INTO cash_transactions (' . implode(',', array_keys($record)) . ') VALUES (' . implode(',', array_fill(0, count($record), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($record));
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function cash_payment_decode_records(string $json, float $saleTotal = 0): array
{
    $rows = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 4) throw new RuntimeException('Bir satış için en fazla 4 ödeme kaydı girilebilir.');
    $payments = []; $ids = []; $total = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Geçersiz ödeme kaydı.');
        $id = (int)($row['id'] ?? 0);
        if ($id < 0 || ($id && isset($ids[$id]))) throw new RuntimeException('Aynı ödeme birden fazla kez gönderilemez.');
        if ($id) $ids[$id] = true;
        $record = cash_payment_record($row);
        $total += $record['payment_type'] === 'term'
            ? array_sum(array_map(static fn($item) => cash_payment_money($item['amount']), json_decode($record['term_schedule'], true)))
            : $record['amount'];
        $payments[] = ['id'=>$id,'record'=>$record];
    }
    if ($saleTotal > 0 && abs($total - $saleTotal) > 0.009) throw new RuntimeException('Ödeme kayıtları toplamı satış tutarına eşit olmalıdır.');
    return $payments;
}
