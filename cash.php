<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
// Kasa yalnızca BB Yönetim Paneli'nden açılır. Doğrudan Vox URL'si kullanılamaz.
$cashRequestedFromBb = str_ends_with(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/bb/cash.php');
if (!$cashRequestedFromBb) redirect('bb/cash.php');
require_admin();
require __DIR__ . '/cash-bootstrap.php';
require __DIR__ . '/bank-bootstrap.php';
require __DIR__ . '/patient-layout.php';

$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$cashRegister = (string)($_GET['cash_register'] ?? '') === 'pre' ? 'pre' : 'main';
$isPreCash = $cashRegister === 'pre';
$cashPage = $isPreCash ? 'cash-pre.php' : 'cash.php';

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
        source_url TEXT NULL,
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
        payment_type ENUM('cash','credit_card','mail_order','term') NOT NULL,
        category_id INT UNSIGNED NULL,
        source_url VARCHAR(255) NULL,
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
ensure_cash_schema($pdo);

// Ünite ziyaretlerinde girilen ödemeler, kasa ekranı açıldığında da kontrol
// edilerek kasa çıkış hareketi olarak eksiksiz görünür tutulur.
try {
    $unitVisitPayments = $pdo->query('SELECT v.id,v.unit_id,v.visit_date,v.payment_amount,u.code FROM unit_visits v INNER JOIN units u ON u.id=v.unit_id WHERE COALESCE(v.payment_amount,0)>0')->fetchAll();
    $syncDelete = $pdo->prepare('DELETE FROM cash_transactions WHERE source_url=?');
    $syncInsert = $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,installment_count,source_url,created_by,cash_register) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach ($unitVisitPayments as $visitPayment) {
        $sourceUrl = 'unit-visits.php?unit_id=' . (int)$visitPayment['unit_id'] . '&visit_id=' . (int)$visitPayment['id'];
        $syncDelete->execute([$sourceUrl]);
        $syncInsert->execute([(string)$visitPayment['visit_date'], 'Ünite ' . (string)$visitPayment['code'] . ' ziyaret ödemesi', 'expense', (float)$visitPayment['payment_amount'], 'cash', 1, $sourceUrl, null, 'main']);
    }
} catch (Throwable $e) {
    // Ünite ziyaret altyapısı henüz kurulmamışsa kasa ekranı normal çalışmaya devam eder.
}

function cash_money(float $value): string
{
    return number_format($value, 2, ',', '.') . ' ₺';
}

function cash_parse_amount(string $value): float
{
    $value = preg_replace('/[^0-9,.-]/u', '', $value) ?? '';
    if (str_contains($value, ',')) return (float)str_replace(',', '.', str_replace('.', '', $value));
    return (float)str_replace('.', '', $value);
}

function cash_balance_until(PDO $pdo, string $date): float
{
    $opening = (float)$pdo->query('SELECT opening_balance FROM cash_settings WHERE id=1')->fetchColumn();
    $statement = $pdo->prepare("SELECT transaction_type,payment_type,amount,term_schedule FROM cash_transactions WHERE transaction_date<=?");
    $statement->execute([$date]);
    $income = 0.0;
    $expense = 0.0;
    foreach ($statement->fetchAll() as $transaction) {
        $amount = $transaction['payment_type'] === 'term' ? cash_paid_term_total($transaction['term_schedule'] ?? null) : (float)$transaction['amount'];
        if ($transaction['transaction_type'] === 'income') {
            $income += $amount;
            if ($transaction['payment_type'] === 'mail_order') $expense += $amount;
        } else $expense += $amount;
    }
    return $opening + $income - $expense;
}

function cash_paid_term_total(?string $schedule): float
{
    $items = json_decode((string)$schedule, true);
    if (!is_array($items)) return 0.0;
    $total = 0.0;
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['paid'])) continue;
        $total += cash_parse_amount((string)($item['amount'] ?? ''));
    }
    return $total;
}

$message = (string)($_SESSION['cash_flash_message'] ?? '');
unset($_SESSION['cash_flash_message']);
$error = '';
$activeTab = (string)($_GET['tab'] ?? 'transactions');
if (!in_array($activeTab, ['transactions', 'closing'], true)) $activeTab = 'transactions';
$sourceUrlFilter = trim((string)($_GET['source_url'] ?? ''));
$returnUrl = trim((string)($_POST['return_url'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_opening') {
            $openingBalance = cash_parse_amount((string)($_POST['opening_balance'] ?? '0'));
            $pdo->prepare('UPDATE cash_settings SET opening_balance=? WHERE id=1')->execute([$openingBalance]);
            $message = 'Devreden kasa güncellendi.';
            $activeTab = 'transactions';
        } elseif ($action === 'save_transaction') {
            $date = trim((string)($_POST['transaction_date'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $type = (string)($_POST['transaction_type'] ?? '');
            $amount = cash_parse_amount((string)($_POST['amount'] ?? '0'));
            $paymentType = (string)($_POST['payment_type'] ?? '');
            $installmentCount = max(1, (int)($_POST['installment_count'] ?? 1));
            $bankName = trim((string)($_POST['bank_name'] ?? ''));
            $commissionRate = (float)str_replace(',', '.', (string)($_POST['commission_rate'] ?? '0'));
            $currentAccountId = (int)($_POST['current_account_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
            $transactionRegister = $sourceUrl !== '' ? 'pre' : $cashRegister;
            $termSchedule = null;
            if ($paymentType === 'term') {
                $termSchedule = [];
                foreach ((array)($_POST['term_amount'] ?? []) as $index => $termAmount) $termSchedule[] = ['date'=>(string)(($_POST['term_date'] ?? [])[$index] ?? ''),'amount'=>(string)$termAmount,'paid'=>isset(($_POST['term_paid'] ?? [])[$index])];
                $termSchedule = json_encode($termSchedule, JSON_UNESCAPED_UNICODE);
            }
            if ($paymentType === 'term' && trim((string)($_POST['term_schedule_json'] ?? '')) !== '') $termSchedule = (string)$_POST['term_schedule_json'];
            if ($paymentType === 'term' && $installmentCount > 1 && trim((string)$termSchedule) === '') throw new RuntimeException('Vade planındaki tüm aylık ödeme alanlarını doldurun ve yeniden kaydedin.');
            $cashRecordedAmount = $paymentType === 'term' ? cash_paid_term_total($termSchedule) : $amount;
            $extraPaymentPosted = trim((string)($_POST['extra_payment_type'] ?? ''));
            $primaryValid = $date !== '' && $description !== '' && in_array($type, ['income', 'expense'], true) && $amount > 0 && in_array($paymentType, ['cash', 'credit_card', 'mail_order', 'term'], true);
            if (!$primaryValid && $extraPaymentPosted === '') throw new RuntimeException('İşlem bilgilerini eksiksiz ve geçerli olarak girin.');
            if ($primaryValid) {
                if ($paymentType === 'mail_order' && !$currentAccountId) throw new RuntimeException('Mail Order için cari hesap seçmelisiniz.');
                $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,installment_count,bank_name,commission_rate,current_account_id,term_schedule,category_id,source_url,created_by,cash_register) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$date, $description, $type, $cashRecordedAmount, $paymentType, $installmentCount, $bankName ?: null, $commissionRate ?: null, $currentAccountId ?: null, $termSchedule, $categoryId ?: null, $sourceUrl ?: null, (int)($_SESSION['user']['id'] ?? 0), $transactionRegister]);
            }
            $extraAmountRaw = trim((string)($_POST['extra_amount'] ?? ''));
            if ($extraAmountRaw !== '') {
                $extraDate = $date;
                $extraDescription = trim((string)($_POST['extra_description'] ?? ''));
                $extraAmount = cash_parse_amount($extraAmountRaw);
                $extraPaymentType = (string)($_POST['extra_payment_type'] ?? $paymentType);
                $extraInstallmentCount = max(1, (int)($_POST['extra_installment_count'] ?? 1));
                $extraBankName = trim((string)($_POST['extra_bank_name'] ?? ''));
                $extraCommissionRate = (float)str_replace(',', '.', (string)($_POST['extra_commission_rate'] ?? '0'));
                $extraCurrentAccountId = (int)($_POST['extra_current_account_id'] ?? 0);
                $extraTermSchedule = null;
                $extraScheduledAmount = $extraAmount;
                if ($extraPaymentType === 'term') {
                    $extraPlan = [];
                    $extraScheduledAmount = 0.0;
                    $extraAmount = 0.0;
                    foreach ((array)($_POST['extra_term_amount'] ?? []) as $index => $termAmount) {
                        $isPaid = isset(($_POST['extra_term_paid'] ?? [])[$index]);
                        $extraPlan[] = ['date'=>(string)(($_POST['extra_term_date'] ?? [])[$index] ?? ''),'amount'=>(string)$termAmount,'paid'=>$isPaid];
                        $termAmountValue = cash_parse_amount((string)$termAmount);
                        $extraScheduledAmount += $termAmountValue;
                        if ($isPaid) $extraAmount += $termAmountValue;
                    }
                    $extraTermSchedule = json_encode($extraPlan, JSON_UNESCAPED_UNICODE);
                }
                if ($extraDate === '' || $extraDescription === '' || $extraScheduledAmount <= 0 || !in_array($extraPaymentType, ['cash', 'credit_card', 'mail_order', 'term'], true)) {
                    throw new RuntimeException('İkinci gelir kaydının bilgilerini eksiksiz ve geçerli olarak girin.');
                }
                if ($extraPaymentType === 'mail_order' && !$extraCurrentAccountId) throw new RuntimeException('Mail Order için cari hesap seçmelisiniz.');
                $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,installment_count,bank_name,commission_rate,current_account_id,term_schedule,category_id,source_url,created_by,cash_register) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$extraDate, $extraDescription, $type, $extraAmount, $extraPaymentType, $extraInstallmentCount, $extraBankName ?: null, $extraCommissionRate ?: null, $extraCurrentAccountId ?: null, $extraTermSchedule, $categoryId ?: null, $sourceUrl ?: null, (int)($_SESSION['user']['id'] ?? 0), $transactionRegister]);
            }
            $message = 'Kasa işlemi kaydedildi.';
            $activeTab = 'transactions';
        } elseif ($action === 'update_transaction') {
            $transactionId = (int)($_POST['id'] ?? 0);
            $date = trim((string)($_POST['transaction_date'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $amount = cash_parse_amount((string)($_POST['amount'] ?? '0'));
            $paymentType = (string)($_POST['payment_type'] ?? '');
            $installmentCount = max(1, (int)($_POST['installment_count'] ?? 1));
            $bankName = trim((string)($_POST['bank_name'] ?? ''));
            $commissionRate = (float)str_replace(',', '.', (string)($_POST['commission_rate'] ?? '0'));
            $currentAccountId = (int)($_POST['current_account_id'] ?? 0);
            $termSchedule = null;
            if ($paymentType === 'term') {
                $termSchedule = [];
                foreach ((array)($_POST['term_amount'] ?? []) as $index => $termAmount) $termSchedule[] = ['date'=>(string)(($_POST['term_date'] ?? [])[$index] ?? ''),'amount'=>(string)$termAmount,'paid'=>isset(($_POST['term_paid'] ?? [])[$index])];
                $termSchedule = json_encode($termSchedule, JSON_UNESCAPED_UNICODE);
            }
            if ($paymentType === 'term' && trim((string)($_POST['term_schedule_json'] ?? '')) !== '') $termSchedule = (string)$_POST['term_schedule_json'];
            $cashRecordedAmount = $paymentType === 'term' ? cash_paid_term_total($termSchedule) : $amount;
            if (!$transactionId || $date === '' || $description === '' || $amount <= 0 || !in_array($paymentType, ['cash', 'credit_card', 'mail_order', 'term'], true)) {
                throw new RuntimeException('İşlem bilgilerini eksiksiz ve geçerli olarak girin.');
            }
            if ($paymentType === 'mail_order' && !$currentAccountId) throw new RuntimeException('Mail Order için cari hesap seçmelisiniz.');
            $pdo->prepare("UPDATE cash_transactions SET transaction_date=?,description=?,amount=?,payment_type=?,installment_count=?,bank_name=?,commission_rate=?,current_account_id=?,term_schedule=? WHERE id=? AND transaction_type='income'")
                ->execute([$date, $description, $cashRecordedAmount, $paymentType, $installmentCount, $bankName ?: null, $commissionRate ?: null, $currentAccountId ?: null, $termSchedule, $transactionId]);
            $message = 'Kasa işlemi güncellendi.';
            $activeTab = 'transactions';
        } elseif ($action === 'delete_transaction') {
            $delete = $isPreCash ? $pdo->prepare("DELETE FROM cash_transactions WHERE id=? AND cash_register='pre'") : $pdo->prepare('DELETE FROM cash_transactions WHERE id=?');
            $delete->execute([(int)($_POST['id'] ?? 0)]);
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
            $counted = cash_parse_amount((string)($_POST['counted_balance'] ?? '0'));
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
    if ((string)($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $error === '', 'message' => $error ?: $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($error === '' && $message !== '') {
        $_SESSION['cash_flash_message'] = $message;
        if ($returnUrl !== '' && str_starts_with($returnUrl, url('patient-followup.php'))) {
            header('Location: ' . $returnUrl);
            exit;
        }
        redirect($cashPage . '?tab=' . urlencode($activeTab));
    }
}

$openingBalance = (float)$pdo->query('SELECT opening_balance FROM cash_settings WHERE id=1')->fetchColumn();
$totals = ['income' => 0.0, 'expense' => 0.0, 'cash_total' => 0.0, 'card_total' => 0.0];
// Ön Kasa kendi hareketlerine ek olarak hasta/hizmet kartından oluşan kasa hareketlerini görür.
$preCashPatientScope = "(cash_register='pre' OR source_url LIKE '%patient-followup.php?id=%')";
foreach (($isPreCash ? $pdo->query("SELECT transaction_type,payment_type,amount,term_schedule FROM cash_transactions WHERE $preCashPatientScope") : $pdo->query('SELECT transaction_type,payment_type,amount,term_schedule FROM cash_transactions'))->fetchAll() as $transaction) {
    $amount = $transaction['payment_type'] === 'term' ? cash_paid_term_total($transaction['term_schedule'] ?? null) : (float)$transaction['amount'];
    $direction = $transaction['transaction_type'] === 'income' ? 1 : -1;
    if ($direction > 0) {
        $totals['income'] += $amount;
        if ($transaction['payment_type'] === 'mail_order') $totals['expense'] += $amount;
    } else $totals['expense'] += $amount;
    if ($transaction['payment_type'] === 'cash') $totals['cash_total'] += $direction * $amount;
    if ($transaction['payment_type'] === 'credit_card') $totals['card_total'] += $direction * $amount;
}
$income = (float)$totals['income'];
$expense = (float)$totals['expense'];
$netBalance = $openingBalance + $income - $expense;

$categories = $pdo->query('SELECT c.*,p.name parent_name FROM cash_categories c LEFT JOIN cash_categories p ON p.id=c.parent_id ORDER BY active DESC,COALESCE(c.parent_id,c.id),c.parent_id IS NOT NULL,c.name')->fetchAll();
$activeCategories = array_values(array_filter($categories, static fn(array $category): bool => (bool)$category['active']));
if ($sourceUrlFilter !== '') {
    $transactionStatement = $pdo->prepare("SELECT t.*,c.name category_name,a.code current_account_code,a.short_name current_account_short_name,a.title current_account_title FROM cash_transactions t LEFT JOIN cash_categories c ON c.id=t.category_id LEFT JOIN current_accounts a ON a.id=t.current_account_id WHERE t.source_url=? AND " . ($isPreCash ? "(t.cash_register='pre' OR t.source_url LIKE '%patient-followup.php?id=%') AND " : '') . "NOT (t.payment_type='term' AND t.amount<=0) ORDER BY t.transaction_date DESC,t.id DESC LIMIT 500");
    $transactionStatement->execute([$sourceUrlFilter]);
    $transactions = $transactionStatement->fetchAll();
} else {
    $transactions = $pdo->query("SELECT t.*,c.name category_name,a.code current_account_code,a.short_name current_account_short_name,a.title current_account_title FROM cash_transactions t LEFT JOIN cash_categories c ON c.id=t.category_id LEFT JOIN current_accounts a ON a.id=t.current_account_id WHERE " . ($isPreCash ? "(t.cash_register='pre' OR t.source_url LIKE '%patient-followup.php?id=%') AND " : '') . "NOT (t.payment_type='term' AND t.amount<=0) ORDER BY t.transaction_date DESC,t.id DESC LIMIT 500")->fetchAll();
}
$transactions = array_values(array_filter(array_map(static function (array $transaction): array {
    if ($transaction['payment_type'] === 'term') $transaction['amount'] = cash_paid_term_total($transaction['term_schedule'] ?? null);
    return $transaction;
}, $transactions), static fn(array $transaction): bool => $transaction['payment_type'] !== 'term' || (float)$transaction['amount'] > 0));
foreach ($transactions as &$transaction) {
    $transaction['invoice_no'] = '';
    $transaction['related_person'] = '';
    $transaction['installment_tooltip'] = '';
    if ($transaction['payment_type'] === 'term') {
        $plan = json_decode((string)($transaction['term_schedule'] ?? ''), true);
        if (is_array($plan)) {
            $paidInstallments = [];
            foreach ($plan as $index => $installment) if (is_array($installment) && !empty($installment['paid'])) $paidInstallments[] = ($index + 1) . '. Vade';
            $transaction['installment_tooltip'] = implode(', ', $paidInstallments);
        }
    }
    $sourceQuery = [];
    parse_str((string)parse_url((string)($transaction['source_url'] ?? ''), PHP_URL_QUERY), $sourceQuery);
    $patientId = (int)($sourceQuery['id'] ?? 0);
    $serviceId = (int)($sourceQuery['service_id'] ?? 0);
    $unitId = (int)($sourceQuery['unit_id'] ?? 0);
    if (!$patientId && !$serviceId && !$unitId) continue;
    try {
        if ($unitId) {
            $unitStatement = $pdo->prepare('SELECT code FROM units WHERE id=? LIMIT 1');
            $unitStatement->execute([$unitId]);
            $unitCode = trim((string)$unitStatement->fetchColumn());
            $transaction['invoice_no'] = $unitCode;
            $transaction['related_person'] = $unitCode !== '' ? 'Ünite ' . $unitCode : 'Ünite';
        } elseif ($serviceId) {
            $invoiceStatement = $pdo->prepare('SELECT sales_details,contact_person FROM patient_services WHERE id=? LIMIT 1');
            $invoiceStatement->execute([$serviceId]);
            $salesService = $invoiceStatement->fetch();
            $salesDetails = json_decode((string)($salesService['sales_details'] ?? ''), true);
            if (is_array($salesDetails)) $transaction['invoice_no'] = trim((string)($salesDetails['sales_invoice_no'] ?? ''));
            $transaction['related_person'] = trim((string)($salesService['contact_person'] ?? ''));
        } else {
        $invoiceStatement = $pdo->prepare("SELECT sales_details,contact_person FROM patient_services WHERE patient_id=? AND service_name='Satış' ORDER BY id DESC LIMIT 1");
        $invoiceStatement->execute([$patientId]);
        $salesService = $invoiceStatement->fetch();
        $salesDetails = json_decode((string)($salesService['sales_details'] ?? ''), true);
        if (is_array($salesDetails)) $transaction['invoice_no'] = trim((string)($salesDetails['sales_invoice_no'] ?? ''));
        $transaction['related_person'] = trim((string)($salesService['contact_person'] ?? ''));
        }
    } catch (Throwable $e) {}
}
unset($transaction);
$closings = $pdo->query('SELECT * FROM cash_closings ORDER BY closing_date DESC,id DESC LIMIT 365')->fetchAll();

patient_header($isPreCash ? 'Ön Kasa' : 'Kasa', 'cash');
?>
<main class="patient-container cash-page">
  <div class="cash-page-head"><div><h1><?=$isPreCash ? 'Ön Kasa' : 'Kasa'?></h1><p>Gelir, gider, bakiye ve günlük kapanış işlemlerini yönetin.</p></div></div>
  <?php if ($message): ?><div class="cash-notice success"><?=e($message)?></div><?php endif ?>
  <?php if ($error): ?><div class="cash-notice error"><?=e($error)?></div><?php endif ?>

  <section class="cash-summary">
    <article><span>Devreden Kasa</span><strong><?=cash_money($openingBalance)?></strong></article>
    <article><span>Toplam Gelir</span><strong class="income"><?=cash_money($income)?></strong></article>
    <article><span>Toplam Gider</span><strong class="expense"><?=cash_money($expense)?></strong></article>
    <article><span>Net Kasa Bakiyesi</span><strong><?=cash_money($netBalance)?></strong><small>Nakit <?=cash_money((float)$totals['cash_total'])?> · Kart <?=cash_money((float)$totals['card_total'])?></small></article>
  </section>

  <nav class="cash-tabs">
    <a class="<?=$activeTab === 'transactions' ? 'active' : ''?>" href="<?=url($cashPage . '?tab=transactions')?>">Gelir / Gider</a>
    <a class="<?=$activeTab === 'closing' ? 'active' : ''?>" href="<?=url($cashPage . '?tab=closing')?>">Günlük Kapanış</a>
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
        <label>Ödeme Türü<select name="payment_type" required><option value="cash">Nakit</option><option value="credit_card">Kredi Kartı</option><option value="mail_order">Mail Order</option><option value="term">Vadeli</option></select></label>
        <label>Kategori<select name="category_id"><option value="">Kategorisiz</option><?php foreach ($activeCategories as $category): ?><option value="<?=(int)$category['id']?>"><?=e(($category['parent_name'] ? $category['parent_name'] . ' / ' : '') . $category['name'])?></option><?php endforeach ?></select></label>
        <div class="cash-actions"><button>Kaydet</button></div>
      </form>
    </details>
    <section class="cash-card">
      <header><div><h2>Kasa Hareketleri</h2><p><?=count($transactions)?> kayıt<?=$sourceUrlFilter !== '' ? ' · Bu hizmet kartına ait hareketler' : ''?></p></div>
        <form class="opening-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_opening"><label>Devreden Kasa<input type="number" step="0.01" name="opening_balance" value="<?=number_format($openingBalance,2,'.','')?>"></label><button>Kaydet</button></form>
      </header>
      <div class="cash-table-wrap"><table><thead><tr><th>Tarih</th><th>Kasa</th><th>Fatura No</th><th>İlgili</th><th>Ödeme</th><th>Giren</th><th>Çıkan</th></tr></thead><tbody>
      <?php foreach ($transactions as $transaction): ?><tr class="<?=!empty($transaction['source_url']) ? 'cash-source-row' : ''?>" data-source-url="<?=e((string)($transaction['source_url'] ?? ''))?>">
        <td><?=format_date_tr($transaction['transaction_date'])?></td><td><?=($transaction['cash_register'] ?? 'main') === 'pre' ? 'Ön Kasa' : 'Kasa'?></td><td><span title="<?=e($transaction['description'])?>"><?=e($transaction['invoice_no'] ?: '—')?></span></td><td><?=e($transaction['related_person'] ?: '—')?></td><td><?=e(['cash'=>'Nakit','credit_card'=>'Kredi Kartı','mail_order'=>'Mail Order','term'=>'Vadeli'][$transaction['payment_type']] ?? '—')?></td>
        <td class="money income"><?=$transaction['transaction_type'] === 'income' ? (!empty($transaction['installment_tooltip']) ? '<span title="'.e($transaction['installment_tooltip']).'">'.cash_money((float)$transaction['amount']).'</span>' : cash_money((float)$transaction['amount'])) : '—'?></td>
        <td class="money expense"><?php if ($transaction['transaction_type'] === 'expense' || ($transaction['transaction_type'] === 'income' && $transaction['payment_type'] === 'mail_order')): ?><?php if ($transaction['payment_type'] === 'mail_order' && !empty($transaction['current_account_code'])): ?><span title="<?=e($transaction['current_account_code'] . ' — ' . ($transaction['current_account_short_name'] ?: $transaction['current_account_title']))?>"><?=cash_money((float)$transaction['amount'])?></span><?php else: ?><?=cash_money((float)$transaction['amount'])?><?php endif ?><?php else: ?>—<?php endif ?></td>
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
<script>
document.querySelectorAll('.cash-source-row').forEach(row=>row.addEventListener('dblclick',event=>{if(event.target.closest('button,form,a,input,select,textarea'))return;const source=row.dataset.sourceUrl;if(!source)return;const target=new URL(source,window.location.origin);target.searchParams.set('open_income_record','1');window.location.href=target.toString();}));
</script>
<style>
.cash-page{max-width:1280px!important;margin:0 auto!important;padding:96px 32px 48px!important}.cash-page-head{margin-bottom:22px}.cash-page-head h1{margin:0 0 6px;font-size:30px}.cash-page-head p,.cash-card header p,.cash-accordion summary p{margin:0;color:var(--muted)}
.cash-source-row{cursor:pointer}.cash-source-row:hover{background:rgba(25,169,75,.06)}
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
