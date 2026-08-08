<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
if (current_role() !== ROLE_COMPANY_MANAGER) { http_response_code(403); exit('Bu sayfa yalnızca firma yöneticilerine açıktır.'); }
require __DIR__ . '/patient-layout.php';
$id = max(0, (int)($_GET['id'] ?? 0)); $pdo = db();
$statement = $pdo->prepare('SELECT * FROM patients WHERE id=?'); $statement->execute([$id]); $patient = $statement->fetch();
if (!$patient) { http_response_code(404); exit('Hasta bulunamadı.'); }
$services = $pdo->prepare('SELECT * FROM patient_services WHERE patient_id=? ORDER BY id DESC'); $services->execute([$id]); $services = $services->fetchAll();
$latest = $services[0] ?? [];
$sale = [];
foreach ($services as $service) { if (trim((string)($service['service_name'] ?? '')) === 'Satış') { $sale = json_decode((string)($service['sales_details'] ?? ''), true) ?: []; $latestSale = $service; break; } }
$anamnesis = trim((string)($latest['complaint'] ?? $patient['anamnesis'] ?? $patient['notes'] ?? ''));
patient_header('Hasta Bilgi Formu');
?>
<main class="patient-info-page"><section class="patient-info-card">
  <header><div><p class="eyebrow">HASTA BİLGİ FORMU</p><h1><?=e((string)$patient['full_name'])?></h1><p><?=e((string)$patient['national_id'])?> · Kayıt: <?=e(format_date_tr((string)$patient['record_date']))?></p></div><a href="<?=e(url('patients.php'))?>" title="Hasta kartlarına dön"><i class="ti tabler-arrow-left"></i></a></header>
  <section class="info-section"><h2>Hasta Özeti</h2><div class="horizontal-form">
    <div><i class="ti tabler-id"></i><label>T.C. Kimlik No</label><strong><?=e((string)$patient['national_id'])?:'—'?></strong></div><div><i class="ti tabler-calendar"></i><label>Doğum Tarihi</label><strong><?=e(format_date_tr((string)$patient['birth_date']))?:'—'?></strong></div>
    <div><i class="ti tabler-phone"></i><label>Telefon</label><strong><?=e((string)$patient['phone_primary'])?:'—'?></strong></div><div><i class="ti tabler-map-pin"></i><label>Adres</label><strong><?=e((string)$patient['address'])?:'—'?></strong></div>
  </div></section>
  <section class="info-section"><h2>Satış Bilgisi</h2><div class="sales-summary"><div class="sales-head"><span>KALEM</span><span>AÇIKLAMA</span><span>TUTAR</span></div><div><span>Cihaz Modeli / Fiyat</span><span><?=e(trim(($sale['sales_brand'] ?? '').' '.($sale['sales_model'] ?? ''))?:'—')?></span><strong><?=e((string)($sale['sales_device_net_price'] ?? $sale['sales_payment_amount'] ?? '—'))?></strong></div><div><span>Adet</span><span><?=((trim((string)($sale['sales_device_2_model'] ?? '')) !== '') ? '2' : (trim((string)($sale['sales_model'] ?? '')) !== '' ? '1' : '—'))?></span><strong>—</strong></div><div><span>SGK</span><span><?=e((string)($sale['sales_device_sgk'] ?? '—'))?></span><strong><?=e((string)($sale['sales_device_sgk'] ?? '—'))?></strong></div><div><span>Kalan Fiyat</span><span>Satış Tutarı</span><strong><?=e((string)($sale['sales_payment_amount'] ?? $sale['sales_device_net_price'] ?? '—'))?></strong></div><div><span>İskonto</span><span><?=e((string)($sale['sales_device_discount_rate'] ?? '—'))?></span><strong><?=e((string)($sale['sales_total_discount_rate'] ?? $sale['sales_device_discount_rate'] ?? '—'))?></strong></div></div></section>
  <div class="sales-total-summary"><span>Satış Cihazı</span><strong><?=e((string)($sale['sales_payment_amount'] ?? $sale['sales_device_net_price'] ?? '—'))?></strong><span>Rapor</span><strong><?=e((string)($patient['report_info'] ?? '—'))?></strong><span>Toplam</span><strong><?=e((string)($sale['sales_payment_amount'] ?? '—'))?></strong></div>
  <section class="info-section"><h2>Cihaz Teslim Bilgisi</h2><div class="horizontal-form"><div><i class="ti tabler-device-hearing-aid"></i><label>Cihaz Bilgisi</label><strong><?=e((string)($latest['service_name'] ?? ''))?:'—'?></strong></div><div><i class="ti tabler-calendar-event"></i><label>İşlem Tarihi</label><strong><?=e(format_date_tr((string)($latest['service_date'] ?? '')))?:'—'?></strong></div></div></section>
  <section class="info-section delivery-section"><div class="delivery-table"><div class="delivery-head"><strong>CİHAZ TESLİM FORMU</strong><strong>TESLİM TARİHİ</strong><strong><?=e(format_date_tr((string)($latest['service_date'] ?? '')))?:'—'?></strong></div><div><span>CİHAZ MODELİ</span><span><?=e(trim(($sale['sales_brand'] ?? '').' '.($sale['sales_model'] ?? ''))?:'—')?></span><span></span></div><div><span>CİHAZ SERİ NO</span><span><?=e((string)($sale['sales_device_serial'] ?? '—'))?></span><span><?=e((string)($sale['sales_device_2_serial'] ?? ''))?></span></div><div><span>RECEIVER (HOPARLÖR)</span><span><?=e((string)($sale['sales_receiver'] ?? '—'))?></span><span><?=e((string)($sale['sales_device_2_receiver'] ?? ''))?></span></div><div><span>DOME - KALIP</span><span><?=e((string)($sale['sales_dome'] ?? '—'))?></span><span><?=e((string)($sale['sales_device_2_dome'] ?? ''))?></span></div><div><span>CHARGER / PİL ADET-MARKA</span><span><?=e(trim(($sale['sales_charger_brand'] ?? '').' '.($sale['sales_charger_model'] ?? ''))?:'—')?></span><span><?=e((string)($sale['sales_consumable_quantity'] ?? ''))?></span></div></div></section>
  <section class="info-section"><h2>Hasta Anamnezi</h2><article class="anamnesis-summary"><i class="ti tabler-notes"></i><p><?=nl2br(e($anamnesis ?: 'Anamnez bilgisi bulunmuyor.'))?></p></article></section>
</section></main>
<style>.patient-info-page{max-width:1080px;margin:0 auto;padding:52px 20px 30px}.patient-info-card{background:#fff;border:1px solid #e1e2e8;border-radius:10px;box-shadow:0 3px 12px #1e283c12;overflow:hidden}.patient-info-card>header{height:auto!important;padding:24px 28px!important;display:flex!important;align-items:start!important;justify-content:space-between!important;background:#f8fbf8!important;border-bottom:4px solid #8fd14f!important}.eyebrow{margin:0 0 7px!important;color:#4c8c1a!important;font-weight:800!important}.patient-info-card h1{margin:0;font-size:25px}.patient-info-card header p{margin:7px 0 0;color:#686574}.patient-info-card header a{display:grid;place-items:center;width:38px;height:38px;border-radius:6px;background:#6f42c1;color:#fff}.info-section{padding:23px 28px;border-bottom:1px solid #e7e6eb}.info-section h2{margin:0 0 16px;color:#376d18;font-size:15px}.horizontal-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 24px}.horizontal-form>div{display:grid;grid-template-columns:32px 130px minmax(0,1fr);align-items:center;min-height:42px;border-bottom:1px solid #ecebf0}.horizontal-form i{color:#6f42c1;font-size:19px}.horizontal-form label{color:#777487;font-size:13px}.horizontal-form strong{font-weight:500;overflow-wrap:anywhere}.sales-summary{border:1px solid #dfe3eb;font-size:13px}.sales-summary>div{display:grid;grid-template-columns:1.25fr 1fr .7fr}.sales-summary span,.sales-summary strong{padding:8px 10px;border-right:1px solid #dfe3eb;border-bottom:1px solid #dfe3eb}.sales-summary strong{text-align:right;font-weight:600}.sales-summary>div:last-child>*{border-bottom:0}.sales-summary .sales-head{background:#f4f7fc;color:#376d18;font-size:11px;font-weight:700}.anamnesis-summary{display:flex;gap:12px;margin:0;padding:16px;border-radius:7px;background:#f8f8fa}.anamnesis-summary i{font-size:21px;color:#6f42c1}.anamnesis-summary p{margin:0;line-height:1.55}@media(max-width:700px){.horizontal-form{grid-template-columns:1fr}.patient-info-page{padding:40px 10px 18px}.info-section{padding:20px}.horizontal-form>div{grid-template-columns:30px 110px minmax(0,1fr)}.sales-summary{font-size:11px}.sales-summary span,.sales-summary strong{padding:7px 5px}}</style>
<?php patient_footer(); ?>
<style>
.sales-summary{border-top:4px solid #8fd14f!important;border-left:1px solid #dfe3eb!important;font-size:12px!important}
.sales-summary>div{grid-template-columns:55% 22% 23%!important}
.sales-summary span,.sales-summary strong{min-height:25px!important;padding:5px 7px!important}
.sales-summary .sales-head{background:#8fd14f!important;color:#173b16!important;font-size:12px!important}
.sales-summary .sales-head span,.sales-summary .sales-head strong{font-weight:700!important}
.sales-total-summary{display:grid;grid-template-columns:1fr 120px;width:45%;margin:-1px 28px 20px auto;border:1px solid #dfe3eb;font-size:12px}.sales-total-summary span,.sales-total-summary strong{padding:5px 8px;border-bottom:1px solid #dfe3eb}.sales-total-summary span{text-align:right;font-weight:700}.sales-total-summary strong{text-align:right;border-left:1px solid #dfe3eb}.sales-total-summary strong:last-child,.sales-total-summary span:nth-last-child(2){border-bottom:0}
.info-section:has(.horizontal-form .tabler-device-hearing-aid){display:none}.delivery-table{border-top:4px solid #8fd14f;border-left:1px solid #dfe3eb;font-size:12px}.delivery-table>div{display:grid;grid-template-columns:55% 25% 20%}.delivery-table span,.delivery-table strong{min-height:25px;padding:5px 7px;border-right:1px solid #dfe3eb;border-bottom:1px solid #dfe3eb}.delivery-table strong{text-align:right}.delivery-table .delivery-head{background:#8fd14f}.delivery-table .delivery-head strong:first-child{text-align:left}.delivery-table .delivery-head strong:last-child{font-size:15px}
</style>
<script>
(() => {
  const modelRow = document.querySelector('.delivery-table > div:nth-child(2)');
  if (!modelRow) return;
  const quantityCell = modelRow.lastElementChild;
  const serialCount = [...document.querySelectorAll('.delivery-table > div:nth-child(3) span')].filter(cell => cell.textContent.trim()).length - 1;
  quantityCell.textContent = serialCount > 1 ? '2 Adet' : (modelRow.children[1]?.textContent.trim() ? '1 Adet' : '');
  document.querySelectorAll('.delivery-table > div').forEach(row => {
    const label = row.firstElementChild?.textContent.trim() || '';
    if (label === 'RECEIVER (HOPARLÖR)' || label === 'DOME - KALIP') row.remove();
    if (label === 'CHARGER / PİL ADET-MARKA') {
      const product = row.children[1]?.textContent.trim() || '';
      const quantity = row.children[2]?.textContent.trim() || '';
      if (!product || product === '—') row.remove();
      else if (quantity) row.children[2].textContent = quantity + ' Adet';
    }
  });
})();
</script>
<script>
(() => {
  const salesTable = document.querySelector('.sales-summary');
  if (!salesTable) return;

  const sectionTitle = salesTable.closest('.info-section')?.querySelector(':scope > h2');
  if (sectionTitle) sectionTitle.remove();

  const firstHeader = salesTable.querySelector('.sales-head > span:first-child');
  if (firstHeader) firstHeader.textContent = 'SATIŞ BİLGİSİ';
})();
</script>
