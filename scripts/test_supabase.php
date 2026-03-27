<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../financial/supabase.php';
require_once __DIR__ . '/../config/supabase_config.php';

function print_line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function status_label(bool $ok, string $okLabel = 'OK', string $failLabel = 'FAIL'): string
{
    return $ok ? $okLabel : $failLabel;
}

print_line('Supabase Integration Test');
print_line('=========================');
print_line();

print_line('1. Configuration Check:');
$supabaseUrl = config('SUPABASE_URL');
$supabaseKey = config('SUPABASE_ANON_KEY');

print_line('   SUPABASE_URL: ' . ($supabaseUrl ? 'SET' : 'MISSING'));
print_line('   SUPABASE_ANON_KEY: ' . ($supabaseKey ? 'SET (' . substr((string) $supabaseKey, 0, 10) . '...)' : 'MISSING'));

if (!$supabaseUrl || !$supabaseKey) {
    print_line();
    print_line('Configuration incomplete. Check your .env file.');
    exit(1);
}

print_line();
print_line('2. Client Initialization:');
$client = supabase_init();
print_line('   Client object: ' . (is_object($client) ? 'CREATED' : 'NOT CREATED'));
print_line('   Client class: ' . (($client instanceof SupabaseClient) ? 'SupabaseClient' : 'UNAVAILABLE'));

print_line();
print_line('3. Helper Functions:');
$configured = supabase_is_configured();
$curlAvailable = function_exists('curl_init');
print_line('   supabase_is_configured(): ' . status_label($configured, 'TRUE', 'FALSE'));
print_line('   cURL extension: ' . status_label($curlAvailable, 'AVAILABLE', 'MISSING'));

if ($curlAvailable) {
    $connectionDetails = function_exists('supabase_test_connection_details')
        ? supabase_test_connection_details()
        : ['ok' => supabase_test_connection(), 'status' => 0, 'endpoint' => null, 'error' => null];
    $connectionOk = (bool) ($connectionDetails['ok'] ?? false);
    print_line('   supabase_test_connection(): ' . status_label($connectionOk, 'CONNECTED', 'FAILED'));
    if (!empty($connectionDetails['endpoint'])) {
        print_line('   Probe endpoint: ' . $connectionDetails['endpoint']);
    }
    if (!empty($connectionDetails['status'])) {
        print_line('   HTTP status: ' . (string) $connectionDetails['status']);
    }
    if (!empty($connectionDetails['error'])) {
        print_line('   Error: ' . $connectionDetails['error']);
    }
} else {
    $connectionOk = false;
    print_line('   supabase_test_connection(): SKIPPED (cURL missing)');
}

print_line();
print_line('4. SupabaseConfig Class:');
if (class_exists('SupabaseConfig')) {
    $status = SupabaseConfig::getStatus();
    print_line('   Class exists: YES');
    print_line('   Mirroring enabled: ' . status_label((bool) $status['mirroring_enabled'], 'YES', 'NO'));
    print_line('   Audit enabled: ' . status_label((bool) $status['audit_enabled'], 'YES', 'NO'));
    print_line('   Integration enabled: ' . status_label(SupabaseConfig::isEnabled(), 'YES', 'NO'));
} else {
    $status = [];
    print_line('   Class exists: NO');
}

print_line();
print_line('5. Health Check:');
if ($curlAvailable) {
    $health = supabase_health_check();
    print_line('   Overall health: ' . status_label((bool) ($health['healthy'] ?? false), 'HEALTHY', 'ISSUES FOUND'));

    foreach (($health['issues'] ?? []) as $issue) {
        print_line('   - ' . $issue);
    }
} else {
    $health = [
        'healthy' => false,
        'issues' => ['cURL extension not available'],
    ];
    print_line('   Health check skipped (cURL extension required)');
}

print_line();
print_line('Summary');
print_line('=======');
print_line('Configuration: ' . status_label($configured, 'PASS', 'FAIL'));
print_line('cURL Extension: ' . status_label($curlAvailable, 'PASS', 'FAIL'));
print_line('Connection: ' . ($curlAvailable ? status_label($connectionOk, 'PASS', 'FAIL') : 'SKIPPED'));
print_line('Integration: ' . status_label(class_exists('SupabaseConfig') && SupabaseConfig::isEnabled(), 'READY', 'NOT READY'));
print_line('Health: ' . status_label((bool) ($health['healthy'] ?? false), 'HEALTHY', 'ISSUES'));
print_line();
print_line('Test completed at: ' . date('Y-m-d H:i:s'));
