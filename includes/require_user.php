<?php
// includes/require_user.php
declare(strict_types=1);

require_once __DIR__ . '/require_login.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'user') {
    http_response_code(403);
    exit('Access denied: user only.');
}
