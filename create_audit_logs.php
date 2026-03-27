<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGSERIAL PRIMARY KEY,
        table_name TEXT,
        record_id BIGINT,
        action TEXT,
        old_values JSONB,
        new_values JSONB,
        user_id BIGINT,
        timestamp TIMESTAMPTZ DEFAULT NOW(),
        ip_address INET
    )');

    echo "✅ Audit logs table created successfully\n";

    // Test if we can access it
    $stmt = $pdo->query("SELECT COUNT(*) FROM audit_logs");
    $count = $stmt->fetchColumn();
    echo "✅ Audit logs table accessible (current records: {$count})\n";

} catch (Exception $e) {
    echo "❌ Error creating audit_logs table: " . $e->getMessage() . "\n";
}
?>