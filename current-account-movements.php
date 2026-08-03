<?php
declare(strict_types=1);
require __DIR__ . '/config.php'; require_login(); require __DIR__ . '/patient-layout.php';
function current_movement_parse_amount(string $value): float { $value = trim(str_replace(' ', '', $value)); if (str_contains($value, ',')) return (float)str_replace(',', '.', str_replace('.', '', $value)); return (float)str_replace('.', '', $value); }
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;$pdo=db();$q=$pdo->prepare('SELECT * FROM current_accounts WHERE id=?');$q->execute([$id]);$account=$q->fetch();if(!$account){http_response_code(404);exit('Cari kart bulunamadı.');}$rows=[];try{$q=$pdo->prepare('SELECT m.*,s.stock_code,s.stock_name FROM stock_movements m JOIN stock_cards s ON s.id=m.stock_id WHERE m.current_account_id=? ORDER BY m.movement_date DESC,m.id DESC');$q->execute([$id]);$rows=$q->fetchAll();}catch(Throwable $e){}patient_header('Cari Hareketleri','cash');
$mailOrderRows = [];
try {
    $mailOrderStatement = $pdo->prepare("SELECT id,transaction_date,description,amount,source_url FROM cash_transactions WHERE current_account_id=? AND transaction_type='income' AND payment_type='mail_order' ORDER BY transaction_date DESC,id DESC");
    $mailOrderStatement->execute([$id]);
    $mailOrderRows = $mailOrderStatement->fetchAll();
} catch (Throwable $e) {}
$sgkRows = [];
try {
    $sgkStatement = $pdo->prepare("SELECT transaction_date,movement_kind,amount,description,invoice_no,source_ref FROM current_account_transactions WHERE current_account_id=? ORDER BY transaction_date DESC,id DESC");
    $sgkStatement->execute([$id]);
    $sgkRows = $sgkStatement->fetchAll();
} catch (Throwable $e) {}
foreach ($mailOrderRows as &$mailOrder) {
    $mailOrder['invoice_no'] = '';
    $sourceQuery = [];
    parse_str((string)parse_url((string)($mailOrder['source_url'] ?? ''), PHP_URL_QUERY), $sourceQuery);
    $patientId = (int)($sourceQuery['id'] ?? 0);
    if (!$patientId) continue;
    try {
        $invoiceStatement = $pdo->prepare("SELECT sales_details FROM patient_services WHERE patient_id=? AND service_name='Satış' ORDER BY id DESC LIMIT 1");
        $invoiceStatement->execute([$patientId]);
        $salesDetails = json_decode((string)$invoiceStatement->fetchColumn(), true);
        if (is_array($salesDetails)) $mailOrder['invoice_no'] = trim((string)($salesDetails['sales_invoice_no'] ?? ''));
    } catch (Throwable $e) {}
}
unset($mailOrder);
$invoiceFilter = trim((string)($_GET['invoice'] ?? ''));
if ($invoiceFilter !== '') $rows = array_values(array_filter($rows, static fn(array $row): bool => trim((string)($row['invoice_no'] ?? '')) === $invoiceFilter));
$invoiceTotalTable = 'current_account_invoice_totals';
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_account_invoice_totals (id INTEGER PRIMARY KEY AUTOINCREMENT, current_account_id INTEGER NOT NULL, invoice_no VARCHAR(100) NOT NULL, gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0, UNIQUE(current_account_id, invoice_no))');
} else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_account_invoice_totals (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, current_account_id INT UNSIGNED NOT NULL, invoice_no VARCHAR(100) NOT NULL, gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0, UNIQUE KEY current_account_invoice_unique (current_account_id, invoice_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
$itemDiscountTable = 'current_account_movement_discounts';
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_account_movement_discounts (movement_id INTEGER PRIMARY KEY, discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0)');
} else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_account_movement_discounts (movement_id INT UNSIGNED PRIMARY KEY, discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invoice_gross') {
    verify_csrf();
    $invoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
    $grossAmount = current_movement_parse_amount((string)($_POST['gross_amount'] ?? '0'));
    if ($invoiceNo !== '' && $grossAmount >= 0) {
        $discountRates = json_decode((string)($_POST['discount_rates'] ?? '{}'), true);
        $pdo->beginTransaction();
        try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $pdo->prepare('INSERT INTO current_account_invoice_totals(current_account_id,invoice_no,gross_amount) VALUES(?,?,?) ON CONFLICT(current_account_id,invoice_no) DO UPDATE SET gross_amount=excluded.gross_amount');
        } else {
            $statement = $pdo->prepare('INSERT INTO current_account_invoice_totals(current_account_id,invoice_no,gross_amount) VALUES(?,?,?) ON DUPLICATE KEY UPDATE gross_amount=VALUES(gross_amount)');
        }
        $statement->execute([$id, $invoiceNo, $grossAmount]);
        if (is_array($discountRates)) foreach ($discountRates as $movementId => $discountRate) {
            $movementId = (int)$movementId;
            if ($movementId < 1) continue;
            $owner = $pdo->prepare('SELECT id FROM stock_movements WHERE id=? AND current_account_id=? AND invoice_no=?');
            $owner->execute([$movementId, $id, $invoiceNo]);
            if (!$owner->fetchColumn()) continue;
            $discountRate = max(0, min(100, (float)str_replace(',', '.', (string)$discountRate)));
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $statement = $pdo->prepare('INSERT INTO current_account_movement_discounts(movement_id,discount_rate) VALUES(?,?) ON CONFLICT(movement_id) DO UPDATE SET discount_rate=excluded.discount_rate');
            } else {
                $statement = $pdo->prepare('INSERT INTO current_account_movement_discounts(movement_id,discount_rate) VALUES(?,?) ON DUPLICATE KEY UPDATE discount_rate=VALUES(discount_rate)');
            }
            $statement->execute([$movementId, $discountRate]);
        }
        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_item_discount') {
    verify_csrf();
    $movementId = (int)($_POST['movement_id'] ?? 0);
    $discountRate = (float)str_replace(',', '.', (string)($_POST['discount_rate'] ?? '0'));
    if ($movementId > 0) {
        $owner = $pdo->prepare('SELECT id FROM stock_movements WHERE id=? AND current_account_id=?');
        $owner->execute([$movementId, $id]);
        if ($owner->fetchColumn()) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $pdo->prepare('INSERT INTO current_account_movement_discounts(movement_id,discount_rate) VALUES(?,?) ON CONFLICT(movement_id) DO UPDATE SET discount_rate=excluded.discount_rate');
        } else {
            $statement = $pdo->prepare('INSERT INTO current_account_movement_discounts(movement_id,discount_rate) VALUES(?,?) ON DUPLICATE KEY UPDATE discount_rate=VALUES(discount_rate)');
        }
        $statement->execute([$movementId, max(0, min(100, $discountRate))]);
        }
    }
    $q=$pdo->prepare('SELECT m.*,s.stock_code,s.stock_name FROM stock_movements m JOIN stock_cards s ON s.id=m.stock_id WHERE m.current_account_id=? ORDER BY m.movement_date DESC,m.id DESC');
    $q->execute([$id]);
    $rows=$q->fetchAll();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_item_discounts') {
    verify_csrf();
    $discountRates = json_decode((string)($_POST['discount_rates'] ?? '{}'), true);
    if (is_array($discountRates)) {
        $pdo->beginTransaction();
        try {
            foreach ($discountRates as $movementId => $discountRate) {
                $movementId = (int)$movementId;
                $discountRate = max(0, min(100, (float)str_replace(',', '.', (string)$discountRate)));
                if ($movementId < 1) continue;
                $owner = $pdo->prepare('SELECT id FROM stock_movements WHERE id=? AND current_account_id=?');
                $owner->execute([$movementId, $id]);
                if (!$owner->fetchColumn()) continue;
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $statement = $pdo->prepare('INSERT INTO current_account_movement_discounts(movement_id,discount_rate) VALUES(?,?) ON CONFLICT(movement_id) DO UPDATE SET discount_rate=excluded.discount_rate');
                } else {
                    $statement = $pdo->prepare('INSERT INTO current_account_movement_discounts(movement_id,discount_rate) VALUES(?,?) ON DUPLICATE KEY UPDATE discount_rate=VALUES(discount_rate)');
                }
                $statement->execute([$movementId, $discountRate]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
$grossStatement = $pdo->prepare('SELECT invoice_no,gross_amount FROM current_account_invoice_totals WHERE current_account_id=?');
$grossStatement->execute([$id]);
$invoiceGrossAmounts = $grossStatement->fetchAll(PDO::FETCH_KEY_PAIR);
$discountStatement = $pdo->prepare('SELECT d.movement_id,d.discount_rate FROM current_account_movement_discounts d JOIN stock_movements m ON m.id=d.movement_id WHERE m.current_account_id=?');
$discountStatement->execute([$id]);
$itemDiscountRates = $discountStatement->fetchAll(PDO::FETCH_KEY_PAIR);
$groupedRows = [];
foreach ($rows as $row) {
    $invoiceNo = trim((string)($row['invoice_no'] ?? ''));
    $key = $invoiceNo === '' ? 'row-' . (int)$row['id'] : $row['movement_type'] . '|' . $invoiceNo;
    if (!isset($groupedRows[$key])) {
        $groupedRows[$key] = ['date' => $row['movement_date'], 'movement_type' => $row['movement_type'], 'invoice_no' => $invoiceNo, 'quantity' => 0, 'total_price' => 0.0, 'total_price_with_vat' => 0.0, 'stocks' => [], 'items' => []];
    }
    $groupedRows[$key]['quantity'] += (int)$row['quantity'];
    $groupedRows[$key]['total_price'] += (float)($row['purchase_price'] ?? 0);
    $groupedRows[$key]['total_price_with_vat'] += (float)($row['purchase_price'] ?? 0) * (1 + ((float)($row['vat_rate'] ?? 0) / 100));
    $groupedRows[$key]['stocks'][] = $row['stock_code'] . ' — ' . $row['stock_name'];
    $groupedRows[$key]['items'][] = ['id' => (int)$row['id'], 'stock' => $row['stock_code'] . ' — ' . $row['stock_name'], 'quantity' => (int)$row['quantity'], 'discount_rate' => (float)($itemDiscountRates[$row['id']] ?? 0), 'price' => (float)($row['purchase_price'] ?? 0)];
}
$groupedRows = array_values($groupedRows);
foreach ($groupedRows as &$groupedRow) $groupedRow['gross_amount'] = $groupedRow['invoice_no'] !== '' ? (float)($invoiceGrossAmounts[$groupedRow['invoice_no']] ?? 0) : 0.0;
unset($groupedRow);
$isSgkAccount = (string)$account['code'] === 'CR-08';
$accountBalance = 0.0;
foreach ($groupedRows as $groupedRow) $accountBalance += ($groupedRow['movement_type'] === 'Giriş' ? 1 : -1) * (float)$groupedRow['total_price_with_vat'];
foreach ($mailOrderRows as $mailOrder) $accountBalance -= (float)$mailOrder['amount'];
foreach ($sgkRows as $sgkRow) $accountBalance += $sgkRow['movement_kind'] === 'debit' ? (float)$sgkRow['amount'] : -(float)$sgkRow['amount'];
?>
<main class="patient-container cam-page<?= $isSgkAccount ? ' sgk-account' : '' ?>">
<style>.sgk-account .cam-card th:nth-child(6),.sgk-account .cam-card td:nth-child(6),.sgk-account .cam-card th:nth-child(7),.sgk-account .cam-card td:nth-child(7),.sgk-account .cam-card th:nth-child(8),.sgk-account .cam-card td:nth-child(8),.sgk-account .cam-card th:nth-child(9),.sgk-account .cam-card td:nth-child(9),.sgk-account .cam-card th:nth-child(11),.sgk-account .cam-card td:nth-child(11){display:none}.sgk-account .cam-card table{min-width:680px}.sgk-account .sgk-debit-row td:nth-child(5){color:#e04f55;font-weight:700}</style>
<?php if ($isSgkAccount): ?><script>document.addEventListener('DOMContentLoaded',()=>{const header=document.querySelector('.sgk-account .cam-card th:nth-child(5)');if(header)header.textContent='TOPLAM';});</script><?php endif; ?>
  <section class="cam-card">
    <header><div><h1>Cari Hareketleri</h1><p><?=e($account['code'].' — '.$account['title'])?></p></div><div class="account-balance<?= $accountBalance > 0 ? ' is-debit' : '' ?>"><span>Bakiye</span><strong><?=e(number_format($accountBalance, 2, ',', '.'))?> TL</strong></div></header>
    <div><table>
      <thead><tr><th><button type="button" class="current-date-sort" aria-label="Tarihe göre sırala">TARİH <i class="ti tabler-sort-descending"></i></button></th><th>HAREKET</th><th>FATURA NO</th><th>MİKTAR</th><th>T. TUTAR</th><th>T. TUTAR (KDV'Lİ)</th><th>İSKONTOSUZ FATURA TUTARI</th><th>İSKONTO</th><th>ORT İSK.</th><th>ÖDEME TİPİ</th><th aria-label="İşlemler"></th></tr></thead>
      <tbody>
      <?php foreach ($sgkRows as $sgkRow): ?>
        <tr class="sgk-current-row <?= $sgkRow['movement_kind'] === 'debit' ? 'sgk-debit-row' : 'sgk-collection-row' ?>">
          <td><?=e(format_date_tr($sgkRow['transaction_date']))?></td><td class="movement-<?= $sgkRow['movement_kind'] === 'debit' ? 'entry' : 'exit' ?>"><?= $sgkRow['movement_kind'] === 'debit' ? 'Borç' : 'Tahsilat' ?></td><td><?=e($sgkRow['invoice_no'] ?: '—')?></td><td>1</td>
          <td><?=e(number_format((float)$sgkRow['amount'], 2, ',', '.'))?> TL</td><td><?=e(number_format((float)$sgkRow['amount'], 2, ',', '.'))?> TL</td><td>—</td><td>—</td><td>—</td><td>SGK</td><td>—</td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($mailOrderRows as $mailOrder): ?>
        <tr class="mail-order-outgoing">
          <td><?=e(format_date_tr($mailOrder['transaction_date']))?></td><td>Çıkış</td><td><span class="mail-order-invoice" title="<?=e($mailOrder['description'])?>"><?=e($mailOrder['invoice_no'] ?: '—')?></span></td><td>—</td>
          <td><?=e(number_format((float)$mailOrder['amount'], 2, ',', '.'))?> TL</td><td><?=e(number_format((float)$mailOrder['amount'], 2, ',', '.'))?> TL</td><td>—</td><td>—</td><td>—</td><td>Mail Order</td><td>—</td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($groupedRows as $index => $row): ?>
        <tr>
          <td><?=e(format_date_tr($row['date']))?></td><td class="movement-<?= $row['movement_type'] === 'Giriş' ? 'entry' : 'exit' ?>"><?=e($row['movement_type'])?></td><td><?=e($row['invoice_no'] ?: '—')?></td><td><?=e((string)$row['quantity'])?></td>
          <td class="<?= $row['movement_type'] === 'Giriş' ? 'total-price-entry' : '' ?>"><?=e(number_format($row['total_price'], 2, ',', '.'))?> TL</td><td class="<?= $row['movement_type'] === 'Giriş' ? 'total-price-entry' : '' ?>"><?=e(number_format($row['total_price_with_vat'], 2, ',', '.'))?> TL</td><td><form method="post" class="invoice-gross-inline"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_invoice_gross"><input type="hidden" name="invoice_no" value="<?=e($row['invoice_no'])?>"><input name="gross_amount" type="text" inputmode="decimal" value="<?=e(number_format($row['gross_amount'], 2, ',', '.'))?>"><button title="Kaydet" aria-label="İskontosuz fatura tutarını kaydet"><i class="ti tabler-device-floppy"></i></button></form></td><td><?=e(number_format($row['gross_amount'] > 0 ? $row['gross_amount'] - $row['total_price'] : 0, 2, ',', '.'))?> TL</td><td><?=e(number_format($row['gross_amount'] > 0 ? (($row['gross_amount'] - $row['total_price']) / $row['gross_amount']) * 100 : 0, 2, ',', '.'))?>%</td>
          <td>—</td><td><button type="button" class="invoice-details-toggle" data-invoice-row="<?=$index?>" title="Fatura kalemlerini göster" aria-label="Fatura kalemlerini göster">+</button></td>
        </tr>
        <tr class="invoice-detail-row" data-invoice-detail="<?=$index?>" hidden><td colspan="11"><table class="invoice-items"><thead><tr><th>STOK KARTI</th><th>MİKTAR</th><th>İSKONTO ORANI</th><th>FİYAT</th></tr></thead><tbody><?php foreach ($row['items'] as $item): ?><tr><td><?=e($item['stock'])?></td><td><?=e((string)$item['quantity'])?></td><td><form method="post" class="item-discount-inline"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_item_discount"><input type="hidden" name="movement_id" value="<?=e((string)$item['id'])?>"><input name="discount_rate" type="number" min="0" max="100" step="0.01" value="<?=e((string)$item['discount_rate'])?>"><span>%</span></form></td><td><?=e(number_format($item['price'], 2, ',', '.'))?> TL</td></tr><?php endforeach ?></tbody></table></td></tr>
      <?php endforeach; if (!$groupedRows && !$mailOrderRows && !$sgkRows): ?>
        <tr><td colspan="11" class="empty">Bu cariye ait hareket bulunmuyor.</td></tr>
      <?php endif ?>
      </tbody>
    </table></div>
  </section>
</main>
<style>.cam-card>header{display:flex;align-items:center;justify-content:space-between;gap:24px}.account-balance{display:flex;flex-direction:column;align-items:flex-end;gap:4px;min-width:160px;padding:9px 14px;border:1px solid #d9e7dc;border-radius:7px;background:#fbfefb}.account-balance span{font-size:12px;color:#7b7b8d}.account-balance strong{font-size:18px;color:#159447}.account-balance.is-debit{border-color:#f0c7ca;background:#fff7f7}.account-balance.is-debit strong{color:#e04f55}@media(max-width:620px){.cam-card>header{align-items:flex-start;flex-direction:column}.account-balance{align-items:flex-start}}</style>
<script>const parseAmount=value=>{value=String(value||'').replace(/\s/g,'');return Number(value.includes(',')?value.replaceAll('.','').replace(',','.'):value.replaceAll('.',''))||0},formatAmount=value=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(parseAmount(value));document.querySelectorAll('input[name="gross_amount"]').forEach(input=>input.addEventListener('blur',()=>input.value=formatAmount(input.value)));document.querySelectorAll('.invoice-gross-inline').forEach(form=>form.addEventListener('submit',()=>{const detail=form.closest('tr')?.nextElementSibling;if(!detail?.classList.contains('invoice-detail-row'))return;const values={};detail.querySelectorAll('.item-discount-inline').forEach(itemForm=>{values[itemForm.querySelector('[name="movement_id"]').value]=itemForm.querySelector('[name="discount_rate"]').value});let input=form.querySelector('[name="discount_rates"]');if(!input){input=document.createElement('input');input.type='hidden';input.name='discount_rates';form.append(input)}input.value=JSON.stringify(values)}));const saveDiscount=form=>fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin'});document.querySelectorAll('.invoice-details-toggle').forEach(button=>button.addEventListener('click',()=>{const detail=document.querySelector(`[data-invoice-detail="${button.dataset.invoiceRow}"]`);if(!detail)return;if(!detail.hidden){const values={};detail.querySelectorAll('.item-discount-inline').forEach(form=>values[form.querySelector('[name="movement_id"]').value]=form.querySelector('[name="discount_rate"]').value);const batch=document.createElement('form');batch.method='post';batch.action=location.href;[['csrf',detail.querySelector('[name="csrf"]').value],['action','save_item_discounts'],['discount_rates',JSON.stringify(values)]].forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;batch.append(input)});document.body.append(batch);batch.submit();return;}detail.hidden=false;button.textContent='−';button.title='Fatura kalemlerini gizle'}));document.querySelectorAll('.item-discount-inline input[name="discount_rate"]').forEach(input=>{let timer;input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{const form=input.form;if(!form||!input.validity.valid)return;saveDiscount(form);},400)});input.addEventListener('change',()=>{clearTimeout(timer);const form=input.form;if(form&&input.validity.valid)saveDiscount(form);});});</script>
<script>if(<?=json_encode($invoiceFilter !== '')?>)document.querySelector('.invoice-details-toggle')?.click();</script>
<script>(()=>{const button=document.querySelector('.current-date-sort'),body=document.querySelector('.cam-card tbody');if(!button||!body)return;let ascending=false;const dateValue=row=>{const [day,month,year]=String(row.cells[0]?.textContent||'').trim().split('.');return Date.UTC(Number(year)||0,(Number(month)||1)-1,Number(day)||0)};button.addEventListener('click',()=>{ascending=!ascending;const records=[...body.children].filter(row=>row.tagName==='TR'&&!row.classList.contains('invoice-detail-row')&&row.cells.length>1&&!row.querySelector('.empty')).map(row=>({row,detail:row.nextElementSibling?.classList.contains('invoice-detail-row')?row.nextElementSibling:null}));records.sort((left,right)=>(dateValue(left.row)-dateValue(right.row))*(ascending?1:-1));records.forEach(({row,detail})=>{body.append(row);if(detail)body.append(detail)});button.querySelector('i').className='ti '+(ascending?'tabler-sort-ascending':'tabler-sort-descending')})})();</script>
<style>.invoice-gross-inline{display:flex;align-items:center;gap:6px}.invoice-gross-inline input{width:115px;height:34px;padding:0 8px;border:1px solid #d2d2dc;border-radius:6px;font:inherit}.invoice-gross-inline button,.item-discount-inline button{display:grid;place-items:center;width:34px;height:34px;padding:0;border:0;border-radius:6px;background:#19a94b;color:#fff;cursor:pointer}.item-discount-inline{display:flex;align-items:center;gap:5px}.item-discount-inline input{width:70px;height:32px;padding:0 7px;border:1px solid #d2d2dc;border-radius:6px;font:inherit}.invoice-gross-toggle{display:inline-grid;place-items:center;width:32px;height:32px;margin-left:6px;border:0;border-radius:6px;background:#19a94b;color:#fff;cursor:pointer}.invoice-gross-form{display:flex;align-items:end;gap:12px;padding:14px 18px;background:#edf9f0}.invoice-gross-form label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700}.invoice-gross-form input{height:38px;padding:0 10px;border:1px solid #d2d2dc;border-radius:6px;font:inherit}.invoice-gross-form button{height:38px;padding:0 14px;border:0;border-radius:6px;background:#19a94b;color:#fff;font-weight:700;cursor:pointer}</style>
<style>.current-date-sort{display:inline-flex;align-items:center;gap:4px;padding:0;border:0;background:transparent;color:inherit;font:inherit;cursor:pointer}.current-date-sort:hover{color:#159447}</style>
<style>.cam-page{max-width:1500px!important;margin:auto;padding:28px 20px}.cam-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;overflow:hidden;box-shadow:0 3px 12px #1e283c0f}.cam-card header{padding:22px 24px;border-bottom:1px solid #e1e2e8}.cam-card h1{margin:0 0 5px;font-size:21px}.cam-card p{margin:0;color:#7b7b8d}.cam-card>div{overflow:auto}.cam-card table{width:100%;min-width:1050px;border-collapse:collapse}.cam-card th,.cam-card td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left}.cam-card th{font-size:12px;color:#6d6b7f}.empty{text-align:center;color:#7b7b8d}.total-price-entry,.movement-entry{color:#e04f55;font-weight:700}.movement-exit,.mail-order-outgoing td:nth-child(2),.mail-order-outgoing td:nth-child(5),.mail-order-outgoing td:nth-child(6){color:#159447;font-weight:700}.invoice-details-toggle{display:inline-grid;place-items:center;width:32px;height:32px;border:0;border-radius:6px;background:#f5a33b;color:#000;font-size:20px;font-weight:700;cursor:pointer}.invoice-detail-row td{padding:0;background:#fff8ed}.invoice-items{width:100%;min-width:0!important}.invoice-items th,.invoice-items td{padding:10px 14px}.invoice-items th{background:#fff0d9}.invoice-items th:last-child,.invoice-items td:last-child{text-align:right}</style><?php patient_footer(); ?>
