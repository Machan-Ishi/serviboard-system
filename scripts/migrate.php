<?php
require_once __DIR__ . '/../config/db.php';

$sql = file_get_contents(__DIR__ . '/create_service_requests_table.sql');

try {
    $pdo->exec($sql);
    echo "Table 'service_requests' created or already exists.\n";
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage() . "\n");
}
