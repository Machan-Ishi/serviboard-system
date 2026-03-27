<?php
// Simple script to insert sample data for CORE requests
$host = 'localhost';
$db   = 'financialsm';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     $samples = [
         ['name' => 'System Installation - Client A', 'amount' => 15000.00],
         ['name' => 'Monthly Maintenance - Corp B', 'amount' => 2500.50],
         ['name' => 'Cloud Storage Upgrade - User C', 'amount' => 499.00],
         ['name' => 'Emergency On-site Support', 'amount' => 3500.00],
     ];

     $stmt = $pdo->prepare("INSERT INTO service_requests (name, amount, status) VALUES (?, ?, 'pending')");
     
     foreach ($samples as $sample) {
         $stmt->execute([$sample['name'], $sample['amount']]);
         echo "Inserted: {$sample['name']} - ₱{$sample['amount']}\n";
     }
     
     echo "Successfully inserted 4 sample requests.";
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
