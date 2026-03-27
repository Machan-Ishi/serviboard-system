<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/../inc/payroll_mod_functions.php';

finance_bootstrap($pdo);

$message = '';
$error = '';

$runId = (int)($_GET['id'] ?? 0);
if ($runId <= 0) {
    header('Location: payroll-run.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf()) {
        $error = 'Invalid request (CSRF failure).';
    } else {
        try {
            if ($_POST['action'] === 'approve_payroll') {
                payroll_approve_run($pdo, $runId, (int)$_SESSION['user_id']);
                $message = 'Payroll run approved successfully.';
            } elseif ($_POST['action'] === 'create_payment_request') {
                payroll_create_payment_request($pdo, $runId);
                $message = 'Payment request created successfully.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$run = $pdo->query("SELECT * FROM public.payroll_runs WHERE id = $runId")->fetch();
$items = $pdo->query("
    SELECT i.*, e.full_name, e.employee_code 
    FROM public.payroll_run_items i 
    JOIN public.employees e ON e.id = i.employee_id 
    WHERE i.payroll_run_id = $runId
")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payroll Run Items - ServiBoard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/financial.css">
    <style>
        .budget-page-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
        .summary-card { background: var(--card-bg); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow-soft); transition: border-color 0.2s ease; }
        .summary-card:hover { border-color: var(--gold); }
        .summary-label { display: block; font-size: 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
        .summary-value { display: block; font-size: 1.2rem; font-weight: 700; color: var(--text); }
        .status-badge { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .badge-paid { background: rgba(62, 207, 142, 0.15); color: #3ecf8e; border: 1px solid rgba(62, 207, 142, 0.3); }
        .badge-pending { background: rgba(240, 175, 28, 0.15); color: #f0af1c; border: 1px solid rgba(240, 175, 28, 0.3); }
    </style>
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <main class="content">
        <div class="page-header">
            <h1>Payroll Run Details (ID: <?= $runId ?>)</h1>
            <p>Review payroll items and approve for payment request.</p>
        </div>

        <?php if ($message): ?>
            <div class="status-badge badge-paid"><?= finance_h($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-text"><?= finance_h($error) ?></div>
        <?php endif; ?>

        <div class="budget-page-summary">
            <article class="summary-card"><span class="summary-label">Total Gross</span><strong class="summary-value">PHP <?= finance_money($run['total_gross']) ?></strong></article>
            <article class="summary-card"><span class="summary-label">Total Net</span><strong class="summary-value">PHP <?= finance_money($run['total_net']) ?></strong></article>
            <article class="summary-card"><span class="summary-label">Status</span><strong class="summary-value"><?= $run['approval_status'] ?></strong></article>
        </div>

        <section class="section-card" style="margin-top: 20px;">
            <div class="section-head">
                <div class="section-title">
                    <h2>Payroll Actions</h2>
                </div>
            </div>
            <div class="form-actions" style="display:flex; gap: 10px;">
                <?php if ($run['approval_status'] === 'Pending'): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve_payroll">
                        <button class="btn primary" type="submit">Approve Payroll Run</button>
                    </form>
                <?php elseif ($run['approval_status'] === 'Approved' && empty($run['payment_request_id'])): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_payment_request">
                        <button class="btn primary" type="submit">Create Payment Request</button>
                    </form>
                <?php elseif (!empty($run['payment_request_id'])): ?>
                    <div class="status-badge badge-paid">Payment Request Created (Linked to Disbursement Module)</div>
                <?php endif; ?>
                <a href="payroll-run.php" class="btn subtle">Back to Runs</a>
            </div>
        </section>

        <section class="section-card">
            <div class="table-title">Employee Breakdown</div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Basic Salary</th>
                            <th>Overtime</th>
                            <th>Allowances</th>
                            <th>Absence Ded.</th>
                            <th>Late Ded.</th>
                            <th>Other Ded.</th>
                            <th>Gross Pay</th>
                            <th>Net Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= finance_h($item['full_name']) ?></strong><br>
                                    <small><?= $item['employee_code'] ?></small>
                                </td>
                                <td>PHP <?= finance_money($item['basic_salary']) ?></td>
                                <td>PHP <?= finance_money($item['overtime_pay']) ?></td>
                                <td>PHP <?= finance_money($item['allowances']) ?></td>
                                <td><span style="color: var(--danger);">- PHP <?= finance_money($item['absence_deduction'] ?? 0) ?></span></td>
                                <td><span style="color: var(--danger);">- PHP <?= finance_money($item['late_deduction'] ?? 0) ?></span></td>
                                <td><span style="color: var(--danger);">- PHP <?= finance_money($item['deductions']) ?></span></td>
                                <td><strong>PHP <?= finance_money($item['gross_pay']) ?></strong></td>
                                <td><strong>PHP <?= finance_money($item['net_pay']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
