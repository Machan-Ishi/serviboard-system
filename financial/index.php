<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/../config/db.php';
$bootstrapMessages = finance_bootstrap($pdo);
$counts = fetch_counts($pdo);
$metrics = get_financial_dashboard_metrics($pdo);
$budgetSummary = getBudgetDashboardSummary($pdo);
$workspace = getFinancialDashboardWorkspace($pdo);

function peso_dashboard($amount): string {
  return 'P' . number_format((float)$amount, 2);
}

function dashboard_value_or_empty(mixed $value, bool $currency = false): string {
  $numeric = is_numeric($value) ? (float) $value : 0.0;
  if ($numeric <= 0) {
    return 'No data yet';
  }
  return $currency ? 'PHP ' . finance_money($numeric) : number_format($numeric);
}

function dashboard_helper_text(int|float $value, string $positiveText, string $emptyText = 'No pending items'): string {
  return ((float) $value) > 0 ? $positiveText : $emptyText;
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>FINANCIAL - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .dashboard-stats .stat {
      min-height: 108px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .dashboard-stats .helper {
      margin-top: 6px;
      color: var(--muted);
      font-size: 12px;
    }
    .dashboard-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.25fr) minmax(320px, 0.75fr);
      gap: 18px;
    }
    .dashboard-stack {
      display: grid;
      gap: 18px;
    }
    .quick-actions-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
    }
    .quick-action {
      background: var(--stat-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      color: inherit;
      text-decoration: none;
      transition: border-color 0.2s ease, transform 0.2s ease, background 0.2s ease;
    }
    .quick-action:hover {
      border-color: rgba(240, 175, 28, 0.45);
      background: rgba(240, 175, 28, 0.05);
      transform: translateY(-1px);
    }
    .quick-action strong {
      font-size: 14px;
      color: var(--text);
    }
    .quick-action span {
      font-size: 12px;
      color: var(--muted);
    }
    .activity-list,
    .priority-list {
      display: grid;
      gap: 10px;
    }
    .activity-item,
    .priority-item {
      background: var(--stat-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px 14px;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
    }
    .activity-item strong,
    .priority-item strong {
      display: block;
      margin-bottom: 4px;
      color: var(--text);
      font-size: 13px;
    }
    .activity-meta,
    .priority-meta {
      color: var(--muted);
      font-size: 12px;
    }
    .activity-amount,
    .priority-count {
      white-space: nowrap;
      font-weight: 600;
      color: var(--gold);
      text-align: right;
    }
    .sub-card .detail-list {
      display: grid;
      gap: 6px;
      font-size: 12px;
      color: var(--muted);
    }
    .sub-card .detail-list span {
      display: flex;
      justify-content: space-between;
      gap: 8px;
    }
    .sub-card .detail-list strong {
      color: var(--text);
      font-size: 12px;
      font-weight: 600;
    }
    @media (max-width: 1100px) {
      .dashboard-grid {
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

    <div class="page-header">
      <h1>ServiBoard Dashboard</h1>
      <p>Select a module to manage your organization's services</p>
    </div>

    <a class="back-link" href="/FinancialSM/index.php">&larr; Back to Dashboard</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>

    <section class="section-card">
      <div class="section-head">
        <div class="section-icon">$</div>
        <div class="section-title">
          <h2>Financial</h2>
          <p>Accounting, budgeting, and financial reports</p>
        </div>
      </div>
      <div class="stats-grid dashboard-stats">
        <div class="stat">
          <h4>Open AR</h4>
          <div class="value"><?= dashboard_value_or_empty($metrics['open_ar'], true) ?></div>
          <div class="helper"><?= dashboard_helper_text((int) ($workspace['summary']['open_ar_count'] ?? 0), number_format((int) ($workspace['summary']['open_ar_count'] ?? 0)) . ' open accounts', 'No open receivables') ?></div>
        </div>
        <div class="stat">
          <h4>Open AP</h4>
          <div class="value"><?= dashboard_value_or_empty($metrics['open_ap'], true) ?></div>
          <div class="helper"><?= dashboard_helper_text((int) ($workspace['summary']['open_ap_count'] ?? 0), number_format((int) ($workspace['summary']['open_ap_count'] ?? 0)) . ' open payables', 'No pending payables') ?></div>
        </div>
        <div class="stat">
          <h4>Collections This Month</h4>
          <div class="value"><?= dashboard_value_or_empty($metrics['collections_month'], true) ?></div>
          <div class="helper"><?= dashboard_helper_text((int) ($workspace['summary']['collections_today_count'] ?? 0), '+' . number_format((int) ($workspace['summary']['collections_today_count'] ?? 0)) . ' today', 'No collections today') ?></div>
        </div>
        <div class="stat">
          <h4>Disbursements This Month</h4>
          <div class="value"><?= dashboard_value_or_empty($metrics['disbursements_month'], true) ?></div>
          <div class="helper"><?= dashboard_helper_text((int) ($workspace['summary']['disbursements_week_count'] ?? 0), number_format((int) ($workspace['summary']['disbursements_week_count'] ?? 0)) . ' this week', 'No new disbursements this week') ?></div>
        </div>
        <div class="stat">
          <h4>Remaining Budget</h4>
          <div class="value"><?= dashboard_value_or_empty($budgetSummary['total_remaining'] ?? 0, true) ?></div>
          <div class="helper"><?= dashboard_helper_text((int) ($workspace['summary']['budgets_active'] ?? 0), number_format((int) ($workspace['summary']['budgets_active'] ?? 0)) . ' active budgets', 'No active budgets') ?></div>
        </div>
        <div class="stat">
          <h4>Pending Client Requests</h4>
          <div class="value"><?= dashboard_value_or_empty($metrics['request_count']) ?></div>
          <div class="helper"><?= dashboard_helper_text((int) ($workspace['summary']['pending_client_requests'] ?? 0), 'Needs review', 'No pending items') ?></div>
        </div>
      </div>
    </section>

    <div class="dashboard-grid">
      <div class="dashboard-stack">
        <section class="section-card">
          <div class="sub-head">
            <h3 class="heading-reset">Quick Actions</h3>
            <span>Shortcuts</span>
          </div>
          <div class="quick-actions-grid">
            <?php foreach ($workspace['quick_actions'] as $action): ?>
              <a class="quick-action" href="<?= finance_h((string) $action['href']) ?>">
                <strong><?= finance_h((string) $action['label']) ?></strong>
                <span>Open module</span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="section-card">
          <div class="sub-head">
            <h3 class="heading-reset">Recent Financial Activity</h3>
            <span>Latest 8</span>
          </div>
          <div class="activity-list">
            <?php if (!empty($workspace['recent_activity'])): ?>
              <?php foreach ($workspace['recent_activity'] as $item): ?>
                <div class="activity-item">
                  <div>
                    <strong><?= finance_h((string) ($item['module'] ?? '-')) ?> · <?= finance_h((string) ($item['action'] ?? '-')) ?></strong>
                    <div class="activity-meta"><?= finance_h((string) ($item['description'] ?? '-')) ?></div>
                    <div class="activity-meta"><?= !empty($item['date_time']) ? date('M d, Y g:i A', strtotime((string) $item['date_time'])) : 'No timestamp' ?></div>
                  </div>
                  <div class="activity-amount">
                    <?= isset($item['amount']) && $item['amount'] !== null ? 'PHP ' . finance_money((float) $item['amount']) : '-' ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="activity-item">
                <div>
                  <strong>No recent activity</strong>
                  <div class="activity-meta">Financial actions will appear here once the system records them.</div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <div class="dashboard-stack">
        <section class="section-card">
          <div class="sub-head">
            <h3 class="heading-reset">Pending Work</h3>
            <span>Needs attention</span>
          </div>
          <div class="priority-list">
            <?php foreach ($workspace['pending_work'] as $item): ?>
              <a class="priority-item" href="<?= finance_h((string) ($item['href'] ?? '/FinancialSM/financial/index.php')) ?>">
                <div>
                  <strong><?= finance_h((string) ($item['label'] ?? 'Pending Item')) ?></strong>
                  <div class="priority-meta"><?= ((int) ($item['count'] ?? 0)) > 0 ? 'Needs review now' : 'Clear' ?></div>
                </div>
                <div class="priority-count"><?= number_format((int) ($item['count'] ?? 0)) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      </div>
    </div>

    <section class="section-card">
      <div class="sub-head">
        <h3 class="heading-reset">Sub-Modules</h3>
        <span>Components</span>
      </div>
      <div class="sub-grid">
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/collection.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Collection</h3>
            <p>Manage payment collections and receivables</p>
          </div>
          <div class="detail-list">
            <span><span>Pending</span><strong><?= number_format((int) ($workspace['submodules']['collection']['pending'] ?? 0)) ?></strong></span>
            <span><span>Today</span><strong><?= dashboard_value_or_empty($workspace['submodules']['collection']['today_amount'] ?? 0, true) ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/ap-ar.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Accounts Payable (AP) &amp; Accounts Receivable (AR)</h3>
            <p>Manage payables and receivables</p>
          </div>
          <div class="detail-list">
            <span><span>Open AR</span><strong><?= number_format((int) ($workspace['submodules']['ar_ap']['open_ar_count'] ?? 0)) ?></strong></span>
            <span><span>Open AP</span><strong><?= number_format((int) ($workspace['submodules']['ar_ap']['open_ap_count'] ?? 0)) ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/general-ledger.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>General Ledger</h3>
            <p>Maintain general ledger and accounting records</p>
          </div>
          <div class="detail-list">
            <span><span>Recent Entries</span><strong><?= number_format((int) ($workspace['submodules']['general_ledger']['recent_entries'] ?? 0)) ?></strong></span>
            <span><span>Status</span><strong><?= ((int) ($workspace['submodules']['general_ledger']['recent_entries'] ?? 0)) > 0 ? 'Active' : 'No data yet' ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/budget-management.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Budget Management</h3>
            <p>Plan and manage organizational budgets</p>
          </div>
          <div class="detail-list">
            <span><span>Active Budgets</span><strong><?= number_format((int) ($workspace['submodules']['budget']['active_budgets'] ?? 0)) ?></strong></span>
            <span><span>Remaining</span><strong><?= dashboard_value_or_empty($workspace['submodules']['budget']['remaining_amount'] ?? 0, true) ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/payroll/employees.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Payroll</h3>
            <p>Manage employee salaries and bonuses</p>
          </div>
          <div class="detail-list">
            <span><span>Pending Runs</span><strong><?= number_format((int) ($workspace['submodules']['payroll']['pending_runs'] ?? 0)) ?></strong></span>
            <span><span>Latest Status</span><strong><?= finance_h((string) ($workspace['submodules']['payroll']['latest_status'] ?? 'No data yet')) ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/disbursement.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Disbursement</h3>
            <p>Process and manage financial disbursements</p>
          </div>
          <div class="detail-list">
            <span><span>Pending</span><strong><?= number_format((int) ($workspace['submodules']['disbursement']['pending'] ?? 0)) ?></strong></span>
            <span><span>This Week</span><strong><?= dashboard_value_or_empty($workspace['submodules']['disbursement']['week_amount'] ?? 0, true) ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/client-requests.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 6h16v12H4z"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Client Requests</h3>
            <p>Review and respond to user-submitted financial requests</p>
          </div>
          <div class="detail-list">
            <span><span>Pending</span><strong><?= number_format((int) ($workspace['summary']['pending_client_requests'] ?? 0)) ?></strong></span>
            <span><span>Status</span><strong><?= ((int) ($workspace['summary']['pending_client_requests'] ?? 0)) > 0 ? 'Needs review' : 'Clear' ?></strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/request-action-logs.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Request Logs</h3>
            <p>Review approval and rejection activity across modules</p>
          </div>
          <div class="detail-list">
            <span><span>Recent Logs</span><strong><?= number_format(count($workspace['recent_activity'])) ?></strong></span>
            <span><span>View</span><strong>History</strong></span>
          </div>
        </a>
        <a class="sub-card sub-card-link" href="/FinancialSM/financial/isupabase.php">
          <div class="row">
            <div class="chip">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
              </svg>
            </div>
            <span>&gt;</span>
          </div>
          <div>
            <h3>Supabase Integration</h3>
            <p>Monitor cloud database synchronization status</p>
          </div>
          <div class="detail-list">
            <span><span>Status</span><strong>Active</strong></span>
            <span><span>Sync</span><strong>Connected</strong></span>
          </div>
        </a>
      </div>
    </section>

    </main>
</div>
</body>
</html>
