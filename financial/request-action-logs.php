<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$logSearch = trim((string) ($_GET['log_q'] ?? ''));
$logModule = trim((string) ($_GET['log_module'] ?? 'All'));
$logAction = trim((string) ($_GET['log_action'] ?? 'All'));
$logDateFrom = trim((string) ($_GET['log_date_from'] ?? ''));
$logDateTo = trim((string) ($_GET['log_date_to'] ?? ''));
$logPage = max(1, (int) ($_GET['log_page'] ?? 1));
$logPerPage = 20;
$logOffset = ($logPage - 1) * $logPerPage;
$logRows = [];
$logTotalRows = 0;
$logTotalPages = 1;
$error = '';
$bootstrapMessages = [];
$moduleOptions = ['All', 'CORE', 'LOGISTICS', 'HR'];

try {
    $bootstrapMessages = finance_bootstrap($pdo);
} catch (Throwable $e) {
    $bootstrapMessages = ['Bootstrap warning: ' . $e->getMessage()];
}

try {
    finance_ensure_request_action_logs_ready($pdo);

    $filters = [
        'search' => $logSearch,
        'module' => $logModule,
        'action' => $logAction,
        'date_from' => $logDateFrom,
        'date_to' => $logDateTo,
    ];

    $logTotalRows = finance_count_request_action_logs($pdo, $filters);
    $logTotalPages = max(1, (int) ceil($logTotalRows / $logPerPage));
    $logPage = min($logPage, $logTotalPages);
    $logOffset = ($logPage - 1) * $logPerPage;
    $logRows = finance_search_request_action_logs($pdo, $filters, $logPerPage, $logOffset);

    $schema = finance_schema_prefix($pdo);
    $moduleStmt = $pdo->query("SELECT DISTINCT module FROM {$schema}approved_request_logs ORDER BY module ASC");
    if ($moduleStmt !== false) {
        foreach (($moduleStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $moduleValue) {
            $moduleValue = strtoupper(trim((string) $moduleValue));
            if ($moduleValue !== '') {
                $moduleOptions[] = $moduleValue;
            }
        }
    }
    $moduleOptions = array_values(array_unique($moduleOptions));
} catch (Throwable $e) {
    $error = 'Could not load request action logs: ' . $e->getMessage();
}

function request_log_query(array $overrides = []): string
{
    $params = array_merge([
        'log_q' => $_GET['log_q'] ?? '',
        'log_module' => $_GET['log_module'] ?? 'All',
        'log_action' => $_GET['log_action'] ?? 'All',
        'log_date_from' => $_GET['log_date_from'] ?? '',
        'log_date_to' => $_GET['log_date_to'] ?? '',
        'log_page' => $_GET['log_page'] ?? 1,
    ], $overrides);

    return http_build_query($params);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Request Action Logs - Financial System</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .logs-shell {
      display: grid;
      gap: 20px;
    }
    .logs-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: end;
      gap: 14px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }
    .logs-filters {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      flex: 1 1 760px;
      min-width: 0;
    }
    .logs-filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 160px;
      flex: 1 1 160px;
    }
    .logs-filter-group.wide {
      min-width: 240px;
      flex-basis: 260px;
    }
    .logs-filter-group label {
      font-size: 11px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .logs-filter-group input,
    .logs-filter-group select {
      width: 100%;
      min-height: 42px;
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
      color: var(--text);
    }
    .logs-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      margin-left: auto;
    }
    .logs-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.03);
      color: var(--text);
      font-size: 12px;
      font-weight: 600;
    }
    .logs-btn.primary {
      background: rgba(60, 113, 206, 0.25);
      border-color: rgba(94, 148, 243, 0.35);
    }
    .logs-table-wrap {
      max-height: 640px;
      overflow: auto;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      background: rgba(7, 11, 18, 0.55);
      scrollbar-width: thin;
      scrollbar-color: rgba(214, 176, 92, 0.55) rgba(255, 255, 255, 0.04);
    }
    .logs-table-wrap::-webkit-scrollbar {
      width: 10px;
      height: 10px;
    }
    .logs-table-wrap::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.04);
      border-radius: 999px;
    }
    .logs-table-wrap::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, rgba(214, 176, 92, 0.72), rgba(214, 176, 92, 0.42));
      border-radius: 999px;
      border: 2px solid rgba(7, 11, 18, 0.65);
    }
    .logs-table-wrap .notion-table {
      margin: 0;
    }
    .logs-table-wrap .notion-table thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      background: #111827;
      box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.08);
    }
    .logs-table .amount-cell {
      white-space: nowrap;
      color: var(--gold);
      font-weight: 600;
    }
    .logs-table .remarks-cell {
      color: var(--muted);
      min-width: 220px;
    }
    .logs-table .actions-cell {
      text-align: right;
      white-space: nowrap;
      min-width: 220px;
    }
    .logs-row-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
      min-width: 0;
    }
    .logs-footer {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }
    .logs-pagination {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }
    @media (max-width: 900px) {
      .logs-actions {
        margin-left: 0;
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
      <h1>Request Action Logs</h1>
      <p>History of request actions across CORE, LOGISTICS, HR, and related financial modules.</p>
    </div>
    <a class="back-link" href="/FinancialSM/financial/collection.php">&larr; Back to Collection</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?>
      <section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section>
    <?php endforeach; ?>
    <?php if ($error !== ''): ?>
      <section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section>
    <?php endif; ?>

    <section class="section-card logs-shell">
      <form method="get" class="logs-toolbar">
        <div class="logs-filters">
          <div class="logs-filter-group wide">
            <label for="log_q">Search</label>
            <input id="log_q" type="text" name="log_q" value="<?= finance_h($logSearch) ?>" placeholder="Search description, remarks, module, action">
          </div>
          <div class="logs-filter-group">
            <label for="log_module">Module</label>
            <select id="log_module" name="log_module">
              <?php foreach ($moduleOptions as $moduleOption): ?>
                <option value="<?= finance_h($moduleOption) ?>" <?= strcasecmp($logModule, $moduleOption) === 0 ? 'selected' : '' ?>><?= finance_h($moduleOption) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="logs-filter-group">
            <label for="log_action">Action</label>
            <select id="log_action" name="log_action">
              <?php foreach (['All', 'Approved', 'Rejected', 'Revision Requested', 'Released'] as $actionOption): ?>
                <option value="<?= finance_h($actionOption) ?>" <?= strcasecmp($logAction, $actionOption) === 0 ? 'selected' : '' ?>><?= finance_h($actionOption) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="logs-filter-group">
            <label for="log_date_from">Date From</label>
            <input id="log_date_from" type="date" name="log_date_from" value="<?= finance_h($logDateFrom) ?>">
          </div>
          <div class="logs-filter-group">
            <label for="log_date_to">Date To</label>
            <input id="log_date_to" type="date" name="log_date_to" value="<?= finance_h($logDateTo) ?>">
          </div>
        </div>
        <div class="logs-actions">
          <button type="submit" class="logs-btn primary">Apply Filter</button>
          <a class="logs-btn" href="request-action-logs.php">Reset</a>
          <a class="logs-btn" href="/FinancialSM/financial/export_request_action_log_pdf.php" target="_blank" rel="noopener">Export PDF</a>
        </div>
      </form>

      <div class="logs-table-wrap">
        <table class="notion-table logs-table">
          <thead>
            <tr>
              <th>Approval Date</th>
              <th>Module</th>
              <th>Action</th>
              <th>Description</th>
              <th>Amount</th>
              <th>Remarks</th>
              <th style="text-align: right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($logRows)): ?>
              <?php foreach ($logRows as $row): ?>
                <tr>
                  <td style="color: var(--muted); font-size: 12px; white-space: nowrap;"><?= date('M d, Y g:i A', strtotime((string) ($row['approved_at'] ?? 'now'))) ?></td>
                  <td><span class="status-badge" style="background: var(--stat-bg);"><?= finance_h((string) ($row['module'] ?? '-')) ?></span></td>
                  <td><span class="status-badge <?= finance_request_action_log_badge_class($row) ?>"><?= finance_h(finance_request_action_log_display_action($row)) ?></span></td>
                  <td style="font-weight: 500; min-width: 260px;"><?= finance_h((string) ($row['description'] ?? '-')) ?></td>
                  <td class="amount-cell"><?= isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== '' ? 'PHP ' . finance_money((float) $row['amount']) : '-' ?></td>
                  <td class="remarks-cell"><?= trim((string) ($row['remarks'] ?? '')) !== '' ? finance_h((string) $row['remarks']) : '-' ?></td>
                  <td class="actions-cell">
                    <div class="logs-row-actions">
                      <?php if (finance_request_action_log_has_receipt($row)): ?>
                        <a class="logs-btn" href="/FinancialSM/financial/request-action-log-receipt.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">Receipt</a>
                      <?php endif; ?>
                      <?php if (finance_request_action_log_has_logistics_approval_slip($row)): ?>
                        <a class="logs-btn" href="/FinancialSM/financial/request-action-log-approval-slip.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">Approval Slip</a>
                      <?php endif; ?>
                      <?php if (finance_request_action_log_has_hr_summary($row)): ?>
                        <a class="logs-btn" href="/FinancialSM/financial/request-action-log-hr-summary.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">HR Summary</a>
                      <?php endif; ?>
                      <?php if (finance_request_action_log_has_disbursement_voucher($row)): ?>
                        <a class="logs-btn" href="/FinancialSM/financial/request-action-log-voucher.php?id=<?= (int) ($row['id'] ?? 0) ?>" target="_blank" rel="noopener">Voucher</a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--muted); padding: 24px;">No request action logs found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="logs-footer">
        <span>Showing <?= number_format(count($logRows)) ?> of <?= number_format($logTotalRows) ?> log entr<?= $logTotalRows === 1 ? 'y' : 'ies' ?></span>
        <div class="logs-pagination">
          <?php if ($logPage > 1): ?>
            <a class="logs-btn" href="?<?= request_log_query(['log_page' => $logPage - 1]) ?>">Previous</a>
          <?php endif; ?>
          <span>Page <?= number_format($logPage) ?> of <?= number_format($logTotalPages) ?></span>
          <?php if ($logPage < $logTotalPages): ?>
            <a class="logs-btn" href="?<?= request_log_query(['log_page' => $logPage + 1]) ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>
</div>
</body>
</html>
