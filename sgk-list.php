<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_account_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT,current_account_id INTEGER NOT NULL,transaction_date TEXT NOT NULL,movement_kind TEXT NOT NULL,amount NUMERIC NOT NULL,description TEXT NOT NULL,invoice_no TEXT NULL,source_ref TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(current_account_id,source_ref,movement_kind))');
} else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_account_transactions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,current_account_id INT UNSIGNED NOT NULL,transaction_date DATE NOT NULL,movement_kind VARCHAR(20) NOT NULL,amount DECIMAL(14,2) NOT NULL,description VARCHAR(255) NOT NULL,invoice_no VARCHAR(100) NULL,source_ref VARCHAR(255) NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY current_account_source_kind_unique(current_account_id,source_ref,movement_kind),INDEX current_account_transaction_date_idx(transaction_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
$sgkAccountStatement = $pdo->prepare('SELECT id FROM current_accounts WHERE code=? LIMIT 1');
$sgkAccountStatement->execute(['CR-08']);
$sgkAccountId = (int)$sgkAccountStatement->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string)($_POST['action'] ?? '') === 'save_delivery_date') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $deliveryDate = trim((string)($_POST['delivery_date'] ?? ''));
        $detailsStatement = $pdo->prepare("SELECT sales_details,service_date FROM patient_services WHERE id=? AND service_name='Satış'");
        $detailsStatement->execute([$serviceId]);
        $saleRecord = $detailsStatement->fetch();
        if ($serviceId <= 0 || !$saleRecord) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Satış kaydı bulunamadı.']);
            exit;
        }
        if ($deliveryDate !== '' && $deliveryDate < (string)$saleRecord['service_date']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Teslim tarihi satış tarihinden küçük olamaz.']);
            exit;
        }
        $details = json_decode((string)$saleRecord['sales_details'], true);
        if (!is_array($details)) $details = [];
        if ($deliveryDate !== '' && trim((string)($details['sales_invoice_no'] ?? '')) === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Fatura No girilmeden teslim tarihi kaydedilemez.']);
            exit;
        }
        $details['sales_delivery_date'] = $deliveryDate;
        $details['sales_due_date'] = $deliveryDate === '' ? '' : (new DateTimeImmutable($deliveryDate))->modify('+3 months')->format('Y-m-d');
        $updateStatement = $pdo->prepare('UPDATE patient_services SET sales_details=? WHERE id=? AND service_name=?');
        $updateStatement->execute([json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $serviceId, 'Satış']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'due_date' => $details['sales_due_date']]);
        exit;
    }
}
$salesStatement = $pdo->query("SELECT s.id,s.patient_id,s.service_date,s.sales_details,s.contact_person,p.full_name FROM patient_services s INNER JOIN patients p ON p.id=s.patient_id WHERE s.service_name='Satış' ORDER BY s.service_date DESC,s.id DESC");
$sales = $salesStatement->fetchAll();

$money = static function (mixed $value): float {
    $text = preg_replace('/[^0-9,.-]/u', '', (string)$value);
    if (str_contains($text, ',')) $text = str_replace('.', '', $text);
    return (float)str_replace(',', '.', $text);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'toggle_sgk_collection') {
    header('Content-Type: application/json; charset=utf-8');
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $collected = (string)($_POST['collected'] ?? '') === '1';
    try {
        if (!$sgkAccountId) throw new RuntimeException('CR-08 cari kartı bulunamadı.');
        $saleStatement = $pdo->prepare("SELECT s.service_date,s.sales_details,p.full_name FROM patient_services s INNER JOIN patients p ON p.id=s.patient_id WHERE s.id=? AND s.service_name='Satış'");
        $saleStatement->execute([$serviceId]);
        $sale = $saleStatement->fetch();
        if (!$sale) throw new RuntimeException('Satış kaydı bulunamadı.');
        $details = json_decode((string)$sale['sales_details'], true);
        if (!is_array($details) || trim((string)($details['sales_delivery_date'] ?? '')) === '') throw new RuntimeException('Teslim tarihi girilmeden tahsilat yapılamaz.');
        $support = 0.0;
        foreach ($details as $key => $value) if (str_ends_with((string)$key, '_sgk')) $support += $money($value);
        if ($support <= 0) throw new RuntimeException('Tahsil edilecek SGK desteği bulunamadı.');
        $invoiceNo = trim((string)($details['sales_invoice_no'] ?? ''));
        $sourceRef = url('sgk-list.php?service_id=' . $serviceId);
        $description = 'SGK tahsilatı — ' . (string)$sale['full_name'];
        $pdo->beginTransaction();
        if ($collected) {
            $exists = $pdo->prepare("SELECT id FROM current_account_transactions WHERE current_account_id=? AND source_ref=? AND movement_kind='collection'");
            $exists->execute([$sgkAccountId, $sourceRef]);
            if (!$exists->fetchColumn()) $pdo->prepare('INSERT INTO current_account_transactions(current_account_id,transaction_date,movement_kind,amount,description,invoice_no,source_ref) VALUES(?,?,?,?,?,?,?)')->execute([$sgkAccountId, date('Y-m-d'), 'collection', $support, $description, $invoiceNo ?: null, $sourceRef]);
            $cashExists = $pdo->prepare("SELECT id FROM cash_transactions WHERE source_url=? AND transaction_type='income' LIMIT 1");
            $cashExists->execute([$sourceRef]);
            if (!$cashExists->fetchColumn()) $pdo->prepare('INSERT INTO cash_transactions(transaction_date,description,transaction_type,amount,payment_type,current_account_id,source_url,created_by,cash_register) VALUES(?,?,?,?,?,?,?,?,?)')->execute([date('Y-m-d'), $description, 'income', $support, 'cash', $sgkAccountId, $sourceRef, (int)($_SESSION['user']['id'] ?? 0), 'main']);
        } else {
            $pdo->prepare("DELETE FROM current_account_transactions WHERE current_account_id=? AND source_ref=? AND movement_kind='collection'")->execute([$sgkAccountId, $sourceRef]);
            $pdo->prepare("DELETE FROM cash_transactions WHERE source_url=? AND transaction_type='income'")->execute([$sourceRef]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'collected' => $collected, 'collection_date' => $collected ? date('Y-m-d') : '']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    }
    exit;
}
$cashStatement = $pdo->prepare("SELECT transaction_date,amount,payment_type,term_schedule FROM cash_transactions WHERE source_url=? AND transaction_type='income' ORDER BY transaction_date,id");
foreach ($sales as &$sale) {
    $details = json_decode((string)$sale['sales_details'], true);
    if (!is_array($details)) $details = [];
    $sale['invoice_no'] = trim((string)($details['sales_invoice_no'] ?? ''));
    $sale['delivery_date'] = trim((string)($details['sales_delivery_date'] ?? ''));
    $sale['sale_total'] = $money($details['sales_payment_amount'] ?? 0);
    $sale['sgk_support'] = 0.0;
    foreach ($details as $key => $value) if (str_ends_with((string)$key, '_sgk')) $sale['sgk_support'] += $money($value);
    $sale['sgk_collection'] = 0.0;
    $sgkSourceRef = url('sgk-list.php?service_id=' . (int)$sale['id']);
    if ($sgkAccountId && $sale['sgk_support'] > 0) {
        $debtDescription = 'SGK borç kaydı — ' . $sale['full_name'];
        $debtExists = $pdo->prepare("SELECT id FROM current_account_transactions WHERE current_account_id=? AND source_ref=? AND movement_kind='debit'");
        $debtExists->execute([$sgkAccountId, $sgkSourceRef]);
        if ($debtExists->fetchColumn()) {
            $pdo->prepare("UPDATE current_account_transactions SET transaction_date=?,amount=?,description=?,invoice_no=? WHERE current_account_id=? AND source_ref=? AND movement_kind='debit'")->execute([$sale['service_date'], $sale['sgk_support'], $debtDescription, $sale['invoice_no'] ?: null, $sgkAccountId, $sgkSourceRef]);
        } else {
            $pdo->prepare('INSERT INTO current_account_transactions(current_account_id,transaction_date,movement_kind,amount,description,invoice_no,source_ref) VALUES(?,?,?,?,?,?,?)')->execute([$sgkAccountId, $sale['service_date'], 'debit', $sale['sgk_support'], $debtDescription, $sale['invoice_no'] ?: null, $sgkSourceRef]);
        }
        $collectionStatement = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM current_account_transactions WHERE current_account_id=? AND source_ref=? AND movement_kind='collection'");
        $collectionStatement->execute([$sgkAccountId, $sgkSourceRef]);
        $sale['sgk_collection'] = (float)$collectionStatement->fetchColumn();
    }
    $sale['due_date'] = trim((string)($details['sales_due_date'] ?? ''));
    $sale['collection'] = 0.0;
    $cashStatement->execute([url('patient-followup.php?id=' . (int)$sale['patient_id'])]);
    foreach ($cashStatement->fetchAll() as $cash) {
        if ($cash['payment_type'] === 'term') {
            foreach ((array)json_decode((string)$cash['term_schedule'], true) as $term) {
                if (!$sale['due_date'] && !empty($term['date'])) $sale['due_date'] = (string)$term['date'];
                if (!empty($term['paid'])) $sale['collection'] += $money($term['amount'] ?? 0);
            }
        } else $sale['collection'] += (float)$cash['amount'];
    }
}
unset($sale);
$sgkStatuses = [];
foreach ($sales as $sale) $sgkStatuses[(string)$sale['id']] = ['support' => (float)$sale['sgk_support'], 'collected' => (float)$sale['sgk_collection'], 'contact_person' => trim((string)($sale['contact_person'] ?? ''))];

patient_header('SGK Listesi', 'patients');
?>
<style>
.sgk-page{width:100%!important;max-width:1220px!important;margin:0 auto!important;padding:96px 20px 48px!important}.sgk-list{width:calc(100% - 64px);margin:28px 32px 48px;border:1px solid var(--line);border-radius:8px;background:var(--card);overflow:hidden}.sgk-list-head{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.sgk-list-head h2{margin:0;font-size:20px;font-weight:500}.sgk-list-head p{margin:4px 0 0;color:var(--muted);font-size:13px}.sgk-list table{width:100%;border-collapse:collapse}.sgk-list th,.sgk-list td{padding:13px 18px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}.sgk-list th{font-size:12px;color:var(--muted)}.sgk-list tbody tr[data-edit-url]{cursor:pointer}.sgk-list tbody tr[data-edit-url]:hover{background:rgba(32,164,71,.05)}.sgk-list td.money{color:#19a94b;font-weight:700}.sgk-list .delivery-date{width:130px;min-height:36px;padding:6px 8px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.sgk-list td.collection{text-align:center}.sgk-list td.collection input{width:18px;height:18px;accent-color:#19a94b;cursor:pointer}.sgk-empty{text-align:center;color:var(--muted)}@media(max-width:720px){.sgk-page{padding:92px 12px 30px!important}.sgk-list{width:auto;margin:20px 12px 30px;overflow:auto}.sgk-list table{min-width:880px}.sgk-list-head{padding:0 16px}}
</style>
<main class="patient-container sgk-page"><section class="sgk-list"><div class="sgk-list-head"><div><h2>SGK Listesi</h2><p><?=count($sales)?> kayıt</p></div></div><table><thead><tr><th>SATIŞ TRH</th><th>TESLİM TRH</th><th>FATURA NO</th><th>AD SOYAD</th><th>SGK DESTEĞİ</th><th>VADE TRH</th><th>TAHSİLAT</th></tr></thead><tbody><?php foreach($sales as $sale):?><tr data-edit-url="<?=e(url('patient-followup.php?id=' . (int)$sale['patient_id'] . '&edit=' . (int)$sale['id'] . '&open_sales_details=1'))?>"><td><?=e(format_date_tr((string)$sale['service_date']))?></td><td><input class="delivery-date" type="date" value="<?=e($sale['delivery_date'])?>" data-service-id="<?=(int)$sale['id']?>" data-sale-date="<?=e((string)$sale['service_date'])?>" data-saved-value="<?=e($sale['delivery_date'])?>" aria-label="Teslim tarihi"></td><td><?=e($sale['invoice_no'] ?: '—')?></td><td><?=e($sale['full_name'])?></td><td class="money"><?=$sale['sgk_support'] > 0 ? number_format($sale['sgk_support'],2,',','.') . ' ₺' : '—'?></td><td data-due-date><?=e($sale['due_date'] ? format_date_tr($sale['due_date']) : '—')?></td><td class="collection"><input type="checkbox" aria-label="Tahsilat tamamlandı" <?=($sale['sale_total'] > 0 && $sale['collection'] >= $sale['sale_total'] - .009) ? 'checked' : ''?>></td></tr><?php endforeach;if(!$sales):?><tr><td colspan="7" class="sgk-empty">Henüz satış kaydı bulunmuyor.</td></tr><?php endif?></tbody></table></section></main>
<script>document.querySelectorAll('.delivery-date').forEach(input=>input.addEventListener('change',async()=>{if(input.value&&input.dataset.saleDate&&input.value<input.dataset.saleDate){alert('Teslim tarihi satış tarihinden küçük olamaz.');input.value=input.dataset.savedValue||'';return;}const data=new FormData();data.set('csrf',<?=json_encode(csrf())?>);data.set('action','save_delivery_date');data.set('service_id',input.dataset.serviceId||'');data.set('delivery_date',input.value);input.disabled=true;try{const response=await fetch(location.href,{method:'POST',body:data,credentials:'same-origin'}),result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Teslim tarihi kaydedilemedi.');input.dataset.savedValue=input.value;input.closest('tr')?.querySelector('[data-due-date]')&&(input.closest('tr').querySelector('[data-due-date]').textContent=result.due_date?result.due_date.split('-').reverse().join('.'):'—');}catch(error){alert(error.message||'Teslim tarihi kaydedilemedi.');input.value=input.dataset.savedValue||'';}finally{input.disabled=false;}}));document.querySelectorAll('.sgk-list tbody tr[data-edit-url]').forEach(row=>row.addEventListener('dblclick',event=>{if(event.target.closest('input,button,a,label'))return;location.href=row.dataset.editUrl;}));</script>
<style>.sgk-list tbody tr.missing-delivery td{color:#e04f55;background:#fff7f7}.sgk-list tbody tr.missing-delivery td.money{color:#e04f55}.sgk-list tbody tr.missing-delivery .delivery-date{border-color:#e04f55;color:#e04f55;background:#fff}.sgk-list tbody tr.missing-delivery:hover{background:#fff0f0}</style>
<script>(()=>{const sync=input=>{const row=input.closest('tr');if(!row)return;const missing=!input.value;row.classList.toggle('missing-delivery',missing);const collection=row.querySelector('.collection input');if(collection)collection.disabled=missing;};document.querySelectorAll('.delivery-date').forEach(input=>{sync(input);input.addEventListener('change',()=>setTimeout(()=>sync(input),0));});})();</script>
<script>(()=>{const statuses=<?=json_encode($sgkStatuses)?>,csrf=<?=json_encode(csrf())?>;document.querySelectorAll('.collection input').forEach(check=>{const row=check.closest('tr'),dateInput=row?.querySelector('.delivery-date'),serviceId=dateInput?.dataset.serviceId||'',status=statuses[serviceId]||{support:0,collected:0};const sync=()=>{check.checked=Number(status.collected)>=Number(status.support)-.009&&Number(status.support)>0;check.disabled=!dateInput?.value||Number(status.support)<=0;};sync();dateInput?.addEventListener('change',()=>setTimeout(sync,0));check.addEventListener('change',async()=>{const requested=check.checked;if(!serviceId)return;check.disabled=true;try{const data=new FormData();data.set('csrf',csrf);data.set('action','toggle_sgk_collection');data.set('service_id',serviceId);data.set('collected',requested?'1':'0');const response=await fetch(location.href,{method:'POST',body:data,credentials:'same-origin'}),result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Tahsilat kaydedilemedi.');status.collected=result.collected?status.support:0;sync();}catch(error){alert(error.message||'Tahsilat kaydedilemedi.');sync();}finally{if(!check.disabled||dateInput?.value)sync();}});});})();</script>
<script>document.querySelectorAll('.sgk-list tbody tr[data-edit-url]').forEach(row=>row.addEventListener('dblclick',event=>{if(event.target.closest('input,button,a,label'))return;event.stopImmediatePropagation();const target=new URL(row.dataset.editUrl,location.origin);target.searchParams.set('from_sgk_list','1');location.href=target.href;},true));</script>
<script>document.querySelectorAll('.delivery-date').forEach(input=>input.addEventListener('change',event=>{const invoice=input.closest('tr')?.children[2]?.textContent.trim()||'';if(input.value&&(!invoice||invoice==='—')){event.stopImmediatePropagation();alert('Fatura No girilmeden teslim tarihi kaydedilemez.');input.value=input.dataset.savedValue||'';}},true));</script>
<script>(()=>{const statuses=<?=json_encode($sgkStatuses)?>,header=document.querySelector('.sgk-list thead tr');if(header&&!header.querySelector('[data-sgk-related]')){const cell=document.createElement('th');cell.dataset.sgkRelated='1';cell.textContent='İLGİLİ';header.children[3]?.after(cell);}document.querySelectorAll('.sgk-list tbody tr').forEach(row=>{const serviceId=row.querySelector('.delivery-date')?.dataset.serviceId;if(!serviceId||row.querySelector('[data-sgk-related]'))return;const cell=document.createElement('td');cell.dataset.sgkRelated='1';cell.textContent=statuses[serviceId]?.contact_person||'—';row.children[3]?.after(cell);});})();</script>
<?php patient_footer(); ?>
