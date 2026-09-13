<?php
declare(strict_types=1);

function cash_company_account(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id,code,title,short_name FROM current_accounts WHERE code='CR-00'")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) throw new RuntimeException('İşletme için tek bir CR-00 cari kartı tanımlanmalıdır.');
    return $rows[0];
}

function ensure_cash_company_schema(PDO $pdo): void
{
    $company = cash_company_account($pdo);
    $id = (int)$company['id'];
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $tables = $sqlite
        ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['cash_transactions', 'current_account_transactions'] as $table) {
        if (!in_array($table, $tables, true)) continue;
        $columns = $sqlite
            ? array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('company_account_id', $columns, true)) {
            // Preserve all financial fields and the counterparty. Only add ownership.
            $type = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
            $pdo->exec("ALTER TABLE $table ADD COLUMN company_account_id $type NOT NULL DEFAULT $id CHECK (company_account_id = $id)");
        }
    }
}

function cash_validate_counterparty(PDO $pdo, int $id): void
{
    if ($id === 0) return;
    $query = $pdo->prepare("SELECT id FROM current_accounts WHERE id=? AND code<>'CR-00'");
    $query->execute([$id]);
    if (!$query->fetchColumn()) throw new RuntimeException('Karşı taraf olarak işletme dışında geçerli bir cari kart seçin.');
}
