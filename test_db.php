<?php
require 'config/db.php';

try {
    $stmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
        ORDER BY table_name
    ");
    $rows = $stmt->fetchAll();

    echo "<h2>Public tables</h2>";
    echo "<pre>";
    print_r($rows);
    echo "</pre>";
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}
?>