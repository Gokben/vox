<?php
declare(strict_types=1);

$cssPath = __DIR__ . '/assets/vendor/fonts/iconify-icons.css';
$css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
preg_match_all('/\.((?:tabler)-[a-z0-9-]+)\s*\{/', $css, $matches);
$icons = array_values(array_unique($matches[1] ?? []));
sort($icons, SORT_NATURAL);

$used = [
    'tabler-smart-home',
    'tabler-layout-sidebar',
    'tabler-calendar',
    'tabler-layout-kanban',
    'tabler-refresh',
    'tabler-shopping-cart',
    'tabler-file-report',
    'tabler-package',
    'tabler-settings',
    'tabler-tools',
    'tabler-user-plus',
    'tabler-users',
];
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>VOX İkon Seti</title>
  <link rel="stylesheet" href="assets/vendor/fonts/iconify-icons.css?v=10.11.1">
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f5f5f9;color:#2f2b3d;font:15px Arial,sans-serif}
    .page{max-width:1380px;margin:auto;padding:32px 24px 60px}.head{display:flex;align-items:end;justify-content:space-between;gap:24px;margin-bottom:24px}
    h1{margin:0 0 7px;font-size:30px}.muted{margin:0;color:#777486}.count{font-weight:700;color:#159447}
    .search{position:sticky;top:0;z-index:3;padding:14px 0;background:#f5f5f9}.search input{width:100%;height:48px;padding:0 16px;border:1px solid #d8d8e2;border-radius:9px;background:#fff;font-size:16px;outline:none}.search input:focus{border-color:#19a94b;box-shadow:0 0 0 3px #19a94b1c}
    h2{margin:28px 0 14px;font-size:19px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
    .icon-card{display:flex;align-items:center;gap:12px;min-width:0;padding:13px;border:1px solid #e1e2e8;border-radius:9px;background:#fff;color:inherit;text-align:left;cursor:pointer}
    .icon-card:hover{border-color:#19a94b;box-shadow:0 4px 12px #1e283c14}.icon-card i{flex:0 0 auto;font-size:25px;color:#535064}.icon-card span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}
    .empty{display:none;padding:28px;border-radius:9px;background:#fff;text-align:center;color:#777486}.note{margin:12px 0 0;color:#777486;font-size:13px}
    .toast{position:fixed;right:22px;bottom:22px;display:none;padding:12px 16px;border-radius:8px;background:#19a94b;color:#fff;box-shadow:0 6px 20px #0002}
    @media(max-width:640px){.page{padding:22px 14px 45px}.head{display:block}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.icon-card{padding:11px 9px}.icon-card i{font-size:22px}}
  </style>
</head>
<body>
<main class="page">
  <div class="head">
    <div><h1>VOX İkon Seti</h1><p class="muted">Vuexy Tabler/Iconify ikonları</p></div>
    <div class="count"><?=count($icons)?> ikon</div>
  </div>

  <h2>Yazılımda kullanılan ikonlar</h2>
  <div class="grid">
    <?php foreach ($used as $icon): ?>
      <button class="icon-card" type="button" data-name="<?=htmlspecialchars($icon, ENT_QUOTES, 'UTF-8')?>"><i class="ti <?=htmlspecialchars($icon, ENT_QUOTES, 'UTF-8')?>"></i><span><?=$icon?></span></button>
    <?php endforeach ?>
  </div>

  <div class="search"><input id="icon-search" type="search" placeholder="İkon ara: user, calendar, report, phone..." autocomplete="off"></div>
  <h2>Tüm ikonlar</h2>
  <div id="icon-grid" class="grid"></div>
  <div id="empty" class="empty">Aramanızla eşleşen ikon bulunamadı.</div>
  <p id="note" class="note"></p>
</main>
<div id="toast" class="toast">İkon sınıfı kopyalandı</div>
<script>
const icons = <?=json_encode($icons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const grid = document.getElementById('icon-grid');
const search = document.getElementById('icon-search');
const empty = document.getElementById('empty');
const note = document.getElementById('note');
const toast = document.getElementById('toast');
const limit = 300;

function copyIcon(name) {
  const value = `<i class="ti ${name}"></i>`;
  navigator.clipboard?.writeText(value);
  toast.textContent = `${name} kopyalandı`;
  toast.style.display = 'block';
  clearTimeout(copyIcon.timer);
  copyIcon.timer = setTimeout(() => toast.style.display = 'none', 1400);
}

function render() {
  const query = search.value.trim().toLocaleLowerCase('tr');
  const matches = icons.filter(name => !query || name.includes(query));
  const shown = matches.slice(0, limit);
  grid.replaceChildren(...shown.map(name => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'icon-card';
    button.title = 'HTML kodunu kopyala';
    button.innerHTML = `<i class="ti ${name}"></i><span>${name}</span>`;
    button.addEventListener('click', () => copyIcon(name));
    return button;
  }));
  empty.style.display = matches.length ? 'none' : 'block';
  note.textContent = matches.length > limit
    ? `${matches.length} sonuçtan ilk ${limit} tanesi gösteriliyor. Aramayı daraltabilirsiniz.`
    : `${matches.length} ikon gösteriliyor. Kopyalamak için ikona tıklayın.`;
}

document.querySelectorAll('[data-name]').forEach(button => button.addEventListener('click', () => copyIcon(button.dataset.name)));
search.addEventListener('input', render);
render();
</script>
</body>
</html>
