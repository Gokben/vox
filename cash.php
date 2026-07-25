<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_settings (
        id INTEGER PRIMARY KEY,
        opening_balance NUMERIC NOT NULL DEFAULT 0,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_date TEXT NOT NULL,
        description TEXT NOT NULL,
        transaction_type TEXT NOT NULL,
        amount NUMERIC NOT NULL,
        payment_type TEXT NOT NULL,
        category_id INTEGER NULL,
        created_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(category_id) REFERENCES cash_categories(id) ON DELETE RESTRICT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_closings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        closing_date TEXT NOT NULL UNIQUE,
        expected_balance NUMERIC NOT NULL,
        counted_balance NUMERIC NOT NULL,
        difference NUMERIC NOT NULL,
        note TEXT,
        created_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT OR IGNORE INTO cash_settings(id,opening_balance) VALUES(1,0)");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_settings (
        id TINYINT UNSIGNED PRIMARY KEY,
        opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_transactions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        description VARCHAR(255) NOT NULL,
        transaction_type ENUM('income','expense') NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        payment_type ENUM('cash','credit_card') NOT NULL,
        category_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX cash_transaction_date_idx(transaction_date),
        CONSTRAINT cash_transaction_category_fk FOREIGN KEY(category_id) REFERENCES cash_categories(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_closings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        closing_date DATE NOT NULL UNIQUE,
        expected_balance DECIMAL(14,2) NOT NULL,
        counted_balance DECIMAL(14,2) NOT NULL,
        difference DECIMAL(14,2) NOT NULL,
        note VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO cash_settings(id,opening_balance) VALUES(1,0)");
}

if ((int)$pdo->query('SELECT COUNT(*) FROM cash_categories')->fetchColumn() === 0) {
    $insertCategory = $pdo->prepare('INSERT INTO cash_categories(name,active) VALUES(?,1)');
    foreach (['Maaş', 'Fatura', 'Satış', 'Kira'] as $categoryName) $insertCategory->execute([$categoryName]);
}

function cash_money(float $value): string
{
    return number_format($value, 2, ',', '.') . ' ₺';
}

function cash_balance_until(PDO $pdo, string $date): float
{
    $opening = (float)$pdo->query('SELECT opening_balance FROM cash_settings WHERE id=1')->fetchColumn();
    $statement = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) income,
        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) expense
        FROM cash_transactions WHERE transaction_date<=?");
    $statement->execute([$date]);
    $totals = $statement->fetch() ?: ['income' => 0, 'expense' => 0];
    return $opening + (float)$totals['income'] - (float)$totals['expense'];
}

$message = '';
$error = '';
$activeTab = (string)($_GET['tab'] ?? 'transactions');
if (!in_array($activeTab, ['transactions', 'categories', 'closing'], true)) $activeTab = 'transactions';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_opening') {
            $openingBalance = (float)str_replace(',', '.', (string)($_POST['opening_balance'] ?? '0'));
            $pdo->prepare('UPDATE cash_settings SET opening_balance=? WHERE id=1')->execute([$openingBalance]);
            $message = 'Devreden kasa güncellendi.';
            $activeTab = 'transactions';
        } elseif ($action === 'save_transaction') {
            $date = trim((string)($_POST['transaction_date'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $type = (string)($_POST['transaction_type'] ?? '');
            $amount = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
            $paymentType = (string)($_POST['payment_type'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($date === '' || $description === '' || !in_array($type, ['income', 'expense'], true) || $amount <= 0 || !in_array($paymentType, ['cash', 'credit_card'], true)) {
                throw new RuntimeException('İşlem bilgilerini eksiksiz ve geçerli olarak girin.');
            }
            $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,category_id,created_by) VALUES(?,?,?,?,?,?,?)')
                ->execute([$date, $description, $type, $amount, $paymentType, $categoryId ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
            $message = 'Kasa işlemi kaydedildi.';
            $activeTab = 'transactions';
        } elseif ($action === 'delete_transaction') {
            $pdo->prepare('DELETE FROM cash_transactions WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Kasa işlemi silindi.';
            $activeTab = 'transactions';
        } elseif ($action === 'save_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Kategori adı zorunludur.');
            $pdo->prepare('INSERT INTO cash_categories(name,active) VALUES(?,1)')->execute([$name]);
            $message = 'Kategori eklendi.';
            $activeTab = 'categories';
        } elseif ($action === 'toggle_category') {
            $pdo->prepare('UPDATE cash_categories SET active=CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Kategori durumu güncellendi.';
            $activeTab = 'categories';
        } elseif ($action === 'delete_category') {
            $id = (int)($_POST['id'] ?? 0);
            $usage = $pdo->prepare('SELECT COUNT(*) FROM cash_transactions WHERE category_id=?');
            $usage->execute([$id]);
            if ((int)$usage->fetchColumn() > 0) throw new RuntimeException('Kullanılan kategori silinemez; pasifleştirebilirsiniz.');
            $pdo->prepare('DELETE FROM cash_categories WHERE id=?')->execute([$id]);
            $message = 'Kategori silindi.';
            $activeTab = 'categories';
        } elseif ($action === 'save_closing') {
            $date = trim((string)($_POST['closing_date'] ?? ''));
            $counted = (float)str_replace(',', '.', (string)($_POST['counted_balance'] ?? '0'));
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            if ($date === '') throw new RuntimeException('Kapanış tarihi zorunludur.');
            $expected = cash_balance_until($pdo, $date);
            $difference = $counted - $expected;
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('INSERT INTO cash_closings(closing_date,expected_balance,counted_balance,difference,note,created_by) VALUES(?,?,?,?,?,?) ON CONFLICT(closing_date) DO UPDATE SET expected_balance=excluded.expected_balance,counted_balance=excluded.counted_balance,difference=excluded.difference,note=excluded.note,created_by=excluded.created_by');
            } else {
                $statement = $pdo->prepare('INSERT INTO cash_closings(closing_date,expected_balance,counted_balance,difference,note,created_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE expected_balance=VALUES(expected_balance),counted_balance=VALUES(counted_balance),difference=VALUES(difference),note=VALUES(note),created_by=VALUES(created_by)');
            }
            $statement->execute([$date, $expected, $counted, $difference, $note ?: null, (int)($_SESSION['user']['id'] ?? 0)]);
            $message = 'Günlük kapanış kaydedildi.';
            $activeTab = 'closing';
        }
    } catch (PDOException $exception) {
        $error = str_contains(strtolower($exception->getMessage()), 'unique') || (string)$exception->getCode() === '23000'
            ? 'Bu kayıt zaten mevcut.'
            : 'Kayıt işlemi tamamlanamadı.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$openingBalance = (float)$pdo->query('SELECT opening_balance FROM cash_settings WHERE id=1')->fetchColumn();
$totalStatement = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) income,
    COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) expense,
    COALESCE(SUM(CASE WHEN payment_type='cash' AND transaction_type='income' THEN amount WHEN payment_type='cash' AND transaction_type='expense' THEN -amount ELSE 0 END),0) cash_total,
    COALESCE(SUM(CASE WHEN payment_type='credit_card' AND transaction_type='income' THEN amount WHEN payment_type='credit_card' AND transaction_type='expense' THEN -amount ELSE 0 END),0) card_total
    FROM cash_transactions");
$totals = $totalStatement->fetch() ?: ['income' => 0, 'expense' => 0, 'cash_total' => 0, 'card_total' => 0];
$income = (float)$totals['income'];
$expense = (float)$totals['expense'];
$netBalance = $openingBalance + $income - $expense;

$categories = $pdo->query('SELECT * FROM cash_categories ORDER BY active DESC,name')->fetchAll();
$activeCategories = array_values(array_filter($categories, static fn(array $category): bool => (bool)$category['active']));
$transactions = $pdo->query('SELECT t.*,c.name category_name FROM cash_transactions t LEFT JOIN cash_categories c ON c.id=t.category_id ORDER BY t.transaction_date DESC,t.id DESC LIMIT 500')->fetchAll();
$closings = $pdo->query('SELECT * FROM cash_closings ORDER BY closing_date DESC,id DESC LIMIT 365')->fetchAll();

patient_header('Kasa', 'cash');
?>
<main class="patient-container cash-page">
  <div class="cash-page-head"><div><h1>Kasa</h1><p>Gelir, gider, bakiye ve günlük kapanış işlemlerini yönetin.</p></div></div>
  <?php if ($message): ?><div class="cash-notice success"><?=e($message)?></div><?php endif ?>
  <?php if ($error): ?><div class="cash-notice error"><?=e($error)?></div><?php endif ?>

  <section class="cash-summary">
    <article><span>Devreden Kasa</span><strong><?=cash_money($openingBalance)?></strong></article>
    <article><span>Toplam Gelir</span><strong class="income"><?=cash_money($income)?></strong></article>
    <article><span>Toplam Gider</span><strong class="expense"><?=cash_money($expense)?></strong></article>
    <article><span>Net Kasa Bakiyesi</span><strong><?=cash_money($netBalance)?></strong><small>Nakit <?=cash_money((float)$totals['cash_total'])?> · Kart <?=cash_money((float)$totals['card_total'])?></small></article>
  </section>

  <nav class="cash-tabs">
    <a class="<?=$activeTab === 'transactions' ? 'active' : ''?>" href="<?=url('cash.php?tab=transactions')?>">Gelir / Gider</a>
    <a class="<?=$activeTab === 'categories' ? 'active' : ''?>" href="<?=url('cash.php?tab=categories')?>">Kategoriler</a>
    <a class="<?=$activeTab === 'closing' ? 'active' : ''?>" href="<?=url('cash.php?tab=closing')?>">Günlük Kapanış</a>
  </nav>

  <?php if ($activeTab === 'transactions'): ?>
    <details class="cash-card cash-accordion cash-transaction-accordion" <?=$error && ($_POST['action'] ?? '') === 'save_transaction' ? 'open' : ''?>>
      <summary><span><h2>Yeni Gelir / Gider Kaydı</h2><p>İşlem tarihi, tutar, ödeme türü ve kategoriyi kaydedin.</p></span><i></i></summary>
      <form class="cash-form" method="post">
        <input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_transaction">
        <label>İşlem Tarihi<input type="date" name="transaction_date" value="<?=e($_POST['transaction_date'] ?? date('Y-m-d'))?>" required></label>
        <label>İşlem Türü<select name="transaction_type" required><option value="income">Gelir</option><option value="expense">Gider</option></select></label>
        <label>Açıklama<input name="description" maxlength="255" value="<?=e($_POST['description'] ?? '')?>" required></label>
        <label>Tutar<input type="number" name="amount" min="0.01" step="0.01" value="<?=e($_POST['amount'] ?? '')?>" required></label>
        <label>Ödeme Türü<select name="payment_type" required><option value="cash">Nakit</option><option value="credit_card">Kredi Kartı</option></select></label>
        <label>Kategori<select name="category_id"><option value="">Kategorisiz</option><?php foreach ($activeCategories as $category): ?><option value="<?=(int)$category['id']?>"><?=e($category['name'])?></option><?php endforeach ?></select></label>
        <div class="cash-actions"><button>Kaydet</button></div>
      </form>
    </details>
    <section class="cash-card">
      <header><div><h2>Kasa Hareketleri</h2><p><?=count($transactions)?> kayıt</p></div>
        <form class="opening-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_opening"><label>Devreden Kasa<input type="number" step="0.01" name="opening_balance" value="<?=number_format($openingBalance,2,'.','')?>"></label><button>Kaydet</button></form>
      </header>
      <div class="cash-table-wrap"><table><thead><tr><th>Tarih</th><th>Açıklama</th><th>Kategori</th><th>Ödeme</th><th>Giren</th><th>Çıkan</th><th>İşlemler</th></tr></thead><tbody>
      <?php foreach ($transactions as $transaction): ?><tr>
        <td><?=format_date_tr($transaction['transaction_date'])?></td><td><?=e($transaction['description'])?></td><td><?=e($transaction['category_name'] ?? '—')?></td><td><?=$transaction['payment_type'] === 'cash' ? 'Nakit' : 'Kredi Kartı'?></td>
        <td class="money income"><?=$transaction['transaction_type'] === 'income' ? cash_money((float)$transaction['amount']) : '—'?></td>
        <td class="money expense"><?=$transaction['transaction_type'] === 'expense' ? cash_money((float)$transaction['amount']) : '—'?></td>
        <td><form method="post" onsubmit="return confirm('Bu kasa işlemi silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="id" value="<?=(int)$transaction['id']?>"><button class="cash-delete" title="Sil" aria-label="Kasa işlemini sil"><i class="ti tabler-trash"></i></button></form></td>
      </tr><?php endforeach ?>
      <?php if (!$transactions): ?><tr><td colspan="7" class="empty">Henüz kasa hareketi bulunmuyor.</td></tr><?php endif ?>
      </tbody></table></div>
    </section>
  <?php elseif ($activeTab === 'categories'): ?>
    <details class="cash-card cash-accordion" <?=$error && ($_POST['action'] ?? '') === 'save_category' ? 'open' : ''?>>
      <summary><span><h2>Yeni Kategori</h2><p>Kasa işlemlerini gruplamak için kategori oluşturun.</p></span><i></i></summary>
      <form class="cash-form category-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_category"><label>Kategori Adı<input name="name" maxlength="150" required></label><div class="cash-actions"><button>Kaydet</button></div></form>
    </details>
    <section class="cash-card"><header><div><h2>Kategori Listesi</h2><p><?=count($categories)?> kayıt</p></div></header><div class="cash-table-wrap"><table><thead><tr><th>Kategori</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
    <?php foreach ($categories as $category): ?><tr><td><?=e($category['name'])?></td><td><span class="cash-status <?=$category['active'] ? 'active' : 'passive'?>"><?=$category['active'] ? 'Aktif' : 'Pasif'?></span></td><td class="category-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?=(int)$category['id']?>"><button class="<?=$category['active'] ? 'cash-deactivate' : 'cash-activate'?>" title="<?=$category['active'] ? 'Pasifleştir' : 'Aktifleştir'?>"><i class="ti <?=$category['active'] ? 'tabler-home-x' : 'tabler-check'?>"></i></button></form>
      <form method="post" onsubmit="return confirm('Bu kategori silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?=(int)$category['id']?>"><button class="cash-delete" title="Sil"><i class="ti tabler-trash"></i></button></form>
    </td></tr><?php endforeach ?></tbody></table></div></section>
  <?php else: ?>
    <details class="cash-card cash-accordion" <?=$error && ($_POST['action'] ?? '') === 'save_closing' ? 'open' : ''?>>
      <summary><span><h2>Yeni Günlük Kapanış</h2><p>Fiili kasa tutarını girerek açık veya fazla durumunu kontrol edin.</p></span><i></i></summary>
      <form class="cash-form closing-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_closing"><label>Kapanış Tarihi<input type="date" name="closing_date" value="<?=date('Y-m-d')?>" required></label><label>Sayılan Kasa<input type="number" name="counted_balance" step="0.01" required></label><label class="wide">Not<input name="note" maxlength="255"></label><div class="cash-actions"><button>Kaydet</button></div></form>
    </details>
    <section class="cash-card"><header><div><h2>Günlük Kapanış Raporu</h2><p><?=count($closings)?> kayıt</p></div></header><div class="cash-table-wrap"><table><thead><tr><th>Tarih</th><th>Beklenen Kasa</th><th>Sayılan Kasa</th><th>Fark</th><th>Durum</th><th>Not</th></tr></thead><tbody>
    <?php foreach ($closings as $closing): $difference = (float)$closing['difference']; ?><tr><td><?=format_date_tr($closing['closing_date'])?></td><td><?=cash_money((float)$closing['expected_balance'])?></td><td><?=cash_money((float)$closing['counted_balance'])?></td><td class="money <?=$difference < 0 ? 'expense' : ($difference > 0 ? 'income' : '')?>"><?=cash_money($difference)?></td><td><span class="closing-status <?=$difference === 0.0 ? 'balanced' : ($difference < 0 ? 'short' : 'over')?>"><?=$difference === 0.0 ? 'Denk' : ($difference < 0 ? 'Açık' : 'Fazla')?></span></td><td><?=e($closing['note'] ?? '')?></td></tr><?php endforeach ?>
    <?php if (!$closings): ?><tr><td colspan="6" class="empty">Henüz günlük kapanış kaydı bulunmuyor.</td></tr><?php endif ?></tbody></table></div></section>
  <?php endif ?>
</main>
<style>
.cash-page{max-width:1280px!important;margin:0 auto!important;padding:96px 32px 48px!important}.cash-page-head{margin-bottom:22px}.cash-page-head h1{margin:0 0 6px;font-size:30px}.cash-page-head p,.cash-card header p,.cash-accordion summary p{margin:0;color:var(--muted)}
.cash-notice{margin-bottom:18px;padding:13px 16px;border-radius:8px}.cash-notice.success{background:#daf5e3;color:#0d7130}.cash-notice.error{background:#ffe3e3;color:#a21d1d}
.cash-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}.cash-summary article{display:flex;flex-direction:column;gap:8px;min-height:108px;padding:20px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.cash-summary span{color:var(--muted)}.cash-summary strong{font-size:23px}.cash-summary small{color:var(--muted);line-height:1.4}.income{color:#19a94b!important}.expense{color:#e04f55!important}
.cash-tabs{display:flex;gap:8px;margin-bottom:20px;overflow-x:auto}.cash-tabs a{padding:12px 18px;border-radius:8px;background:#e8f7ed;color:#16883d;text-decoration:none;font-weight:700;white-space:nowrap}.cash-tabs a.active{background:#19a94b;color:#fff}
.cash-card{margin-bottom:24px;overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.cash-card>header,.cash-accordion>summary{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 24px;border-bottom:1px solid var(--line)}.cash-card h2{margin:0 0 5px;font-size:21px}
.cash-transaction-accordion{background:#ffe3e3;border-color:#f2c4c4}
.cash-accordion>summary{position:relative;padding-right:64px;cursor:pointer;list-style:none;user-select:none}.cash-accordion>summary::-webkit-details-marker{display:none}.cash-accordion>summary::marker{display:none;content:""}.cash-accordion>summary>i{position:absolute;right:24px;top:50%;width:10px;height:10px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-60%) rotate(-45deg);transition:transform .2s}.cash-accordion[open]>summary>i{transform:translateY(-30%) rotate(45deg)}.cash-accordion:not([open])>summary{border-bottom:0}
.cash-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px 24px;padding:24px}.cash-form label,.opening-form label{display:flex;flex-direction:column;gap:7px}.cash-form input,.cash-form select,.opening-form input{width:100%;height:43px;padding:0 12px;border:1px solid #d2d2dc;border-radius:7px;background:transparent;color:inherit;font:inherit}.cash-form .wide{grid-column:span 2}.cash-actions{grid-column:1/-1}.cash-actions button,.opening-form button{height:42px;border:0;border-radius:7px;background:#19a94b;color:#fff;font-weight:700}.category-form{grid-template-columns:minmax(0,1fr) auto}.closing-form{grid-template-columns:repeat(2,minmax(0,1fr))}
.opening-form{display:flex;align-items:end;gap:8px}.opening-form label{font-size:12px}.opening-form input{width:150px}.opening-form button{min-width:42px;padding:0 14px}
.cash-table-wrap{overflow:auto}.cash-table-wrap table{width:100%;min-width:850px;border-collapse:collapse}.cash-table-wrap th,.cash-table-wrap td{padding:14px 18px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}.cash-table-wrap th{font-size:12px}.cash-table-wrap td form{display:inline-flex;margin:0}.money{font-weight:700}.empty{text-align:center!important;padding:36px!important;color:var(--muted)}
.cash-delete,.cash-deactivate,.cash-activate{display:inline-flex;align-items:center;justify-content:center;width:40px;height:42px;min-height:42px;padding:0;border:0;border-radius:7px;color:#fff}.cash-delete,.cash-deactivate{background:#e04f55}.cash-activate{background:#19a94b}.category-actions{display:flex;align-items:center;gap:8px}.cash-status,.closing-status{display:inline-block;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:700}.cash-status.active,.closing-status.balanced{background:#e2f7e9;color:#12883c}.cash-status.passive{background:#f1f1f4;color:#777}.closing-status.short{background:#ffe3e3;color:#a21d1d}.closing-status.over{background:#fff1dc;color:#a35b00}
[data-theme=dark] .cash-summary article,[data-theme=dark] .cash-card{background:#2f3349;border-color:#454a63}[data-theme=dark] .cash-transaction-accordion{background:#ffe3e3;border-color:#f2c4c4}[data-theme=dark] .cash-form input,[data-theme=dark] .cash-form select,[data-theme=dark] .opening-form input{border-color:#5a607b;color:#fff}
@media(max-width:1000px){.cash-summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.cash-page{padding:92px 14px 30px!important}}@media(max-width:700px){.cash-summary{grid-template-columns:1fr}.cash-form,.category-form,.closing-form{grid-template-columns:1fr}.cash-form .wide{grid-column:auto}.cash-card>header{align-items:flex-start;flex-direction:column}.opening-form{width:100%}.opening-form label{flex:1}.opening-form input{width:100%}}
</style>
<?php patient_footer(); ?>
