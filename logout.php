<?php
require __DIR__ . '/config.php';
$timedOut = isset($_GET['timeout']);
$_SESSION = [];
session_destroy();
redirect('login.php' . ($timedOut ? '?timeout=1' : ''));
