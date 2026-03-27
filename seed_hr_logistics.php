<?php
require 'config/db.php';
try {
    // Create HR requests table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS hr_requests (
        id SERIAL PRIMARY KEY,
        request_details TEXT NOT NULL,
        department TEXT,
        status TEXT DEFAULT 'pending',
        created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
    )");
    
    // Create logistic requests table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS logistic_requests (
        id SERIAL PRIMARY KEY,
        item_name TEXT NOT NULL,
        quantity INTEGER DEFAULT 1,
        destination TEXT,
        status TEXT DEFAULT 'pending',
        created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
    )");
    
    // Insert sample HR requests
    $pdo->exec("INSERT INTO hr_requests (request_details, department, status, created_at) VALUES 
        ('New Employee Laptop', 'IT', 'pending', '2026-03-18 12:00:00+00'),
        ('Training Materials', 'HR', 'pending', '2026-03-18 13:00:00+00')
    ON CONFLICT DO NOTHING");
    
    // Insert sample Logistics requests
    $pdo->exec("INSERT INTO logistic_requests (item_name, quantity, destination, status, created_at) VALUES 
        ('Office Supplies', 50, 'Main Office', 'pending', '2026-03-18 14:00:00+00'),
        ('Server Equipment', 2, 'Data Center', 'pending', '2026-03-18 15:00:00+00')
    ON CONFLICT DO NOTHING");
    
    echo "Sample HR and Logistics requests seeded successfully.\n";
    
    // Check counts
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM hr_requests WHERE status = 'pending'");
    $hr_count = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM logistic_requests WHERE status = 'pending'");
    $log_count = $stmt->fetch()['count'];
    
    echo "HR pending requests: $hr_count\n";
    echo "Logistics pending requests: $log_count\n";
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>