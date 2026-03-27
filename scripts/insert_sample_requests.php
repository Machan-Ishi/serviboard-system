<?php
/**
 * CORE MODULE SIMULATION
 * Inserts sample service requests into the FINANCIAL module
 */

require_once __DIR__ . '/../config/db.php';

$samples = [
    ['name' => 'System Installation - Client A', 'amount' => 15000.00],
    ['name' => 'Monthly Maintenance - Corp B', 'amount' => 2500.50],
    ['name' => 'Cloud Storage Upgrade - User C', 'amount' => 499.00],
    ['name' => 'Emergency On-site Support', 'amount' => 3500.00],
];

try {
    $stmt = $pdo->prepare("INSERT INTO service_requests (name, amount, status) VALUES (?, ?, 'pending')");
    
    foreach ($samples as $sample) {
        $stmt->execute([$sample['name'], $sample['amount']]);
        echo "Inserted: {$sample['name']} - ₱{$sample['amount']}\n";
    }
    
    echo "Successfully inserted " . count($samples) . " sample requests.\n";
} catch (PDOException $e) {
    die("Error inserting sample data: " . $e->getMessage() . "\n");
}
