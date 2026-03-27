<?php
require_once __DIR__ . '/../config/db.php';

try {
    echo "Attempting to create tables for PostgreSQL...\n";

    // --- Create service_requests table for CORE/Financial ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS service_requests (
          id SERIAL PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          amount DECIMAL(10,2) NOT NULL,
          status VARCHAR(20) DEFAULT 'pending',
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        );
    ");
    echo "Table 'service_requests' created or already exists.\n";

    // --- Create hr_requests table for HR ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hr_requests (
          id SERIAL PRIMARY KEY,
          request_details VARCHAR(255) NOT NULL,
          department VARCHAR(100) NOT NULL,
          status VARCHAR(20) DEFAULT 'pending',
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        );
    ");
    echo "Table 'hr_requests' created or already exists.\n";

    // --- Create logistic_requests table for Logistics ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logistic_requests (
          id SERIAL PRIMARY KEY,
          item_name VARCHAR(255) NOT NULL,
          quantity INT NOT NULL,
          destination VARCHAR(255) NOT NULL,
          status VARCHAR(20) DEFAULT 'pending',
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        );
    ");
    echo "Table 'logistic_requests' created or already exists.\n";

    echo "\nClearing old sample data...\n";
    $pdo->exec("TRUNCATE TABLE service_requests, hr_requests, logistic_requests RESTART IDENTITY;");

    echo "Inserting new sample data...\n";

    // --- Insert Sample Data ---
    $pdo->exec("
        INSERT INTO service_requests (name, amount, status) VALUES
        ('System Installation - Client A', 15000.00, 'pending'),
        ('Monthly Maintenance - Corp B', 2500.50, 'pending');
    ");
    echo "- 2 CORE requests inserted.\n";

    $pdo->exec("
        INSERT INTO hr_requests (request_details, department, status) VALUES
        ('Onboard New Employee', 'Sales', 'pending');
    ");
    echo "- 1 HR request inserted.\n";

    $pdo->exec("
        INSERT INTO logistic_requests (item_name, quantity, destination, status) VALUES
        ('Vehicle for Site Visit', 1, 'Makati Office', 'pending');
    ");
    echo "- 1 Logistics request inserted.\n";

    echo "\nDatabase setup complete!\n";

} catch (PDOException $e) {
    die("Database error during setup: " . $e->getMessage() . "\n");
}
