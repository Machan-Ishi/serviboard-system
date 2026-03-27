<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('peso')) {
    function peso($amount): string
    {
        return '₱' . number_format((float)$amount, 2);
    }
}

function portal_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
              AND table_name = :table 
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        $exists = (bool)$stmt->fetchColumn();
        if ($exists) {
            $pdo->query("SELECT 1 FROM public.{$table} LIMIT 1");
        }
        $cache[$key] = $exists;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function portal_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM information_schema.columns 
            WHERE table_schema = 'public' 
              AND table_name = :table 
              AND column_name = :column 
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function portal_resolve_client(PDO $pdo, int $userId, string $fallbackName = 'User'): array
{
    $context = [
        'user_id' => $userId,
        'user_name' => $fallbackName,
        'user_email' => '',
        'client_id' => 0,
        'party_name' => $fallbackName,
        'department' => '',
    ];

    $userCols = ['id', 'name', 'email'];
    $sql = 'SELECT ' . implode(', ', $userCols) . ' FROM public.users WHERE id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch() ?: [];

    if (!empty($userRow['name'])) {
        $context['user_name'] = (string)$userRow['name'];
    }
    if (!empty($userRow['email'])) {
        $context['user_email'] = (string)$userRow['email'];
    }

    // Attempt to resolve client context from ar_ap (party_name)
    if (portal_table_exists($pdo, 'ar_ap')) {
        $clientStmt = $pdo->prepare("SELECT DISTINCT party_name FROM public.ar_ap WHERE party_name = ? OR party_name = ? LIMIT 1");
        $clientStmt->execute([$context['user_name'], $context['user_email']]);
        $row = $clientStmt->fetch();
        if ($row) {
            $context['party_name'] = $row['party_name'];
        }
    }

    return $context;
}

function portal_invoice_status(float $total, float $paid, ?string $dueDate): string
{
    $balance = max(0.0, $total - $paid);
    if ($balance <= 0.00001) {
        return 'PAID';
    }
    if ($paid > 0.00001 && $paid < $total) {
        if (!empty($dueDate) && strtotime($dueDate) < strtotime(date('Y-m-d'))) {
            return 'OVERDUE';
        }
        return 'PARTIAL';
    }
    if (!empty($dueDate) && strtotime($dueDate) < strtotime(date('Y-m-d'))) {
        return 'OVERDUE';
    }
    return 'UNPAID';
}

function portal_status_badge(string $status): string
{
    return match (strtoupper($status)) {
        'PAID', 'RESOLVED', 'POSTED' => 'badge-paid',
        'PARTIAL', 'PROCESSING', 'IN REVIEW' => 'badge-partial',
        'OVERDUE' => 'badge-overdue',
        'REJECTED' => 'badge-unpaid',
        default => 'badge-unpaid',
    };
}

/**
 * @deprecated Use migrate.php script for schema updates
 */
function portal_ensure_client_requests_schema(PDO $pdo): void
{
    // Logic moved to scripts/migrate.php
}
