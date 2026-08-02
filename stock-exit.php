<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$movements = $pdo->query("SELECT m.*, s.stock_code, s.stock_name, s.stock_type, s.brand, s.model
    FROM stock_movements m
    INNER JOIN stock_cards s ON s.id = m.stock_id
    WHERE m.movement_type = 'Çıkış'
    ORDER BY m.movement_date DESC, m.id DESC")->fetchAll();
$stockBalances = [];
foreach ($pdo->query("SELECT stock_id, SUM(CASE WHEN movement_type='Giriş' THEN quantity WHEN movement_type='Çıkış' THEN -quantity ELSE 0 END) AS balance FROM stock_movements GROUP BY stock_id") as $balance) {
    $stockBalances[(int)$balance['stock_id']] = (int)$balance['balance'];
}
$totalSalesByProduct = [];
foreach ($pdo->query("SELECT m.stock_id, m.quantity, s.stock_type, s.brand, s.model FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE m.movement_type='Çıkış'") as $saleMovement) {
    $key = implode('|', [(string)$saleMovement['stock_type'], trim((string)$saleMovement['brand']) ?: '#' . $saleMovement['stock_id'], trim((string)$saleMovement['model']) ?: '#' . $saleMovement['stock_id']]);
    $totalSalesByProduct[$key] = ($totalSalesByProduct[$key] ?? 0) + (int)$saleMovement['quantity'];
}
$totalEntriesByProduct = [];
foreach ($pdo->query("SELECT m.stock_id, m.quantity, s.stock_type, s.brand, s.model FROM stock_movements m INNER JOIN stock_cards s ON s.id=m.stock_id WHERE m.movement_type='GiriÅŸ'") as $entryMovement) {
    $key = implode('|', [(string)$entryMovement['stock_type'], trim((string)$entryMovement['brand']) ?: '#' . $entryMovement['stock_id'], trim((string)$entryMovement['model']) ?: '#' . $entryMovement['stock_id']]);
    $totalEntriesByProduct[$key] = ($totalEntriesByProduct[$key] ?? 0) + (int)$entryMovement['quantity'];
}
$totalEntriesByProduct = $totalSalesByProduct;
foreach ($pdo->query('SELECT id,stock_type,brand,model FROM stock_cards') as $stockCard) {
    $stockId = (int)$stockCard['id'];
    $key = implode('|', [(string)$stockCard['stock_type'], trim((string)$stockCard['brand']) ?: '#' . $stockId, trim((string)$stockCard['model']) ?: '#' . $stockId]);
    $totalEntriesByProduct[$key] = ($totalEntriesByProduct[$key] ?? 0) + (int)($stockBalances[$stockId] ?? 0);
}
$salesByRecordNo = [];
foreach ($pdo->query("SELECT ps.id, ps.patient_id, ps.record_no, ps.sales_details, p.full_name AS patient_name FROM patient_services ps LEFT JOIN patients p ON p.id=ps.patient_id WHERE COALESCE(ps.record_no, '') <> ''") as $sale) {
    $details = json_decode((string)($sale['sales_details'] ?? ''), true);
    $sale['invoice_no'] = is_array($details) ? trim((string)($details['sales_invoice_no'] ?? '')) : '';
    $salesByRecordNo[(string)$sale['record_no']] = $sale;
}

function stock_exit_money($value): string {
    if ($value === null || $value === '' || (float)$value === 0.0) return '—';
    return number_format((float)$value, 2, ',', '.') . ' TL';
}

function stock_exit_serials($value): string {
    if (!$value) return '—';
    $serials = json_decode((string)$value, true);
    if (!is_array($serials)) return '—';
    $serials = array_filter(array_map('trim', $serials));
    return $serials ? implode(', ', $serials) : '—';
}

patient_header('Stok Çıkış', 'stock');
?>
<main class="patient-container stock-exit-page">
  <section class="stock-exit-card">
    <header>
      <h1><i class="ti tabler-package-export"></i> Stok Çıkış</h1>
      <p>Satışlardan oluşan stok çıkış hareketleri.</p>
    </header>
    <div class="stock-exit-search"><i class="ti tabler-search"></i><input id="stock-exit-search" type="search" placeholder="Stok, seri no, fatura no veya tarih ara" autocomplete="off"></div>
    <div class="stock-exit-table-wrap">
      <table>
        <thead><tr><th>TARİH</th><th>STOK TİPİ</th><th>STOK KARTI</th><th>SERİ NO</th><th>FATURA NO</th><th>ÇIKIŞ</th><th>MEVCUT</th><th>T. SATIŞ</th></tr></thead>
        <tbody>
        <?php foreach ($movements as $movement):
          $description = trim((string)($movement['description'] ?? ''));
          $prefix = 'Hizmet kartı satışı: ';
          $recordNo = str_starts_with($description, $prefix) ? trim(substr($description, strlen($prefix))) : '';
          $sale = $recordNo === '' ? null : ($salesByRecordNo[$recordNo] ?? null);
          $invoiceNo = trim((string)($movement['invoice_no'] ?? '')) ?: trim((string)($sale['invoice_no'] ?? ''));
          $productKey = implode('|', [(string)$movement['stock_type'], trim((string)$movement['brand']) ?: '#' . $movement['stock_id'], trim((string)$movement['model']) ?: '#' . $movement['stock_id']]);
        ?>
          <tr<?= $sale ? ' class="stock-exit-sale-row" data-sale-url="' . e(url('patient-followup.php?id=' . (int)$sale['patient_id'] . '&edit=' . (int)$sale['id'] . '&open_sales_details=1')) . '" ondblclick="window.location.assign(this.dataset.saleUrl)" title="Satış kartını açmak için çift tıklayın"' : '' ?>>
            <td><?=e(format_date_tr($movement['movement_date']))?></td>
            <td><?=e($movement['stock_type'] ?: '—')?></td>
            <td<?= $sale && trim((string)($sale['patient_name'] ?? '')) !== '' ? ' title="' . e((string)$sale['patient_name']) . '"' : '' ?>><?=e($movement['stock_code'] . ' — ' . $movement['stock_name'])?></td>
            <td><?=e(stock_exit_serials($movement['serial_numbers'] ?? null))?></td>
            <td><?=e($invoiceNo ?: '—')?></td>
            <td><?=e((string)$movement['quantity'])?></td>
            <td><?=e((string)($stockBalances[(int)$movement['stock_id']] ?? 0))?></td>
            <td><?=e((string)($totalEntriesByProduct[$productKey] ?? 0))?></td>
          </tr>
        <?php endforeach; if (!$movements): ?>
          <tr><td class="stock-exit-empty" colspan="8">Henüz stok çıkışı bulunmuyor.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<style>
.stock-exit-page{width:100%!important;max-width:1800px!important;min-height:100vh;margin:0 auto!important;padding:46px 20px 48px!important}.stock-exit-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.stock-exit-card>header{padding:22px 24px;border-bottom:1px solid #e1e2e8}.stock-exit-card h1{margin:0 0 5px;color:#2f2b3d;font-size:21px}.stock-exit-card p{margin:0;color:#7b7b8d}.stock-exit-search{display:flex;align-items:center;gap:9px;padding:16px 24px;border-bottom:1px solid #e1e2e8;color:#6f7180}.stock-exit-search input{width:min(440px,100%);padding:10px 12px;border:1px solid #d7d9e2;border-radius:6px;font:inherit}.stock-exit-table-wrap{overflow:auto}.stock-exit-card table{width:100%;min-width:850px;border-collapse:collapse}.stock-exit-card th,.stock-exit-card td{padding:14px 18px;border-bottom:1px solid #e1e2e8;text-align:left;white-space:nowrap}.stock-exit-card th{font-size:12px;color:#5d5b6d}.stock-exit-card tbody tr:hover{background:#f8fcf9}.stock-exit-sale-row{cursor:pointer}.stock-exit-empty{text-align:center;color:#7b7b8d}@media(max-width:720px){.stock-exit-page{padding:92px 14px 30px!important}}
</style>
<script>
(()=>{const search=document.getElementById('stock-exit-search');document.querySelector('.stock-exit-table-wrap thead tr th:last-child').textContent='GİRİŞ';if(!search)return;const normalize=value=>String(value||'').toLocaleLowerCase('tr-TR');search.addEventListener('input',()=>{const query=normalize(search.value.trim());document.querySelectorAll('.stock-exit-table-wrap tbody tr').forEach(row=>{if(row.querySelector('.stock-exit-empty'))return;row.hidden=!!query&&!normalize(row.textContent).includes(query)})})})();
</script>
<?php patient_footer(); ?>
