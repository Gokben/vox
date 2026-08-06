<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$userId = (int)($_SESSION['bb_user_id'] ?? 0);
$statement = db()->prepare('SELECT id,name,email,role,active FROM users WHERE id=? LIMIT 1');
$statement->execute([$userId]);
$user = $statement->fetch();

if (!$user || !(int)$user['active'] || normalize_role((string)$user['role']) !== ROLE_COMPANY_MANAGER) {
    unset($_SESSION['bb_user_id']);
    redirect('bb/login.php');
}

// Mevcut kasa modülü ana uygulama oturumunu kullanır. BB'de doğrulanan
// Firma Yöneticisi için bu oturumu yalnızca kasa ekranı açılırken oluşturur.
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'name' => (string)$user['name'],
    'email' => (string)$user['email'],
    'role' => (string)$user['role'],
    'active' => (int)$user['active'],
];
$_SESSION['bb_cash_session_user_id'] = (int)$user['id'];

require __DIR__ . '/../cash.php';
?>
<style>
  .bb-cash-page .patient-nav .bb-cash-nav-item{display:flex!important;align-items:center!important;gap:12px!important;width:calc(100% - 24px)!important;min-height:38px!important;margin:3px 12px!important;padding:8px 12px!important;border-radius:6px!important;color:#565466!important;font-size:15px!important;line-height:1.467!important;text-decoration:none!important}
  .bb-cash-page .patient-nav .bb-cash-nav-item.active{background:linear-gradient(100deg,#159447,#43c936)!important;color:#fff!important;box-shadow:0 5px 12px rgba(25,169,75,.23)!important}
  .bb-cash-page .patient-nav .bb-cash-nav-item svg{width:19px;height:19px;flex:0 0 19px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
</style>
<script>
(() => {
  document.body.classList.add('bb-cash-page');
  const nav = document.querySelector('.patient-nav');
  const brand = document.querySelector('.patient-brand');
  if (brand) brand.href = <?=json_encode(url('bb/index.php'))?>;
  if (!nav) return;
  const icon = path => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${path}"/></svg>`;
  nav.innerHTML = `
    <a class="bb-cash-nav-item" href="<?=e(url('bb/index.php'))?>">${icon('M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z')}Ana Sayfa</a>
    <a class="bb-cash-nav-item" href="<?=e(url('bb/income-expense.php'))?>">${icon('M3 7h18v12H3zM3 10h18M7 15h3')}Gelir / Gider</a>
    <a class="bb-cash-nav-item" href="<?=e(url('employees.php'))?>">${icon('M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8m6-1a4 4 0 0 1 0 8')}Çalışan Performansı</a>
    <a class="bb-cash-nav-item" href="<?=e(url('bb/section.php?page=product-performance'))?>">${icon('M4 4h16v16H4zM8 8h8M8 12h8M8 16h5')}Ürün Performansı</a>
    <a class="bb-cash-nav-item" href="<?=e(url('bb/profit-margins.php'))?>">${icon('M4 19V5m0 14h16M8 16v-5m4 5V7m4 9v-3')}Kar Marjları</a>
    <a class="bb-cash-nav-item active" href="<?=e(url('bb/cash.php'))?>">${icon('M5 7h14v13H5zM8 7V5h8v2M8 12h8M8 16h5')}Kasa</a>`;
})();
</script>
<script>
(() => { const pages={'Gelir / Gider':'income-expense','Çalışan Performansı':'employee-performance','Ürün Performansı':'product-performance','Kar Marjları':'profit-margins'}; document.querySelectorAll('.bb-cash-nav-item').forEach(link=>{const page=pages[link.textContent.trim()];if(page)link.href=<?=json_encode(url('bb/section.php?page='))?>+page;}); })();
</script>
