<?php
declare(strict_types=1);

// Use the same paid-installment and Mail Order treatment as the existing cash screen.
function company_finance_amount(array $row): float
{
    if ($row['payment_type'] !== 'term') return (float)$row['amount'];
    $total = 0.0;
    foreach ((array)json_decode((string)($row['term_schedule'] ?? ''), true) as $item) {
        if (!is_array($item) || empty($item['paid'])) continue;
        $value = preg_replace('/[^0-9,.-]/u', '', (string)($item['amount'] ?? '')) ?? '';
        $total += str_contains($value, ',') ? (float)str_replace(',', '.', str_replace('.', '', $value)) : (float)str_replace('.', '', $value);
    }
    return $total;
}
function company_finance_summary(array $rows): array
{
    $result = ['income'=>0.0,'expense'=>0.0,'net'=>0.0,'groups'=>[],'rows'=>[]];
    foreach ($rows as $row) {
        $amount = company_finance_amount($row);
        if ($row['payment_type'] === 'term' && $amount <= 0) continue;
        $row['income'] = $row['transaction_type'] === 'income' ? $amount : 0.0;
        $row['expense'] = $row['transaction_type'] === 'expense' || ($row['transaction_type'] === 'income' && $row['payment_type'] === 'mail_order') ? $amount : 0.0;
        $result['income'] += $row['income'];
        $result['expense'] += $row['expense'];
        $register = ($row['cash_register'] ?? 'main') === 'pre' ? 'Ön Kasa' : 'Ana Kasa';
        $bank = trim((string)($row['bank_name'] ?? ''));
        $group = $register . ($bank !== '' ? ' · '.$bank : '');
        $result['groups'][$group] = ($result['groups'][$group] ?? 0) + $row['income'] - $row['expense'];
        $result['rows'][] = $row;
    }
    $result['net'] = $result['income'] - $result['expense'];
    return $result;
}
