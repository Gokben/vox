<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';
$pdo=db();$sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
$pdo->prepare('UPDATE stock_cards SET stock_type = ? WHERE stock_type = ?')->execute(['İşitme Cihazı', 'Kulaklık']);
$pdo->exec($sqlite?'CREATE TABLE IF NOT EXISTS current_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, title TEXT NOT NULL, account_type TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)':'CREATE TABLE IF NOT EXISTS current_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, title VARCHAR(190) NOT NULL, account_type ENUM("customer","supplier","both") NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
function stock_entry_current_account_column(PDO $pdo, bool $sqlite, string $column): bool { if ($sqlite) { foreach ($pdo->query('PRAGMA table_info(current_accounts)')->fetchAll() as $item) if ($item['name'] === $column) return true; return false; } $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="current_accounts" AND column_name=?'); $statement->execute([$column]); return (bool)$statement->fetchColumn(); }
if (!stock_entry_current_account_column($pdo, $sqlite, 'short_name')) $pdo->exec('ALTER TABLE current_accounts ADD COLUMN short_name VARCHAR(190) NULL');
$pdo->exec($sqlite?'CREATE TABLE IF NOT EXISTS stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, stock_id INTEGER NOT NULL, movement_type TEXT NOT NULL, quantity INTEGER NOT NULL, movement_date TEXT NOT NULL, description TEXT, current_account_id INTEGER NULL, invoice_no TEXT NULL, serial_numbers TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)':'CREATE TABLE IF NOT EXISTS stock_movements (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, stock_id INT UNSIGNED NOT NULL, movement_type VARCHAR(30) NOT NULL, quantity INT NOT NULL, movement_date DATE NOT NULL, description TEXT NULL, current_account_id INT UNSIGNED NULL, invoice_no VARCHAR(100) NULL, serial_numbers TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX stock_movements_stock_id (stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
function stock_entry_column(PDO $pdo,string $column):bool{$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);if($driver==='sqlite'){foreach($pdo->query('PRAGMA table_info(stock_movements)')->fetchAll() as $item)if($item['name']===$column)return true;return false;}$q=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="stock_movements" AND column_name=?');$q->execute([$column]);return(bool)$q->fetchColumn();}
function stock_entry_parse_amount(string $value): float { $value=trim(str_replace(' ','',$value)); if(str_contains($value,',')) return (float)str_replace(',', '.', str_replace('.', '', $value)); return (float)str_replace('.', '', $value); }
foreach(['current_account_id'=>'INTEGER NULL','invoice_no'=>'VARCHAR(100) NULL','serial_numbers'=>'TEXT NULL','uts_lot_no'=>'VARCHAR(190) NULL','warranty_start'=>'DATE NULL','warranty_end'=>'DATE NULL','purchase_price'=>'DECIMAL(12,2) NULL','sale_price'=>'DECIMAL(12,2) NULL','vat_rate'=>'DECIMAL(5,2) NULL','unit_cost'=>'DECIMAL(12,2) NULL','unit'=>'VARCHAR(20) NULL'] as $column=>$definition)if(!stock_entry_column($pdo,$column))$pdo->exec('ALTER TABLE stock_movements ADD COLUMN '.$column.' '.$definition);
$vatNormalization = 'CASE WHEN COALESCE(vat_rate,0)>=15 THEN 20 WHEN COALESCE(vat_rate,0)>=5 THEN 10 ELSE 0 END';
$pdo->exec('UPDATE stock_movements SET vat_rate='.$vatNormalization.' WHERE vat_rate IS NULL OR vat_rate NOT IN (0,10,20)');
$pdo->exec('UPDATE stock_cards SET vat_rate='.$vatNormalization.' WHERE vat_rate IS NULL OR vat_rate NOT IN (0,10,20)');
$pdo->exec($sqlite?'CREATE TABLE IF NOT EXISTS stock_price_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)':'CREATE TABLE IF NOT EXISTS stock_price_lists (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite?'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INTEGER NOT NULL, stock_id INTEGER NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id))':'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INT UNSIGNED NOT NULL, stock_id INT UNSIGNED NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$stocks=$pdo->query('SELECT id,stock_code,stock_name,brand,serial_no,COALESCE(stock_type,"") AS stock_type,purchase_price,sale_price,vat_rate,unit_cost FROM stock_cards ORDER BY brand,stock_name,stock_code')->fetchAll();$brands=$pdo->query('SELECT DISTINCT brand FROM stock_cards WHERE brand IS NOT NULL AND brand != "" ORDER BY brand')->fetchAll(PDO::FETCH_COLUMN);$accounts=$pdo->query("SELECT id,code,title,short_name FROM current_accounts WHERE account_type IN ('supplier','both') ORDER BY title")->fetchAll();$form=['stock_type'=>'','brand'=>'','stock_id'=>'','current_account_id'=>'','invoice_no'=>'','quantity'=>'','unit'=>'Adet','movement_date'=>date('Y-m-d'),'purchase_price'=>'','sale_price'=>'','vat_rate'=>'20','unit_cost'=>'','uts_lot_no'=>'','warranty_start'=>'','warranty_end'=>'','description'=>''];$serials=[];$error='';$editId=filter_input(INPUT_GET,'edit',FILTER_VALIDATE_INT)?:0;$editRecord=null;$editInvoiceCorrectionAllowed=false;
$priceListItems=$pdo->query('SELECT i.stock_id,i.list_price,l.valid_from,l.valid_until,l.id AS price_list_id FROM stock_price_list_items i INNER JOIN stock_price_lists l ON l.id=i.price_list_id ORDER BY l.valid_from DESC,l.id DESC')->fetchAll();
$form['vat_rate'] = '0';
if (isset($_GET['account_search'])) {
    $search = trim((string)$_GET['account_search']);
    header('Content-Type: application/json; charset=utf-8');
    if ((function_exists('mb_strlen') ? mb_strlen($search) : strlen($search)) < 3) { echo '[]'; exit; }
    $accountSearch = $pdo->prepare("SELECT id,title,short_name FROM current_accounts WHERE account_type IN ('supplier','both') AND COALESCE(short_name,'') LIKE ? ORDER BY short_name LIMIT 8");
    $like = '%' . $search . '%';
    $accountSearch->execute([$like]);
    echo json_encode($accountSearch->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete'){verify_csrf();$deleteId=filter_var($_POST['movement_id']??null,FILTER_VALIDATE_INT);if($deleteId)$pdo->prepare('DELETE FROM stock_movements WHERE id=? AND movement_type="Giriş"')->execute([$deleteId]);header('Location: '.url('stock-entry.php?deleted=1'));exit;}
if($editId){$editStatement=$pdo->prepare('SELECT m.*,s.stock_type,s.brand FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE m.id=? AND m.movement_type="Giriş"');$editStatement->execute([$editId]);$editRecord=$editStatement->fetch();if(!$editRecord){http_response_code(404);exit('Stok girişi bulunamadı.');}foreach($form as $field=>$value)$form[$field]=(string)($editRecord[$field]??$value);$serials=json_decode((string)$editRecord['serial_numbers'],true)?:[];$originalInvoiceNo=trim((string)$editRecord['invoice_no']);if($originalInvoiceNo!==''){$sameDayInvoiceStatement=$pdo->prepare('SELECT COUNT(*) AS total_rows,SUM(CASE WHEN DATE(created_at)=? THEN 1 ELSE 0 END) AS today_rows FROM stock_movements WHERE movement_type="Giriş" AND LOWER(TRIM(invoice_no))=LOWER(TRIM(?))');$sameDayInvoiceStatement->execute([date('Y-m-d'),$originalInvoiceNo]);$sameDayInvoice=$sameDayInvoiceStatement->fetch()?:[];$editInvoiceCorrectionAllowed=(int)($sameDayInvoice['total_rows']??0)>0&&(int)($sameDayInvoice['total_rows']??0)===(int)($sameDayInvoice['today_rows']??0);}}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($form as $field => $value) $form[$field] = trim((string)($_POST[$field] ?? $value));
    foreach (['purchase_price', 'sale_price', 'unit_cost'] as $field) $form[$field] = stock_entry_parse_amount($form[$field]);
    $vatRateInput = $form['vat_rate'];
    $form['vat_rate'] = in_array($vatRateInput, ['0', '10', '20'], true) ? (int)$vatRateInput : -1;
    $stockId = filter_var($form['stock_id'], FILTER_VALIDATE_INT);
    if ($stockId) {
        $stockVatStatement = $pdo->prepare('SELECT vat_rate FROM stock_cards WHERE id=?');
        $stockVatStatement->execute([$stockId]);
        $stockVatRate = $stockVatStatement->fetchColumn();
        if ($stockVatRate !== false) $form['vat_rate'] = (int)(float)$stockVatRate;
    }
    $accountId = filter_var($form['current_account_id'], FILTER_VALIDATE_INT);
    $quantity = filter_var($form['quantity'], FILTER_VALIDATE_INT);
    if (!in_array($form['unit'], ['Adet', 'Kutu', 'Kg'], true)) $form['unit'] = '';
    $serials = array_values(array_filter(array_map('trim', (array)($_POST['serial_numbers'] ?? [])), static fn($serial) => $serial !== ''));

    if (!in_array($form['vat_rate'], [0, 10, 20], true)) {
        $error = 'KDV oranı yalnız %0, %10 veya %20 olabilir.';
    } elseif (!in_array($form['stock_type'], ['İşitme Cihazı', 'Sarf Malzeme', 'Pil', 'Şarj Cihazı'], true) || ($form['stock_type'] !== 'Sarf Malzeme' && $form['brand'] === '') || !$stockId || !$accountId || !$quantity || $quantity < 1 || $form['unit'] === '') {
        $error = 'Stok tipi, stok, cari, giriş miktarı ve birim zorunludur. Marka, sarf malzemede boş bırakılabilir.';
    } elseif (count($serials) !== count(array_unique($serials))) {
        $error = 'Seri numaraları birbirinden farklı olmalıdır.';
    } else {
        $invoiceNo = trim($form['invoice_no']);
        if ($invoiceNo !== '') {
            $invoiceDateStatement = $pdo->prepare('SELECT movement_date FROM stock_movements WHERE movement_type=? AND LOWER(TRIM(invoice_no))=LOWER(TRIM(?)) AND id<>? ORDER BY id ASC LIMIT 1');
            $invoiceDateStatement->execute(['Giriş', $invoiceNo, $editId]);
            $existingInvoiceDate = $invoiceDateStatement->fetchColumn();
            $editingSameInvoice = $editRecord !== null && strtolower(trim((string)$editRecord['invoice_no'])) === strtolower($invoiceNo);
            if ($existingInvoiceDate !== false && $form['movement_date'] !== $existingInvoiceDate && !($editInvoiceCorrectionAllowed && $editingSameInvoice)) {
                $error = 'Bu fatura numarası için giriş tarihi ' . format_date_tr((string)$existingInvoiceDate) . ' olmalıdır.';
            }
        }
        if ($error === '') {
        $check = $form['stock_type'] === 'Sarf Malzeme' && $form['brand'] === ''
            ? $pdo->prepare('SELECT 1 FROM stock_cards WHERE id=? AND stock_type=? AND (brand IS NULL OR TRIM(brand)="")')
            : $pdo->prepare('SELECT 1 FROM stock_cards WHERE id=? AND stock_type=? AND brand=?');
        $check->execute($form['stock_type'] === 'Sarf Malzeme' && $form['brand'] === '' ? [$stockId, $form['stock_type']] : [$stockId, $form['stock_type'], $form['brand']]);
        $accountCheck = $pdo->prepare('SELECT 1 FROM current_accounts WHERE id=?');
        $accountCheck->execute([$accountId]);
        if (!$check->fetchColumn() || !$accountCheck->fetchColumn()) {
            $error = 'Seçilen stok tipi ve marka bilgisine uygun stok ile cari seçin.';
        } else {
            if ($form['stock_type'] === 'İşitme Cihazı') {
                $activeListPrice = $pdo->prepare('SELECT i.list_price FROM stock_price_list_items i INNER JOIN stock_price_lists l ON l.id=i.price_list_id WHERE i.stock_id=? AND l.valid_from<=? AND l.valid_until>=? ORDER BY l.valid_from DESC,l.id DESC LIMIT 1');
                $activeListPrice->execute([$stockId, $form['movement_date'], $form['movement_date']]);
                $listPrice = $activeListPrice->fetchColumn();
                if ($listPrice === false) {
                    $error = 'Bu ürün için giriş tarihinde geçerli liste fiyatı bulunamadı. Önce fiyat listesini güncelleyin.';
                } else {
                    $form['sale_price'] = (float)$listPrice;
                }
            }
            if ($error === '') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE stock_cards SET purchase_price=?,sale_price=?,vat_rate=?,unit_cost=? WHERE id=?')->execute([$form['purchase_price'], $form['sale_price'], $form['vat_rate'], $form['unit_cost'], $stockId]);
                if ($editId) {
                    $pdo->prepare('UPDATE stock_movements SET stock_id=?,quantity=?,unit=?,movement_date=?,description=?,current_account_id=?,invoice_no=?,serial_numbers=?,uts_lot_no=?,warranty_start=?,warranty_end=?,purchase_price=?,sale_price=?,vat_rate=?,unit_cost=? WHERE id=? AND movement_type="Giriş"')->execute([$stockId, $quantity, $form['unit'], $form['movement_date'], $form['description'], $accountId, $form['invoice_no'], json_encode($serials, JSON_UNESCAPED_UNICODE), $form['uts_lot_no'], $form['warranty_start'] ?: null, $form['warranty_end'] ?: null, $form['purchase_price'], $form['sale_price'], $form['vat_rate'], $form['unit_cost'], $editId]);
                    if ($editInvoiceCorrectionAllowed && trim((string)$editRecord['invoice_no']) !== '') {
                        $pdo->prepare('UPDATE stock_movements SET movement_date=?,invoice_no=? WHERE movement_type="Giriş" AND LOWER(TRIM(invoice_no))=LOWER(TRIM(?))')->execute([$form['movement_date'], $invoiceNo, trim((string)$editRecord['invoice_no'])]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO stock_movements(stock_id,movement_type,quantity,unit,movement_date,description,current_account_id,invoice_no,serial_numbers,uts_lot_no,warranty_start,warranty_end,purchase_price,sale_price,vat_rate,unit_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$stockId, 'Giriş', $quantity, $form['unit'], $form['movement_date'], $form['description'], $accountId, $form['invoice_no'], json_encode($serials, JSON_UNESCAPED_UNICODE), $form['uts_lot_no'], $form['warranty_start'] ?: null, $form['warranty_end'] ?: null, $form['purchase_price'], $form['sale_price'], $form['vat_rate'], $form['unit_cost']]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            header('Location: ' . url('stock-entry.php?saved=1'));
            exit;
            }
        }
        }
    }
}
$accountShortNames = $pdo->query('SELECT id,short_name,title FROM current_accounts')->fetchAll();
$lastSavedEntry = $pdo->query('SELECT m.current_account_id,m.invoice_no,m.movement_date,s.stock_type,s.brand FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE m.movement_type="Giriş" ORDER BY m.movement_date DESC,m.id DESC LIMIT 1')->fetch() ?: null;
$invoiceDateRows = $pdo->prepare('SELECT LOWER(TRIM(invoice_no)) AS invoice_key, MIN(movement_date) AS movement_date FROM stock_movements WHERE movement_type=? AND TRIM(COALESCE(invoice_no, ""))<>"" GROUP BY LOWER(TRIM(invoice_no))');
$invoiceDateRows->execute(['Giriş']);
$invoiceDatesByNumber = array_column($invoiceDateRows->fetchAll(), 'movement_date', 'invoice_key');
if ($editInvoiceCorrectionAllowed && $editRecord !== null) unset($invoiceDatesByNumber[strtolower(trim((string)$editRecord['invoice_no']))]);
if (isset($_GET['copy_last']) && $lastSavedEntry) {
    $form['stock_type'] = (string)$lastSavedEntry['stock_type'];
    $form['brand'] = (string)$lastSavedEntry['brand'];
    $form['current_account_id'] = (string)$lastSavedEntry['current_account_id'];
    $form['invoice_no'] = (string)$lastSavedEntry['invoice_no'];
    $form['movement_date'] = (string)$lastSavedEntry['movement_date'];
}
if (!isset($_GET['new'])) {
    $dateSort = (($_GET['date_sort'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
    $filterAccountId = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT) ?: 0;
    $filterText = trim((string)($_GET['q'] ?? ''));
    $filterDateStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_start'] ?? '')) ? (string)$_GET['date_start'] : '';
    $filterDateEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_end'] ?? '')) ? (string)$_GET['date_end'] : '';
    $filterWhere = ['m.movement_type=?'];
    $filterParams = ['Giriş'];
    if ($filterAccountId > 0) { $filterWhere[] = 'm.current_account_id=?'; $filterParams[] = $filterAccountId; }
    if ($filterDateStart !== '') { $filterWhere[] = 'm.movement_date>=?'; $filterParams[] = $filterDateStart; }
    if ($filterDateEnd !== '') { $filterWhere[] = 'm.movement_date<=?'; $filterParams[] = $filterDateEnd; }
    if ($filterText !== '') {
        $filterWhere[] = "(s.stock_code LIKE ? OR s.stock_name LIKE ? OR COALESCE(c.title,'') LIKE ? OR COALESCE(c.short_name,'') LIKE ? OR COALESCE(m.invoice_no,'') LIKE ? OR m.movement_date LIKE ?)";
        $like = '%' . $filterText . '%';
        $dateLike = $like;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $filterText, $dateParts)
            && checkdate((int)$dateParts[2], (int)$dateParts[1], (int)$dateParts[3])) {
            $dateLike = '%' . $dateParts[3] . '-' . $dateParts[2] . '-' . $dateParts[1] . '%';
        }
        array_push($filterParams, $like, $like, $like, $like, $like, $dateLike);
    }
    $filterSql = implode(' AND ', $filterWhere);
    $movementsStatement = $pdo->prepare('SELECT m.*,s.stock_code,s.stock_name,s.stock_type,c.title AS account_title FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id LEFT JOIN current_accounts c ON c.id=m.current_account_id WHERE ' . $filterSql . ' ORDER BY m.movement_date ' . strtoupper($dateSort) . ',m.id ' . strtoupper($dateSort));
    $movementsStatement->execute($filterParams);
    $movements = $movementsStatement->fetchAll();
    $summaryStatement = $pdo->prepare('SELECT COUNT(DISTINCT CASE WHEN TRIM(COALESCE(m.invoice_no,""))<>"" THEN LOWER(TRIM(m.invoice_no)) END) AS invoice_count,COALESCE(SUM(COALESCE(m.purchase_price,0)),0) AS amount_excluding_vat,COALESCE(SUM(COALESCE(m.purchase_price,0)*(1+COALESCE(m.vat_rate,0)/100.0)),0) AS amount_including_vat FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id LEFT JOIN current_accounts c ON c.id=m.current_account_id WHERE ' . $filterSql);
    $summaryStatement->execute($filterParams);
    $entrySummary = $summaryStatement->fetch() ?: ['invoice_count' => 0, 'amount_excluding_vat' => 0, 'amount_including_vat' => 0];
    $selectedAccountShortName = '';
    if ($filterAccountId > 0) {
        $selectedAccountStatement = $pdo->prepare('SELECT short_name FROM current_accounts WHERE id=? LIMIT 1');
        $selectedAccountStatement->execute([$filterAccountId]);
        $selectedAccountShortName = (string)($selectedAccountStatement->fetchColumn() ?: '');
    }
    $filterQuery = http_build_query(['q' => $filterText ?: null, 'account_id' => $filterAccountId ?: null, 'date_start' => $filterDateStart ?: null, 'date_end' => $filterDateEnd ?: null]);
    patient_header('Stok Girişi','stock'); ?>
<main class="patient-container stock-entry-list-page">
  <section class="technical-card">
    <header><h1><i class="ti tabler-package-import"></i> Stok Girişi</h1><p>Kaydedilen stok giriş hareketleri.</p><a class="button bulk-stock-entry" href="<?=e(url('bulk-battery-entry.php'))?>">Toplu Pil Girişi</a><a class="button new-stock-entry" href="<?=e(url('stock-entry.php?new=1'))?>">+ Yeni Stok Girişi</a></header>
    <form class="stock-movement-filter" method="get" autocomplete="off">
      <div class="stock-filter-text"><i class="ti tabler-search"></i><input name="q" type="text" value="<?=e($filterText)?>" placeholder="Stok, cari, fatura no veya tarih ara"></div>
      <div class="stock-filter-company"><span>Firma</span><div class="stock-filter-control"><input type="hidden" id="stock-filter-account-id" name="account_id" value="<?=$filterAccountId?>"><input id="stock-filter-account" type="text" value="<?=e($selectedAccountShortName)?>" placeholder=""><button type="button" class="stock-filter-clear" data-clear="stock-filter-account" title="Firmayı temizle" aria-label="Firmayı temizle">×</button><div class="stock-filter-results" id="stock-filter-results"></div></div></div>
      <div class="stock-filter-dates"><label>Başlangıç Tarihi<div class="stock-filter-control"><input type="date" name="date_start" value="<?=e($filterDateStart)?>"><button type="button" class="stock-filter-clear" data-clear="date_start" title="Tarihi temizle" aria-label="Başlangıç tarihini temizle">×</button></div></label><label>Bitiş Tarihi<div class="stock-filter-control"><input type="date" name="date_end" value="<?=e($filterDateEnd)?>"><button type="button" class="stock-filter-clear" data-clear="date_end" title="Tarihi temizle" aria-label="Bitiş tarihini temizle">×</button></div></label><button type="submit" class="stock-filter-submit" title="Ara" aria-label="Ara"><i class="ti tabler-search"></i></button></div>
    </form>
    <div class="technical-table-wrap"><table><thead><tr><th><a class="date-sort" href="<?=e(url('stock-entry.php?date_sort='.($dateSort==='asc'?'desc':'asc').($filterQuery!==''?'&amp;'.$filterQuery:'')))?>">TARİH <i class="ti <?=e($dateSort==='asc'?'tabler-sort-ascending':'tabler-sort-descending')?>"></i></a></th><th>STOK TİPİ</th><th>STOK KARTI</th><th>CARİ</th><th>FATURA NO</th><th>MİKTAR</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach($movements as $movement):?><tr><td><?=e(format_date_tr($movement['movement_date']))?></td><td><?=e($movement['stock_type']??'—')?></td><td><?=e($movement['stock_code'].' — '.$movement['stock_name'])?></td><td><?=e($movement['account_title']?:'—')?></td><td><?=e($movement['invoice_no']?:'—')?></td><td><?=e((string)$movement['quantity'])?></td><td><div class="entry-actions"><a href="<?=e(url('stock-entry.php?new=1&edit='.(int)$movement['id']))?>" title="Düzenle"><i class="ti tabler-pencil"></i></a><form method="post" onsubmit="return confirm('Bu stok girişi silinsin mi?');"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="movement_id" value="<?=$movement['id']?>"><button title="Sil"><i class="ti tabler-trash"></i></button></form></div></td></tr><?php endforeach;if(!$movements):?><tr><td class="empty" colspan="7">Henüz stok girişi bulunmuyor.</td></tr><?php endif?></tbody></table></div>
    <section class="stock-entry-summary"><div><span>Toplam Fatura</span><strong><?=(int)$entrySummary['invoice_count']?></strong></div><div><span>KDV Hariç Tutar</span><strong><?=e(number_format((float)$entrySummary['amount_excluding_vat'],2,',','.'))?> TL</strong></div><div><span>KDV Dahil Tutar</span><strong><?=e(number_format((float)$entrySummary['amount_including_vat'],2,',','.'))?> TL</strong></div></section>
  </section>
</main>
<style>.stock-entry-list-page{width:100%!important;max-width:1800px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.technical-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.technical-card>header{position:relative;padding:22px 220px 22px 24px;border-bottom:1px solid #e1e2e8}.technical-card h1{margin:0 0 5px;color:#2f2b3d;font-size:21px}.technical-card p{margin:0;color:#7b7b8d}.new-stock-entry{position:absolute;right:24px;top:50%;transform:translateY(-50%);text-decoration:none}.stock-entry-search{display:flex;align-items:center;gap:9px;padding:16px 24px;border-bottom:1px solid #e1e2e8;color:#6f7180}.stock-entry-search input{width:min(440px,100%);padding:10px 12px;border:1px solid #d7d9e2;border-radius:6px;font:inherit}.technical-table-wrap{overflow:auto}.technical-card table{width:100%;min-width:1250px;border-collapse:collapse}.technical-card th,.technical-card td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.technical-card th{font-size:12px;color:#5d5b6d}.date-sort{display:inline-flex;align-items:center;gap:4px;color:inherit;text-decoration:none}.date-sort:hover{color:#19a94b}.technical-card tbody tr:hover{background:#f8fcf9}.entry-actions{display:flex;gap:8px}.entry-actions a,.entry-actions button{display:grid;place-items:center;width:40px;height:42px;padding:0;border:0;border-radius:7px;background:#19a94b;color:#fff;text-decoration:none;cursor:pointer}.entry-actions form{margin:0}.entry-actions button{background:#e04f55}.empty{text-align:center;color:#7b7b8d}@media(max-width:720px){.stock-entry-list-page{padding:92px 14px 30px!important}.technical-card>header{padding-right:24px;padding-bottom:80px}.new-stock-entry{top:auto;bottom:20px;transform:none;left:24px;right:24px;text-align:center}}</style>
<style>.stock-entry-filter{display:flex;flex-wrap:wrap;align-items:end;gap:14px;padding:18px 24px;border-bottom:1px solid #e1e2e8}.stock-entry-filter label{display:flex;flex:1 1 190px;flex-direction:column;gap:6px;font-size:13px;color:#5d5b6d}.stock-entry-filter select,.stock-entry-filter input{height:40px;padding:0 10px;border:1px solid #d7d9e2;border-radius:6px;background:#fff;font:inherit}.stock-entry-filter button{height:40px;padding:0 18px;border:0;border-radius:6px;cursor:pointer}.stock-entry-filter>a{height:40px;display:grid;place-items:center;color:#7b7b8d;text-decoration:none}.stock-entry-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:#e1e2e8;border-bottom:1px solid #e1e2e8}.stock-entry-summary>div{padding:15px 24px;background:#fff}.stock-entry-summary span{display:block;margin-bottom:5px;color:#7b7b8d;font-size:12px}.stock-entry-summary strong{color:#2f2b3d;font-size:17px}@media(max-width:720px){.stock-entry-filter{padding:16px}.stock-entry-summary{grid-template-columns:1fr}.stock-entry-summary>div{padding:14px 16px}}</style>
<script>(()=>{const accounts=<?=json_encode($accountShortNames,JSON_UNESCAPED_UNICODE)?>,byId=new Map(accounts.map(account=>[String(account.id),account]));const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,header=document.querySelector('.technical-table-wrap thead th:nth-child(3)');if(header){const serialHeader=document.createElement('th');serialHeader.textContent='SERİ NO';header.after(serialHeader)}document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index],account=byId.get(String(movement?.current_account_id||'')),stockCell=row.children[2],accountCell=row.children[3];if(!movement)return;if(stockCell)stockCell.textContent=movement.stock_name||'—';const serialCell=document.createElement('td');serialCell.className='serial-status';const serialTrackingNotRequired=['Pil','Sarf Malzeme'].includes(String(movement.stock_type||''));let serials=[];try{serials=Array.isArray(JSON.parse(movement.serial_numbers||'[]'))?JSON.parse(movement.serial_numbers||'[]').filter(value=>String(value).trim()!==''):[]}catch(error){}serialCell.textContent=serialTrackingNotRequired?'-':(serials.length===0?'Yok':serials.length<Number(movement.quantity||0)?'Eksik':'Var');if(!serialTrackingNotRequired)serialCell.dataset.status=serialCell.textContent;stockCell?.after(serialCell);if(accountCell&&account)accountCell.textContent=account.short_name||account.title||'—'})})();</script>
<script>(()=>document.querySelectorAll('.technical-table-wrap .serial-status[data-status]').forEach(cell=>{const status=cell.dataset.status;if(!['Var','Yok','Eksik'].includes(status))return;cell.style.background=status==='Var'?'#bfe9ca':status==='Yok'?'#f8c6ca':'#fff3cd';cell.style.color='#000';cell.style.fontWeight='700';cell.style.textAlign='center'}))();</script>
<script>(()=>{const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,headers=[...document.querySelectorAll('.technical-table-wrap thead th')],quantityHeader=headers.find(header=>header.textContent.trim()==='MİKTAR');if(quantityHeader){const purchaseHeader=document.createElement('th'),costHeader=document.createElement('th');purchaseHeader.textContent='ALIŞ FİYATI';costHeader.textContent='BİRİM MALİYET';quantityHeader.after(purchaseHeader);purchaseHeader.after(costHeader)}const money=value=>Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index],quantityCell=row.children[6];if(!movement||!quantityCell)return;const purchaseCell=document.createElement('td'),costCell=document.createElement('td');purchaseCell.textContent=money(movement.purchase_price);costCell.textContent=money(movement.unit_cost);quantityCell.after(purchaseCell);purchaseCell.after(costCell)})})();</script>
<script>(()=>{const headerRow=document.querySelector('.technical-table-wrap thead tr'),headers=[...headerRow?.children||[]],dateHeader=headers.find(header=>header.textContent.trim()==='TARİH'),accountHeader=headers.find(header=>header.textContent.trim()==='CARİ');if(dateHeader&&accountHeader)dateHeader.after(accountHeader);const allHeaders=[...headerRow?.children||[]],priceIndexes=['ALIŞ FİYATI','BİRİM MALİYET'].map(name=>allHeaders.findIndex(header=>header.textContent.trim()===name));document.querySelectorAll('.technical-table-wrap tbody tr').forEach(row=>{const accountCell=row.children[4];if(accountCell)row.children[0]?.after(accountCell);priceIndexes.forEach(index=>{const cell=row.children[index];if(cell&&cell.textContent.trim()&&cell.textContent.trim()!=='—'&&!cell.textContent.includes('TL'))cell.textContent+=' TL'})})})();</script>
<script>(()=>{const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,headers=[...document.querySelectorAll('.technical-table-wrap thead th')],purchaseIndex=headers.findIndex(header=>header.textContent.trim()==='ALIŞ FİYATI'),costIndex=headers.findIndex(header=>header.textContent.trim()==='BİRİM MALİYET'),moneyFormat=value=>Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index];if(!movement)return;if(row.children[purchaseIndex])row.children[purchaseIndex].textContent=moneyFormat(movement.purchase_price);if(row.children[costIndex])row.children[costIndex].textContent=moneyFormat(movement.unit_cost)})})();</script>
<script>(()=>{const headerRow=document.querySelector('.technical-table-wrap thead tr'),headers=[...headerRow?.children||[]],quantityHeader=headers.find(header=>header.textContent.trim()==='MİKTAR'),costHeader=headers.find(header=>header.textContent.trim()==='BİRİM MALİYET');if(quantityHeader&&costHeader)quantityHeader.after(costHeader);document.querySelectorAll('.technical-table-wrap tbody tr').forEach(row=>{const quantityCell=row.children[6],costCell=row.children[8];if(quantityCell&&costCell)quantityCell.after(costCell)})})();</script>
<script>(()=>{const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,headerRow=document.querySelector('.technical-table-wrap thead tr');if(!headerRow)return;const costHeader=[...headerRow.children].find(header=>header.textContent.trim()==='BİRİM MALİYET');if(!costHeader)return;const vatHeader=document.createElement('th');vatHeader.textContent='KDV';costHeader.after(vatHeader);const vatIndex=[...headerRow.children].indexOf(vatHeader);document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index];if(!movement)return;const vatCell=document.createElement('td');vatCell.textContent=Number(movement.vat_rate||0).toLocaleString('tr-TR',{maximumFractionDigits:2})+'%';row.children[vatIndex-1]?.after(vatCell)})})();</script>
<script>(()=>{const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,headerRow=document.querySelector('.technical-table-wrap thead tr');if(!headerRow)return;const purchaseHeader=[...headerRow.children].find(header=>header.textContent.trim()==='ALIŞ FİYATI');if(!purchaseHeader)return;const withVatHeader=document.createElement('th');withVatHeader.textContent='ALIŞ FİYATI (KDV\'Lİ)';purchaseHeader.after(withVatHeader);const withVatIndex=[...headerRow.children].indexOf(withVatHeader),money=value=>Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index];if(!movement)return;const withVatCell=document.createElement('td'),purchase=Number(movement.purchase_price||0),vat=Number(movement.vat_rate||0);withVatCell.textContent=money(purchase*(1+vat/100));row.children[withVatIndex-1]?.after(withVatCell)})})();</script>
<script>(()=>{const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,headerRow=document.querySelector('.technical-table-wrap thead tr');if(!headerRow)return;const withVatHeader=[...headerRow.children].find(header=>header.textContent.trim()==="ALIŞ FİYATI (KDV'Lİ)");if(!withVatHeader)return;const saleHeader=document.createElement('th');saleHeader.textContent='LİSTE FİYATI';withVatHeader.after(saleHeader);const saleIndex=[...headerRow.children].indexOf(saleHeader),money=value=>Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index];if(!movement)return;const saleCell=document.createElement('td');saleCell.textContent=money(movement.sale_price);row.children[saleIndex-1]?.after(saleCell)})})();</script>
<script>(()=>{const movements=<?=json_encode($movements,JSON_UNESCAPED_UNICODE)?>,headerRow=document.querySelector('.technical-table-wrap thead tr');if(!headerRow)return;const quantityHeader=[...headerRow.children].find(header=>header.textContent.trim()==='MİKTAR');if(!quantityHeader)return;const unitHeader=document.createElement('th');unitHeader.textContent='BİRİM';quantityHeader.after(unitHeader);const unitIndex=[...headerRow.children].indexOf(unitHeader);document.querySelectorAll('.technical-table-wrap tbody tr').forEach((row,index)=>{const movement=movements[index];if(!movement)return;const unitCell=document.createElement('td');unitCell.textContent=movement.unit||'Adet';row.children[unitIndex-1]?.after(unitCell)})})();</script>
<script>(()=>{const search=document.getElementById('stock-entry-search');if(!search)return;const normalize=value=>String(value||'').toLocaleLowerCase('tr-TR');search.addEventListener('input',()=>{const query=normalize(search.value.trim());document.querySelectorAll('.technical-table-wrap tbody tr').forEach(row=>{if(row.querySelector('.empty'))return;row.hidden=!!query&&!normalize(row.textContent).includes(query)})})})();</script>
<style>.bulk-stock-entry{position:absolute;right:190px;top:50%;transform:translateY(-50%);text-decoration:none}@media(max-width:720px){.bulk-stock-entry{position:static!important;transform:none!important;display:block;margin:12px 24px 0;text-align:center}}</style>
<style>.stock-entry-filter{display:flex;flex-wrap:wrap;align-items:end;gap:10px;padding:12px 24px;border-bottom:1px solid #e1e2e8}.stock-entry-filter label{display:flex;flex:1 1 190px;flex-direction:column;gap:4px;font-size:12px;color:#5d5b6d}.stock-entry-filter input{width:100%;height:34px;padding:0 28px 0 9px;border:1px solid #d7d9e2;border-radius:5px;background:#fff;font:inherit;font-size:13px}.filter-control{position:relative}.field-clear{position:absolute;top:50%;right:5px;display:grid;place-items:center;width:22px;height:22px;padding:0;border:0;border-radius:50%;background:transparent;color:#888;cursor:pointer;font-size:18px;line-height:1;transform:translateY(-50%)}.field-clear:hover{background:#f0f0f2;color:#e04f55}.account-filter{position:relative}.account-results{position:absolute;z-index:20;top:100%;right:0;left:0;display:none;max-height:210px;overflow:auto;border:1px solid #d7d9e2;border-radius:5px;background:#fff;box-shadow:0 8px 18px rgba(47,43,61,.15)}.account-results.open{display:block}.account-results button{display:block;width:100%;padding:8px 10px;border:0;background:#fff;color:#2f2b3d;text-align:left;cursor:pointer;font:inherit;font-size:13px}.account-results button:hover{background:#eef9f1}.stock-entry-filter button[type="submit"]{height:34px;padding:0 14px;border:0;border-radius:5px;cursor:pointer;font-size:13px}.stock-entry-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;margin-top:14px;background:#e1e2e8;border:1px solid #e1e2e8}.stock-entry-summary>div{padding:8px 14px;background:#fff}.stock-entry-summary span{display:block;margin-bottom:2px;color:#7b7b8d;font-size:11px}.stock-entry-summary strong{color:#2f2b3d;font-size:14px}@media(max-width:720px){.stock-entry-filter{padding:12px 16px}.stock-entry-summary{grid-template-columns:1fr;margin:12px 14px}.stock-entry-summary>div{padding:8px 12px}}</style>
<script>(()=>{const filter=document.querySelector('.stock-entry-filter'),card=document.querySelector('.technical-card'),header=card?.querySelector('header'),search=document.querySelector('.stock-entry-search'),table=document.querySelector('.technical-table-wrap'),summary=document.querySelector('.stock-entry-summary');if(!filter||!card)return;if(header&&search)header.after(search);if(table&&summary)table.after(summary);const select=filter.querySelector('select[name="account_id"]');if(select){const selected=select.selectedOptions[0],label=select.closest('label'),input=document.createElement('input'),hidden=document.createElement('input'),control=document.createElement('div'),clear=document.createElement('button'),results=document.createElement('div');hidden.type='hidden';hidden.name='account_id';hidden.value=select.value;input.type='search';input.id='entry-account-search';input.autocomplete='off';input.placeholder='En az 2 harf ile firma ara';input.value=selected?.value?selected.textContent.trim():'';clear.type='button';clear.className='field-clear';clear.textContent='×';clear.title='Firma alanını temizle';results.className='account-results';control.className='filter-control';control.append(input,clear);label.textContent='Firma';label.append(hidden,control,results);const close=()=>{results.replaceChildren();results.classList.remove('open')};clear.addEventListener('click',()=>{input.value='';hidden.value='';close();input.focus()});input.addEventListener('input',async()=>{hidden.value='';const q=input.value.trim();if(q.length<2){close();return}try{const response=await fetch(<?=json_encode(url('stock-entry.php'))?>+'?account_search='+encodeURIComponent(q));const accounts=await response.json();results.replaceChildren(...accounts.map(account=>{const item=document.createElement('button');item.type='button';item.textContent=account.short_name||account.title;item.addEventListener('click',()=>{hidden.value=account.id;input.value=account.title;close()});return item}));results.classList.toggle('open',accounts.length>0)}catch(error){close()}});document.addEventListener('click',event=>{if(!label.contains(event.target))close()});filter.addEventListener('submit',event=>{if(input.value.trim()&& !hidden.value){event.preventDefault();input.focus();alert('Listeden bir firma seçin.')}})}filter.querySelectorAll('input[type="date"]').forEach(field=>{const label=field.closest('label'),control=document.createElement('div'),clear=document.createElement('button');control.className='filter-control';clear.type='button';clear.className='field-clear';clear.textContent='×';clear.title='Tarihi temizle';control.append(field,clear);label.append(control);clear.addEventListener('click',()=>{field.value='';field.focus()})})})();</script>
<style>.stock-entry-filter .stock-entry-search{flex:1 1 260px;padding:0;border:0}.stock-entry-filter .stock-entry-search input{height:34px;font-size:13px}.stock-entry-filter .stock-entry-search i{font-size:16px}.stock-entry-filter .account-results.open{display:block!important}</style>
<script>(()=>{const filter=document.querySelector('.stock-entry-filter'),search=document.querySelector('.stock-entry-search');if(filter&&search)filter.prepend(search)})();</script>
<script>(()=>{const filter=document.querySelector('.stock-entry-filter'),input=document.getElementById('entry-account-search'),accountId=filter?.querySelector('input[type="hidden"][name="account_id"]'),results=document.querySelector('.account-results');if(!filter||!input||!accountId||!results)return;let requestId=0;const close=()=>{results.replaceChildren();results.classList.remove('open')};input.addEventListener('input',async()=>{accountId.value='';const text=input.value.trim();if(text.length<3){close();return}const currentRequest=++requestId;try{const response=await fetch(<?=json_encode(url('stock-entry.php'))?>+'?account_search='+encodeURIComponent(text));const accounts=await response.json();if(currentRequest!==requestId)return;results.replaceChildren(...accounts.map(account=>{const item=document.createElement('button');item.type='button';item.textContent=account.short_name?account.short_name+' — '+account.title:account.title;item.addEventListener('click',()=>{accountId.value=String(account.id);input.value=account.title;close()});return item}));results.classList.toggle('open',accounts.length>0)}catch(error){close()}});document.addEventListener('click',event=>{if(!filter.querySelector('.account-filter')?.contains(event.target))close()});filter.addEventListener('submit',event=>{if(input.value.trim()&&!accountId.value){event.preventDefault();input.focus()}})})();</script>
<style>.stock-entry-filter>label{flex:0 0 260px!important}.stock-entry-filter .stock-entry-search{flex:0 0 260px!important}.stock-entry-filter .account-filter{flex-direction:row!important;align-items:center!important;gap:9px!important;margin-left:22px!important}.stock-entry-filter .account-filter-title{display:block;flex:0 0 auto;margin:0!important;transform:none!important}.stock-entry-filter .account-filter .filter-control{flex:1 1 auto}.stock-entry-filter>label.date-filter{flex-basis:160px!important}.stock-entry-filter .filter-search-submit{display:grid;place-items:center;width:25px;height:25px;padding:0;border:0;background:transparent;color:#6f7180;cursor:pointer}.stock-entry-filter .filter-search-submit i{font-size:17px}.stock-entry-filter .field-clear,.stock-entry-filter .field-clear:hover{display:grid!important;place-items:center!important;width:18px!important;min-width:18px!important;height:18px!important;min-height:18px!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:#7b7b8d!important;box-shadow:none!important;font-size:17px!important;line-height:1!important;appearance:none!important}.stock-entry-filter .field-clear:focus{outline:0!important}</style>
<script>const entryAccountSearch=document.getElementById('entry-account-search');if(entryAccountSearch)entryAccountSearch.type='text';const entryAccountLabel=entryAccountSearch?.closest('label');entryAccountLabel?.classList.add('account-filter');if(entryAccountLabel&&!entryAccountLabel.querySelector('.account-filter-title')){const title=document.createElement('span');title.className='account-filter-title';title.textContent='Firma';entryAccountLabel.firstChild?.remove();entryAccountLabel.prepend(title);}const entryStartDateLabel=document.querySelector('input[name="date_start"]')?.closest('label');entryStartDateLabel?.classList.add('date-filter');if(entryStartDateLabel)entryStartDateLabel.style.marginLeft='auto';document.querySelector('input[name="date_end"]')?.closest('label')?.classList.add('date-filter');const entryFilter=document.querySelector('.stock-entry-filter'),entrySearch=document.querySelector('.stock-entry-search');entryFilter?.querySelector('button[type="submit"].button')?.remove();const entrySearchIcon=entrySearch?.querySelector('i');if(entryFilter&&entrySearchIcon){const button=document.createElement('button');button.type='submit';button.className='filter-search-submit';button.title='Ara';button.setAttribute('aria-label','Ara');button.innerHTML='<i class="ti tabler-search" aria-hidden="true"></i>';entrySearchIcon.replaceWith(button);}</script>
<style>.stock-entry-filter>label:not(.date-filter),.stock-entry-filter .stock-entry-search{flex:0 0 300px!important}.stock-entry-filter .account-results button{font-size:12px!important}.stock-entry-filter .account-filter{transform:translateY(-6px)!important}</style>
<script>(()=>{const results=document.querySelector('.account-results'),input=document.getElementById('entry-account-search'),filter=document.querySelector('.stock-entry-filter'),accountId=filter?.querySelector('input[type="hidden"][name="account_id"]');if(!results||!input||!accountId)return;const render=accounts=>{results.replaceChildren(...accounts.map(account=>{const item=document.createElement('button');item.type='button';item.textContent=account.short_name||'';item.addEventListener('click',()=>{accountId.value=String(account.id);input.value=account.short_name||'';results.replaceChildren();results.classList.remove('open')});return item}));results.classList.toggle('open',accounts.length>0)};const originalFetch=window.fetch;window.fetch=async(...args)=>{const response=await originalFetch(...args);if(String(args[0]).includes('account_search=')){try{const accounts=await response.clone().json();render(accounts)}catch(error){}}return response}})();</script>
<script>const entryAccountInput=document.getElementById('entry-account-search');if(entryAccountInput)entryAccountInput.placeholder='';document.addEventListener('submit',async event=>{const form=event.target;if(!form.matches('.stock-entry-filter'))return;event.preventDefault();event.stopImmediatePropagation();const input=document.getElementById('entry-account-search'),accountId=form.querySelector('input[type="hidden"][name="account_id"]'),results=document.querySelector('.account-results'),term=input?.value.trim()||'';if(!input||!accountId||!term||accountId.value){HTMLFormElement.prototype.submit.call(form);return}if(term.length<3){input.focus();return}try{const response=await fetch(<?=json_encode(url('stock-entry.php'))?>+'?account_search='+encodeURIComponent(term));const accounts=await response.json();if(accounts.length===1){accountId.value=String(accounts[0].id);input.value=accounts[0].short_name||'';HTMLFormElement.prototype.submit.call(form);return}results?.classList.toggle('open',accounts.length>0);input.focus()}catch(error){input.focus()}},true);</script>
<script>(()=>{const input=document.getElementById('entry-account-search'),filter=document.querySelector('.stock-entry-filter'),accountId=filter?.querySelector('input[type="hidden"][name="account_id"]'),results=document.querySelector('.account-results');if(!input||!accountId||!results)return;let requestId=0;input.addEventListener('input',async event=>{event.stopImmediatePropagation();accountId.value='';const term=input.value.trim();if(term.length<3){results.replaceChildren();results.classList.remove('open');return}const id=++requestId;try{const response=await fetch(<?=json_encode(url('stock-entry.php'))?>+'?account_search='+encodeURIComponent(term));const accounts=await response.json();if(id!==requestId)return;results.replaceChildren(...accounts.map(account=>{const item=document.createElement('button');item.type='button';item.textContent=account.short_name||'';item.addEventListener('click',()=>{accountId.value=String(account.id);input.value=account.short_name||'';results.replaceChildren();results.classList.remove('open')});return item}));results.classList.toggle('open',accounts.length>0)}catch(error){results.replaceChildren();results.classList.remove('open')}},true)})();</script>
<style>
  .stock-movement-filter{display:flex;align-items:center;gap:28px;padding:14px 24px;border-bottom:1px solid #e1e2e8;font-size:12px}
  .stock-filter-text{display:flex;align-items:center;gap:10px;width:300px;color:#6f7180}
  .stock-filter-text input,.stock-filter-control input{box-sizing:border-box;width:100%;height:34px;padding:0 28px 0 10px;border:1px solid #d7d9e2;border-radius:6px;background:#fff;font:inherit;font-size:12px}
  .stock-filter-company{display:flex;align-items:center;gap:10px;width:300px}
  .stock-filter-company>span{white-space:nowrap;color:#5d5b6d}
  .stock-filter-control{position:relative;flex:1;min-width:0}
  .stock-filter-clear{position:absolute;top:50%;right:7px;width:16px!important;min-width:16px!important;height:16px!important;min-height:16px!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:#76798a!important;font:700 16px/16px Arial!important;transform:translateY(-50%);cursor:pointer!important;box-shadow:none!important}
  .stock-filter-clear:hover,.stock-filter-clear:focus{background:transparent!important;color:#e04f55!important;outline:0!important}
  .stock-filter-results{position:absolute;z-index:50;top:calc(100% + 3px);left:0;right:0;display:none;max-height:205px;overflow:auto;border:1px solid #d7d9e2;border-radius:6px;background:#fff;box-shadow:0 8px 18px rgba(47,43,61,.15)}
  .stock-filter-results.open{display:block}
  .stock-filter-results button{display:block;width:100%;padding:8px 10px;border:0;background:#fff;color:#2f2b3d;text-align:left;font:12px/1.35 inherit;cursor:pointer}
  .stock-filter-results button:hover{background:#eef9f1}
  .stock-filter-dates{display:flex;align-items:end;gap:10px;margin-left:auto}
  .stock-filter-dates label{display:flex;flex-direction:column;gap:4px;width:160px;color:#5d5b6d}
  .stock-filter-submit{display:grid;place-items:center;width:34px;height:34px;padding:0;border:0;border-radius:6px;background:#18a84b;color:#fff;cursor:pointer}
  .stock-filter-submit i{font-size:17px}
  .stock-entry-summary{margin:14px 24px 18px!important;border:1px solid #e1e2e8!important}
  @media(max-width:900px){.stock-movement-filter{flex-wrap:wrap;gap:14px}.stock-filter-dates{margin-left:0}.stock-filter-text,.stock-filter-company{width:300px}}
</style>
<script>
(() => {
  const form = document.querySelector('.stock-movement-filter');
  const input = document.getElementById('stock-filter-account');
  const accountId = document.getElementById('stock-filter-account-id');
  const results = document.getElementById('stock-filter-results');
  if (!form || !input || !accountId || !results) return;
  let latestRequest = 0;
  const close = () => { results.replaceChildren(); results.classList.remove('open'); };
  const choose = account => { accountId.value = String(account.id); input.value = account.short_name || ''; close(); };
  const search = async () => {
    const term = input.value.trim();
    accountId.value = '';
    if (term.length < 3) { close(); return []; }
    const requestId = ++latestRequest;
    try {
      const response = await fetch(<?=json_encode(url('stock-entry.php'))?> + '?account_search=' + encodeURIComponent(term));
      const accounts = await response.json();
      if (requestId !== latestRequest) return [];
      results.replaceChildren(...accounts.map(account => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = account.short_name || '';
        button.addEventListener('click', () => choose(account));
        return button;
      }));
      results.classList.toggle('open', accounts.length > 0);
      return accounts;
    } catch (error) { close(); return []; }
  };
  input.addEventListener('input', search);
  document.addEventListener('click', event => { if (!event.target.closest('.stock-filter-company')) close(); });
  form.querySelectorAll('[data-clear]').forEach(button => button.addEventListener('click', () => {
    const target = button.dataset.clear;
    if (target === 'stock-filter-account') { input.value = ''; accountId.value = ''; close(); input.focus(); return; }
    const field = form.querySelector('[name="' + target + '"]');
    if (field) { field.value = ''; field.focus(); }
  }));
  form.addEventListener('submit', async event => {
    const term = input.value.trim();
    if (!term || accountId.value || term.length < 3) return;
    event.preventDefault();
    const accounts = await search();
    if (accounts.length === 1) { choose(accounts[0]); form.submit(); }
    else input.focus();
  });
})();
</script>
<style>
  .stock-column-picker{position:relative;margin-left:auto;flex:0 0 auto}
  .stock-column-picker-trigger{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:0;border-radius:6px;background:#18a84b;color:#fff;font:600 12px inherit;cursor:pointer}
  .stock-column-picker-trigger i{font-size:15px}
  .stock-column-picker-menu{position:absolute;z-index:80;top:calc(100% + 7px);right:0;display:none;width:210px;max-height:355px;overflow:auto;padding:8px;border:1px solid #dfe1e8;border-radius:7px;background:#fff;box-shadow:0 10px 22px rgba(47,43,61,.18)}
  .stock-column-picker-menu.open{display:block}
  .stock-column-picker-actions{display:flex;gap:6px;padding:0 0 8px;margin:0 0 7px;border-bottom:1px solid #ececf0}
  .stock-column-picker-actions button{padding:4px 6px;border:0;border-radius:4px;background:#f0f1f5;color:#5d5b6d;font:600 10px inherit;cursor:pointer}
  .stock-column-picker-option{display:flex;align-items:center;gap:7px;padding:5px 2px;color:#353244;font:12px/1.3 inherit;cursor:pointer}
  .stock-column-picker-option input{width:15px;height:15px;margin:0;accent-color:#18a84b}
  .technical-card.stock-column-picker-open{overflow:visible}
  @media(max-width:900px){.stock-column-picker{margin-left:0}.stock-movement-filter{gap:12px}}
</style>
<script>
(() => {
  const table = document.querySelector('.technical-table-wrap table');
  const filter = document.querySelector('.stock-movement-filter');
  const card = document.querySelector('.technical-card');
  if (!table || !filter || !table.tHead?.rows[0]) return;

  const headers = [...table.tHead.rows[0].cells];
  const columnNames = headers.map((header, index) => header.textContent.trim().replace(/\s+/g, ' ') || `Sütun ${index + 1}`);
  const storageKey = 'vox-stock-entry-columns';
  let visibleColumns;
  try { visibleColumns = JSON.parse(localStorage.getItem(storageKey) || 'null'); } catch (error) { visibleColumns = null; }
  if (!Array.isArray(visibleColumns) || visibleColumns.length !== columnNames.length) visibleColumns = columnNames.map(() => true);

  const picker = document.createElement('div');
  picker.className = 'stock-column-picker';
  picker.innerHTML = '<button type="button" class="stock-column-picker-trigger" aria-expanded="false"><i class="ti tabler-columns-3"></i>Sütunlar</button><div class="stock-column-picker-menu"><div class="stock-column-picker-actions"><button type="button" data-columns-all>Tümünü seç</button><button type="button" data-columns-simple>Sade görünüm</button></div><div class="stock-column-picker-options"></div></div>';
  filter.insertBefore(picker, filter.querySelector('.stock-filter-dates'));

  const trigger = picker.querySelector('.stock-column-picker-trigger');
  const menu = picker.querySelector('.stock-column-picker-menu');
  const options = picker.querySelector('.stock-column-picker-options');
  const applyColumns = () => {
    table.querySelectorAll('tr').forEach(row => {
      [...row.children].forEach((cell, index) => { cell.style.display = visibleColumns[index] ? '' : 'none'; });
    });
    localStorage.setItem(storageKey, JSON.stringify(visibleColumns));
  };
  const renderOptions = () => {
    options.replaceChildren(...columnNames.map((name, index) => {
      const label = document.createElement('label');
      label.className = 'stock-column-picker-option';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.checked = visibleColumns[index];
      checkbox.addEventListener('change', () => { visibleColumns[index] = checkbox.checked; applyColumns(); });
      label.append(checkbox, document.createTextNode(name));
      return label;
    }));
  };
  const close = () => { menu.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); card?.classList.remove('stock-column-picker-open'); };
  trigger.addEventListener('click', () => {
    const open = !menu.classList.contains('open');
    menu.classList.toggle('open', open);
    trigger.setAttribute('aria-expanded', String(open));
    card?.classList.toggle('stock-column-picker-open', open);
  });
  picker.querySelector('[data-columns-all]').addEventListener('click', () => { visibleColumns = columnNames.map(() => true); renderOptions(); applyColumns(); });
  picker.querySelector('[data-columns-simple]').addEventListener('click', () => {
    const keep = new Set(['TARİH', 'STOK TİPİ', 'STOK KARTI', 'CARİ', 'MİKTAR', 'İŞLEMLER']);
    visibleColumns = columnNames.map(name => keep.has(name));
    renderOptions(); applyColumns();
  });
  document.addEventListener('click', event => { if (!picker.contains(event.target)) close(); });
  renderOptions();
  applyColumns();
})();
</script>
<?php patient_footer(); exit; }
patient_header('Stok Girişi','stock');
?>
<main class="patient-container stock-entry-page"><section class="vuexy-form-card stock-entry-card"><header class="form-card-title"><h1>Stok Girişi</h1><p>Stok kartına giriş hareketi ekleyin.</p></header><?php if($error):?><p class="stock-entry-error"><?=e($error)?></p><?php endif?><?php if($editInvoiceCorrectionAllowed):?><p class="stock-entry-notice">Bu fatura bugün oluşturuldu. Fatura no veya giriş tarihi değiştirilirse, aynı faturaya bağlı tüm giriş satırları birlikte güncellenir.</p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><label>Stok Tipi *<select id="entry-stock-type" name="stock_type" required><option value="">Seçiniz</option><option <?=$form['stock_type']==='Kulaklık'?'selected':''?>>Kulaklık</option><option <?=$form['stock_type']==='Sarf Malzeme'?'selected':''?>>Sarf Malzeme</option></select></label><label>Stok Kartı *<select id="entry-stock-card" name="stock_id" required><option value="">Önce stok tipi seçiniz</option><?php foreach($stocks as $stock):?><option data-stock-type="<?=e($stock['stock_type'])?>" value="<?=$stock['id']?>" <?=$form['stock_id']==$stock['id']?'selected':''?>><?=e($stock['stock_code'].' — '.$stock['stock_name'])?></option><?php endforeach?></select></label><label>Cari *<select name="current_account_id" required><option value="">Seçiniz</option><?php foreach($accounts as $account):?><option value="<?=$account['id']?>" <?=$form['current_account_id']==$account['id']?'selected':''?>><?=e($account['code'].' — '.$account['title'])?></option><?php endforeach?></select></label><label>Fatura No<input name="invoice_no" value="<?=e($form['invoice_no'])?>" maxlength="100"></label><label>Giriş Tarihi *<input type="date" name="movement_date" value="<?=e($form['movement_date'])?>" required></label><label>Giriş Miktarı *<input id="entry-quantity" type="number" min="1" name="quantity" value="<?=e($form['quantity'])?>" required></label><section id="entry-tracking" class="stock-entry-wide entry-tracking"><h2>Takip ve İzlenebilirlik Bilgileri</h2><div class="tracking-grid"><div class="serial-area"><strong>Seri Numaraları *</strong><div id="serial-fields"></div></div><label>ÜTS / Lot No<input name="uts_lot_no" value="<?=e($form['uts_lot_no'])?>" maxlength="190"></label><label>Garanti Başlangıç Tarihi<input type="date" name="warranty_start" value="<?=e($form['warranty_start'])?>"></label><label>Garanti Bitiş Tarihi<input type="date" name="warranty_end" value="<?=e($form['warranty_end'])?>"></label></div></section><label>Açıklama<textarea name="description" rows="3"><?=e($form['description'])?></textarea></label><footer><a href="<?=e(url('stocks.php'))?>">İptal</a><button class="button">Kaydet</button></footer></form></section></main>
<script>(()=>{const quantity=document.getElementById('entry-quantity'),box=document.getElementById('serial-fields'),type=document.getElementById('entry-stock-type'),stock=document.getElementById('entry-stock-card'),tracking=document.getElementById('entry-tracking'),old=<?=json_encode($serials)?>;const filterStocks=()=>{[...stock.options].forEach(option=>{if(!option.dataset.stockType)return;option.hidden=option.dataset.stockType!==type.value});if(stock.selectedOptions[0]?.hidden)stock.value=''};const render=()=>{const n=type.value==='Kulaklık'?Math.max(0,parseInt(quantity.value||0,10)):0;const values=[...box.querySelectorAll('input')].map(i=>i.value);box.innerHTML='';for(let i=0;i<n;i++){const input=document.createElement('input');input.name='serial_numbers[]';input.placeholder='Seri No '+(i+1);input.required=true;input.value=values[i]??old[i]??'';box.append(input)}};const toggleTracking=()=>{tracking.hidden=type.value!=='Kulaklık';render()};type.addEventListener('change',()=>{stock.value='';filterStocks();toggleTracking()});quantity.addEventListener('input',render);filterStocks();toggleTracking()})();</script>
<script>(()=>{const type=document.getElementById('entry-stock-type'),stock=document.getElementById('entry-stock-card');if(!type||!stock)return;const brandByStock=<?=json_encode(array_column($stocks,'brand','id'),JSON_UNESCAPED_UNICODE)?>,brands=<?=json_encode($brands,JSON_UNESCAPED_UNICODE)?>,selectedBrand=<?=json_encode($form['brand'],JSON_UNESCAPED_UNICODE)?>;const label=document.createElement('label'),brand=document.createElement('select');label.textContent='Marka *';brand.id='entry-brand';brand.name='brand';brand.required=true;brand.innerHTML='<option value="">Önce stok tipi seçiniz</option>'+brands.map(item=>'<option value="'+item.replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'">'+item+'</option>').join('');label.append(brand);type.closest('label').after(label);brand.value=selectedBrand;const setBrandRequired=()=>{const optional=type.value==='Sarf Malzeme',blank=brand.querySelector('option[value=""]');brand.required=!optional;label.firstChild.textContent=optional?'Marka':'Marka *';if(blank)blank.textContent=optional?'Marka yok':'Marka seçiniz'};const filter=()=>{setBrandRequired();[...stock.options].forEach(option=>{if(!option.dataset.stockType)return;option.hidden=option.dataset.stockType!==type.value||brandByStock[option.value]!==brand.value});if(stock.selectedOptions[0]?.hidden)stock.value=''};type.addEventListener('change',()=>{brand.value='';filter()});brand.addEventListener('change',()=>{stock.value='';filter()});filter()})();</script>
<script>(()=>{const type=document.getElementById('entry-stock-type'),brand=document.getElementById('entry-brand'),stock=document.getElementById('entry-stock-card');if(!type||!brand||!stock)return;const cards=<?=json_encode($stocks,JSON_UNESCAPED_UNICODE)?>;const filterBrands=()=>{const available=new Set(cards.filter(card=>card.stock_type===type.value).map(card=>card.brand));[...brand.options].forEach(option=>{if(!option.value)return;option.hidden=!available.has(option.value)});if(brand.selectedOptions[0]?.hidden)brand.value=''};type.addEventListener('change',filterBrands);filterBrands()})();</script>
<script>
(() => {
  const quantity = document.getElementById('entry-quantity');
  if (!quantity) return;
  const values = <?=json_encode(['purchase_price'=>$form['purchase_price'],'sale_price'=>$form['sale_price'],'unit_cost'=>$form['unit_cost']],JSON_UNESCAPED_UNICODE)?>;
  const fields = [['purchase_price','Alış Fiyatı'],['unit_cost','Birim Maliyet']];
  const format = value => value === '' || value === null ? '' : new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value || 0));
  const section = document.createElement('section');
  section.className = 'stock-entry-wide entry-prices';
  section.innerHTML = '<div class="entry-prices-grid"></div>';
  const grid = section.querySelector('div');
  fields.forEach(([name,label]) => {
    const wrapper = document.createElement('label');
    wrapper.textContent = label;
    const field = document.createElement('input');
    field.name = name;
    field.type = 'text';
    field.inputMode = 'decimal';
    field.value = format(values[name]);
    wrapper.append(field); grid.append(wrapper);
  });
  const saleWrapper = document.createElement('label');
  saleWrapper.className = 'entry-sale-price';
  saleWrapper.textContent = 'Liste Fiyatı (TL)';
  const saleField = document.createElement('input');
  saleField.name = 'sale_price';
  saleField.type = 'text';
  saleField.inputMode = 'decimal';
  saleField.value = format(values.sale_price);
  saleField.readOnly = true;
  saleField.setAttribute('aria-disabled', 'true');
  saleField.tabIndex = -1;
  saleWrapper.append(saleField);
  const quantityWrapper = quantity.closest('label');
  quantityWrapper.after(saleWrapper);
  saleWrapper.after(section);
  const alignPriceColumns = () => { grid.style.gridTemplateColumns = getComputedStyle(section.closest('form')).gridTemplateColumns; };
  requestAnimationFrame(alignPriceColumns);
  window.addEventListener('load', alignPriceColumns);
  window.addEventListener('resize', alignPriceColumns);
})();
</script>
<script>
(() => {
  const stock = document.getElementById('entry-stock-card');
  const type = document.getElementById('entry-stock-type');
  const date = document.querySelector('input[name="movement_date"]');
  const price = document.querySelector('input[name="sale_price"]');
  const priceLabel = price?.closest('label');
  const priceLists = <?=json_encode($priceListItems, JSON_UNESCAPED_UNICODE)?>;
  if (!stock || !type || !date || !price || !priceLabel) return;
  const warning = document.createElement('small');
  warning.className = 'entry-list-price-warning';
  priceLabel.append(warning);
  const format = value => new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value || 0));
  const setListPrice = () => {
    const isConsumable = type.value === 'Sarf Malzeme';
    priceLabel.firstChild.nodeValue = isConsumable ? 'Satış Fiyatı (TL)' : 'Liste Fiyatı (TL)';
    price.readOnly = !isConsumable;
    price.toggleAttribute('aria-disabled', !isConsumable);
    price.tabIndex = isConsumable ? 0 : -1;
    if (isConsumable) { warning.textContent = ''; return; }
    const stockId = String(stock.value || '');
    const purchaseDate = date.value;
    if (!stockId || !purchaseDate) { warning.textContent = ''; return; }
    const item = priceLists.find(row => String(row.stock_id) === stockId && row.valid_from <= purchaseDate && row.valid_until >= purchaseDate);
    if (!item) {
      price.value = '';
      price.readOnly = true;
      warning.textContent = 'Bu ürün için alış tarihinde geçerli liste fiyatı bulunamadı.';
      return;
    }
    price.value = format(item.list_price);
    price.readOnly = true;
    warning.textContent = '';
  };
  stock.addEventListener('change', setListPrice);
  type.addEventListener('change', setListPrice);
  date.addEventListener('change', setListPrice);
  setListPrice();
})();
</script>
<script>(()=>{const prices=document.querySelector('.entry-prices'),sale=document.querySelector('.entry-sale-price'),description=document.querySelector('textarea[name="description"]')?.closest('label');if(!prices||!sale||!description)return;prices.after(sale);sale.after(description)})();</script>
<script>(()=>{const quantity=document.getElementById('entry-quantity');if(!quantity)return;const wrapper=document.createElement('label'),unit=document.createElement('select'),saved=<?=json_encode($form['unit'],JSON_UNESCAPED_UNICODE)?>;wrapper.textContent='Birim *';unit.name='unit';[['Adet','Adet'],['Kutu','Kutu'],['Kg','Kg']].forEach(([value,label])=>{const option=new Option(label,value);if(value===saved)option.selected=true;unit.add(option)});wrapper.append(unit);quantity.closest('label')?.after(wrapper)})();</script>
<script>(()=>{const type=document.getElementById('entry-stock-type'),quantity=document.getElementById('entry-quantity'),tracking=document.getElementById('entry-tracking'),box=document.getElementById('serial-fields'),stored=<?=json_encode($form['stock_type'],JSON_UNESCAPED_UNICODE)?>,old=<?=json_encode($serials,JSON_UNESCAPED_UNICODE)?>;if(!type)return;const legacy=type.querySelector('option[value="Kulaklık"]');if(legacy){legacy.value='İşitme Cihazı';legacy.textContent='İşitme Cihazı'}if(![...type.options].some(option=>option.value==='Pil')){const option=document.createElement('option');option.value='Pil';option.textContent='Pil';type.append(option)}const renderTracking=()=>{if(!tracking||!box||!quantity||type.value!=='İşitme Cihazı')return;tracking.hidden=false;const n=Math.max(0,parseInt(quantity.value||0,10)),values=[...box.querySelectorAll('input')].map(input=>input.value);box.innerHTML='';for(let i=0;i<n;i++){const input=document.createElement('input');input.name='serial_numbers[]';input.placeholder='Seri No '+(i+1);input.required=true;input.value=values[i]??old[i]??'';box.append(input)}};type.addEventListener('change',renderTracking);quantity?.addEventListener('input',renderTracking);if(stored){type.value=stored;type.dispatchEvent(new Event('change'))}})();</script>
<script>(()=>{const type=document.getElementById('entry-stock-type');if(!type)return;const legacy=[...type.options].find(option=>option.value==='Kulaklık'||option.textContent.trim()==='Kulaklık');if(legacy){legacy.value='İşitme Cihazı';legacy.textContent='İşitme Cihazı'}if(![...type.options].some(option=>option.value==='Pil')){const option=new Option('Pil','Pil');type.add(option)}})();</script>
<script>(()=>{const stock=document.getElementById('entry-stock-card');if(stock?.options[0])stock.options[0].textContent='Seçim yapınız';})();</script>
<script>(()=>{const accounts=<?=json_encode($accounts,JSON_UNESCAPED_UNICODE)?>;const select=document.querySelector('select[name="current_account_id"]');if(!select)return;const byId=new Map(accounts.map(account=>[String(account.id),account]));[...select.options].forEach(option=>{const account=byId.get(option.value);if(account)option.textContent=account.code+' — '+(account.short_name||account.title)})})();</script>
<script>(()=>{const savedType=<?=json_encode($form['stock_type'],JSON_UNESCAPED_UNICODE)?>,savedBrand=<?=json_encode($form['brand'],JSON_UNESCAPED_UNICODE)?>,savedStock=<?=json_encode($form['stock_id'])?>,type=document.getElementById('entry-stock-type'),brand=document.getElementById('entry-brand'),stock=document.getElementById('entry-stock-card');if(!type)return;type.innerHTML='<option value="">Seçiniz</option><option value="İşitme Cihazı">İşitme Cihazı</option><option value="Sarf Malzeme">Sarf Malzeme</option><option value="Pil">Pil</option><option value="Şarj Cihazı">Şarj Cihazı</option>';if(savedType){type.value=savedType;type.dispatchEvent(new Event('change'))}if(brand&&savedBrand){brand.value=savedBrand;brand.dispatchEvent(new Event('change'))}if(stock&&savedStock)stock.value=String(savedStock)})();</script>
<script>(()=>{const optionalSerials=()=>document.querySelectorAll('#serial-fields input[name="serial_numbers[]"]').forEach(input=>input.required=false);document.getElementById('entry-stock-type')?.addEventListener('change',optionalSerials);document.getElementById('entry-quantity')?.addEventListener('input',optionalSerials);optionalSerials()})();</script>
<script>(()=>['uts_lot_no','warranty_start','warranty_end'].forEach(name=>document.querySelector(`[name="${name}"]`)?.closest('label')?.remove()))();</script>
<script>(()=>{const lastEntry=<?=json_encode($lastSavedEntry,JSON_UNESCAPED_UNICODE)?>,footer=document.querySelector('.stock-entry-card footer'),newEntryUrl=<?=json_encode(url('stock-entry.php?new=1'))?>;if(!lastEntry||!footer)return;const button=document.createElement('button');button.type='button';button.className='entry-copy-last';button.title='Son stok girişini getir';button.setAttribute('aria-label','Son stok girişini getir');button.innerHTML='<i class="ti tabler-phone"></i>';button.addEventListener('click',()=>{const type=document.getElementById('entry-stock-type'),brand=document.getElementById('entry-brand'),account=document.querySelector('select[name="current_account_id"]'),invoice=document.querySelector('input[name="invoice_no"]'),date=document.querySelector('input[name="movement_date"]'),purchase=document.querySelector('input[name="purchase_price"]'),unitCost=document.querySelector('input[name="unit_cost"]');const form=footer.closest('form');if(form)form.action=newEntryUrl;if(type){type.value=lastEntry.stock_type||'';type.dispatchEvent(new Event('change'))}if(brand){brand.value=lastEntry.brand||'';brand.dispatchEvent(new Event('change'))}if(account)account.value=String(lastEntry.current_account_id||'');if(invoice)invoice.value=lastEntry.invoice_no||'';if(date)date.value=lastEntry.movement_date||'';if(purchase)purchase.value='';if(unitCost)unitCost.value='';document.getElementById('entry-stock-card').value='' });footer.prepend(button)})();</script>
<script>(()=>{const purchase=document.querySelector('input[name="purchase_price"]'),quantity=document.getElementById('entry-quantity'),unitCost=document.querySelector('input[name="unit_cost"]'),salePrice=document.querySelector('input[name="sale_price"]'),parse=value=>{value=String(value||'').replace(/\s/g,'');return Number(value.includes(',')?value.replaceAll('.','').replace(',','.'):value.replaceAll('.',''))||0},format=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value);const calculate=()=>{const total=parse(purchase?.value),count=Number(quantity?.value||0);if(!unitCost||!Number.isFinite(total)||total<0||!Number.isFinite(count)||count<=0)return;unitCost.value=format(Math.round((total/count)*100)/100)};purchase?.addEventListener('input',calculate);purchase?.addEventListener('blur',()=>purchase.value=format(parse(purchase.value)));quantity?.addEventListener('input',calculate);unitCost?.addEventListener('blur',()=>unitCost.value=format(parse(unitCost.value)));salePrice?.addEventListener('blur',()=>salePrice.value=format(parse(salePrice.value)))})();</script>
<script>(()=>document.querySelector('.entry-copy-last')?.addEventListener('click',()=>{const purchase=document.querySelector('input[name="purchase_price"]'),unitCost=document.querySelector('input[name="unit_cost"]');if(purchase)purchase.value='';if(unitCost)unitCost.value=''})})();</script>
<script>(()=>document.querySelector('.entry-copy-last')?.addEventListener('click',()=>window.location.assign(<?=json_encode(url('stock-entry.php?new=1&copy_last=1'))?>)))();</script>
<script>(()=>document.querySelector('.stock-entry-card footer a')?.addEventListener('click',event=>{event.preventDefault();window.history.back()})()</script>
<script>(()=>{const dates=<?=json_encode($invoiceDatesByNumber,JSON_UNESCAPED_UNICODE)?>,invoice=document.querySelector('input[name="invoice_no"]'),date=document.querySelector('input[name="movement_date"]'),form=document.querySelector('.stock-entry-card form'),sameDayCorrection=<?=json_encode($editInvoiceCorrectionAllowed)?>,originalInvoice=<?=json_encode($editRecord['invoice_no']??'',JSON_UNESCAPED_UNICODE)?>,originalDate=<?=json_encode($editRecord['movement_date']??'',JSON_UNESCAPED_UNICODE)?>;if(!invoice||!date)return;const key=value=>String(value||'').trim().toLocaleLowerCase('tr-TR');const knownDate=()=>dates[key(invoice.value)]||'';const applyInvoiceDate=()=>{const value=knownDate();if(value)date.value=value};invoice.addEventListener('input',applyInvoiceDate);invoice.addEventListener('blur',applyInvoiceDate);date.addEventListener('change',()=>{const value=knownDate();if(value&&date.value!==value){alert('Bu fatura numarası için giriş tarihi '+new Intl.DateTimeFormat('tr-TR').format(new Date(value+'T00:00:00'))+' olmalıdır.');date.value=value;}});form?.addEventListener('submit',event=>{if(!sameDayCorrection||key(invoice.value)===key(originalInvoice)&&date.value===originalDate)return;if(!confirm('Fatura no veya giriş tarihi değişti. Aynı faturaya bağlı tüm giriş satırları birlikte güncellenecek. Devam edilsin mi?'))event.preventDefault()});applyInvoiceDate()})();</script>
<script>(()=>{const input=document.querySelector('input[name="unit_cost"]');if(!input||!input.value.trim())return;const value=input.value.trim(),amount=Number(value.includes(',')?value.replaceAll('.','').replace(',','.'):value.replaceAll('.',''));if(Number.isFinite(amount))input.value=new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(amount)})();</script>
<script>(()=>{const grid=document.querySelector('.entry-prices-grid'),unit=document.querySelector('input[name="unit_cost"]')?.closest('label'),purchase=document.querySelector('input[name="purchase_price"]')?.closest('label');if(!grid||!unit||!purchase)return;grid.prepend(unit);grid.append(purchase)})();</script>
<script>(()=>{const label=document.querySelector('input[name="unit_cost"]')?.closest('label');if(label?.firstChild)label.firstChild.nodeValue='Birim Fiyatı';document.querySelectorAll('.technical-table-wrap th').forEach(header=>{if(header.textContent.trim()==='BİRİM MALİYET')header.textContent='BİRİM FİYATI';if(header.textContent.trim()==='SATIŞ FİYATI')header.textContent='LİSTE FİYATI'})})();</script>
<style>.stock-entry-page{max-width:900px!important;margin:0 auto!important;padding:28px 20px 48px!important}.stock-entry-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-entry-card .form-card-title{padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-entry-card h1{margin:0 0 5px;font-size:21px}.stock-entry-card p{margin:0;color:#7b7b8d}.stock-entry-card form{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:24px}.stock-entry-card label,.serial-area{display:flex;flex-direction:column;gap:7px}.stock-entry-card input,.stock-entry-card select,.stock-entry-card textarea{border:1px solid #d5d3de;border-radius:6px;padding:10px 12px;font:inherit}.stock-entry-card input,.stock-entry-card select{height:42px}.stock-entry-wide,.stock-entry-card footer{grid-column:1/-1}.entry-prices{border-top:1px solid #e1e2e8;padding-top:18px}.entry-prices h2{margin:0 0 14px;color:#19a94b;font-size:14px}.entry-prices-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.entry-prices-grid label{display:flex;flex-direction:column;gap:7px}.entry-tracking{border-top:1px solid #e1e2e8;padding-top:18px}.entry-tracking h2{margin:0 0 14px;color:#19a94b;font-size:14px}.tracking-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.serial-area{grid-column:1/-1}.serial-area strong{font-size:14px}.serial-area #serial-fields{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.stock-entry-card footer{display:flex;justify-content:flex-end;align-items:center;gap:14px}.stock-entry-card footer a{text-decoration:none;color:#7b7b8d}.stock-entry-error{margin:16px 24px;padding:12px;background:#ffe3e3;color:#a21d1d;border-radius:7px}@media(max-width:720px){.stock-entry-page{padding:20px 12px 36px!important}.stock-entry-card form,.entry-prices-grid,.tracking-grid,.serial-area #serial-fields{grid-template-columns:1fr}}</style>
<style>.stock-entry-card footer .entry-copy-last,.stock-entry-card footer a,.stock-entry-card footer button{display:inline-grid!important;place-items:center!important;width:36px!important;min-width:36px!important;height:36px!important;min-height:36px!important;margin:0!important;padding:0!important;border-radius:7px!important}.stock-entry-card footer .entry-copy-last{border:1px solid #f5a33b;background:#f5a33b;color:#fff;cursor:pointer}.stock-entry-card footer .entry-copy-last i{font-size:18px}.stock-entry-card footer .entry-copy-last:hover{background:#e98d18}</style>
<style>.entry-prices input[name="sale_price"][readonly]{background:#f3f4f7;color:#5d5b6d;cursor:not-allowed;pointer-events:none}.entry-list-price-warning{display:block;margin-top:4px;color:#dc3545;font-size:12px}</style>
<style>
[data-theme=dark] .stock-entry-page{background:transparent}
[data-theme=dark] .stock-entry-card{background:#30334d;border-color:#464968;box-shadow:0 3px 14px rgba(0,0,0,.24)}
[data-theme=dark] .stock-entry-card .form-card-title,[data-theme=dark] .entry-prices,[data-theme=dark] .entry-tracking{border-color:#464968}
[data-theme=dark] .stock-entry-card h1,[data-theme=dark] .stock-entry-card h2,[data-theme=dark] .stock-entry-card label,[data-theme=dark] .serial-area strong{color:#f1f1f7}
[data-theme=dark] .stock-entry-card p,[data-theme=dark] .stock-entry-card footer a{color:#b8b8c7}
[data-theme=dark] .stock-entry-card input,[data-theme=dark] .stock-entry-card select,[data-theme=dark] .stock-entry-card textarea{background:#3c3f5f;border-color:#565a7d;color:#f4f4f8}
[data-theme=dark] .stock-entry-card input::placeholder,[data-theme=dark] .stock-entry-card textarea::placeholder{color:#afb0c0}
[data-theme=dark] .stock-entry-card select option{background:#3c3f5f;color:#f4f4f8}
[data-theme=dark] .entry-prices input[name="sale_price"][readonly]{background:#292c45;color:#c5c6d3}
[data-theme=dark] .stock-entry-error{background:#5a3038;color:#ffd8dc}
[data-theme=dark] .technical-card{background:#30334d;border-color:#464968}
[data-theme=dark] .technical-card>header,[data-theme=dark] .stock-entry-search,[data-theme=dark] .technical-card th,[data-theme=dark] .technical-card td{border-color:#464968}
[data-theme=dark] .technical-card h1,[data-theme=dark] .technical-card th{color:#f1f1f7}
[data-theme=dark] .technical-card p,[data-theme=dark] .stock-entry-search,[data-theme=dark] .empty{color:#b8b8c7}
[data-theme=dark] .stock-entry-search input{background:#3c3f5f;border-color:#565a7d;color:#f4f4f8}
[data-theme=dark] .technical-card tbody tr:hover{background:#393c59}
</style>
<?php patient_footer(); ?>
