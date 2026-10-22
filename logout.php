<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

us_logout();
header('Location: login.php');
exit;
