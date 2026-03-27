<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$bootstrapMessages = finance_bootstrap($pdo);
$trialBalance = getTrialBalance($pdo);
$incomeSummary = getIncomeSummary($pdo);
$budgetPerformance = getBudgetPerformance($pdo);
$receivableAging = getAccountsReceivableAging($pdo);
$payableSummary = getAccountsPayableSummary($pdo);
$dashboardSummary = getFinancialDashboardSummary($pdo);
$ledgerCheck = verifyLedgerBalance($pdo);

function reports_balance_class(float $balance): string
{
    if ($balance > 0) {
        return 'badge-paid';
    }
    if ($balance < 0) {
        return 'badge-cancelled';
    }

    return 'badge-partial';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Financial Reports - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .reports-summary {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 14px;
    }

    .reports-summary .summary-card {
      position: relative;
      overflow: hidden;
      min-height: 110px;
      padding: 18px;
      border: 1px solid var(--border);
      border-radius: 16px;
      background: var(--card-bg);
      box-shadow: var(--shadow-soft);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: border-color 0.2s ease;
    }

    .reports-summary .summary-card:hover {
      border-color: var(--gold);
    }

    .reports-summary .summary-card::after {
      content: "";
      position: absolute;
      right: -18px;
      bottom: -18px;
      width: 84px;
      height: 84px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(240, 175, 28, 0.1) 0%, rgba(240, 175, 28, 0) 74%);
    }

    .reports-summary .summary-label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.7px;
    }

    .reports-summary .summary-value {
      display: block;
      font-size: 22px;
      line-height: 1.1;
      font-weight: 700;
      color: var(--text);
      word-break: break-word;
    }

    .report-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 18px;
    }

    .report-card .table-title {
      margin-bottom: 16px;
    }

    .report-highlight {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 14px;
    }

    .report-highlight .stat {
      min-height: 96px;
      background: var(--stat-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
    }

    @media (max-width: 1320px) {
      .reports-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 760px) {
      .reports-summary,
      .report-highlight {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../inc/sidebar.php'; ?>
  <main class="content" role="main">
    <?php include __DIR__ . '/../inc/financial_topbar.php'; ?>

    <div class="page-header"><h1>Financial Reports</h1><p>Generate dynamic financial summaries from the general ledger and related operational tables.</p></div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>
    <?php if (!$ledgerCheck['is_balanced']): ?><section class="section-card"><div class="error-text"><?= finance_h($ledgerCheck['warning']) ?>. Debit: PHP <?= finance_money($ledgerCheck['total_debit']) ?> | Credit: PHP <?= finance_money($ledgerCheck['total_credit']) ?> | Difference: PHP <?= finance_money($ledgerCheck['difference']) ?></div></section><?php endif; ?>

    <section class="reports-summary">
      <article class="summary-card"><span class="summary-label">Collections This Month</span><strong class="summary-value">PHP <?= finance_money($dashboardSummary['collections_month']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Disbursements This Month</span><strong class="summary-value">PHP <?= finance_money($dashboardSummary['disbursements_month']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Open AR</span><strong class="summary-value">PHP <?= finance_money($dashboardSummary['open_ar']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Open AP</span><strong class="summary-value">PHP <?= finance_money($dashboardSummary['open_ap']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Budget Remaining</span><strong class="summary-value">PHP <?= finance_money($dashboardSummary['budget_remaining']) ?></strong></article>
    </section>

    <div class="report-grid">
      <section class="section-card report-card">
        <div class="table-title">Trial Balance</div>
        <div class="table-wrap">
          <table class="notion-table">
            <thead>
              <tr>
                <th>Account</th>
                <th>Total Debit</th>
                <th>Total Credit</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$trialBalance): ?><tr><td colspan="4" class="muted-cell">No trial balance data available.</td></tr><?php endif; ?>
              <?php foreach ($trialBalance as $row): ?>
                <tr>
                  <td><?= finance_h((string) ($row['account_title'] ?? '-')) ?></td>
                  <td>PHP <?= finance_money($row['total_debit'] ?? 0) ?></td>
                  <td>PHP <?= finance_money($row['total_credit'] ?? 0) ?></td>
                  <td><span class="status-badge <?= reports_balance_class((float) ($row['ending_balance'] ?? 0)) ?>">PHP <?= finance_money($row['ending_balance'] ?? 0) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="section-card report-card">
        <div class="table-title">Income Summary</div>
        <div class="report-highlight">
          <div class="stat"><h4>Total Collections</h4><div class="value">PHP <?= finance_money($incomeSummary['total_collections']) ?></div></div>
          <div class="stat"><h4>Total Disbursements</h4><div class="value">PHP <?= finance_money($incomeSummary['total_disbursements']) ?></div></div>
          <div class="stat"><h4>Net Income / Loss</h4><div class="value">PHP <?= finance_money($incomeSummary['net_income']) ?></div></div>
        </div>
      </section>

      <section class="section-card report-card">
        <div class="table-title">Budget Performance</div>
        <div class="table-wrap">
          <table class="notion-table">
            <thead>
              <tr>
                <th>Budget</th>
                <th>Department</th>
                <th>Allocated</th>
                <th>Used</th>
                <th>Remaining</th>
                <th>Utilization</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$budgetPerformance): ?><tr><td colspan="6" class="muted-cell">No budget records available.</td></tr><?php endif; ?>
              <?php foreach ($budgetPerformance as $row): ?>
                <tr>
                  <td><?= finance_h((string) ($row['budget_name'] ?? '-')) ?></td>
                  <td><?= finance_h((string) ($row['department'] ?? '-')) ?></td>
                  <td>PHP <?= finance_money($row['allocated_amount'] ?? 0) ?></td>
                  <td>PHP <?= finance_money($row['used_amount'] ?? 0) ?></td>
                  <td>PHP <?= finance_money($row['remaining_amount'] ?? 0) ?></td>
                  <td><?= finance_h(number_format((float) ($row['utilization_percentage'] ?? 0), 2)) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="section-card report-card">
        <div class="table-title">Accounts Receivable Aging</div>
        <div class="table-wrap">
          <table class="notion-table">
            <thead>
              <tr>
                <th>Reference No</th>
                <th>Party Name</th>
                <th>Balance</th>
                <th>Due Date</th>
                <th>Days Overdue</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$receivableAging): ?><tr><td colspan="6" class="muted-cell">No outstanding receivables.</td></tr><?php endif; ?>
              <?php foreach ($receivableAging as $row): ?>
                <tr>
                  <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                  <td><?= finance_h((string) ($row['party_name'] ?? '-')) ?></td>
                  <td>PHP <?= finance_money($row['balance'] ?? 0) ?></td>
                  <td><?= finance_h((string) ($row['due_date'] ?? '-')) ?></td>
                  <td><?= (int) ($row['days_overdue'] ?? 0) ?></td>
                  <td><span class="status-badge badge-partial"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="section-card report-card">
        <div class="table-title">Accounts Payable Summary</div>
        <div class="table-wrap">
          <table class="notion-table">
            <thead>
              <tr>
                <th>Reference No</th>
                <th>Party Name</th>
                <th>Balance</th>
                <th>Due Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$payableSummary): ?><tr><td colspan="5" class="muted-cell">No outstanding payables.</td></tr><?php endif; ?>
              <?php foreach ($payableSummary as $row): ?>
                <tr>
                  <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                  <td><?= finance_h((string) ($row['party_name'] ?? '-')) ?></td>
                  <td>PHP <?= finance_money($row['balance'] ?? 0) ?></td>
                  <td><?= finance_h((string) ($row['due_date'] ?? '-')) ?></td>
                  <td><span class="status-badge badge-partial"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
</div>
</body>
</html>
