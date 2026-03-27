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

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf()) {
        $error = 'Invalid request (CSRF failure).';
    } else {
        try {
            $action = $_POST['action'];
            if ($action === 'generate_payroll') {
                $budgetId = !empty($_POST['budget_id']) ? (int)$_POST['budget_id'] : null;
                $runId = payroll_generate_run($pdo, $start, $end, $budgetId);
                $message = 'Payroll run generated successfully.';
                header('Location: payroll-run.php?msg=' . urlencode($message));
                exit;
            } elseif ($action === 'delete_run') {
                payroll_delete_run($pdo, (int)($_POST['id'] ?? 0));
                $message = 'Payroll run deleted.';
                header('Location: payroll-run.php?msg=' . urlencode($message));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$runs = $pdo->query("SELECT * FROM public.payroll_runs ORDER BY created_at DESC")->fetchAll();
$budgetRows = getBudgetList($pdo);

// Calculate stats
$totalRuns = count($runs);
$pendingRuns = count(array_filter($runs, fn($r) => $r['approval_status'] === 'Pending'));
$approvedRuns = count(array_filter($runs, fn($r) => $r['approval_status'] === 'Approved'));
$totalNetPaid = array_sum(array_column(array_filter($runs, fn($r) => $r['approval_status'] === 'Approved'), 'total_net'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payroll Processing - ServiBoard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/financial.css">
    <style>
        .budget-page-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
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
            <h1>Payroll Processing</h1>
            <p>Generate and manage payroll runs for your employees.</p>
        </div>
        <a class="back-link" href="/FinancialSM/financial/index.php" style="margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; color: var(--blue); font-size: 13px;">&larr; Back to Financial</a>

        <div class="budget-page-summary" style="margin-bottom: 30px;">
            <article class="summary-card">
                <span class="summary-label">Total Payroll Runs</span>
                <strong class="summary-value"><?= $totalRuns ?></strong>
            </article>
            <article class="summary-card">
                <span class="summary-label">Pending Approval</span>
                <strong class="summary-value"><?= $pendingRuns ?></strong>
            </article>
            <article class="summary-card">
                <span class="summary-label">Approved Runs</span>
                <strong class="summary-value"><?= $approvedRuns ?></strong>
            </article>
            <article class="summary-card">
                <span class="summary-label">Total Net Paid</span>
                <strong class="summary-value">PHP <?= finance_money($totalNetPaid) ?></strong>
            </article>
        </div>

        <?php if ($message || isset($_GET['msg'])): ?>
            <div class="status-badge badge-paid"><?= finance_h($message ?: $_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-text"><?= finance_h($error) ?></div>
        <?php endif; ?>

        <section class="section-card">
            <div class="section-head">
                <div class="section-title">
                    <h2>Generate New Payroll Run</h2>
                </div>
            </div>
            <form method="post" class="filter-bar">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_payroll">
                <div class="form-row">
                    <label>Period Start</label>
                    <input type="date" name="start" value="<?= $start ?>">
                </div>
                <div class="form-row">
                    <label>Period End</label>
                    <input type="date" name="end" value="<?= $end ?>">
                </div>
                <div class="form-row">
                    <label>Charge to Budget</label>
                    <select name="budget_id">
                        <option value="">-- No Budget Link --</option>
                        <?php foreach ($budgetRows as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= finance_h($b['budget_name']) ?> (Remaining: PHP <?= finance_money($b['remaining_amount']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit">Generate Run</button>
                </div>
            </form>
        </section>

        <section class="section-card">
            <div class="table-title">Payroll History</div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Total Gross</th>
                            <th>Total Net</th>
                            <th>Linked Budget</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($runs)): ?>
                            <tr><td colspan="6" class="muted-cell" style="text-align: center;">No payroll runs found. Generate your first run above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($runs as $run): ?>
                            <?php
                                $linkedBudget = null;
                                if ($run['budget_id']) {
                                    foreach ($budgetRows as $b) {
                                        if ((int)$b['id'] === (int)$run['budget_id']) {
                                            $linkedBudget = $b;
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <tr>
                                <td><?= finance_h($run['period_start'] . ' to ' . $run['period_end']) ?></td>
                                <td>PHP <?= finance_money($run['total_gross']) ?></td>
                                <td>PHP <?= finance_money($run['total_net']) ?></td>
                                <td><?= $linkedBudget ? finance_h($linkedBudget['budget_name']) : '<span class="muted-text">None</span>' ?></td>
                                <td><span class="status-badge <?= $run['approval_status'] === 'Approved' ? 'badge-paid' : 'badge-pending' ?>"><?= $run['approval_status'] ?></span></td>
                                <td class="table-actions">
                                    <a href="payroll-view.php?id=<?= $run['id'] ?>" class="btn-link primary">View Items</a>
                                    <?php if ($run['approval_status'] === 'Pending'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this payroll run?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_run">
                                            <input type="hidden" name="id" value="<?= $run['id'] ?>">
                                            <button type="submit" class="btn-link danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
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
