<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/../inc/payroll_mod_functions.php';

finance_bootstrap($pdo);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

// Summary for reports
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_gross) as total_gross,
        SUM(total_net) as total_net,
        COUNT(*) as run_count
    FROM public.payroll_runs
    WHERE period_start >= ? AND period_end <= ? AND approval_status = 'Approved'
");
$stmt->execute([$start, $end]);
$summary = $stmt->fetch();

$runs = $pdo->prepare("
    SELECT * FROM public.payroll_runs 
    WHERE period_start >= ? AND period_end <= ?
    ORDER BY created_at DESC
");
$runs->execute([$start, $end]);
$payrollHistory = $runs->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payroll Reports - ServiBoard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/financial.css">
    <style>
        .budget-page-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
        .summary-card { background: #fff; padding: 1.5rem; border-radius: 8px; border: 1px solid #eee; }
        .summary-label { display: block; font-size: 0.8rem; color: #666; margin-bottom: 0.5rem; }
        .summary-value { display: block; font-size: 1.2rem; font-weight: bold; }
        .status-badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; }
        .badge-paid { background: #e6f4ea; color: #1e7e34; }
        .badge-pending { background: #fef7e0; color: #b05500; }
    </style>
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <main class="content">
        <div class="page-header">
            <h1>Payroll Reports</h1>
            <p>View financial summaries and historical payroll data.</p>
        </div>
        <a class="back-link" href="/FinancialSM/financial/index.php" style="margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; color: var(--blue); font-size: 13px;">&larr; Back to Financial</a>

        <section class="section-card">
            <div class="section-head">
                <div class="section-title">
                    <h2>Filter Report Period</h2>
                </div>
            </div>
            <form method="get" class="filter-bar">
                <div class="form-row">
                    <label>From</label>
                    <input type="date" name="start" value="<?= $start ?>">
                </div>
                <div class="form-row">
                    <label>To</label>
                    <input type="date" name="end" value="<?= $end ?>">
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit">Generate Report</button>
                </div>
            </form>
        </section>

        <div class="budget-page-summary">
            <article class="summary-card"><span class="summary-label">Total Gross Cost</span><strong class="summary-value">PHP <?= finance_money($summary['total_gross'] ?? 0) ?></strong></article>
            <article class="summary-card"><span class="summary-label">Total Net Paid</span><strong class="summary-value">PHP <?= finance_money($summary['total_net'] ?? 0) ?></strong></article>
            <article class="summary-card"><span class="summary-label">Approved Runs</span><strong class="summary-value"><?= (int)($summary['run_count'] ?? 0) ?></strong></article>
        </div>

        <section class="section-card" style="margin-top: 20px;">
            <div class="table-title">Payroll History (<?= $start ?> to <?= $end ?>)</div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                        <tr>
                            <th>Run ID</th>
                            <th>Period</th>
                            <th>Total Gross</th>
                            <th>Total Net</th>
                            <th>Approval Status</th>
                            <th>Payment Request</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payrollHistory as $run): ?>
                            <tr>
                                <td>#<?= $run['id'] ?></td>
                                <td><?= finance_h($run['period_start'] . ' to ' . $run['period_end']) ?></td>
                                <td>PHP <?= finance_money($run['total_gross']) ?></td>
                                <td>PHP <?= finance_money($run['total_net']) ?></td>
                                <td><span class="status-badge <?= $run['approval_status'] === 'Approved' ? 'badge-paid' : 'badge-pending' ?>"><?= $run['approval_status'] ?></span></td>
                                <td><?= $run['payment_request_id'] ? 'REQ-'.$run['payment_request_id'] : 'None' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payrollHistory)): ?>
                            <tr><td colspan="6" class="muted-cell">No records found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
