<?php
require __DIR__.'/config.php';
require_login();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$sql = 'SELECT * FROM items WHERE 1=1';
$params = [];
if ($q !== '') {
    $searchColumns=['item_no','serial_no','related_items','location','found_department','found_by','category','name','brand','color','details','storage_location','status','recorded_by','delivery_method','delivered_by','delivery_form_no'];
    $terms=array_slice(array_values(array_filter(array_map('trim',explode(';',$q)),fn($term)=>$term!=='')),0,12);
    foreach($terms as $term){$sql.=' AND ('.implode(' OR ',array_map(fn($column)=>$column.' LIKE ?',$searchColumns)).')';$like='%'.$term.'%';foreach($searchColumns as $_)$params[]=$like;}
}
if ($status !== '') {
    $sql .= ' AND status=?';
    $params[] = $status;
}
$sql .= ' ORDER BY CASE WHEN import_order IS NULL THEN 0 ELSE 1 END, import_order ASC, id DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
$counts = db()->query("SELECT COUNT(*) total,SUM(status='Depoda') storage,SUM(status='Eşleşme bekliyor') waiting,SUM(status LIKE 'Teslim edildi%') delivered FROM items")->fetch();
$statuses = ['Eşleşme bekliyor','Talep sahibinden eylem bekliyor','Yetkilendirilmiş kişi bekleniyor','Teslim edildi','Teslim edildi (Görüşüldü)','Depoda','Kargolandı','Tasfiye edildi'];
$profileStmt=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$profileStmt->execute(['profile_'.(int)$_SESSION['user']['id']]);$profile=json_decode((string)$profileStmt->fetchColumn(),true)?:[];$avatar=$profile['avatar']??'';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bulunan Eşyalar | <?=APP_NAME?></title>
<link rel="icon" href="<?=url('assets/favicon.png')?>">
<link rel="stylesheet" href="<?=url('assets/style.css')?>"><link rel="stylesheet" href="<?=url('assets/items-list.css')?>">
<link rel="stylesheet" href="<?=url('assets/amerce/fonts/fonts.css')?>"><link rel="stylesheet" href="<?=url('assets/amerce/icon/icomoon/style.css')?>">
<link rel="stylesheet" href="<?=url('assets/amerce/css/bootstrap.min.css')?>"><link rel="stylesheet" href="<?=url('assets/amerce/css/styles.css')?>">
<link rel="stylesheet" href="<?=url('assets/amerce-lf.css')?>"><link rel="stylesheet" href="<?=url('assets/vuexy-inspired.css')?>"><link rel="stylesheet" href="<?=url('assets/dashboard-header.css')?>">
<link rel="stylesheet" href="<?=url('assets/green-buttons.css?v=20260712-3')?>"><script src="<?=url('assets/theme.js?v=20260712-2')?>"></script></head>
<body>
<header class="app-header">
  <div class="header-top">
    <a class="brand brand-back-link" href="<?=url('index.php')?>" title="Ana sayfa"><img class="brand-logo" style="display:block!important;width:38px!important;height:38px!important;object-fit:contain!important" src="<?=url('assets/kirpisoftware-logo-transparent-v2.png')?>" alt="Kirpisoft"><span><b>Lost &amp; Found</b></span></a>
    <div class="header-tools">
      <button type="button" title="Arama" aria-label="Arama">⌕</button><button type="button" title="Dil">TR</button><button id="theme-toggle" type="button" title="Gece görünümüne geç" aria-label="Görünümü değiştir"><span class="theme-icon">☼</span></button>
      <div class="account-menu"><button id="account-toggle" class="account-toggle" type="button" aria-expanded="false"><span class="user-avatar"><?php if($avatar):?><img src="<?=url($avatar)?>" alt="Profil"><?php else:?><?=e(mb_strtoupper(mb_substr($_SESSION['user']['name'],0,1)))?><?php endif?></span><span class="user-name"><?=e($_SESSION['user']['name'])?><small><?=e($_SESSION['user']['role'])?></small></span><span class="account-chevron">⌄</span></button>
        <div id="account-dropdown" class="account-dropdown"><div class="account-card-head"><span class="user-avatar large"><?php if($avatar):?><img src="<?=url($avatar)?>" alt="Profil"><?php else:?><?=e(mb_strtoupper(mb_substr($_SESSION['user']['name'],0,1)))?><?php endif?></span><span><?=e($_SESSION['user']['name'])?><small><?=e($_SESSION['user']['role'])?></small></span></div><a href="<?=url('profile.php')?>"><span>♙</span> Profilim</a><?php if(is_admin()):?><a href="<?=url('admin.php')?>"><span>⚙</span> Ayarlar</a><?php endif?><a class="dropdown-logout" href="<?=url('logout.php')?>">Çıkış <span>↪</span></a></div>
      </div>
    </div>
  </div>
  <nav class="main-nav">
    <a class="active" href="<?=url('index.php')?>"><span>⌂</span> Ana Sayfa</a>
    <a href="<?=url('index.php')?>"><span>▣</span> Bulunan Eşyalar</a>
    <a href="<?=url('item-new.php')?>"><span>＋</span> Eşya Ekle</a>
    <a href="#"><span>◎</span> Talepler</a><a href="#"><span>⇄</span> Eşleşmeler</a>
    <a href="#"><span>▥</span> Teslimatlar</a><a href="#"><span>▤</span> Raporlar</a>
    <?php if(is_admin()):?><a href="<?=url('admin.php')?>"><span>⚙</span> Ayarlar</a><?php endif?>
  </nav>
</header>
<main class="container">
<section class="title-row"><div><h1>Bulunan Eşyalar</h1><p>Otelde bulunan eşyaları kaydedin, takip edin ve teslim süreçlerini yönetin.</p></div><div class="title-actions"><button id="stretch-toggle" class="view-toggle" type="button" title="Esnek görünüme geç" aria-label="Esnek görünüme geç" aria-pressed="false"><span aria-hidden="true">⤢</span></button><a class="primary button" href="<?=url('item-new.php')?>">+ Yeni Eşya Kaydı</a></div></section>
<section class="stats"><article><span>Toplam Kayıt</span><strong><?=(int)($counts['total']??0)?></strong></article><article><span>Depodaki Eşyalar</span><strong><?=(int)($counts['storage']??0)?></strong></article><article><span>Eşleşme Bekleyen</span><strong><?=(int)($counts['waiting']??0)?></strong></article><article><span>Teslim Edilen</span><strong><?=(int)($counts['delivered']??0)?></strong></article></section>
<section class="panel"><form class="filters"><input name="q" value="<?=e($q)?>" placeholder="Birden fazla terim için ; kullanın (Lobby; Apple; Beyaz)" title="Her terim farklı bir kolonda bulunabilir. Terimleri noktalı virgülle ayırın."><select name="status"><option value="">Tüm Durumlar</option><?php foreach($statuses as $s):?><option <?=($status===$s)?'selected':''?>><?=e($s)?></option><?php endforeach?></select><button type="submit">Ara</button></form>
<div class="table-wrap found-items-table"><table><thead><tr><th></th><th>Eşya<br>No</th><th>Seri No</th><th>Bulunduğu<br>Zaman</th><th>Bulunduğu<br>Yer</th><th>Kategori</th><th>Eşya İsmi</th><th>Marka</th><th>Renk</th><th>Bulan</th><th>Kaydeden</th><th>Görseller</th><th>Miktar</th><th>Detaylar</th><th>Depo</th><th>Eşya<br>Statüsü</th><th>Tasfiye Takibi</th><th>İlgili Eşleşmeler<br>/ Talepler</th><th>Teslimat<br>Tarihi</th><th>Detaylar</th><th>İletişim<br>Durumu</th><th>Eylemler</th></tr></thead><tbody>
<?php foreach($items as $item):?><tr>
<td><button class="row-toggle" type="button" title="Satırı göster">»</button></td><td><b class="item-no"><?=e($item['item_no'])?></b></td><td><?=e($item['serial_no']??'')?></td><td><?=e(date('d-m-Y',strtotime($item['found_at'])))?></td><td><?=e($item['location'])?></td><td><?=e($item['category'])?></td><td class="item-name"><?=e($item['name'])?></td><td><?=e($item['brand']??'')?></td><td><?=e($item['color']??'')?></td><td><?=e($item['found_by']??'')?></td><td><?=e($item['recorded_by']??'')?></td><td><span class="muted-cell">—</span></td><td><?=(int)$item['quantity']?> adet</td><td class="details-cell"><?=e($item['details']??'')?></td><td><?=e($item['storage_location'])?></td><td><span class="status-text"><?=e($item['status'])?></span></td><td><?=str_contains((string)$item['status'],'Tasfiye')?'Tasfiye edildi':'—'?></td><td><?=e($item['related_items']??'')?></td><td><?=$item['delivered_at']?e(date('d-m-Y',strtotime($item['delivered_at']))):''?></td><td><a class="document-action" href="<?=url('item-edit.php?id='.(int)$item['id'])?>" title="Detayları aç">▱</a></td><td><span class="muted-cell">—</span></td><td><div class="item-actions"><a class="action-button folder-action" href="<?=url('item-edit.php?id='.(int)$item['id'])?>" title="Kaydı aç">▰</a><a class="action-button edit-action" href="<?=url('item-edit.php?id='.(int)$item['id'])?>" title="Düzenle">✎</a><?php if(is_admin()):?><form method="post" action="<?=url('item-delete.php')?>" onsubmit="return confirm('Bu eşya kaydı kalıcı olarak silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="action-button delete-action" title="Sil">×</button></form><?php endif?></div></td>
</tr><?php endforeach?>
<?php if(!$items):?><tr><td colspan="22" class="empty">Kayıt bulunamadı.</td></tr><?php endif?></tbody></table></div></section>
</main><script>const root=document.documentElement,themeButton=document.getElementById('theme-toggle'),themeIcon=themeButton.querySelector('.theme-icon');function setTheme(theme){root.dataset.theme=theme;localStorage.setItem('lf-theme',theme);const dark=theme==='dark';themeIcon.textContent=dark?'☾':'☼';themeButton.title=dark?'Gündüz görünümüne geç':'Gece görünümüne geç'}setTheme(localStorage.getItem('lf-theme')||'light');themeButton.addEventListener('click',()=>setTheme(root.dataset.theme==='dark'?'light':'dark'));const accountButton=document.getElementById('account-toggle'),dropdown=document.getElementById('account-dropdown');accountButton.addEventListener('click',event=>{event.stopPropagation();const open=dropdown.classList.toggle('open');accountButton.setAttribute('aria-expanded',open)});document.addEventListener('click',event=>{if(!dropdown.contains(event.target)){dropdown.classList.remove('open');accountButton.setAttribute('aria-expanded','false')}});const stretchButton=document.getElementById('stretch-toggle');function setStretch(enabled){document.body.classList.toggle('stretch-view',enabled);localStorage.setItem('lf-table-stretch',enabled?'1':'0');const label=enabled?'Kompakt görünüme geç':'Esnek görünüme geç';stretchButton.querySelector('span').textContent=enabled?'⤡':'⤢';stretchButton.title=label;stretchButton.setAttribute('aria-label',label);stretchButton.setAttribute('aria-pressed',enabled?'true':'false')}setStretch(localStorage.getItem('lf-table-stretch')==='1');stretchButton.addEventListener('click',()=>setStretch(!document.body.classList.contains('stretch-view')));</script></body></html>
