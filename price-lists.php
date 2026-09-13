<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_login();
$canEditPrices = is_admin();
if (!$canEditPrices && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    http_response_code(403);
    exit('Fiyat listelerini yalnızca yöneticiler değiştirebilir.');
}
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
    : 'CREATE TABLE IF NOT EXISTS stock_price_lists (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, brand VARCHAR(190) NOT NULL, valid_from DATE NOT NULL, valid_until DATE NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INTEGER NOT NULL, stock_id INTEGER NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id))'
    : 'CREATE TABLE IF NOT EXISTS stock_price_list_items (price_list_id INT UNSIGNED NOT NULL, stock_id INT UNSIGNED NOT NULL, list_price DECIMAL(12,2) NOT NULL DEFAULT 0, PRIMARY KEY(price_list_id,stock_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$hasStockType = $sqlite
    ? (bool)array_filter($pdo->query('PRAGMA table_info(stock_cards)')->fetchAll(), static fn(array $column): bool => $column['name'] === 'stock_type')
    : (bool)$pdo->query("SHOW COLUMNS FROM stock_cards LIKE 'stock_type'")->fetch();
if (!$hasStockType) $pdo->exec('ALTER TABLE stock_cards ADD COLUMN stock_type VARCHAR(50) NULL');
$pdo->prepare('UPDATE stock_cards SET stock_type=? WHERE stock_type=?')->execute(['İşitme Cihazı', 'Kulaklık']);

$error = '';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$copyFromId = filter_input(INPUT_GET, 'copy_from', FILTER_VALIDATE_INT) ?: 0;
$newList = isset($_GET['new']);
if (!$canEditPrices && ($editId || $copyFromId || $newList)) { http_response_code(403); exit('Fiyat listelerini yalnızca yöneticiler değiştirebilir.'); }
$form = ['brand' => '', 'valid_from' => '', 'valid_until' => ''];
if ($editId) {
    $statement = $pdo->prepare('SELECT * FROM stock_price_lists WHERE id=?');
    $statement->execute([$editId]);
    $record = $statement->fetch();
    if (!$record) { header('Location: ' . url('price-lists.php')); exit; }
    $form = ['brand' => (string)$record['brand'], 'valid_from' => (string)$record['valid_from'], 'valid_until' => (string)$record['valid_until']];
}
if ($copyFromId) {
    $statement = $pdo->prepare('SELECT * FROM stock_price_lists WHERE id=?');
    $statement->execute([$copyFromId]);
    $sourceList = $statement->fetch();
    if (!$sourceList) { header('Location: ' . url('price-lists.php')); exit; }
    $form['brand'] = (string)$sourceList['brand'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['delete_id'])) {
        $statement = $pdo->prepare('DELETE FROM stock_price_lists WHERE id=?');
        $statement->execute([(int)$_POST['delete_id']]);
        header('Location: ' . url('price-lists.php?deleted=1')); exit;
    }
    $brand = trim((string)($_POST['brand'] ?? ''));
    $validFrom = trim((string)($_POST['valid_from'] ?? ''));
    $validUntil = trim((string)($_POST['valid_until'] ?? ''));
    $form = ['brand' => $brand, 'valid_from' => $validFrom, 'valid_until' => $validUntil];
    if ($brand === '' || $validFrom === '' || $validUntil === '') $error = 'Marka ve geçerlilik tarihleri zorunludur.';
    elseif ($validUntil < $validFrom) $error = 'Bitiş tarihi başlangıç tarihinden önce olamaz.';
    else {
        $postedEditId = filter_input(INPUT_POST, 'edit_id', FILTER_VALIDATE_INT) ?: 0;
        if ($postedEditId) {
            $statement = $pdo->prepare('UPDATE stock_price_lists SET brand=?,valid_from=?,valid_until=? WHERE id=?');
            $statement->execute([$brand, $validFrom, $validUntil, $postedEditId]);
        } else {
            $statement = $pdo->prepare('INSERT INTO stock_price_lists(brand,valid_from,valid_until) VALUES(?,?,?)');
            $statement->execute([$brand, $validFrom, $validUntil]);
            $newListId = (int)$pdo->lastInsertId();
            $copyStatement = $pdo->prepare('INSERT INTO stock_price_list_items(price_list_id,stock_id,list_price) SELECT ?,s.id,0 FROM stock_cards s WHERE s.stock_type=? AND s.brand=?');
            $copyStatement->execute([$newListId, 'İşitme Cihazı', $brand]);
        }
        header('Location: ' . url('price-lists.php?saved=1')); exit;
    }
}

$brands = $pdo->query("SELECT DISTINCT brand FROM stock_cards WHERE stock_type='İşitme Cihazı' AND brand IS NOT NULL AND brand<>'' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
$priceLists = $pdo->query('SELECT * FROM stock_price_lists ORDER BY valid_from DESC,id DESC')->fetchAll();
patient_header('Liste Fiyatları', 'stock');
?>
<link rel="stylesheet" href="<?=url('assets/classic-stock-menu.css?v=20260823-3')?>">
<main class="patient-container price-lists-page"><section class="price-lists-card">
  <header><div><h1>Liste Fiyatları</h1><p>Markalara ait liste fiyatı geçerlilik dönemlerini yönetin.</p></div><?php if($canEditPrices): ?><a class="new-list-header-button" href="<?=e(url('price-lists.php?new=1'))?>" title="Yeni liste oluştur" aria-label="Yeni liste oluştur">+</a><?php endif ?></header>
  <?php if ($error): ?><p class="price-list-alert error"><?=e($error)?></p><?php elseif (isset($_GET['saved'])): ?><p class="price-list-alert success">Liste fiyatı dönemi kaydedildi.</p><?php endif; ?>
  <?php if ($canEditPrices && (!$priceLists || $newList || $editId || $copyFromId || $error !== '')): ?><form method="post" class="price-list-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><?php if ($editId): ?><input type="hidden" name="edit_id" value="<?=e((string)$editId)?>"><?php endif; ?><?php if ($copyFromId): ?><input type="hidden" name="copy_from_id" value="<?=e((string)$copyFromId)?>"><?php endif; ?><label>Marka<select name="brand" required><option value="">Seçiniz</option><?php foreach ($brands as $brand): ?><option value="<?=e($brand)?>" <?= $form['brand'] === $brand ? 'selected' : '' ?>><?=e($brand)?></option><?php endforeach; ?></select></label><label>Geçerlilik Başlangıç Tarihi<input type="date" name="valid_from" value="<?=e($form['valid_from'])?>" required></label><label>Geçerlilik Bitiş Tarihi<input type="date" name="valid_until" value="<?=e($form['valid_until'])?>" required></label><a class="price-list-cancel" href="<?=e(url('price-lists.php'))?>" title="Geri dön" aria-label="Geri dön"><i class="icon-base ti tabler-arrow-left"></i></a><button type="submit" title="<?= $editId ? 'Güncelle' : 'Kaydet' ?>" aria-label="<?= $editId ? 'Güncelle' : 'Kaydet' ?>"><i class="icon-base ti tabler-device-floppy"></i></button></form><?php endif; ?>
  <div class="table-responsive"><table><thead><tr><th>MARKA</th><th>GEÇERLİLİK BAŞLANGIÇ</th><th>GEÇERLİLİK BİTİŞ</th><th>İŞLEMLER</th></tr></thead><tbody><?php if (!$priceLists): ?><tr><td colspan="4" class="price-list-empty">Kayıt bulunmuyor.</td></tr><?php else: foreach ($priceLists as $list): ?><tr><td><?=e($list['brand'])?></td><td><?=e(format_date_tr($list['valid_from']))?></td><td><?=e(format_date_tr($list['valid_until']))?></td><td class="price-list-actions"><?php if($canEditPrices): ?><a class="edit-button" style="box-sizing:border-box!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;max-width:36px!important;max-height:36px!important;margin:0!important;padding:0!important" href="<?=e(url('price-lists.php?edit=' . (int)$list['id']))?>" title="Düzenle" aria-label="Düzenle"><i class="icon-base ti tabler-pencil"></i></a><?php endif ?><a class="prices-button" style="box-sizing:border-box!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;max-width:36px!important;max-height:36px!important;margin:0!important;padding:0!important" href="<?=e(url('stock-prices.php?price_list_id=' . (int)$list['id']))?>" title="Liste fiyatlarını aç" aria-label="Liste fiyatlarını aç"><i class="icon-base ti tabler-door-enter"></i></a><?php if($canEditPrices): ?><form method="post" style="box-sizing:border-box!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;max-width:36px!important;max-height:36px!important;margin:0!important;padding:0!important"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="delete_id" value="<?=e((string)$list['id'])?>"><button class="delete-button" style="box-sizing:border-box!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;max-width:36px!important;max-height:36px!important;margin:0!important;padding:0!important" type="submit" title="Sil" aria-label="Sil" onclick="return confirm('Liste fiyatı dönemi silinsin mi?')"><i class="icon-base ti tabler-trash"></i></button></form><?php endif ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
</section></main>


<?php patient_footer(); ?>
