<?php
require 'config/db.php';
require_once 'inc/functions.php';

try {
    // Fetch and combine all approved requests for the history view
    $approved_core = $pdo->query("SELECT 'CORE' as module, name as description, updated_at as approved_at, amount FROM service_requests WHERE status = 'approved'")->fetchAll();
    $approved_hr = $pdo->query("SELECT 'HR' as module, request_details as description, updated_at as approved_at, NULL as amount FROM hr_requests WHERE status = 'approved'")->fetchAll();
    $approved_logistics = $pdo->query("SELECT 'LOGISTICS' as module, item_name as description, updated_at as approved_at, NULL as amount FROM logistic_requests WHERE status = 'approved'")->fetchAll();

    $approved_client = [];
    if (function_exists('table_exists') && table_exists($pdo, 'client_requests')) {
        $approved_client = $pdo->query("SELECT 'CLIENT' as module, description, updated_at as approved_at, amount FROM client_requests WHERE status = 'Approved'")->fetchAll();
    }

    $approved_requests = array_merge($approved_core, $approved_hr, $approved_logistics, $approved_client);

    // Sort all approved items by the approval date (newest first)
    usort($approved_requests, function($a, $b) {
        $ta = strtotime((string)($a['approved_at'] ?? '0'));
        $tb = strtotime((string)($b['approved_at'] ?? '0'));
        return $tb <=> $ta;
    });

    echo "Approved requests found: " . count($approved_requests) . "\n\n";

    if (!empty($approved_requests)) {
        echo "Approved Requests Log:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-12s %-8s %-30s %-12s %-10s\n", "Date", "Module", "Description", "Amount", "Status");
        echo str_repeat("-", 80) . "\n";

        foreach ($approved_requests as $request) {
            $date = date('M d, Y', strtotime($request['approved_at']));
            $module = $request['module'];
            $description = substr($request['description'], 0, 28);
            $amount = $request['amount'] ? '₱' . number_format($request['amount'], 2) : '-';
            $status = 'Approved';

            printf("%-12s %-8s %-30s %-12s %-10s\n", $date, $module, $description, $amount, $status);
        }
    } else {
        echo "No approved requests found.\n";
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>