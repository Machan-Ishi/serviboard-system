<?php
// includes/require_admin.php
declare(strict_types=1);

require_once __DIR__ . '/require_login.php';

$role = strtolower((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? ''));

if ($role !== 'admin') {
    http_response_code(403);
    echo "Access denied: admin only. (Current role: " . htmlspecialchars($role) . ")";
    exit;
}
