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
                approveHRRequest($pdo, (string) $id);
                $_SESSION['hr_request_flash'] = 'HR request approved successfully.';
            } else {
                rejectHRRequest($pdo, (string) $id, 'Rejected from HR requests page.');
                $_SESSION['hr_request_flash'] = 'HR request rejected successfully.';
            }
        } catch (Throwable $e) {
            $_SESSION['hr_request_error'] = 'Unable to process HR request: ' . $e->getMessage();
        }
    }

    header("Location: /FinancialSM/hr/requests/index.php");
    exit;
}
