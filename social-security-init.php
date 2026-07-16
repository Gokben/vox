<?php
putenv('APP_ENV=local');
require __DIR__.'/config.php';
require __DIR__.'/social-security-bootstrap.php';
echo count(social_security_definitions())." sosyal güvence tanımı hazır.\n";
