<?php
declare(strict_types=1);

$lockFile   = __DIR__ . '/config/.installed';
$configFile = __DIR__ . '/config/config.php';

if (!file_exists($lockFile) || !file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
