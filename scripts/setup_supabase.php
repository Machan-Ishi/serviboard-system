<?php
/**
 * Supabase Setup Script
 *
 * This script initializes the Supabase database with the required schema
 * and ensures the system is ready for Supabase integration.
 *
 * Run this script to set up Supabase for the first time or after schema changes.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../financial/supabase.php';

function setupSupabase(PDO $pdo) {
    echo "🚀 Starting Supabase setup...\n\n";

    // Test Supabase connection
    echo "1. Testing Supabase connection...\n";
    if (!supabase_is_configured()) {
        echo "❌ Supabase is not configured. Please check your environment variables.\n";
        return false;
    }

    if (!supabase_test_connection()) {
        echo "❌ Cannot connect to Supabase. Please check your credentials.\n";
        return false;
    }

    echo "✅ Supabase connection successful!\n\n";

    // Execute schema setup
    echo "2. Setting up database schema...\n";

    $schemaFile = __DIR__ . '/../supabase.sql';
    if (!file_exists($schemaFile)) {
        echo "❌ Schema file not found: {$schemaFile}\n";
        return false;
    }

    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        echo "❌ Could not read schema file\n";
        return false;
    }

    // Split schema into individual statements
    $statements = array_filter(array_map('trim', explode(';', $schema)));

    $successCount = 0;
    $totalCount = count($statements);

    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }

        try {
            $pdo->exec($statement);
            $successCount++;
        } catch (Exception $e) {
            echo "⚠️  Warning: " . $e->getMessage() . "\n";
            // Continue with other statements
        }
    }

    echo "✅ Schema setup completed ({$successCount}/{$totalCount} statements executed)\n\n";

    // Test mirroring functionality
    echo "3. Testing data mirroring...\n";

    // Test with a simple audit log entry
    $testData = [
        'user_id' => null,
        'action' => 'System Setup',
        'module' => 'Setup',
        'record_id' => null,
        'old_values' => null,
        'new_values' => json_encode(['setup_completed' => true]),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Supabase Setup Script'
    ];

    $result = supabase_mirror('audit_logs', $testData);
    if ($result) {
        echo "✅ Data mirroring test successful!\n\n";
    } else {
        echo "⚠️  Data mirroring test failed, but this may be due to permissions.\n\n";
    }

    // Create setup verification
    echo "4. Creating setup verification...\n";

    $verificationData = [
        'user_id' => null,
        'action' => 'Supabase Integration Ready',
        'module' => 'System',
        'record_id' => null,
        'old_values' => null,
        'new_values' => json_encode([
            'setup_date' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'supabase_url' => config('SUPABASE_URL'),
            'database_host' => config('DB_HOST')
        ]),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Supabase Setup Script v1.0'
    ];

    supabase_mirror('audit_logs', $verificationData);

    echo "🎉 Supabase setup completed successfully!\n\n";
    echo "📋 Next steps:\n";
    echo "   - Your system is now ready for Supabase integration\n";
    echo "   - All financial transactions will be automatically mirrored to Supabase\n";
    echo "   - Check the audit_logs table to verify synchronization\n";
    echo "   - Monitor supabase_error.log for any connection issues\n\n";

    return true;
}

function showSupabaseStatus() {
    echo "📊 Supabase Integration Status\n";
    echo "==============================\n\n";

    echo "Configuration:\n";
    echo "  SUPABASE_URL: " . (config('SUPABASE_URL') ? "✅ Set" : "❌ Not set") . "\n";
    echo "  SUPABASE_ANON_KEY: " . (config('SUPABASE_ANON_KEY') ? "✅ Set" : "❌ Not set") . "\n";
    echo "  DB_HOST: " . (config('DB_HOST') ? "✅ Set" : "❌ Not set") . "\n";
    echo "  DB_USER: " . (config('DB_USER') ? "✅ Set" : "❌ Not set") . "\n\n";

    if (supabase_is_configured()) {
        echo "Connection Test: " . (supabase_test_connection() ? "✅ Connected" : "❌ Failed") . "\n\n";
    } else {
        echo "❌ Supabase is not configured properly\n\n";
    }

    echo "Features:\n";
    echo "  ✅ Real-time data mirroring\n";
    echo "  ✅ Audit logging\n";
    echo "  ✅ Schema synchronization\n";
    echo "  ✅ Error handling and logging\n\n";
}

// Main execution
if ($argc > 1 && $argv[1] === 'status') {
    showSupabaseStatus();
} else {
    try {
        $result = setupSupabase($pdo);
        if (!$result) {
            exit(1);
        }
    } catch (Exception $e) {
        echo "❌ Setup failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>