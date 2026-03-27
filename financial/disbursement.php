<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

require_once __DIR__ . '/../inc/functions.php';

$bootstrapMessages = finance_bootstrap($pdo);
$message = '';
$error = '';
$isAdmin = finance_current_user_is_admin();

$filters = [
    'date' => trim((string) ($_GET['date'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'payee' => trim((string) ($_GET['payee'] ?? '')),
    'request_source' => strtoupper(trim((string) ($_GET['request_source'] ?? ''))),
];

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? getDisbursementById($pdo, $editId) : null;

$apRows = array_values(array_filter(
    getAPList($pdo),
    static fn (array $row): bool => (float) ($row['balance'] ?? 0) > 0
));
// If editing and linked to AP, ensure the linked AP is in the list
if ($editId > 0 && $editRow && strtoupper((string) ($editRow['request_source'] ?? '')) === 'AP') {
    $linkedApId = (int) ($editRow['request_id'] ?? 0);
    if ($linkedApId > 0) {
        $apExists = false;
        foreach ($apRows as $ap) {
            if ((int) $ap['id'] === $linkedApId) {
                $apExists = true;
                break;
            }
        }
        if (!$apExists) {
            $stmt = $pdo->prepare("SELECT * FROM public.ar_ap WHERE id = ? AND entry_type = 'AP'");
            $stmt->execute([$linkedApId]);
            $linkedAp = $stmt->fetch();
            if ($linkedAp) {
                $apRows[] = $linkedAp;
            }
        }
    }
}
$budgetRows = getBudgetList($pdo);
$payrollRequestRows = getPayrollPaymentRequestList($pdo);
$approvedRequestRows = finance_get_approved_requests_for_disbursement($pdo);
$readyForDisbursementRows = array_values(array_filter(
    $approvedRequestRows,
    static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['Approved', 'Ready for Disbursement'], true)
));
// If editing and linked to payroll, ensure the linked payroll request is in the list
if ($editId > 0 && $editRow && strtoupper((string) ($editRow['request_source'] ?? '')) === 'PAYROLL') {
    $linkedPayrollId = (int) ($editRow['request_id'] ?? 0);
    if ($linkedPayrollId > 0) {
        $payrollExists = false;
        foreach ($payrollRequestRows as $pr) {
            if ((int) $pr['id'] === $linkedPayrollId) {
                $payrollExists = true;
                break;
            }
        }
        if (!$payrollExists) {
            $linkedPayroll = getPayrollPaymentRequestById($pdo, $linkedPayrollId);
            if ($linkedPayroll) {
                $payrollRequestRows[] = $linkedPayroll;
            }
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            throw new Exception('Invalid request (CSRF failure).');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'save_disbursement') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                updateDisbursement($pdo, $id, $_POST);
                $message = 'Disbursement updated.';
                $editRow = getDisbursementById($pdo, $id);
                $editId = $id;
            } else {
                $savedId = createDisbursement($pdo, $_POST);
                $message = 'Disbursement recorded.';
                $editRow = getDisbursementById($pdo, $savedId);
                $editId = $savedId;
            }
        }

        if ($action === 'release_request_funds') {
            $sourceModule = (string) ($_POST['source_module'] ?? '');
            $requestId = (string) ($_POST['source_request_id'] ?? '');
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Bank Transfer'));
            $savedId = finance_release_request_for_disbursement($pdo, $sourceModule, $requestId, $paymentMethod);
            $message = 'Funds released and disbursement entry created.';
            $editRow = getDisbursementById($pdo, $savedId);
            $editId = $savedId;
            $approvedRequestRows = finance_get_approved_requests_for_disbursement($pdo);
            $readyForDisbursementRows = array_values(array_filter(
                $approvedRequestRows,
                static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['Approved', 'Ready for Disbursement'], true)
            ));
        }

        if ($action === 'delete_disbursement') {
            deleteDisbursement($pdo, (int) ($_POST['id'] ?? 0));
            $message = 'Disbursement deleted.';
            $editRow = null;
            $editId = 0;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$rows = getDisbursementList($pdo, $filters);
$allRows = getDisbursementList($pdo);

$summary = [
    'month_total' => 0.0,
    'pending_total' => 0.0,
    'released_total' => 0.0,
    'budget_linked' => 0,
    'ap_linked' => 0,
];

foreach ($allRows as $row) {
    $amount = (float) ($row['amount'] ?? 0);
    $source = strtoupper((string) ($row['request_source'] ?? ''));
    $status = (string) ($row['status'] ?? '');
    $date = (string) ($row['disbursement_date'] ?? '');

    if (str_starts_with($date, date('Y-m'))) {
        $summary['month_total'] += $amount;
    }
    if ($status === 'Pending') {
        $summary['pending_total'] += $amount;
    }
    if (in_array($status, ['Released', 'Posted'], true)) {
        $summary['released_total'] += $amount;
    }
    if ($source === 'BUDGET') {
        $summary['budget_linked']++;
    }
    if ($source === 'AP') {
        $summary['ap_linked']++;
    }
}

$apLookup = [];
foreach ($apRows as $row) {
    $apLookup[(int) $row['id']] = $row;
}

$budgetLookup = [];
foreach ($budgetRows as $row) {
    $budgetLookup[(int) $row['id']] = $row;
}

$form = $editRow ?: [
    'id' => 0,
    'reference_no' => '',
    'payee_name' => '',
    'request_source' => 'MANUAL',
    'request_id' => 0,
    'amount' => '',
    'disbursement_date' => date('Y-m-d'),
    'payment_method' => 'Cash',
    'status' => 'Released',
    'remarks' => '',
];

$formSource = strtoupper((string) ($form['request_source'] ?? 'MANUAL'));
if (!in_array($formSource, ['MANUAL', 'AP', 'BUDGET', 'PAYROLL', 'CORE', 'HR', 'LOGISTICS'], true)) {
    $formSource = 'MANUAL';
}
$formRequestId = (int) ($form['request_id'] ?? 0);
$formLedgerMode = ($formSource === 'AP') ? 'ap' : 'expense';

function disbursement_source_display_label(array $row, array $apLookup, array $budgetLookup): string
{
    $source = strtoupper((string) ($row['request_source'] ?? ''));
    $requestId = (int) ($row['request_id'] ?? 0);

    if ($source === 'AP' && isset($apLookup[$requestId])) {
        return 'AP / ' . (string) ($apLookup[$requestId]['reference_no'] ?? $requestId);
    }
    if ($source === 'BUDGET' && isset($budgetLookup[$requestId])) {
        return 'Budget / ' . (string) ($budgetLookup[$requestId]['budget_name'] ?? $requestId);
    }
    if ($source === 'PAYROLL') {
        return 'Payroll / Request ' . $requestId;
    }
    if ($source === 'CORE') {
        return 'Core Services / Request ' . $requestId;
    }
    if ($source === 'HR') {
        return 'Human Resources / Request ' . $requestId;
    }
    if ($source === 'LOGISTICS') {
        return 'Logistics / Request ' . $requestId;
    }
    if ($source === '' || $source === 'MANUAL') {
        return 'Manual';
    }

    return $source;
}

function disbursement_badge_class(string $status): string
{
    return match ($status) {
        'Released', 'Posted' => 'badge-paid',
        'Pending' => 'badge-pending',
        'Cancelled' => 'badge-cancelled',
        default => 'badge-partial',
    };
}

function disbursement_source_label(array $row, array $apLookup, array $budgetLookup): string
{
    $source = strtoupper((string) ($row['request_source'] ?? ''));
    $requestId = (int) ($row['request_id'] ?? 0);

    if ($source === 'AP' && isset($apLookup[$requestId])) {
        return 'AP · ' . (string) ($apLookup[$requestId]['reference_no'] ?? $requestId);
    }
    if ($source === 'BUDGET' && isset($budgetLookup[$requestId])) {
        return 'Budget · ' . (string) ($budgetLookup[$requestId]['budget_name'] ?? $requestId);
    }
    if ($source === 'PAYROLL') {
        return 'Payroll · Request ' . $requestId;
    }
    if ($source === '' || $source === 'MANUAL') {
        return 'Manual';
    }

    return $source;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Disbursement - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .disb-page-summary {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 14px;
    }

    .disb-page-summary .summary-card {
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
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .disb-page-summary .summary-card:hover {
      transform: translateY(-2px);
      border-color: var(--gold);
    }

    .disb-page-summary .summary-label {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .disb-page-summary .summary-value {
      display: block;
      font-size: 24px;
      line-height: 1.1;
      font-weight: 700;
      color: var(--text);
      word-break: break-word;
      margin-top: 8px;
    }

    .disb-panel {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 24px;
      background: var(--card-bg);
      box-shadow: var(--shadow-soft);
      transition: border-color 0.3s ease;
    }

    .disb-panel:hover {
      border-color: rgba(240, 175, 28, 0.2);
    }

    .form-row input,
    .form-row select,
    .form-row textarea {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--stat-bg);
      color: var(--text);
      font-size: 14px;
      transition: all 0.2s ease;
    }

    .form-row input:focus,
    .form-row select:focus,
    .form-row textarea:focus {
      outline: none;
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(240, 175, 28, 0.1);
    }

    .notion-table {
      width: 100%;
      border-collapse: collapse;
      background: transparent;
    }

    .notion-table thead {
      background: var(--stat-bg);
    }

    .notion-table td {
      padding: 14px 12px;
      border-bottom: 1px solid var(--border);
      font-size: 14px;
      color: var(--text);
    }

    .notion-table tbody tr:hover td {
      background: rgba(255, 255, 255, 0.02);
    }

    .filter-bar .form-row input,
    .filter-bar .form-row select {
      padding: 8px 10px;
      font-size: 13px;
    }

    .filter-bar .form-actions {
      margin-top: 16px;
      justify-content: flex-start;
    }

    .disb-message {
      min-height: auto;
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(16, 185, 129, 0.1);
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .disb-message::before {
      content: "✓";
      font-size: 16px;
    }

    .error-text {
      background: rgba(239, 68, 68, 0.1);
      color: #dc2626;
      border: 1px solid rgba(239, 68, 68, 0.2);
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .error-text::before {
      content: "⚠";
      font-size: 16px;
    }

    .section-card {
      margin-bottom: 24px;
    }

    .disb-ready-table th.amount-cell,
    .disb-ready-table td.amount-cell {
      text-align: right;
    }

    .disb-ready-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
    }

    .disb-ready-table .status-badge {
      white-space: nowrap;
    }

    .disb-ready-note {
      margin-top: 12px;
      color: var(--muted);
      font-size: 13px;
    }

    .page-header {
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border);
    }

    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--primary);
      text-decoration: none;
      font-weight: 500;
      margin-bottom: 24px;
      transition: color 0.2s ease;
    }

    .back-link:hover {
      color: var(--primary-dark, #2563eb);
    }

    .back-link::before {
      content: "←";
      font-size: 16px;
    }

    @media (max-width: 1320px) {
      .disb-page-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 980px) {
      .disb-workspace-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 760px) {
      .disb-page-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 520px) {
      .disb-page-summary {
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

    <div class="page-header"><h1>Disbursement</h1><p>Record outgoing payments, settle payables, consume budgets, and post to the general ledger automatically.</p></div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>
    <?php if ($message !== ''): ?><section class="section-card disb-message"><div class="status-badge badge-paid"><?= finance_h($message) ?></div></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section><?php endif; ?>

    <section class="disb-page-summary">
      <article class="summary-card"><span class="summary-label">This Month</span><strong class="summary-value">PHP <?= finance_money($summary['month_total']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Pending</span><strong class="summary-value">PHP <?= finance_money($summary['pending_total']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Released</span><strong class="summary-value">PHP <?= finance_money($summary['released_total']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Budget Linked</span><strong class="summary-value"><?= number_format($summary['budget_linked']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">AP Linked</span><strong class="summary-value"><?= number_format($summary['ap_linked']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Records</span><strong class="summary-value"><?= number_format(count($allRows)) ?></strong></article>
    </section>

    <section class="section-card disb-register">
      <div class="disb-workspace-header">
        <div>
          <h2 class="collection-title">Approved Requests for Disbursement</h2>
          <p class="collection-subtitle">Approved requests from HR, Logistics, and Core are queued here for fund release.</p>
        </div>
      </div>
      <div class="table-wrap table-scroll-pane">
        <table class="notion-table disb-ready-table">
          <thead>
            <tr>
              <th>Request ID</th>
              <th>Source Module</th>
              <th>Description</th>
              <th class="amount-cell">Approved Amount</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$readyForDisbursementRows): ?><tr><td colspan="6" class="muted-cell">No approved requests are ready for disbursement.</td></tr><?php endif; ?>
            <?php foreach ($readyForDisbursementRows as $row): ?>
              <tr>
                <td><?= finance_h((string) ($row['request_code'] ?? $row['request_id'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['source_module'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['description'] ?? '-')) ?></td>
                <td class="amount-cell">PHP <?= finance_money($row['approved_amount'] ?? 0) ?></td>
                <td><span class="status-badge badge-approved"><?= finance_h((string) ($row['status'] ?? 'Approved')) ?></span></td>
                <td>
                  <div class="disb-ready-actions">
                    <form
                      method="post"
                      class="js-release-request-form"
                      data-approved-amount="<?= number_format((float) ($row['approved_amount'] ?? 0), 2, '.', '') ?>"
                      data-budget-id="<?= (int) ($row['related_budget_id'] ?? 0) ?>"
                      data-budget-remaining="<?php
                        $linkedBudgetId = (int) ($row['related_budget_id'] ?? 0);
                        $linkedBudget = $linkedBudgetId > 0 ? ($budgetLookup[$linkedBudgetId] ?? null) : null;
                        echo number_format((float) ($linkedBudget['remaining_amount'] ?? 0), 2, '.', '');
                      ?>"
                      onsubmit="return confirm('Release funds for this approved request?');"
                    >
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="release_request_funds">
                      <input type="hidden" name="source_module" value="<?= finance_h((string) ($row['source_module'] ?? '')) ?>">
                      <input type="hidden" name="source_request_id" value="<?= finance_h((string) ($row['request_id'] ?? '')) ?>">
                      <input type="hidden" name="payment_method" value="Bank Transfer">
                      <input type="hidden" name="budget_override" value="0">
                      <button class="btn primary" type="submit">Release Funds</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="disb-ready-note">Only approved requests can be released. Releasing funds marks the source request as Released and creates a linked disbursement record.</p>
    </section>

    <section class="section-card collection-shell disb-shell disb-page-shell">
      <div class="disb-workspace-header">
        <div>
          <h2 class="collection-title">Disbursement Workspace</h2>
          <p class="collection-subtitle">Use AP mode when settling a supplier payable. Use Budget mode when the payment should consume a budget allocation.</p>
        </div>
      </div>

      <div class="disb-workspace-grid">
        <form class="disb-panel" method="post">
          <?= csrf_field() ?>
          <h3 class="form-title"><?= $editRow ? 'Edit Disbursement' : 'Create Disbursement' ?></h3>
          <input type="hidden" name="action" value="save_disbursement">
          <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">
          <input type="hidden" name="budget_override" id="budget_override" value="0">

          <div class="form-row"><label for="reference_no">Reference No</label><input id="reference_no" name="reference_no" type="text" value="<?= finance_h((string) ($form['reference_no'] ?? '')) ?>" placeholder="Auto-generated if empty"></div>
          <div class="form-row"><label for="payee_name">Payee Name</label><input id="payee_name" name="payee_name" type="text" required value="<?= finance_h((string) ($form['payee_name'] ?? '')) ?>"></div>

          <div class="form-row split">
            <div>
              <label for="request_source">Request Source</label>
              <select id="request_source" name="request_source">
                <?php foreach (['MANUAL' => 'Manual', 'AP' => 'Accounts Payable', 'BUDGET' => 'Budget', 'PAYROLL' => 'Payroll Request', 'CORE' => 'Core Services', 'HR' => 'Human Resources', 'LOGISTICS' => 'Logistics'] as $value => $label): ?>
                  <option value="<?= finance_h($value) ?>" <?= $formSource === $value ? 'selected' : '' ?>><?= finance_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="ledger_mode">Ledger Mode</label>
              <select id="ledger_mode" name="ledger_mode">
                <option value="expense" <?= $formLedgerMode === 'expense' ? 'selected' : '' ?>>Expense</option>
                <option value="ap" <?= $formLedgerMode === 'ap' ? 'selected' : '' ?>>Accounts Payable</option>
              </select>
            </div>
          </div>

          <div class="form-row split">
            <div id="ap_picker_wrap">
              <label for="related_ap_id">Related AP</label>
              <select id="related_ap_id" name="related_ap_id">
                <option value="0">Select AP record</option>
                <?php foreach ($apRows as $ap): ?>
                  <option value="<?= (int) $ap['id'] ?>" <?= $formSource === 'AP' && $formRequestId === (int) $ap['id'] ? 'selected' : '' ?>>
                    <?= finance_h((string) ($ap['reference_no'] ?? 'AP')) ?> · <?= finance_h((string) ($ap['party_name'] ?? '')) ?> · Balance PHP <?= finance_money($ap['balance'] ?? 0) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="budget_picker_wrap">
              <label for="related_budget_id">Related Budget</label>
              <select id="related_budget_id" name="related_budget_id">
                <option value="0">Select budget</option>
                <?php foreach ($budgetRows as $budget): ?>
                  <option
                    value="<?= (int) $budget['id'] ?>"
                    data-remaining="<?= number_format((float) ($budget['remaining_amount'] ?? 0), 2, '.', '') ?>"
                    data-utilization="<?= number_format((float) ($budget['utilization_percentage'] ?? 0), 2, '.', '') ?>"
                    data-status="<?= finance_h((string) ($budget['status'] ?? '')) ?>"
                    <?= $formSource === 'BUDGET' && $formRequestId === (int) $budget['id'] ? 'selected' : '' ?>
                  >
                    <?= finance_h((string) ($budget['budget_name'] ?? 'Budget')) ?> · <?= finance_h((string) ($budget['department'] ?? '')) ?> · Remaining PHP <?= finance_money($budget['remaining_amount'] ?? 0) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="payroll_picker_wrap">
              <label for="related_payroll_id">Related Payroll Request</label>
              <select id="related_payroll_id" name="related_payroll_id">
                <option value="0">Select payroll request</option>
                <?php foreach ($payrollRequestRows as $pr): ?>
                  <option value="<?= (int) $pr['id'] ?>" <?= $formSource === 'PAYROLL' && $formRequestId === (int) $pr['id'] ? 'selected' : '' ?> data-amount="<?= (float)$pr['total_amount'] ?>">
                    <?= finance_h((string) ($pr['request_no'])) ?> · PHP <?= finance_money($pr['total_amount']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row split">
            <div><label for="amount">Amount</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= finance_h((string) ($form['amount'] ?? '')) ?>"></div>
            <div><label for="disbursement_date">Disbursement Date</label><input id="disbursement_date" name="disbursement_date" type="date" required value="<?= finance_h((string) ($form['disbursement_date'] ?? date('Y-m-d'))) ?>"></div>
          </div>

          <div class="form-row split">
            <div><label for="payment_method">Payment Method</label><select id="payment_method" name="payment_method"><?php foreach (['Cash', 'Bank Transfer', 'Check', 'Online'] as $method): ?><option value="<?= finance_h($method) ?>" <?= (($form['payment_method'] ?? 'Cash') === $method) ? 'selected' : '' ?>><?= finance_h($method) ?></option><?php endforeach; ?></select></div>
            <div><label for="status">Status</label><select id="status" name="status"><?php foreach (['Released', 'Pending', 'Cancelled'] as $status): ?><option value="<?= finance_h($status) ?>" <?= (($form['status'] ?? 'Released') === $status) ? 'selected' : '' ?>><?= finance_h($status) ?></option><?php endforeach; ?></select></div>
          </div>

          <div class="form-row"><label for="remarks">Remarks</label><textarea id="remarks" name="remarks" rows="3"><?= finance_h((string) ($form['remarks'] ?? '')) ?></textarea></div>
          <div class="form-row"><div class="muted-cell" id="disbursement_hint">Manual disbursements debit Expense and credit Cash. AP-linked disbursements debit Accounts Payable and credit Cash. Budget-linked disbursements are blocked if they exceed remaining budget.</div></div>
          <div class="form-row"><div class="muted-cell" id="budget_limit_note">Select a budget to view remaining allocation and utilization.</div></div>
          <div class="form-actions"><?php if ($editRow): ?><a class="btn subtle" href="disbursement.php">Cancel</a><?php endif; ?><button class="btn primary" type="submit"><?= $editRow ? 'Update Disbursement' : 'Save Disbursement' ?></button></div>
        </form>

        <div class="disb-panel">
          <div class="table-title">Search And Filter</div>
          <form class="filter-bar" method="get">
            <div class="form-row">
              <label for="filter_payee">Payee</label>
              <input id="filter_payee" name="payee" type="text" value="<?= finance_h($filters['payee']) ?>" placeholder="Search payee">
            </div>
            <div class="form-row split">
              <div>
                <label for="filter_date">Date</label>
                <input id="filter_date" name="date" type="date" value="<?= finance_h($filters['date']) ?>">
              </div>
              <div>
                <label for="filter_status">Status</label>
                <select id="filter_status" name="status">
                  <option value="">All statuses</option>
                  <?php foreach (['Released', 'Pending', 'Cancelled'] as $status): ?>
                    <option value="<?= finance_h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= finance_h($status) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-row">
              <label for="filter_source">Request Source</label>
              <select id="filter_source" name="request_source">
                <option value="">All sources</option>
                <?php foreach (['MANUAL' => 'Manual', 'AP' => 'Accounts Payable', 'BUDGET' => 'Budget', 'PAYROLL' => 'Payroll Request', 'CORE' => 'Core Services', 'HR' => 'Human Resources', 'LOGISTICS' => 'Logistics'] as $value => $label): ?>
                  <option value="<?= finance_h($value) ?>" <?= $filters['request_source'] === $value ? 'selected' : '' ?>><?= finance_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-actions"><button class="btn primary" type="submit">Apply Filters</button><a class="btn subtle" href="disbursement.php">Reset</a></div>
          </form>
        </div>
      </div>
    </section>

    <section class="section-card disb-register">
      <div class="table-title">Disbursement Register</div>
      <div class="table-wrap table-scroll-pane">
        <table class="notion-table">
          <thead>
            <tr>
              <th>Reference No</th>
              <th>Payee</th>
              <th>Amount</th>
              <th>Date</th>
              <th>Payment Method</th>
              <th>Status</th>
              <th>Request Source</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?><tr><td colspan="8" class="muted-cell">No disbursement records found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['payee_name'] ?? '-')) ?></td>
                <td>PHP <?= finance_money($row['amount'] ?? 0) ?></td>
                <td><?= finance_h((string) ($row['disbursement_date'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['payment_method'] ?? '-')) ?></td>
                <td><span class="status-badge <?= disbursement_badge_class((string) ($row['status'] ?? '')) ?>"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                <td><?= finance_h(disbursement_source_display_label($row, $apLookup, $budgetLookup)) ?></td>
                <td class="table-actions">
                  <a class="btn-link" href="disbursement.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                  <form class="inline-form" method="post" onsubmit="return confirm('Delete this disbursement record?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_disbursement">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="btn-link danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<script>
  (function () {
    const sourceSelect = document.getElementById('request_source');
    const ledgerModeSelect = document.getElementById('ledger_mode');
    const apWrap = document.getElementById('ap_picker_wrap');
    const budgetWrap = document.getElementById('budget_picker_wrap');
    const payrollWrap = document.getElementById('payroll_picker_wrap');
    const apSelect = document.getElementById('related_ap_id');
    const budgetSelect = document.getElementById('related_budget_id');
    const payrollSelect = document.getElementById('related_payroll_id');
    const hint = document.getElementById('disbursement_hint');
    const budgetOverrideInput = document.getElementById('budget_override');
    const amountInput = document.getElementById('amount');
    const budgetLimitNote = document.getElementById('budget_limit_note');
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

    if (!sourceSelect || !ledgerModeSelect || !apWrap || !budgetWrap || !payrollWrap || !apSelect || !budgetSelect || !payrollSelect || !hint || !budgetOverrideInput || !amountInput || !budgetLimitNote) {
      return;
    }

    const hints = {
      MANUAL: 'Manual disbursements debit Expense and credit Cash.',
      AP: 'AP-linked disbursements reduce the selected payable, mark it Partially Paid or Paid, and debit Accounts Payable.',
      BUDGET: 'Budget-linked disbursements consume budget and are blocked when the amount exceeds remaining budget.',
      PAYROLL: 'Payroll-linked disbursements pay approved payroll requests and debit Expense.'
    };

    function syncDisbursementSource() {
      const source = sourceSelect.value || 'MANUAL';
      apWrap.style.display = source === 'AP' ? '' : 'none';
      budgetWrap.style.display = source === 'BUDGET' ? '' : 'none';
      payrollWrap.style.display = source === 'PAYROLL' ? '' : 'none';

      if (source !== 'AP') {
        apSelect.value = '0';
      }
      if (source !== 'BUDGET') {
        budgetSelect.value = '0';
      }
      if (source !== 'PAYROLL') {
        payrollSelect.value = '0';
      }

      ledgerModeSelect.value = source === 'AP' ? 'ap' : 'expense';
      hint.textContent = hints[source] || hints.MANUAL;
      updateBudgetNote();
    }

    function getSelectedBudgetMeta() {
      const option = budgetSelect.options[budgetSelect.selectedIndex];
      if (!option || option.value === '0') {
        return null;
      }

      return {
        id: Number(option.value || 0),
        remaining: Number(option.dataset.remaining || 0),
        utilization: Number(option.dataset.utilization || 0),
        status: option.dataset.status || ''
      };
    }

    function updateBudgetNote() {
      const source = sourceSelect.value || 'MANUAL';
      const meta = getSelectedBudgetMeta();
      if (source !== 'BUDGET' || !meta) {
        budgetLimitNote.textContent = 'Select a budget to view remaining allocation and utilization.';
        return;
      }

      budgetLimitNote.textContent = 'Remaining budget: PHP ' + meta.remaining.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' | Utilization: ' + meta.utilization.toFixed(1) + '% | Status: ' + (meta.status || 'Active');
    }

    function validateBudgetThreshold() {
      budgetOverrideInput.value = '0';
      const source = sourceSelect.value || 'MANUAL';
      const meta = getSelectedBudgetMeta();
      const amount = Number(amountInput.value || 0);

      if (source !== 'BUDGET' || !meta || amount <= 0) {
        return true;
      }

      if (amount <= meta.remaining + 0.00001) {
        return true;
      }

      if (!isAdmin) {
        window.alert('This will exceed the allocated budget.');
        return false;
      }

      const confirmed = window.confirm('This will exceed the allocated budget. Continue with admin override?');
      if (confirmed) {
        budgetOverrideInput.value = '1';
      }
      return confirmed;
    }

    sourceSelect.addEventListener('change', syncDisbursementSource);
    budgetSelect.addEventListener('change', updateBudgetNote);
    amountInput.addEventListener('input', updateBudgetNote);
    
    payrollSelect.addEventListener('change', function() {
      const selected = payrollSelect.options[payrollSelect.selectedIndex];
      if (selected && selected.value !== '0') {
        const amount = selected.getAttribute('data-amount');
        if (amount) {
          document.getElementById('amount').value = amount;
          document.getElementById('payee_name').value = 'ServiBoard Employees (Payroll ' + selected.text.split(' · ')[0] + ')';
        }
      }
    });

    syncDisbursementSource();
    updateBudgetNote();

    // Enhanced form validation
    const form = document.querySelector('form.disb-panel');
    const inputs = form.querySelectorAll('input[required], select[required]');

    inputs.forEach(input => {
      input.addEventListener('blur', function() {
        validateField(this);
      });

      input.addEventListener('input', function() {
        if (this.classList.contains('invalid')) {
          validateField(this);
        }
      });
    });

    function validateField(field) {
      const value = field.value.trim();
      const isRequired = field.hasAttribute('required');
      
      field.classList.remove('invalid', 'valid');
      
      if (isRequired && value === '') {
        field.classList.add('invalid');
        showFieldError(field, 'This field is required');
      } else if (field.type === 'number' && field.min && parseFloat(value) < parseFloat(field.min)) {
        field.classList.add('invalid');
        showFieldError(field, `Minimum value is ${field.min}`);
      } else if (value !== '') {
        field.classList.add('valid');
        hideFieldError(field);
      }
    }

    form.addEventListener('submit', function (event) {
      if (!validateBudgetThreshold()) {
        event.preventDefault();
      }
    });

    function showFieldError(field, message) {
      hideFieldError(field);
      const errorDiv = document.createElement('div');
      errorDiv.className = 'field-error';
      errorDiv.textContent = message;
      field.parentNode.appendChild(errorDiv);
    }

    function hideFieldError(field) {
      const existing = field.parentNode.querySelector('.field-error');
      if (existing) {
        existing.remove();
      }
    }

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      });
    });

    // Auto-hide messages after 5 seconds
    const messages = document.querySelectorAll('.disb-message, .error-text');
    messages.forEach(message => {
      setTimeout(() => {
        message.style.opacity = '0';
        message.style.transform = 'translateY(-10px)';
        message.style.transition = 'all 0.3s ease';
        setTimeout(() => message.remove(), 300);
      }, 5000);
    });

    document.querySelectorAll('.js-release-request-form').forEach((releaseForm) => {
      releaseForm.addEventListener('submit', function (event) {
        const amount = Number(releaseForm.dataset.approvedAmount || 0);
        const budgetId = Number(releaseForm.dataset.budgetId || 0);
        const remaining = Number(releaseForm.dataset.budgetRemaining || 0);
        if (budgetId <= 0 || amount <= remaining + 0.00001) {
          return;
        }

        if (!isAdmin) {
          event.preventDefault();
          window.alert('This will exceed the allocated budget.');
          return;
        }

        const confirmed = window.confirm('This will exceed the allocated budget. Continue with admin override?');
        if (!confirmed) {
          event.preventDefault();
          return;
        }

        const overrideInput = releaseForm.querySelector('input[name=\"budget_override\"]');
        if (overrideInput) {
          overrideInput.value = '1';
        }
      });
    });
  })();
</script>
</body>
</html>
