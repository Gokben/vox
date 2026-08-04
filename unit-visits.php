<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS unit_visits (id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER NOT NULL, visit_date TEXT NOT NULL, description TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS unit_visits (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unit_id INT UNSIGNED NOT NULL, visit_date DATE NOT NULL, description TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_unit_visits_unit_date (unit_id, visit_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$unitId = (int)($_GET['unit_id'] ?? $_POST['unit_id'] ?? 0);
$editVisitId = (int)($_GET['edit'] ?? $_POST['visit_id'] ?? 0);
$showForm = isset($_GET['new']) || $editVisitId > 0 || ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') !== 'delete');
$unitStatement = $pdo->prepare('SELECT id, code, name, last_name FROM units WHERE id=?');
$unitStatement->execute([$unitId]);
$unit = $unitStatement->fetch();
if (!$unit) {
    http_response_code(404);
    exit('Ünite kaydı bulunamadı.');
}

$error = '';
$visit = ['visit_date' => date('Y-m-d'), 'description' => ''];
if ($editVisitId > 0) {
    $visitStatement = $pdo->prepare('SELECT id, visit_date, description FROM unit_visits WHERE id=? AND unit_id=?');
    $visitStatement->execute([$editVisitId, $unitId]);
    $visit = $visitStatement->fetch() ?: $visit;
    if (empty($visit['id'])) { http_response_code(404); exit('Ziyaret kaydı bulunamadı.'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM unit_visits WHERE id=? AND unit_id=?')->execute([$editVisitId, $unitId]);
        redirect('unit-visits.php?unit_id=' . $unitId . '&deleted=1');
    }
    $visitDate = trim((string)($_POST['visit_date'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    if ($visitDate === '') {
        $error = 'Tarih alanı zorunludur.';
    } else {
        if ($editVisitId > 0) {
            $update = $pdo->prepare('UPDATE unit_visits SET visit_date=?, description=? WHERE id=? AND unit_id=?');
            $update->execute([$visitDate, $description, $editVisitId, $unitId]);
        } else {
            $insert = $pdo->prepare('INSERT INTO unit_visits (unit_id, visit_date, description) VALUES (?, ?, ?)');
            $insert->execute([$unitId, $visitDate, $description]);
        }
        redirect('unit-visits.php?unit_id=' . $unitId . '&saved=1');
    }
}

$visitsStatement = $pdo->prepare('SELECT id, visit_date, description FROM unit_visits WHERE unit_id=? ORDER BY visit_date DESC, id DESC');
$visitsStatement->execute([$unitId]);
$visits = $visitsStatement->fetchAll();
$unitName = trim((string)$unit['name'] . ' ' . (string)($unit['last_name'] ?? ''));

patient_header('Ziyaret', 'cash');
?>
<style>
.unit-visits-page{width:100%;max-width:1000px;margin:0 auto;padding:46px 20px 48px}.unit-visits-card{background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:0 3px 12px #1e283c0f;overflow:hidden}.unit-visits-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--line)}.unit-visits-head h2{margin:0;font-size:21px}.unit-visits-head p{margin:5px 0 0;color:var(--muted)}.unit-visits-head-actions{display:flex;align-items:center;gap:16px}.unit-visits-new{min-height:40px!important;padding:0 14px!important}.unit-visits-form{display:grid;grid-template-columns:150px minmax(0,1fr);gap:14px 0;padding:22px 24px;border-bottom:1px solid var(--line)}.unit-visits-form label{padding:11px 15px 0 0}.unit-visits-form input,.unit-visits-form textarea{width:100%;border:1px solid #d5d3de;border-radius:6px;padding:9px 12px;background:var(--card);color:var(--text);font:inherit}.unit-visits-form textarea{min-height:84px;resize:vertical}.unit-visits-actions{grid-column:2;display:flex;gap:12px;margin-top:4px}.unit-visits-table{width:100%;border-collapse:collapse}.unit-visits-table th,.unit-visits-table td{padding:14px 24px;border-bottom:1px solid var(--line);text-align:left}.unit-visits-table th{font-size:12px;color:var(--muted)}.unit-visits-empty{padding:32px 24px;color:var(--muted)}.unit-visits-alert,.unit-visits-success{margin:18px 24px 0;padding:12px;border-radius:6px}.unit-visits-alert{background:#fde8e8;color:#a62c2c}.unit-visits-success{background:#e8f7ed;color:#16883d}.unit-visits-back{color:#16883d;text-decoration:none;font-weight:700}@media(max-width:720px){.unit-visits-page{padding:20px 12px 30px}.unit-visits-head{padding:18px}.unit-visits-head-actions{gap:10px}.unit-visits-form{grid-template-columns:1fr;padding:18px}.unit-visits-form label{padding:0}.unit-visits-actions{grid-column:auto}.unit-visits-table th,.unit-visits-table td{padding:12px}.unit-visits-table{font-size:13px}}
</style>
<style>.unit-visits-table-actions{display:flex;gap:8px}.unit-visits-table-actions a,.unit-visits-table-actions button{display:grid;place-items:center;box-sizing:border-box;width:36px;height:36px;margin:0;padding:0;border:0;border-radius:6px;color:#fff;cursor:pointer;text-decoration:none}.unit-visit-edit{background:#20a447}.unit-visit-delete{background:#e04f55}</style>
<main class="patient-container unit-visits-page">
  <section class="unit-visits-card">
    <header class="unit-visits-head">
      <div><h2>Ziyaret</h2><p><?=e($unit['code'])?> — <?=e($unitName)?></p></div>
      <div class="unit-visits-head-actions">
        <?php if ($showForm): ?><a class="unit-visits-back" href="<?=e(url('unit-visits.php?unit_id=' . $unitId))?>">Listeye Dön</a><?php else: ?><a class="button unit-visits-new" href="<?=e(url('unit-visits.php?unit_id=' . $unitId . '&new=1'))?>">+ Yeni Ziyaret</a><?php endif; ?>
        <a class="unit-visits-back" href="<?=e(url('units.php'))?>">Ünitelere Dön</a>
      </div>
    </header>
    <?php if ($error): ?><div class="unit-visits-alert"><?=e($error)?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="unit-visits-success">Ziyaret kaydedildi.</div><?php endif; ?>
    <?php if ($showForm): ?><form method="post" class="unit-visits-form">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="unit_id" value="<?=$unitId?>"><input type="hidden" name="visit_id" value="<?=$editVisitId?>"><input type="hidden" name="action" value="<?=$editVisitId ? 'update' : 'save'?>">
      <label for="visit_date">Tarih</label><input id="visit_date" type="date" name="visit_date" value="<?=e((string)($_POST['visit_date'] ?? $visit['visit_date']))?>" required>
      <label for="description">Açıklama</label><textarea id="description" name="description"><?=e((string)($_POST['description'] ?? $visit['description']))?></textarea>
      <div class="unit-visits-actions"><button class="button">Kaydet</button></div>
    </form><?php endif; ?>
    <?php if (!$showForm && $visits): ?>
      <table class="unit-visits-table"><thead><tr><th>TARİH</th><th>AÇIKLAMA</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach ($visits as $visit): ?><tr><td><?=e(date('d.m.Y', strtotime((string)$visit['visit_date'])))?></td><td><?=nl2br(e((string)$visit['description']))?></td><td><div class="unit-visits-table-actions"><a class="unit-visit-edit" href="<?=e(url('unit-visits.php?unit_id=' . $unitId . '&edit=' . (int)$visit['id']))?>" title="Düzenle"><i class="icon-base ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu ziyaret silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="unit_id" value="<?=$unitId?>"><input type="hidden" name="visit_id" value="<?=(int)$visit['id']?>"><input type="hidden" name="action" value="delete"><button class="unit-visit-delete" title="Sil"><i class="icon-base ti tabler-trash"></i></button></form></div></td></tr><?php endforeach; ?></tbody></table>
    <?php elseif (!$showForm): ?><div class="unit-visits-empty">Henüz ziyaret kaydı bulunmuyor.</div><?php endif; ?>
  </section>
</main>
<?php patient_footer(); ?>
