<?php
require __DIR__.'/config.php';require_admin();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Geçersiz istek.');}
verify_csrf();$id=(int)($_POST['id']??0);if($id>0){$stmt=db()->prepare('DELETE FROM items WHERE id=?');$stmt->execute([$id]);}redirect('index.php');
