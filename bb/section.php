<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$userId = (int)($_SESSION['bb_user_id'] ?? 0);
$statement = db()->prepare('SELECT id,name,role,active FROM users WHERE id=? LIMIT 1');
$statement->execute([$userId]);
$user = $statement->fetch();
if (!$user || !(int)$user['active'] || normalize_role((string)$user['role']) !== ROLE_COMPANY_MANAGER) {
    unset($_SESSION['bb_user_id']);
    redirect('bb/login.php');
}

$sections = [
    'income-expense' => ['Gelir / Gider', 'Gelir ve gider analizi hazırlanmaktadır.'],
    'employee-performance' => ['Çalışan Performansı', 'Çalışan performans analizi hazırlanmaktadır.'],
    'product-performance' => ['Ürün Performansı', 'Ürün performans analizi hazırlanmaktadır.'],
    'profit-margins' => ['Kar Marjları', 'Kar marjı analiz ekranı hazırlanmaktadır.'],
];
$key = (string)($_GET['page'] ?? 'income-expense');
if (!isset($sections[$key])) $key = 'income-expense';
[$title, $description] = $sections[$key];
$menu = [
    'income-expense' => 'Gelir / Gider',
    'employee-performance' => 'Çalışan Performansı',
    'product-performance' => 'Ürün Performansı',
    'profit-margins' => 'Kar Marjları',
];
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | <?=e(APP_NAME)?></title><link rel="stylesheet" href="<?=e(url('bb/style.css'))?>"></head><body class="bb-dashboard"><header class="bb-topbar"><a href="<?=e(url('bb/index.php'))?>" class="bb-brand"><img src="<?=e(url('assets/vox-logo-02.png'))?>" alt="VOX"></a><div><span class="bb-user"><?=e((string)$user['name'])?></span><a class="bb-logout" href="<?=e(url('bb/logout.php'))?>">Çıkış</a></div></header><aside class="bb-sidebar"><nav class="bb-sidebar-menu"><a class="bb-menu-item" href="<?=e(url('bb/index.php'))?>">⌂ <b>Ana Sayfa</b></a><?php foreach($menu as $menuKey=>$menuLabel):?><a class="bb-menu-item <?=$menuKey===$key?'active':''?>" href="<?=e(url('bb/section.php?page='.$menuKey))?>">▣ <?=e($menuLabel)?></a><?php endforeach;?><a class="bb-menu-item" href="<?=e(url('bb/cash.php'))?>">▣ Kasa</a></nav></aside><main class="bb-shell"><div class="bb-title"><div><p class="bb-eyebrow">YÖNETİM PANELİ</p><h1><?=e($title)?></h1><p><?=e($description)?></p></div></div></main></body></html>
