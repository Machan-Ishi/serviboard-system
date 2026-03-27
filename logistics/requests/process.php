<?php
require_once __DIR__ . '/../../includes/require_admin.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../inc/functions.php';
require_once __DIR__ . '/../../inc/finance_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        exit('Invalid request (CSRF failure).');
    }

    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            if ($action === 'approve') {
                approveLogisticsRequest($pdo, $id);
                $_SESSION['logistics_request_flash'] = 'Logistics request approved successfully.';
            } else {
                rejectLogisticsRequest($pdo, $id, 'Rejected from Logistics requests page.');
                $_SESSION['logistics_request_flash'] = 'Logistics request rejected successfully.';
            }
        } catch (Throwable $e) {
            $_SESSION['logistics_request_error'] = 'Unable to process logistics request: ' . $e->getMessage();
        }
    }

    header("Location: /FinancialSM/logistics/requests/index.php");
    exit;
}
