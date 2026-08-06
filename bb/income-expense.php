<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../cash-bootstrap.php';

$userId = (int)($_SESSION['bb_user_id'] ?? 0);
$userStatement = db()->prepare('SELECT id,name,role,active FROM users WHERE id=? LIMIT 1');
$userStatement->execute([$userId]);
$user = $userStatement->fetch();
if (!$user || !(int)$user['active'] || normalize_role((string)$user['role']) !== ROLE_COMPANY_MANAGER) {
    unset($_SESSION['bb_user_id']);
    redirect('bb/login.php');
}

$pdo = db();
ensure_cash_schema($pdo);
$totals = $pdo->query("SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) expense FROM cash_transactions")->fetch() ?: ['income'=>0,'expense'=>0];
$recent = $pdo->query("SELECT transaction_date,description,transaction_type,amount,payment_type FROM cash_transactions ORDER BY transaction_date DESC,id DESC LIMIT 20")->fetchAll();
$income = (float)$totals['income'];
$expense = (float)$totals['expense'];
$net = $income - $expense;
function bb_income_money(float $amount): string { return number_format($amount, 2, ',', '.') . ' TL'; }
function bb_income_date(string $date): string { try { return (new DateTime($date))->format('d.m.Y'); } catch (Throwable $e) { return $date; } }
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gelir / Gider | <?=e(APP_NAME)?></title><link rel="stylesheet" href="<?=e(url('bb/style.css'))?>"></head>
<body class="bb-dashboard"><header class="bb-topbar"><a href="<?=e(url('bb/index.php'))?>" class="bb-brand"><img src="<?=e(url('assets/vox-logo-02.png'))?>" alt="VOX"></a><div><span class="bb-user"><?=e((string)$user['name'])?></span><a class="bb-logout" href="<?=e(url('bb/logout.php'))?>">Çıkış</a></div></header>
<aside class="bb-sidebar"><nav class="bb-sidebar-menu"><a class="bb-menu-item" href="<?=e(url('bb/index.php'))?>">⌂ <b>Ana Sayfa</b></a><a class="bb-menu-item active" href="<?=e(url('bb/income-expense.php'))?>">▣ Gelir / Gider</a><a class="bb-menu-item" href="<?=e(url('employees.php'))?>">♙ Çalışan Performansı</a><a class="bb-menu-item" href="<?=e(url('stocks.php'))?>">▤ Ürün Performansı</a><a class="bb-menu-item" href="<?=e(url('bb/profit-margins.php'))?>">▥ Kar Marjları</a><a class="bb-menu-item" href="<?=e(url('bb/cash.php'))?>">▣ Kasa</a></nav></aside>
<main class="bb-shell"><div class="bb-title"><div><p class="bb-eyebrow">FİNANS</p><h1>Gelir / Gider</h1><p>Kasa hareketlerinden oluşan gelir ve gider özeti.</p></div></div>
<section class="bb-kpis bb-income-kpis"><article class="bb-kpi success"><div><b><?=e(bb_income_money($income))?></b><p>Toplam Gelir</p></div></article><article class="bb-kpi danger"><div><b><?=e(bb_income_money($expense))?></b><p>Toplam Gider</p></div></article><article class="bb-kpi primary"><div><b><?=e(bb_income_money($net))?></b><p>Net Bakiye</p></div></article></section>
<section class="bb-card bb-income-table"><header><div><p class="bb-eyebrow">HAREKETLER</p><h2>Son gelir / gider kayıtları</h2></div></header><div class="bb-income-scroll"><table><thead><tr><th>Tarih</th><th>Açıklama</th><th>Ödeme</th><th>Tür</th><th>Tutar</th></tr></thead><tbody><?php foreach($recent as $row):?><tr><td><?=e(bb_income_date((string)$row['transaction_date']))?></td><td><?=e((string)$row['description'])?></td><td><?=e((string)$row['payment_type'])?></td><td class="<?=e((string)$row['transaction_type'])?>"><?= $row['transaction_type']==='income'?'Gelir':'Gider' ?></td><td class="<?=e((string)$row['transaction_type'])?>"><?=e(bb_income_money((float)$row['amount']))?></td></tr><?php endforeach; if(!$recent):?><tr><td colspan="5">Henüz kasa hareketi bulunmuyor.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
