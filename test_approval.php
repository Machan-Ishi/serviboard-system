<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/inc/finance_functions.php';
require_once __DIR__ . '/financial/supabase.php';
require_once __DIR__ . '/config/supabase_config.php';

echo "Testing approval functions...\n";

try {
    // Test if we can get a pending request
    $stmt = $pdo->prepare("SELECT id FROM service_requests WHERE status = 'pending' LIMIT 1");
    $stmt->execute();
    $request = $stmt->fetch();

    if ($request) {
        echo "Found pending service request ID: {$request['id']}\n";
        echo "Testing approval function...\n";

        approveServiceRequest($pdo, $request['id']);

        echo "✅ Approval function executed successfully\n";

        // Check if it was updated
        $stmt = $pdo->prepare("SELECT status FROM service_requests WHERE id = ?");
        $stmt->execute([$request['id']]);
        $updated = $stmt->fetch();

        echo "Status updated to: {$updated['status']}\n";
    } else {
        echo "No pending service requests found for testing\n";
    }

} catch (Exception $e) {
    echo "❌ Error testing approval function: " . $e->getMessage() . "\n";
}
?>