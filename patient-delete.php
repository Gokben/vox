<?php
require __DIR__.'/config.php';require_login();if($_SERVER['REQUEST_METHOD']!=='POST')redirect('patients.php');verify_csrf();$st=db()->prepare('DELETE FROM patients WHERE id=?');$st->execute([(int)($_POST['id']??0)]);redirect('patients.php');
