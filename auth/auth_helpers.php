<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function auth_safe_primary_pdo(): ?PDO
{
    if (!class_exists('PDO')) {
        return null;
    }

    try {
        $availableDrivers = PDO::getAvailableDrivers();
    } catch (Throwable) {
        return null;
    }

    $configuredDriver = strtolower((string) config('DB_DRIVER', 'pgsql'));

    try {
        if ($configuredDriver === 'mysql' && in_array('mysql', $availableDrivers, true)) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) config('DB_HOST', '127.0.0.1'),
                (string) config('DB_PORT', '3306'),
                (string) config('DB_NAME', 'financialsm')
            );

            return new PDO($dsn, (string) config('DB_USER', 'root'), (string) config('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        }

        if (in_array('pgsql', $availableDrivers, true)) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=5',
                (string) config('DB_HOST', 'aws-1-ap-southeast-1.pooler.supabase.com'),
                (string) config('DB_PORT', '5432'),
                (string) config('DB_NAME', 'postgres'),
                (string) config('DB_SSL_MODE', 'require')
            );

            return new PDO($dsn, (string) config('DB_USER', 'postgres'), (string) config('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    } catch (Throwable) {
        return null;
    }

    return null;
}

function auth_table_has_column(PDO $pdo, string $schema, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = :table_schema
          AND table_name = :table_name
          AND column_name = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_schema' => $schema,
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (bool) $stmt->fetchColumn();
}

function auth_table_columns(PDO $pdo, string $schema, string $table): array
{
    static $cache = [];

    $cacheKey = implode(':', [$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), $schema, $table]);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = :table_schema
          AND table_name = :table_name
    ");
    $stmt->execute([
        ':table_schema' => $schema,
        ':table_name' => $table,
    ]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[] = strtolower((string) $column);
    }

    $cache[$cacheKey] = $columns;
    return $columns;
}

function auth_normalize_identifier(string $value): string
{
    return strtolower(trim($value));
}

function auth_resolve_role(array $user): string
{
    $userType = strtolower(trim((string) ($user['user_type'] ?? '')));
    if ($userType === 'admin') {
        return 'admin';
    }

    $roleSlug = strtolower(trim((string) ($user['role_slug'] ?? '')));
    if ($roleSlug === 'admin') {
        return 'admin';
    }

    if (in_array($roleSlug, ['user', 'client'], true)) {
        return 'user';
    }

    return 'user';
}

function auth_resolve_subsystem(array $user): string
{
    $department = strtolower(trim((string) ($user['department'] ?? '')));
    $role = auth_resolve_role($user);

    if ($role === 'user') {
        return 'user';
    }

    if (str_contains($department, 'logistic')) {
        return 'logistics';
    }

    if (str_contains($department, 'hr')) {
        return 'hr';
    }

    if (
        str_contains($department, 'core')
        || str_contains($department, 'financ')
        || str_contains($department, 'payroll')
        || str_contains($department, 'account')
    ) {
        return 'financial';
    }

    return 'financial';
}

function auth_redirect_path_for_user(array $user): string
{
    $role = auth_resolve_role($user);
    $subsystem = auth_resolve_subsystem($user);

    if ($role === 'user') {
        return BASE_URL . '/user/dashboard.php';
    }

    return match ($subsystem) {
        'hr' => BASE_URL . '/hr/requests/index.php',
        'logistics' => BASE_URL . '/logistics/requests/index.php',
        default => BASE_URL . '/financial/index.php',
    };
}

function auth_fetch_system_user_from_pdo(PDO $pdo, string $identifier): ?array
{
    $normalized = auth_normalize_identifier($identifier);
    if ($normalized === '') {
        return null;
    }

    $schemaName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'public' : $pdo->query('SELECT DATABASE()')->fetchColumn();
    $schema = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'public.' : '';
    $table = $schema . 'users';
    $columns = auth_table_columns($pdo, (string) $schemaName, 'users');
    $conditions = [];

    if (in_array('email', $columns, true)) {
        $conditions[] = "LOWER(COALESCE(email, '')) = :identifier";
    }
    if (in_array('username', $columns, true)) {
        $conditions[] = "LOWER(COALESCE(username, '')) = :identifier";
    }
    if (in_array('user_name', $columns, true)) {
        $conditions[] = "LOWER(COALESCE(user_name, '')) = :identifier";
    }

    if ($conditions === []) {
        throw new RuntimeException('The users table has no login identifier columns.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM {$table}
        WHERE " . implode(' OR ', $conditions) . "
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':identifier' => $normalized]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function auth_supabase_rest_request(string $path): ?array
{
    $url = rtrim((string) config('SUPABASE_URL', ''), '/');
    $key = (string) config('SUPABASE_SERVICE_ROLE_KEY', config('SUPABASE_ANON_KEY', ''));
    if ($url === '' || $key === '') {
        return null;
    }

    $endpoint = $url . '/' . ltrim($path, '/');
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return null;
        }

        return [
            'status' => $httpCode,
            'data' => json_decode((string) $response, true),
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'header' => implode("\r\n", $headers),
        ],
    ]);

    $response = @file_get_contents($endpoint, false, $context);
    if ($response === false) {
        return null;
    }

    $status = 200;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('/\s(\d{3})\s/', (string) $headerLine, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    return [
        'status' => $status,
        'data' => json_decode($response, true),
    ];
}

function auth_fetch_system_user_from_supabase(string $identifier): ?array
{
    $normalized = auth_normalize_identifier($identifier);
    if ($normalized === '') {
        return null;
    }

    $candidateColumns = str_contains($normalized, '@')
        ? ['email', 'username', 'user_name']
        : ['username', 'user_name', 'email'];

    foreach ($candidateColumns as $column) {
        $query = http_build_query([
            'select' => '*',
            $column => 'eq.' . $normalized,
            'limit' => 1,
        ]);

        $response = auth_supabase_rest_request('/rest/v1/users?' . $query);
        if (!$response || (int) ($response['status'] ?? 0) >= 400) {
            continue;
        }

        $data = $response['data'] ?? null;
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }
    }

    return null;
}

function auth_fetch_system_user(string $identifier, ?PDO $pdo = null): ?array
{
    $normalized = auth_normalize_identifier($identifier);
    if ($normalized === '') {
        return null;
    }

    $pdo ??= auth_safe_primary_pdo();
    if ($pdo instanceof PDO) {
        try {
            $user = auth_fetch_system_user_from_pdo($pdo, $normalized);
            if ($user) {
                return $user;
            }
        } catch (Throwable) {
            // Fall back to the REST API when direct DB access is unavailable.
        }
    }

    return auth_fetch_system_user_from_supabase($normalized);
}

function auth_verify_system_user_password(array $user, string $password): bool
{
    $candidates = [];
    foreach (['password_hash', 'password', 'passwd', 'pass', 'user_password'] as $column) {
        $value = trim((string) ($user[$column] ?? ''));
        if ($value !== '') {
            $candidates[] = $value;
        }
    }

    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $candidate) {
        $info = password_get_info($candidate);
        if (($info['algo'] ?? null) !== null && (int) ($info['algo'] ?? 0) !== 0 && password_verify($password, $candidate)) {
            return true;
        }

        if (hash_equals($candidate, $password)) {
            return true;
        }
    }

    return false;
}

function auth_upgrade_legacy_password_if_needed(array $user, string $password): void
{
    $userId = (string) ($user['id'] ?? '');
    if ($userId === '' || $password === '') {
        return;
    }

    $plaintextColumns = [];
    $hasVerifiedHash = false;
    foreach (['password_hash', 'password', 'passwd', 'pass', 'user_password'] as $column) {
        $candidate = trim((string) ($user[$column] ?? ''));
        if ($candidate === '') {
            continue;
        }

        $info = password_get_info($candidate);
        if (($info['algo'] ?? null) !== null && (int) ($info['algo'] ?? 0) !== 0 && password_verify($password, $candidate)) {
            $hasVerifiedHash = true;
            break;
        }

        if (hash_equals($candidate, $password)) {
            $plaintextColumns[] = $column;
        }
    }

    if ($hasVerifiedHash || $plaintextColumns === []) {
        return;
    }

    $pdo = auth_safe_primary_pdo();
    if (!$pdo) {
        return;
    }

    $schemaName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? 'public'
        : (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($schemaName === '') {
        return;
    }

    $table = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'public.users' : 'users';
    $columns = auth_table_columns($pdo, $schemaName, 'users');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return;
    }

    $assignments = [];
    $params = [':id' => $userId];
    $updatedColumns = [];

    if (in_array('password_hash', $columns, true)) {
        $assignments[] = 'password_hash = :password_hash';
        $params[':password_hash'] = $hash;
        $updatedColumns[] = 'password_hash';
    }

    foreach ($plaintextColumns as $column) {
        if (!in_array(strtolower($column), $columns, true)) {
            continue;
        }

        $placeholder = ':' . $column;
        $assignments[] = $column . ' = ' . $placeholder;
        $params[$placeholder] = $hash;
        $updatedColumns[] = strtolower($column);
    }

    if ($assignments === []) {
        return;
    }

    if (in_array('updated_at', $columns, true)) {
        $assignments[] = 'updated_at = NOW()';
    }

    $stmt = $pdo->prepare("
        UPDATE {$table}
        SET " . implode(', ', array_unique($assignments)) . "
        WHERE id = :id
    ");
    $stmt->execute($params);

    foreach (array_unique($updatedColumns) as $column) {
        $user[$column] = $hash;
    }
}

function auth_store_user_session(array $user): void
{
    $_SESSION['user_id'] = (string) ($user['id'] ?? '');
    $_SESSION['user_name'] = (string) ($user['name'] ?? $user['full_name'] ?? $user['username'] ?? $user['user_name'] ?? $user['email'] ?? 'User');
    $_SESSION['user_email'] = (string) ($user['email'] ?? '');
    $_SESSION['user_department'] = (string) ($user['department'] ?? '');
    $_SESSION['user_type'] = (string) ($user['user_type'] ?? '');
    $_SESSION['user_role'] = auth_resolve_role($user);
    $_SESSION['role'] = $_SESSION['user_role'];
    $_SESSION['subsystem'] = auth_resolve_subsystem($user);
    $_SESSION['login_redirect'] = auth_redirect_path_for_user($user);
}
