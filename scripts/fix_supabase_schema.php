<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking for missing columns in Supabase employees table...\n";

try {
    // 1. Check for 'allowance' column
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'employees' AND column_name = 'allowance' LIMIT 1");
    $stmt->execute();
    if (!$stmt->fetch()) {
        echo "Adding missing column 'allowance' to 'employees'...\n";
        $pdo->exec("ALTER TABLE public.employees ADD COLUMN allowance NUMERIC(14,2) NOT NULL DEFAULT 0");
    } else {
        echo "Column 'allowance' already exists.\n";
    }

    // 2. Check for 'deduction_default' column
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'employees' AND column_name = 'deduction_default' LIMIT 1");
    $stmt->execute();
    if (!$stmt->fetch()) {
        echo "Adding missing column 'deduction_default' to 'employees'...\n";
        $pdo->exec("ALTER TABLE public.employees ADD COLUMN deduction_default NUMERIC(14,2) NOT NULL DEFAULT 0");
    } else {
        echo "Column 'deduction_default' already exists.\n";
    }

    // 3. Ensure other tables from migrate.php exist
    // collection
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'collection' LIMIT 1");
    $stmt->execute();
    if (!$stmt->fetch()) {
        echo "Creating missing table 'collection'...\n";
        $pdo->exec("CREATE TABLE IF NOT EXISTS public.collection (
            id BIGSERIAL PRIMARY KEY,
            reference_no VARCHAR(60),
            source_type VARCHAR(50),
            source_id BIGINT,
            payer_name VARCHAR(150) NOT NULL,
            amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40),
            payment_date DATE NOT NULL,
            status VARCHAR(30),
            remarks TEXT,
            related_budget_id BIGINT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
    }

    echo "Fix applied successfully! Supabase schema updated.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
