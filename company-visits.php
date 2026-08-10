<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

function company_visit_amount(string $value): float
{
    $value = trim(str_replace(' ', '', $value));
    if ($value === '') return 0.0;
    return str_contains($value, ',') ? (float)str_replace(',', '.', str_replace('.', '', $value)) : (float)str_replace('.', '', $value);
}

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS company_visits (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, visit_date TEXT NOT NULL, payment_amount REAL NOT NULL DEFAULT 0, description TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS company_visits (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id INT UNSIGNED NOT NULL, visit_date DATE NOT NULL, payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0, description TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_company_visits_company_date (company_id, visit_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$companyId = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$editVisitId = (int)($_GET['edit'] ?? $_POST['visit_id'] ?? 0);
$showForm = isset($_GET['new']) || $editVisitId > 0 || ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') !== 'delete');
$companyStatement = $pdo->prepare('SELECT * FROM companies WHERE id=?');
$companyStatement->execute([$companyId]);
$company = $companyStatement->fetch();
if (!$company) { http_response_code(404); exit('Kişi kaydı bulunamadı.'); }

$name = trim((string)($company['first_name'] ?? '') . ' ' . (string)($company['last_name'] ?? ''));
if ($name === '') $name = (string)($company['company_name'] ?? '');
$error = '';
$visit = ['visit_date' => date('Y-m-d'), 'payment_amount' => 0, 'description' => ''];
if ($editVisitId > 0) {
    $statement = $pdo->prepare('SELECT id,visit_date,payment_amount,description FROM company_visits WHERE id=? AND company_id=?');
    $statement->execute([$editVisitId, $companyId]);
    $visit = $statement->fetch() ?: $visit;
    if (empty($visit['id'])) { http_response_code(404); exit('Ziyaret kaydı bulunamadı.'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM company_visits WHERE id=? AND company_id=?')->execute([$editVisitId, $companyId]);
        redirect('company-visits.php?company_id=' . $companyId . '&deleted=1');
    }
    $date = trim((string)($_POST['visit_date'] ?? ''));
    $payment = max(0, company_visit_amount((string)($_POST['payment_amount'] ?? '')));
    $description = trim((string)($_POST['description'] ?? ''));
    if ($date === '') {
        $error = 'Tarih alanı zorunludur.';
    } elseif ($editVisitId > 0) {
        $pdo->prepare('UPDATE company_visits SET visit_date=?,payment_amount=?,description=? WHERE id=? AND company_id=?')->execute([$date, $payment, $description, $editVisitId, $companyId]);
        redirect('company-visits.php?company_id=' . $companyId . '&saved=1');
    } else {
        $pdo->prepare('INSERT INTO company_visits(company_id,visit_date,payment_amount,description) VALUES(?,?,?,?)')->execute([$companyId, $date, $payment, $description]);
        redirect('company-visits.php?company_id=' . $companyId . '&saved=1');
    }
}
$statement = $pdo->prepare('SELECT id,visit_date,payment_amount,description FROM company_visits WHERE company_id=? ORDER BY visit_date DESC,id DESC');
$statement->execute([$companyId]);
$visits = $statement->fetchAll();
patient_header('Ziyaret', 'cash');
?>
<style>
.company-visits-page{width:100%;max-width:1000px;margin:0 auto;padding:46px 20px 48px}.company-visits-card{background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.company-visits-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--line)}.company-visits-head h2{margin:0;font-size:21px}.company-visits-head p{margin:5px 0 0;color:var(--muted)}.company-visits-head-actions{display:flex;align-items:center;gap:16px}.company-visits-new{min-height:40px!important;padding:0 14px!important}.company-visits-form{display:grid;grid-template-columns:150px minmax(0,1fr);gap:14px 0;padding:22px 24px;border-bottom:1px solid var(--line)}.company-visits-form label{padding:11px 15px 0 0}.company-visits-form input,.company-visits-form textarea{width:100%;border:1px solid #d5d3de;border-radius:6px;padding:9px 12px;background:var(--card);color:var(--text);font:inherit}.company-visits-form textarea{min-height:84px;resize:vertical}.company-visits-actions{grid-column:2;display:flex;gap:12px;margin-top:4px}.company-visits-table{width:100%;border-collapse:collapse}.company-visits-table th,.company-visits-table td{padding:14px 24px;border-bottom:1px solid var(--line);text-align:left}.company-visits-table th{font-size:12px;color:var(--muted)}.company-visits-empty{padding:32px 24px;color:var(--muted)}.company-visits-alert,.company-visits-success{margin:18px 24px 0;padding:12px;border-radius:6px}.company-visits-alert{background:#fde8e8;color:#a62c2c}.company-visits-success{background:#e8f7ed;color:#16883d}.company-visits-back{color:#16883d;text-decoration:none;font-weight:700}.company-visits-table-actions{display:flex;gap:8px}.company-visits-table-actions a,.company-visits-table-actions button{display:grid;place-items:center;box-sizing:border-box;width:36px;height:36px;margin:0;padding:0;border:0;border-radius:6px;color:#fff;cursor:pointer;text-decoration:none}.company-visit-edit{background:#20a447}.company-visit-delete{background:#e04f55}@media(max-width:720px){.company-visits-page{padding:20px 12px 30px}.company-visits-head{padding:18px}.company-visits-head-actions{gap:10px}.company-visits-form{grid-template-columns:1fr;padding:18px}.company-visits-form label{padding:0}.company-visits-actions{grid-column:auto}.company-visits-table th,.company-visits-table td{padding:12px}.company-visits-table{font-size:13px}}
</style>
<main class="patient-container company-visits-page"><section class="company-visits-card"><header class="company-visits-head"><div><h2>Ziyaret</h2><p><?=e((string)$company['code'])?> — <?=e($name)?></p></div><div class="company-visits-head-actions"><?php if ($showForm): ?><a class="company-visits-back" href="<?=e(url('company-visits.php?company_id=' . $companyId))?>">Listeye Dön</a><?php else: ?><a class="button company-visits-new" href="<?=e(url('company-visits.php?company_id=' . $companyId . '&new=1'))?>">+ Yeni Ziyaret</a><?php endif; ?><a class="company-visits-back" href="<?=e(url('companies.php'))?>">Saha Aksiyonlarına Dön</a></div></header><?php if ($error): ?><div class="company-visits-alert"><?=e($error)?></div><?php endif; ?><?php if (isset($_GET['saved'])): ?><div class="company-visits-success">Ziyaret kaydedildi.</div><?php endif; ?><?php if ($showForm): ?><form method="post" class="company-visits-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="company_id" value="<?=$companyId?>"><input type="hidden" name="visit_id" value="<?=$editVisitId?>"><input type="hidden" name="action" value="<?=$editVisitId ? 'update' : 'save'?>"><label for="visit_date">Tarih</label><input id="visit_date" type="date" name="visit_date" value="<?=e((string)($_POST['visit_date'] ?? $visit['visit_date']))?>" required><label for="payment_amount">Ödeme</label><input id="payment_amount" type="text" name="payment_amount" inputmode="decimal" value="<?=e((float)($_POST['payment_amount'] ?? $visit['payment_amount']) > 0 ? number_format((float)($_POST['payment_amount'] ?? $visit['payment_amount']), 2, ',', '.') : '')?>" placeholder="0,00 TL"><label for="description">Açıklama</label><textarea id="description" name="description"><?=e((string)($_POST['description'] ?? $visit['description']))?></textarea><div class="company-visits-actions"><button class="button">Kaydet</button></div></form><?php endif; ?><?php if (!$showForm && $visits): ?><table class="company-visits-table"><thead><tr><th>TARİH</th><th>AÇIKLAMA</th><th>ÖDENEN</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach ($visits as $item): ?><tr><td><?=e(date('d.m.Y', strtotime((string)$item['visit_date'])))?></td><td><?=nl2br(e((string)$item['description']))?></td><td><?=((float)$item['payment_amount'] > 0) ? e(number_format((float)$item['payment_amount'], 2, ',', '.')) . ' TL' : '—'?></td><td><div class="company-visits-table-actions"><a class="company-visit-edit" href="<?=e(url('company-visits.php?company_id=' . $companyId . '&edit=' . (int)$item['id']))?>" title="Düzenle"><i class="icon-base ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu ziyaret silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="company_id" value="<?=$companyId?>"><input type="hidden" name="visit_id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="delete"><button class="company-visit-delete" title="Sil"><i class="icon-base ti tabler-trash"></i></button></form></div></td></tr><?php endforeach; ?></tbody></table><?php elseif (!$showForm): ?><div class="company-visits-empty">Henüz ziyaret kaydı bulunmuyor.</div><?php endif; ?></section></main>
<script>
(() => {
  const home = document.querySelector('.company-visits-head-actions a[href$="companies.php"]');
  if (!home) return;
  home.classList.add('button', 'company-visits-home');
  home.title = 'Saha Aksiyonları';
  home.setAttribute('aria-label', 'Saha Aksiyonları');
  home.innerHTML = '<i class="icon-base ti tabler-home"></i>';
})();
</script>
<style>.company-visits-home{display:grid!important;place-items:center!important;width:40px!important;height:40px!important;min-width:40px!important;min-height:40px!important;padding:0!important;color:#fff!important}.company-visits-home .icon-base{font-size:19px!important;color:#fff!important}</style>
<?php patient_footer(); ?>
