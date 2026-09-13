<?php
declare(strict_types=1);

function current_account_types(bool $admin): array
{
    $types = ['customer'=>'Müşteri','supplier'=>'Tedarikçi','both'=>'Hizmet Alım','institution'=>'Kurum'];
    if ($admin) $types['business'] = 'İşletme';
    return $types;
}

function current_account_resolve_type(string $requested, bool $admin, ?array $existing = null): string
{
    if (!$admin && $requested === 'business') throw new InvalidArgumentException('İşletme cari tipi yalnızca yöneticiler tarafından seçilebilir.');
    if (($existing['code'] ?? '') === 'CR-00' || (!$admin && ($existing['account_type'] ?? '') === 'business')) return 'business';
    if (!array_key_exists($requested, current_account_types($admin))) throw new InvalidArgumentException('Geçerli bir cari tipi seçin.');
    return $requested;
}

function ensure_current_account_types(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $column = $pdo->query("SHOW COLUMNS FROM current_accounts LIKE 'account_type'")->fetch(PDO::FETCH_ASSOC);
        $definition = (string)$column['Type'];
        foreach (['institution','business'] as $type) {
            if (str_starts_with($definition, 'enum(') && !str_contains($definition, "'".$type."'")) {
                $definition = substr($definition, 0, -1).",'".$type."')";
            }
        }
        if ($definition !== $column['Type']) $pdo->exec('ALTER TABLE current_accounts MODIFY COLUMN account_type '.$definition.' NOT NULL');
    }
    $pdo->exec("UPDATE current_accounts SET account_type='business' WHERE code='CR-00' AND account_type<>'business'");
}
