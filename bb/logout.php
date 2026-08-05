<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
unset($_SESSION['bb_user_id']);
redirect('bb/login.php');
