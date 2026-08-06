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
    'income-expense' => ['Gelir / Gider', 'M3 7h18v12H3zM3 10h18M7 15h3'],
    'employee-performance' => ['Çalışan Performansı', 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8m6-1a4 4 0 0 1 0 8'],
    'product-performance' => ['Ürün Performansı', 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5'],
    'profit-margins' => ['Kar Marjları', 'M4 19V5m0 14h16M8 16v-5m4 5V7m4 9v-3'],
];
function bb_section_icon(string $path): string { return '<svg class="bb-menu-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="' . e($path) . '"/></svg>'; }
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | <?=e(APP_NAME)?></title><link rel="stylesheet" href="<?=e(url('bb/style.css'))?>"></head><body class="bb-dashboard"><header class="bb-topbar"><a href="<?=e(url('bb/index.php'))?>" class="bb-brand"><img src="<?=e(url('assets/vox-logo-02.png'))?>" alt="VOX"></a><div><span class="bb-user"><?=e((string)$user['name'])?></span><a class="bb-logout" href="<?=e(url('bb/logout.php'))?>">Çıkış</a></div></header><aside class="bb-sidebar"><nav class="bb-sidebar-menu"><a class="bb-menu-item" href="<?=e(url('bb/index.php'))?>"><?=bb_section_icon('M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z')?><b>Ana Sayfa</b></a><?php foreach($menu as $menuKey=>[$menuLabel,$iconPath]):?><a class="bb-menu-item <?=$menuKey===$key?'active':''?>" href="<?=e(url('bb/section.php?page='.$menuKey))?>"><?=bb_section_icon($iconPath)?><?=e($menuLabel)?></a><?php endforeach;?><a class="bb-menu-item" href="<?=e(url('bb/cash.php'))?>"><?=bb_section_icon('M5 7h14v13H5zM8 7V5h8v2M8 12h8M8 16h5')?>Kasa</a></nav></aside><main class="bb-shell"><div class="bb-title"><div><p class="bb-eyebrow">YÖNETİM PANELİ</p><h1><?=e($title)?></h1><p><?=e($description)?></p></div></div></main></body></html>
