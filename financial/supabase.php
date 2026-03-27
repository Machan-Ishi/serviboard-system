<?php
declare(strict_types=1);

/**
 * Supabase Client for PHP
 * Handles PostgREST API interactions for synchronization.
 */

require_once __DIR__ . '/../config/config.php';

class SupabaseClient {
    private string $url;
    private string $key;
    private string $logFile;
    private static array $tableAliases = [
        'audit_logs' => 'log1_audit_logs',
    ];

    public function __construct(string $url, string $key) {
        $this->url = rtrim($url, '/');
        $this->key = $key;
        $this->logFile = __DIR__ . '/supabase_error.log';
    }

    private function logError(string $method, string $endpoint, string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$method} {$endpoint}: {$message}" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    private function resolveTableAlias(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return $table;
        }

        return self::$tableAliases[$table] ?? $table;
    }

    private function cacheTableAlias(string $requestedTable, string $resolvedTable): void
    {
        $requestedTable = trim($requestedTable);
        $resolvedTable = trim($resolvedTable);
        if ($requestedTable === '' || $resolvedTable === '' || $requestedTable === $resolvedTable) {
            return;
        }

        self::$tableAliases[$requestedTable] = $resolvedTable;
    }

    private function hintedTableFromResponse(array $response): ?string
    {
        if (!in_array((int) ($response['status'] ?? 0), [404, 400], true)) {
            return null;
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $hint = trim((string) ($data['hint'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $searchText = $hint !== '' ? $hint : $message;
        if ($searchText === '') {
            return null;
        }

        if (preg_match("/table 'public\\.([^']+)'/i", $searchText, $matches)) {
            return trim((string) $matches[1]);
        }

        return null;
    }

    private function retryWithHintedTable(string $method, string $table, callable $retry): ?array
    {
        $response = $retry($this->resolveTableAlias($table));
        $hintedTable = $this->hintedTableFromResponse($response);
        if ($hintedTable === null) {
            return $response;
        }

        $this->cacheTableAlias($table, $hintedTable);
        $this->logError($method, $table, "Retrying with hinted table: {$hintedTable}");
        return $retry($hintedTable);
    }

    private function makeRequest(string $method, string $endpoint, $data = null, array $extraHeaders = []): array {
        if (!function_exists('curl_init')) {
            $this->logError($method, $endpoint, "PHP cURL extension is not enabled.");
            return ['status' => 500, 'error' => 'cURL extension is missing on the server.'];
        }
        $ch = curl_init($endpoint);
        $headers = [
            "apikey: {$this->key}",
            "Authorization: Bearer {$this->key}",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ];
        if ($extraHeaders) {
            $headers = array_merge($headers, $extraHeaders);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Reduced timeout for better performance
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // Connection timeout
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FinancialSM SupabaseClient/1.0');

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logError($method, $endpoint, "Curl Error: {$curlError}");
            return ['status' => 0, 'error' => $curlError];
        }

        $decodedResponse = json_decode((string)$response, true);
        if ($httpCode >= 400) {
            $this->logError($method, $endpoint, "HTTP {$httpCode} - Response: {$response}");
        }

        return ['status' => $httpCode, 'data' => $decodedResponse];
    }

    public function insert($table, $data) {
        $table = $this->resolveTableAlias((string) $table);
        $res = $this->retryWithHintedTable('POST', $table, function (string $resolvedTable) use ($data): array {
            $endpoint = "{$this->url}/rest/v1/{$resolvedTable}";
            return $this->makeRequest('POST', $endpoint, $data);
        });
        $endpoint = "{$this->url}/rest/v1/{$table}";
        
        // Retry logic for missing columns (PGRST204)
        if ($res['status'] === 400 && isset($res['data']['code']) && $res['data']['code'] === 'PGRST204') {
            $msg = $res['data']['message'] ?? '';
            if (preg_match("/'([^']+)'/", $msg, $matches)) {
                $missingCol = $matches[1];
                $this->logError('POST', $endpoint, "Retrying without missing column: {$missingCol}");
                unset($data[$missingCol]);
                return $this->insert($table, $data);
            }
        }
        
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $this->logError('POST', $endpoint, "SUCCESS: Record synced to Supabase.");
        }
        
        return $res;
    }

    public function update($table, $data, $query) {
        $table = $this->resolveTableAlias((string) $table);
        $res = $this->retryWithHintedTable('PATCH', $table, function (string $resolvedTable) use ($data, $query): array {
            $endpoint = "{$this->url}/rest/v1/{$resolvedTable}?{$query}";
            return $this->makeRequest('PATCH', $endpoint, $data);
        });
        $endpoint = "{$this->url}/rest/v1/{$table}?{$query}";

        // Retry logic for missing columns (PGRST204)
        if ($res['status'] === 400 && isset($res['data']['code']) && $res['data']['code'] === 'PGRST204') {
            $msg = $res['data']['message'] ?? '';
            if (preg_match("/'([^']+)'/", $msg, $matches)) {
                $missingCol = $matches[1];
                $this->logError('PATCH', $endpoint, "Retrying without missing column: {$missingCol}");
                unset($data[$missingCol]);
                return $this->update($table, $data, $query);
            }
        }
        return $res;
    }

    public function delete($table, $query) {
        $table = $this->resolveTableAlias((string) $table);
        return $this->retryWithHintedTable('DELETE', $table, function (string $resolvedTable) use ($query): array {
            $endpoint = "{$this->url}/rest/v1/{$resolvedTable}?{$query}";
            return $this->makeRequest('DELETE', $endpoint);
        });
    }

    public function get($table, $params = []) {
        $queryString = http_build_query($params);
        $table = $this->resolveTableAlias((string) $table);
        return $this->retryWithHintedTable('GET', $table, function (string $resolvedTable) use ($queryString): array {
            $endpoint = "{$this->url}/rest/v1/{$resolvedTable}" . ($queryString ? "?{$queryString}" : "");
            return $this->makeRequest('GET', $endpoint);
        });
    }

    public function request(string $method, string $path, $data = null, array $extraHeaders = []): array
    {
        $endpoint = $this->url . '/' . ltrim($path, '/');
        return $this->makeRequest($method, $endpoint, $data, $extraHeaders);
    }
}

// Global functions for easier access
function supabase_init() {
    global $supabase;
    if (isset($supabase)) return $supabase;

    $url = config('SUPABASE_URL');
    $key = config('SUPABASE_SERVICE_ROLE_KEY', config('SUPABASE_ANON_KEY'));

    if (!$url || !$key) return null;

    $supabase = new SupabaseClient($url, $key);
    return $supabase;
}

function supabase_mirror($table, $data, $action = 'INSERT', $where = []) {
    $client = supabase_init();
    if (!$client) return null;

    if (strtoupper($action) === 'UPDATE' && !empty($where)) {
        $filters = [];
        foreach ($where as $key => $value) {
            $filters[] = "{$key}=eq.{$value}";
        }
        $query = implode('&', $filters);
        return $client->update($table, $data, $query);
    } elseif (strtoupper($action) === 'DELETE' && !empty($where)) {
        $filters = [];
        foreach ($where as $key => $value) {
            $filters[] = "{$key}=eq.{$value}";
        }
        $query = implode('&', $filters);
        return $client->delete($table, $query);
    } else {
        return $client->insert($table, $data);
    }
}

function supabase_mirror_safe($table, $data, $action = 'INSERT', $where = []) {
    try {
        return supabase_mirror($table, $data, $action, $where);
    } catch (Throwable $e) {
        return null;
    }
}

function supabase_is_configured() {
    return (bool) (config('SUPABASE_URL') && (config('SUPABASE_SERVICE_ROLE_KEY') || config('SUPABASE_ANON_KEY')));
}

function supabase_test_connection() {
    $details = supabase_test_connection_details();
    return (bool) ($details['ok'] ?? false);
}

function supabase_test_connection_details(): array {
    $client = supabase_init();
    if (!$client) {
        return [
            'ok' => false,
            'status' => 0,
            'endpoint' => null,
            'error' => 'Supabase client is not configured.',
        ];
    }

    // Public auth settings endpoint is a safer liveness probe than /rest/v1/,
    // which returns 401 for anon keys even when the project is reachable.
    $res = $client->request('GET', '/auth/v1/settings');
    if (in_array($res['status'] ?? 0, [200, 401, 403], true)) {
        return [
            'ok' => true,
            'status' => (int) ($res['status'] ?? 0),
            'endpoint' => '/auth/v1/settings',
            'error' => null,
        ];
    }

    // Fall back to the REST root and treat authorization errors as reachable.
    $restRes = $client->request('GET', '/rest/v1/');
    $ok = in_array($restRes['status'] ?? 0, [200, 401, 403, 404], true);

    return [
        'ok' => $ok,
        'status' => (int) ($restRes['status'] ?? ($res['status'] ?? 0)),
        'endpoint' => $ok ? '/rest/v1/' : '/auth/v1/settings',
        'error' => $ok ? null : ($res['error'] ?? $restRes['error'] ?? 'Unknown Supabase connection failure.'),
        'auth_probe' => $res,
        'rest_probe' => $restRes,
    ];
}

function supabase_active_db_driver(): string
{
    global $pdo;

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable) {
            return '';
        }
    }

    return '';
}

function supabase_mode(): string {
    $dbHost = strtolower((string) config('DB_HOST', ''));
    $dbDriver = strtolower((string) config('DB_DRIVER', ''));
    $activeDriver = supabase_active_db_driver();

    if ($activeDriver === 'pgsql' && ($dbDriver === 'pgsql' || str_contains($dbHost, 'supabase.co') || str_contains($dbHost, 'pooler.supabase.com'))) {
        return 'primary';
    }

    if (supabase_is_configured()) {
        return 'mirror';
    }

    return 'disabled';
}

function supabase_mode_label(): string {
    return match (supabase_mode()) {
        'primary' => 'Supabase is the primary database',
        'mirror' => 'Supabase mirroring is configured',
        default => 'Supabase is not configured',
    };
}
