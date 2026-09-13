<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_admin();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Bu ekran yalnızca görüntüleme içindir.');
}
require __DIR__.'/patient-layout.php';
require __DIR__.'/cash-company.php';
require __DIR__.'/company-finance-data.php';
$pdo = db();
$company = cash_company_account($pdo);
$from = trim((string)($_GET['date_from'] ?? ''));
$to = trim((string)($_GET['date_to'] ?? ''));
// Ana Kasa includes all registers; ignore old register-filter links.
unset($_GET['register']);
$error = $from !== '' && $to !== '' && $from > $to ? 'Başlangıç tarihi bitiş tarihinden sonra olamaz.' : '';
$where = ['t.company_account_id=?']; $params = [(int)$company['id']];
if ($from !== '') { $where[] = 't.transaction_date>=?'; $params[]=$from; }
if ($to !== '') { $where[] = 't.transaction_date<=?'; $params[]=$to; }
$rows = [];
if ($error === '') {
    $query=$pdo->prepare('SELECT t.*,a.code AS counterparty_code,a.title AS counterparty_title,a.short_name AS counterparty_short_name FROM cash_transactions t LEFT JOIN current_accounts a ON a.id=t.current_account_id WHERE '.implode(' AND ', $where).' ORDER BY t.transaction_date DESC,t.id DESC');
    $query->execute($params); $rows=$query->fetchAll();
}
$summary=company_finance_summary($rows);
$page=max(1,(int)($_GET['page']??1));$pages=max(1,(int)ceil(count($summary['rows'])/50));$page=min($page,$pages);
$visibleRows=array_slice($summary['rows'],($page-1)*50,50);
$money=static fn(float $value): string=>number_format($value,2,',','.').' ₺';
$paymentLabels=['cash'=>'Nakit','eft_transfer'=>'EFT / Havale','credit_card'=>'Kredi Kartı','mail_order'=>'Mail Order','term'=>'Vadeli'];
$pageUrl=static fn(int $number): string=>url('company-finance.php?'.http_build_query(array_merge($_GET,['page'=>$number])));
patient_header('CR-00 · İşletme Finans Özeti','cash');
?>
<link rel="stylesheet" href="<?=e(url('assets/company-finance.css'))?>">
<main class="company-finance">
  <form class="finance-filters" method="get">
    <?php if(isset($_GET['_vox_window'])): ?><input type="hidden" name="_vox_window" value="1"><?php endif ?>
    <label>Başlangıç<input type="date" name="date_from" value="<?=e($from)?>"></label>
    <label>Bitiş<input type="date" name="date_to" value="<?=e($to)?>"></label>
    <button type="submit" class="finance-search" title="Ara" aria-label="Ara"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="10" cy="10" r="6" fill="none" stroke="currentColor" stroke-width="2"/><path d="m15 15 5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button><a href="<?=e(url('company-finance.php'.(isset($_GET['_vox_window'])?'?_vox_window=1':'')))?>">Temizle</a>
  </form>
  <?php if($error): ?><p role="alert"><?=e($error)?></p><?php endif ?>
  <section class="finance-totals" aria-label="Seçili dönem özeti">
    <article><span>Toplam giriş</span><strong class="finance-income"><?=e($money($summary['income']))?></strong></article>
    <article><span>Toplam çıkış</span><strong class="finance-expense"><?=e($money($summary['expense']))?></strong></article>
    <article><span>Net hareket</span><strong><?=e($money($summary['net']))?></strong></article>
    <article><span>Hareket sayısı</span><strong><?=count($summary['rows'])?></strong></article>
  </section>
  <section class="finance-distribution"><h2>Kasa / banka dağılımı</h2><div><?php foreach($summary['groups'] as $label=>$net): ?><article><span><?=e($label)?></span><b><?=e($money($net))?></b></article><?php endforeach ?><?php if(!$summary['groups']): ?><p>Seçili dönemde hareket bulunmuyor.</p><?php endif ?></div></section>
  <section class="finance-transactions"><header><h2>Hareketler</h2></header><div class="finance-scroll"><table><thead><tr><th>Tarih</th><th>Açıklama / detay</th><th>Karşı cari</th><th>Kasa</th><th>Ödeme / banka</th><th>Giriş</th><th>Çıkış</th></tr></thead><tbody>
    <?php foreach($visibleRows as $row): ?><tr>
      <td><?=e(format_date_tr($row['transaction_date']))?></td>
      <td><details><summary><?=e($row['description'])?></summary><dl><dt>Kayıt no</dt><dd><?= (int)$row['id'] ?></dd><dt>İşletme</dt><dd>CR-00</dd><dt>İşlem</dt><dd><?=$row['transaction_type']==='income'?'Gelir':'Gider'?></dd><dt>Taksit sayısı</dt><dd><?=(int)$row['installment_count']?></dd></dl></details></td>
      <td><?=e(trim(($row['counterparty_code']??'').' '.($row['counterparty_short_name'] ?: $row['counterparty_title'] ?? '')) ?: '—')?></td>
      <td><?=($row['cash_register']??'main')==='pre'?'Ön Kasa':'Ana Kasa'?></td>
      <td><?=e($paymentLabels[$row['payment_type']]??$row['payment_type'])?><?php if($row['bank_name']): ?><small><?=e($row['bank_name'])?></small><?php endif ?></td>
      <td class="finance-income"><?= $row['income']>0 ? e($money($row['income'])) : '—' ?></td><td class="finance-expense"><?= $row['expense']>0 ? e($money($row['expense'])) : '—' ?></td>
    </tr><?php endforeach ?>
    <?php if(!$visibleRows): ?><tr><td colspan="7">Seçili filtrelere uygun hareket bulunmuyor.</td></tr><?php endif ?>
  </tbody></table></div>
  <?php if($pages>1): ?><nav class="finance-pages"><?php if($page>1): ?><a href="<?=e($pageUrl($page-1))?>">Önceki</a><?php endif ?><span><?=$page?> / <?=$pages?></span><?php if($page<$pages): ?><a href="<?=e($pageUrl($page+1))?>">Sonraki</a><?php endif ?></nav><?php endif ?>
  <p class="finance-note">Net hareket, seçili dönemin giriş ve çıkış farkıdır; devir bakiyesini içermez. Mail Order tutarları mevcut kasa hesabındaki gibi giriş ve çıkışa birlikte yansır. Vadeli işlemlerde yalnızca ödenen taksitler gösterilir.</p>
  </section>
</main>
<?php patient_footer(); ?>
