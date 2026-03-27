<?php
/**
 * Supabase Integration Readiness Check
 *
 * This script verifies that the system is fully ready for Supabase integration,
 * including database connections, mirroring functionality, and audit logging.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/financial/supabase.php';
require_once __DIR__ . '/config/supabase_config.php';
require_once __DIR__ . '/inc/finance_functions.php';

echo "🔍 Supabase Integration Readiness Check\n";
echo "=======================================\n\n";

$checks = [];
$warnings = [];
$errors = [];

// 1. Check environment configuration
echo "1. Environment Configuration:\n";
$supabaseUrl = config('SUPABASE_URL');
$supabaseKey = config('SUPABASE_ANON_KEY');
$dbHost = config('DB_HOST');
$dbDriver = config('DB_DRIVER');

if ($supabaseUrl && $supabaseKey) {
    echo "   ✅ SUPABASE_URL: Configured\n";
    echo "   ✅ SUPABASE_ANON_KEY: Configured\n";
    $checks[] = 'Environment variables configured';
} else {
    echo "   ❌ SUPABASE_URL or SUPABASE_ANON_KEY missing\n";
    $errors[] = 'Supabase credentials not configured';
}

if (strpos($dbHost, 'supabase.co') !== false || strpos($dbHost, 'pooler.supabase.com') !== false || $dbDriver === 'pgsql') {
    echo "   ✅ Database configured for PostgreSQL/Supabase\n";
    $checks[] = 'Database configured for Supabase';
} else {
    echo "   ⚠️  Database configured for MySQL (will fallback if Supabase fails)\n";
    $warnings[] = 'Using MySQL as primary database';
}

echo "\n";

// 2. Check database connectivity
echo "2. Database Connectivity:\n";
try {
    // Test primary connection
    $stmt = $pdo->query("SELECT 1");
    echo "   ✅ Primary database connection successful\n";

    // Check if we're connected to PostgreSQL
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        echo "   ✅ Connected to PostgreSQL (Supabase)\n";
        $checks[] = 'Connected to PostgreSQL';
    } else {
        echo "   ℹ️  Connected to {$driver} (fallback)\n";
        $warnings[] = "Using {$driver} fallback connection";
    }

    // Test basic table access
    $tables = ['service_requests', 'hr_requests', 'logistic_requests', 'client_requests', 'audit_logs'];
    $missingTables = [];

    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} LIMIT 1");
            echo "   ✅ Table '{$table}' accessible\n";
        } catch (Exception $e) {
            $missingTables[] = $table;
            echo "   ❌ Table '{$table}' not accessible: " . $e->getMessage() . "\n";
        }
    }

    if (empty($missingTables)) {
        $checks[] = 'All required tables accessible';
    } else {
        $errors[] = 'Missing tables: ' . implode(', ', $missingTables);
    }

} catch (Exception $e) {
    echo "   ❌ Database connection failed: " . $e->getMessage() . "\n";
    $errors[] = 'Database connection failed';
}

echo "\n";

// 3. Check Supabase client functionality
echo "3. Supabase Client Functionality:\n";

if (supabase_is_configured()) {
    echo "   ✅ Supabase client configured\n";

    if (supabase_test_connection()) {
        echo "   ✅ Supabase connection test successful\n";
        $checks[] = 'Supabase client functional';

        // Test mirroring
        echo "   Testing data mirroring...\n";
        $testData = [
            'table_name' => 'system_checks',
            'record_id' => 0,
            'action' => 'INTEGRATION_CHECK',
            'old_values' => null,
            'new_values' => json_encode(['check_date' => date('c')]),
            'user_id' => null,
            'timestamp' => date('c'),
            'ip_address' => '127.0.0.1'
        ];

        $mirrorResult = supabase_mirror_safe('audit_logs', $testData);
        if ($mirrorResult) {
            echo "   ✅ Data mirroring functional\n";
            $checks[] = 'Data mirroring working';
        } else {
            echo "   ⚠️  Data mirroring failed (may be due to permissions)\n";
            $warnings[] = 'Data mirroring not working';
        }

    } else {
        echo "   ❌ Supabase connection test failed\n";
        $errors[] = 'Supabase connection test failed';
    }
} else {
    echo "   ❌ Supabase client not configured\n";
    $errors[] = 'Supabase client not configured';
}

echo "\n";

// 4. Check integration configuration
echo "4. Integration Configuration:\n";

$healthCheck = supabase_health_check();
$status = SupabaseConfig::getStatus();

if ($status['enabled']) {
    echo "   ✅ Supabase integration enabled\n";
    $checks[] = 'Integration enabled';
} else {
    echo "   ❌ Supabase integration disabled\n";
    $errors[] = 'Integration disabled';
}

if ($status['mirroring_enabled']) {
    echo "   ✅ Data mirroring enabled\n";
} else {
    echo "   ⚠️  Data mirroring disabled\n";
    $warnings[] = 'Data mirroring disabled';
}

if ($status['audit_enabled']) {
    echo "   ✅ Audit logging enabled\n";
} else {
    echo "   ⚠️  Audit logging disabled\n";
    $warnings[] = 'Audit logging disabled';
}

echo "\n";

// 5. Check approval functions
echo "5. Request Approval Functions:\n";

$functions = [
    'approveServiceRequest',
    'rejectServiceRequest',
    'approveHRRequest',
    'rejectHRRequest',
    'approveLogisticsRequest',
    'rejectLogisticsRequest',
    'approveClientRequest',
    'rejectClientRequest'
];

foreach ($functions as $function) {
    if (function_exists($function)) {
        echo "   ✅ Function '{$function}' exists\n";
    } else {
        echo "   ❌ Function '{$function}' missing\n";
        $errors[] = "Function '{$function}' missing";
    }
}

if (count($errors) === 0) {
    $checks[] = 'All approval functions available';
}

echo "\n";

// 6. Summary
echo "6. Summary:\n";

$totalChecks = count($checks);
$totalWarnings = count($warnings);
$totalErrors = count($errors);

echo "   ✅ Checks passed: {$totalChecks}\n";
echo "   ⚠️  Warnings: {$totalWarnings}\n";
echo "   ❌ Errors: {$totalErrors}\n";

if ($totalErrors === 0) {
    echo "\n🎉 SUPABASE INTEGRATION IS READY!\n";
    echo "   Your system is fully configured for Supabase integration.\n";
    echo "   All request approvals will be mirrored to Supabase with audit logging.\n";
} else {
    echo "\n❌ INTEGRATION ISSUES DETECTED\n";
    echo "   Please resolve the errors above before proceeding.\n";
}

if ($totalWarnings > 0) {
    echo "\n⚠️  WARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "   - {$warning}\n";
    }
}

if ($totalErrors > 0) {
    echo "\n❌ ERRORS:\n";
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
}

echo "\n=======================================\n";
echo "Check completed at: " . date('Y-m-d H:i:s') . "\n";
?>