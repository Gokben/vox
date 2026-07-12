<?php
// Kurulumdan sonra bu dosyayı sunucudan silin.
$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strlen($_POST['password'] ?? '') >= 10) $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
?><!doctype html><html lang="tr"><meta charset="utf-8"><title>Şifre özeti</title><body style="font:16px system-ui;max-width:700px;margin:60px auto"><h1>Yönetici şifresi oluştur</h1><form method="post"><input type="password" name="password" minlength="10" required><button>Özet üret</button></form><?php if($hash): ?><p>Bu özeti database.sql içindeki OZET alanına yapıştırın:</p><textarea style="width:100%;height:100px"><?= htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') ?></textarea><?php endif ?></body></html>
