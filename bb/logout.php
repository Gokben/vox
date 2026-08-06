<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
if ((int)($_SESSION['bb_cash_session_user_id'] ?? 0) === (int)($_SESSION['bb_user_id'] ?? 0)) {
    unset($_SESSION['user'], $_SESSION['bb_cash_session_user_id']);
}
unset($_SESSION['bb_user_id']);
redirect('bb/login.php');
