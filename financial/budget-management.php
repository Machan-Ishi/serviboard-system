<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$bootstrapMessages = finance_bootstrap($pdo);
$message = '';
$error = '';

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'department' => trim((string) ($_GET['department'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'period' => trim((string) ($_GET['period'] ?? '')),
];

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? getBudgetById($pdo, $editId) : null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            throw new RuntimeException('Invalid request (CSRF failure).');
        }

        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_budget') {
            $id = (int) ($_POST['id'] ?? 0);
            $savedId = updateBudget($pdo, [
                'id' => $id,
                'budget_name' => $_POST['budget_name'] ?? '',
                'department' => $_POST['department'] ?? '',
                'allocated_amount' => $_POST['allocated_amount'] ?? 0,
                'period_start' => $_POST['period_start'] ?? '',
                'period_end' => $_POST['period_end'] ?? '',
                'status' => $_POST['status'] ?? 'Active',
                'notes' => $_POST['notes'] ?? '',
            ]);
            recalculateBudget($pdo, $savedId);
            $message = $id > 0 ? 'Budget updated.' : 'Budget created.';
            $editRow = getBudgetById($pdo, $savedId);
            $editId = $savedId;
        } elseif ($action === 'archive_budget') {
            $budgetId = (int) ($_POST['id'] ?? 0);
            $budget = getBudgetById($pdo, $budgetId);
            if (!$budget) {
                throw new RuntimeException('Budget record not found.');
            }
            updateBudget($pdo, [
                'id' => $budgetId,
                'budget_name' => $budget['budget_name'] ?? '',
                'department' => $budget['department'] ?? '',
                'allocated_amount' => $budget['allocated_amount'] ?? 0,
                'period_start' => $budget['period_start'] ?? '',
                'period_end' => $budget['period_end'] ?? '',
                'status' => 'Closed',
                'notes' => $budget['notes'] ?? '',
            ]);
            recalculateBudget($pdo, $budgetId);
            $message = 'Budget archived.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$rows = getBudgetList($pdo, $filters);
$allBudgets = getBudgetList($pdo);
$summary = finance_get_budget_dashboard_summary_from_rows($allBudgets);
$filteredSummary = finance_get_budget_filtered_summary($rows);

$departments = [];
foreach ($allBudgets as $budgetRow) {
    $department = trim((string) ($budgetRow['department'] ?? ''));
    if ($department !== '') {
        $departments[$department] = $department;
    }
}
ksort($departments);

$formBudget = $editRow ?: [
    'id' => 0,
    'budget_name' => '',
    'department' => '',
    'allocated_amount' => '0.00',
    'used_amount' => '0.00',
    'remaining_amount' => '0.00',
    'period_start' => date('Y-m-01'),
    'period_end' => date('Y-m-t'),
    'status' => 'Active',
    'notes' => '',
];

recalculateBudget($pdo, (int) ($formBudget['id'] ?? 0));
if ($editId > 0) {
    $formBudget = getBudgetById($pdo, $editId) ?: $formBudget;
}

function budget_badge_class(string $status): string
{
    return match ($status) {
        'Active' => 'badge-paid',
        'Pending' => 'badge-pending',
        'Over Budget', 'Closed', 'Expired' => 'badge-cancelled',
        default => 'badge-partial',
    };
}

function budget_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

function budget_utilization_level(array $row): string
{
    $status = (string) ($row['status'] ?? '');
    $utilization = (float) ($row['utilization_percentage'] ?? 0);

    if ($status === 'Over Budget' || $utilization > 90) {
        return 'critical';
    }
    if ($utilization >= 70) {
        return 'warning';
    }

    return 'safe';
}

function budget_utilization_label(array $row): string
{
    return match (budget_utilization_level($row)) {
        'critical' => 'Critical',
        'warning' => 'Warning',
        default => 'Safe',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Budget Management - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .budget-shell {
      gap: 18px;
    }
    .budget-shell .form-card,
    .budget-shell .table-card,
    .budget-shell .summary-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-soft);
      color: var(--text);
    }
    .budget-shell .form-card,
    .budget-shell .table-card {
      border-radius: 14px;
      padding: 18px;
    }
    .budget-page-summary {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .budget-page-summary .summary-card {
      min-height: 108px;
      padding: 18px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      border-radius: 14px;
    }
    .budget-page-summary .summary-label {
      font-size: 11px;
      letter-spacing: 0.06em;
      color: var(--muted);
    }
    .budget-page-summary .summary-value {
      font-size: 22px;
      line-height: 1.15;
      word-break: break-word;
    }
    .budget-shell-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
      gap: 16px;
      align-items: start;
    }
    .budget-shell .form-title {
      margin-bottom: 14px;
      font-size: 16px;
    }
    .budget-shell .form-row {
      margin-bottom: 0;
    }
    .budget-shell .form-row label {
      display: block;
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 500;
    }
    .budget-shell .form-row input,
    .budget-shell .form-row select,
    .budget-shell .form-row textarea {
      min-height: 42px;
      background: var(--input-bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--text);
      padding: 10px 12px;
      font-size: 13px;
    }
    .budget-shell .form-row textarea {
      min-height: 88px;
      resize: vertical;
    }
    .budget-shell .form-row input[readonly] {
      background: rgba(255, 255, 255, 0.04);
      color: #dbe7ff;
      font-weight: 600;
    }
    .budget-shell .form-row input:focus,
    .budget-shell .form-row select:focus,
    .budget-shell .form-row textarea:focus {
      border-color: var(--blue);
      outline: none;
      box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.18);
    }
    .budget-shell .form-actions {
      margin-top: 16px;
    }
    .budget-filter-summary {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 14px;
    }
    .budget-filter-summary div {
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--stat-bg);
    }
    .budget-filter-summary span,
    .budget-detail-grid span {
      display: block;
      color: var(--muted);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .budget-filter-summary strong,
    .budget-detail-grid strong {
      display: block;
      margin-top: 6px;
      color: var(--text);
    }
    .budget-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .budget-table-wrap {
      overflow-x: auto;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.01);
    }
    .budget-table {
      min-width: 1120px;
    }
    .budget-table thead th {
      background: var(--stat-bg);
      position: sticky;
      top: 0;
      z-index: 1;
    }
    .budget-table tbody td {
      vertical-align: middle;
    }
    .budget-table .money-cell {
      text-align: right;
      white-space: nowrap;
    }
    .budget-table .actions-cell {
      text-align: right;
      white-space: nowrap;
    }
    .budget-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
    .budget-progress {
      min-width: 190px;
    }
    .budget-progress strong {
      display: inline-block;
      font-size: 13px;
      color: var(--text);
    }
    .budget-progress .budget-progress-meta {
      margin-top: 6px;
      font-size: 11px;
      color: var(--muted);
    }
    .budget-progress .util-bar {
      margin: 8px 0 0;
      height: 10px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid var(--border);
      border-radius: 999px;
      overflow: hidden;
    }
    .budget-progress .util-bar > div {
      height: 100%;
      border-radius: 999px;
    }
    .budget-progress.safe .util-bar > div {
      background: linear-gradient(90deg, #16a34a, #22c55e);
    }
    .budget-progress.warning .util-bar > div {
      background: linear-gradient(90deg, #d97706, #f59e0b);
    }
    .budget-progress.critical .util-bar > div {
      background: linear-gradient(90deg, #dc2626, #ef4444);
    }
    .budget-status-stack {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }
    .budget-indicator {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .budget-indicator.safe {
      background: rgba(34, 197, 94, 0.12);
      color: #86efac;
    }
    .budget-indicator.warning {
      background: rgba(245, 158, 11, 0.14);
      color: #fcd34d;
    }
    .budget-indicator.critical {
      background: rgba(239, 68, 68, 0.14);
      color: #fca5a5;
    }
    .budget-row-over {
      background: rgba(248, 81, 73, 0.05);
    }
    .budget-row-soon {
      background: rgba(244, 179, 33, 0.05);
    }
    .budget-empty {
      text-align: center;
      color: var(--muted);
      padding: 28px 16px;
      font-size: 13px;
    }
    .budget-helper {
      color: var(--muted);
      font-size: 12px;
      margin: 2px 0 0;
      line-height: 1.5;
    }
    .budget-tooltip {
      cursor: help;
      border-bottom: 1px dotted currentColor;
    }
    .budget-filter-links {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 14px;
    }
    .budget-detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 12px;
      margin-top: 14px;
    }
    .budget-detail-grid div {
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--stat-bg);
    }
    .budget-history {
      margin-top: 16px;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      background: var(--card-bg);
    }
    .budget-history table {
      width: 100%;
      border-collapse: collapse;
    }
    .budget-history th,
    .budget-history td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      font-size: 13px;
    }
    .budget-history th {
      color: var(--muted);
      text-transform: uppercase;
      font-size: 11px;
      background: var(--stat-bg);
      text-align: left;
    }
    .budget-history td.money-cell {
      text-align: right;
      white-space: nowrap;
    }
    .budget-modal-card {
      max-width: 760px;
    }
    .budget-actions .btn,
    .budget-actions .btn-link {
      min-width: 88px;
      text-align: center;
    }
    .budget-actions .inline-form {
      display: inline-flex;
    }
    @media (max-width: 1180px) {
      .budget-page-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
      .budget-shell-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 760px) {
      .budget-page-summary,
      .budget-form-grid,
      .budget-filter-summary,
      .budget-detail-grid {
        grid-template-columns: 1fr;
      }
      .budget-shell .form-card,
      .budget-shell .table-card {
        padding: 16px;
      }
      .budget-page-summary .summary-card {
        min-height: auto;
      }
      .budget-actions {
        justify-content: flex-start;
      }
      .budget-table {
        min-width: 980px;
      }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../inc/sidebar.php'; ?>
  <main class="content" role="main">
    <?php include __DIR__ . '/../inc/financial_topbar.php'; ?>

    <div class="page-header">
      <h1>Budget Management</h1>
      <p>Monitor budget allocations, real usage, remaining funds, and linked transaction activity.</p>
    </div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>
    <?php if ($message !== ''): ?><section class="section-card"><div class="status-badge badge-paid"><?= finance_h($message) ?></div></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section><?php endif; ?>

    <section class="budget-page-summary">
      <article class="summary-card"><span class="summary-label">Total Allocated</span><strong class="summary-value">PHP <?= finance_money($summary['total_allocated'] ?? 0) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Total Used</span><strong class="summary-value">PHP <?= finance_money($summary['total_used'] ?? 0) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Total Remaining</span><strong class="summary-value">PHP <?= finance_money($summary['total_remaining'] ?? 0) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Near Limit Budgets</span><strong class="summary-value"><?= number_format((int) ($summary['near_limit_budgets'] ?? 0)) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Over Budget</span><strong class="summary-value"><?= number_format((int) ($summary['over_budget_count'] ?? 0)) ?></strong></article>
    </section>

    <section class="section-card budget-shell">
      <div class="budget-shell-grid">
        <form class="form-card" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_budget">
          <input type="hidden" name="id" value="<?= (int) ($formBudget['id'] ?? 0) ?>">
          <h3 class="form-title"><?= $editRow ? 'Edit Budget' : 'Create Budget' ?></h3>

          <div class="budget-form-grid">
            <div class="form-row"><label for="budget_name">Budget Name</label><input id="budget_name" name="budget_name" type="text" required value="<?= finance_h((string) ($formBudget['budget_name'] ?? '')) ?>"></div>
            <div class="form-row"><label for="department">Department</label><input id="department" name="department" type="text" required value="<?= finance_h((string) ($formBudget['department'] ?? '')) ?>"></div>
            <div class="form-row"><label for="allocated_amount">Allocated Amount</label><input id="allocated_amount" name="allocated_amount" type="number" step="0.01" min="0" required value="<?= number_format((float) ($formBudget['allocated_amount'] ?? 0), 2, '.', '') ?>"></div>
            <div class="form-row"><label for="status">Status</label><select id="status" name="status"><?php foreach (['Active', 'Closed'] as $statusOption): ?><option value="<?= finance_h($statusOption) ?>" <?= (string) ($formBudget['status'] ?? 'Active') === $statusOption ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label for="period_start">Period Start</label><input id="period_start" name="period_start" type="date" value="<?= finance_h((string) ($formBudget['period_start'] ?? '')) ?>"></div>
            <div class="form-row"><label for="period_end">Period End</label><input id="period_end" name="period_end" type="date" value="<?= finance_h((string) ($formBudget['period_end'] ?? '')) ?>"></div>
          </div>

          <div class="budget-form-grid">
            <div class="form-row"><label>Calculated Used Amount</label><input type="text" value="PHP <?= finance_money($formBudget['used_amount'] ?? 0) ?>" readonly></div>
            <div class="form-row"><label>Calculated Remaining Amount</label><input type="text" value="PHP <?= finance_money($formBudget['remaining_amount'] ?? ((float) ($formBudget['allocated_amount'] ?? 0) - (float) ($formBudget['used_amount'] ?? 0))) ?>" readonly></div>
          </div>

          <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= finance_h((string) ($formBudget['notes'] ?? '')) ?></textarea></div>
          <p class="budget-helper"><span class="budget-tooltip" title="Used amount is calculated from released and posted disbursements linked to this budget.">Used amount is calculated from disbursements.</span></p>

          <div class="form-actions">
            <?php if ($editRow): ?><a class="btn subtle" href="budget-management.php">Cancel</a><?php endif; ?>
            <button class="btn primary" type="submit"><?= $editRow ? 'Update Budget' : 'Create Budget' ?></button>
          </div>
        </form>

        <div class="table-card">
          <h3 class="form-title">Filter &amp; Summary</h3>
          <form method="get">
            <div class="budget-form-grid">
              <div class="form-row"><label for="search">Search</label><input id="search" name="search" type="text" value="<?= finance_h($filters['search']) ?>" placeholder="Budget name, department, notes"></div>
              <div class="form-row"><label for="filter_department">Department</label><select id="filter_department" name="department"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= finance_h($department) ?>" <?= $filters['department'] === $department ? 'selected' : '' ?>><?= finance_h($department) ?></option><?php endforeach; ?></select></div>
              <div class="form-row"><label for="filter_status">Status</label><select id="filter_status" name="status"><option value="">All statuses</option><?php foreach (['Active', 'Pending', 'Expired', 'Over Budget', 'Closed'] as $statusOption): ?><option value="<?= finance_h($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option><?php endforeach; ?></select></div>
              <div class="form-row"><label for="filter_period">Period</label><select id="filter_period" name="period"><option value="">All periods</option><option value="current" <?= $filters['period'] === 'current' ? 'selected' : '' ?>>Current</option><option value="expiring_soon" <?= $filters['period'] === 'expiring_soon' ? 'selected' : '' ?>>Expiring Soon</option><option value="expired" <?= $filters['period'] === 'expired' ? 'selected' : '' ?>>Expired</option></select></div>
            </div>
            <div class="form-actions"><button class="btn primary" type="submit">Apply Filters</button><a class="btn subtle" href="budget-management.php">Reset</a></div>
          </form>
          <div class="budget-filter-links">
            <a class="btn subtle" href="budget-management.php?<?= finance_h(budget_query(['status' => 'Active'])) ?>">Active</a>
            <a class="btn subtle" href="budget-management.php?<?= finance_h(budget_query(['status' => 'Expired'])) ?>">Expired</a>
            <a class="btn subtle" href="budget-management.php?<?= finance_h(budget_query(['status' => 'Over Budget'])) ?>">Over Budget</a>
          </div>

          <div class="budget-filter-summary">
            <div><span>Filtered Budgets</span><strong><?= number_format((int) ($filteredSummary['count'] ?? 0)) ?></strong></div>
            <div><span>Allocated</span><strong>PHP <?= finance_money($filteredSummary['allocated'] ?? 0) ?></strong></div>
            <div><span>Used</span><strong>PHP <?= finance_money($filteredSummary['used'] ?? 0) ?></strong></div>
            <div><span>Remaining</span><strong>PHP <?= finance_money($filteredSummary['remaining'] ?? 0) ?></strong></div>
          </div>
        </div>
      </div>
    </section>

    <section class="section-card">
      <div class="collection-header">
        <div>
          <h2 class="collection-title">Budget List</h2>
          <p class="collection-subtitle">Usage is calculated from linked disbursements and budget-tagged transactions.</p>
        </div>
      </div>

      <div class="budget-table-wrap">
        <table class="notion-table budget-table">
          <thead>
            <tr>
              <th>Budget Name</th>
              <th>Department</th>
              <th class="money-cell">Allocated</th>
              <th class="money-cell">Used</th>
              <th class="money-cell">Remaining</th>
              <th>Utilization %</th>
              <th>Period</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?><tr><td colspan="9" class="budget-empty">No budgets yet. Create your first budget.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $history = finance_get_budget_usage_history($pdo, (int) ($row['id'] ?? 0));
                $rowPayload = $row;
                $rowPayload['history'] = $history;
                $utilizationLevel = budget_utilization_level($row);
              ?>
              <tr class="<?= (($row['status'] ?? '') === 'Over Budget') ? 'budget-row-over' : (!empty($row['is_expiring_soon']) ? 'budget-row-soon' : '') ?>">
                <td><?= finance_h((string) ($row['budget_name'] ?? '')) ?></td>
                <td><?= finance_h((string) ($row['department'] ?? '')) ?></td>
                <td class="money-cell">PHP <?= finance_money($row['allocated_amount'] ?? 0) ?></td>
                <td class="money-cell">PHP <?= finance_money($row['used_amount'] ?? 0) ?></td>
                <td class="money-cell">PHP <?= finance_money($row['remaining_amount'] ?? 0) ?></td>
                <td class="budget-progress <?= finance_h($utilizationLevel) ?>">
                  <strong><?= number_format((float) ($row['utilization_percentage'] ?? 0), 1) ?>%</strong>
                  <div class="util-bar"><div style="width: <?= min(100, max(0, (float) ($row['utilization_percentage'] ?? 0))) ?>%"></div></div>
                  <div class="budget-progress-meta">PHP <?= finance_money($row['used_amount'] ?? 0) ?> of PHP <?= finance_money($row['allocated_amount'] ?? 0) ?></div>
                </td>
                <td><?= finance_h(trim((string) ($row['period_start'] ?? '')) !== '' || trim((string) ($row['period_end'] ?? '')) !== '' ? ((string) ($row['period_start'] ?? '-') . ' to ' . (string) ($row['period_end'] ?? '-')) : '-') ?></td>
                <td>
                  <div class="budget-status-stack">
                    <span class="status-badge <?= budget_badge_class((string) ($row['status'] ?? '')) ?>"><?= finance_h((string) ($row['status'] ?? '')) ?></span>
                    <span class="budget-indicator <?= finance_h($utilizationLevel) ?>"><?= finance_h(budget_utilization_label($row)) ?></span>
                  </div>
                </td>
                <td class="actions-cell">
                  <div class="budget-actions">
                    <button class="btn subtle js-budget-detail" type="button" data-budget='<?= finance_h(json_encode($rowPayload, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?: "{}") ?>'>View Details</button>
                    <a class="btn subtle" href="budget-management.php?edit=<?= (int) ($row['id'] ?? 0) ?>">Edit</a>
                    <?php if (($row['status'] ?? '') !== 'Closed'): ?>
                      <form method="post" class="inline-form" onsubmit="return confirm('Archive this budget?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="archive_budget">
                        <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
                        <button class="btn subtle" type="submit">Archive</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<div class="sb-modal-backdrop" id="budgetDetailModal" aria-hidden="true">
  <div class="sb-modal-card budget-modal-card">
    <div class="sb-modal-head">
      <h3>Budget Details</h3>
      <p>Allocation, utilization, status, and linked usage history.</p>
    </div>
    <div class="budget-detail-grid" id="budgetDetailGrid"></div>
    <div class="budget-history">
      <table>
        <thead><tr><th>Date</th><th>Source</th><th>Reference</th><th>Party</th><th class="money-cell">Amount</th></tr></thead>
        <tbody id="budgetHistoryBody"><tr><td colspan="5" class="budget-empty">No linked transactions yet.</td></tr></tbody>
      </table>
    </div>
    <div class="sb-modal-actions">
      <button class="btn subtle" type="button" data-close-modal="#budgetDetailModal">Close</button>
    </div>
  </div>
</div>

<script>
function openBudgetModal(selector) {
  const modal = document.querySelector(selector);
  if (!modal) return;
  modal.classList.add('open');
  document.body.classList.add('sb-modal-open');
}

function closeBudgetModal(selector) {
  const modal = document.querySelector(selector);
  if (!modal) return;
  modal.classList.remove('open');
  document.body.classList.remove('sb-modal-open');
}

function escapeBudgetHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

document.querySelectorAll('[data-close-modal]').forEach((button) => {
  button.addEventListener('click', () => closeBudgetModal(button.getAttribute('data-close-modal')));
});

document.querySelectorAll('.sb-modal-backdrop').forEach((backdrop) => {
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      backdrop.classList.remove('open');
      document.body.classList.remove('sb-modal-open');
    }
  });
});

document.querySelectorAll('.js-budget-detail').forEach((button) => {
  button.addEventListener('click', () => {
    const budget = JSON.parse(button.dataset.budget || '{}');
    const details = [
      ['Budget Name', budget.budget_name || '-'],
      ['Department', budget.department || '-'],
      ['Allocated Amount', 'PHP ' + Number(budget.allocated_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })],
      ['Used Amount', 'PHP ' + Number(budget.used_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })],
      ['Remaining Amount', 'PHP ' + Number(budget.remaining_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })],
      ['Utilization %', (Number(budget.utilization_percentage || 0).toFixed(1)) + '%'],
      ['Period Start', budget.period_start || '-'],
      ['Period End', budget.period_end || '-'],
      ['Status', budget.status || '-'],
      ['Usage Status', budget.status === 'Over Budget' ? 'Critical' : (Number(budget.utilization_percentage || 0) > 90 ? 'Critical' : (Number(budget.utilization_percentage || 0) >= 70 ? 'Warning' : 'Safe'))],
      ['Notes', budget.notes || '-']
    ];

    const detailGrid = document.getElementById('budgetDetailGrid');
    detailGrid.innerHTML = details.map(([label, value]) => '<div><span>' + escapeBudgetHtml(label) + '</span><strong>' + escapeBudgetHtml(value) + '</strong></div>').join('');

    const historyRows = Array.isArray(budget.history) ? budget.history : [];
    const historyBody = document.getElementById('budgetHistoryBody');
    historyBody.innerHTML = historyRows.length
      ? historyRows.map((row) => '<tr><td>' + escapeBudgetHtml(row.transaction_date || '-') + '</td><td>' + escapeBudgetHtml(row.source_type || '-') + '</td><td>' + escapeBudgetHtml(row.reference_no || '-') + '</td><td>' + escapeBudgetHtml(row.party_name || '-') + '</td><td class="money-cell">PHP ' + Number(row.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td></tr>').join('')
      : '<tr><td colspan="5" class="budget-empty">No linked transactions yet.</td></tr>';

    openBudgetModal('#budgetDetailModal');
  });
});
</script>
</body>
</html>
