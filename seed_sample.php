<?php
require 'config/db.php';
try {
    // Check if table exists, create if not
    $result = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'service_requests')");
    $exists = $result->fetchColumn();
    
    if (!$exists) {
        $pdo->exec("CREATE TABLE service_requests (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
        )");
        echo "Table created.\n";
    }
    
    // Insert sample pending requests
    $pdo->exec("INSERT INTO service_requests (name, amount, status, created_at) VALUES 
        ('System Installation - Client A', 15000.00, 'pending', '2026-03-18 10:00:00+00'),
        ('Monthly Maintenance - Corp B', 2500.50, 'pending', '2026-03-18 11:00:00+00')
    ON CONFLICT DO NOTHING");
    
    echo "Sample requests seeded successfully.\n";
    
    // Check count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM service_requests WHERE status = 'pending'");
    $result = $stmt->fetch();
    echo 'Total pending requests: ' . $result['count'] . "\n";
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>