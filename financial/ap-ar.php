<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';

function arap_save_entry(PDO $pdo, string $entryType, array $data): int
{
    finance_bootstrap($pdo);
    finance_ensure_ar_ap_link_columns($pdo);
    finance_ensure_ar_ap_tracking_columns($pdo);

    $entryType = strtoupper(trim($entryType));
    if (!in_array($entryType, ['AR', 'AP'], true)) {
        throw new InvalidArgumentException('Invalid AR/AP entry type.');
    }

    $partyField = $entryType === 'AR' ? 'client_name' : 'supplier_name';
    $partyLabel = $entryType === 'AR' ? 'Client name' : 'Vendor / payee';
    $partyName = trim((string) ($data[$partyField] ?? $data['party_name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    $referenceNo = trim((string) ($data['reference_no'] ?? ''));
    $status = trim((string) ($data['status'] ?? 'Pending'));

    if ($partyName === '') {
        throw new InvalidArgumentException($partyLabel . ' is required.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }
    if ($dueDate !== '' && !finance_is_valid_date($dueDate)) {
        throw new InvalidArgumentException('Due date is invalid.');
    }
    if ($status === '') {
        $status = 'Pending';
    }

    $referenceNo = $referenceNo !== ''
        ? $referenceNo
        : ($entryType === 'AR' ? generateARReference($pdo) : generateAPReference($pdo));

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO {$schema}ar_ap (
            entry_type,
            party_name,
            reference_no,
            description,
            amount,
            balance,
            due_date,
            status,
            created_at,
            updated_at
        ) VALUES (
            :entry_type,
            :party_name,
            :reference_no,
            :description,
            :amount,
            :balance,
            :due_date,
            :status,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
    ");
    $stmt->execute([
        ':entry_type' => $entryType,
        ':party_name' => $partyName,
        ':reference_no' => $referenceNo,
        ':description' => $description !== '' ? $description : null,
        ':amount' => $amount,
        ':balance' => $amount,
        ':due_date' => $dueDate !== '' ? $dueDate : null,
        ':status' => $status,
    ]);

    $id = (int) $pdo->lastInsertId();
    if (function_exists('supabase_mirror_safe')) {
        supabase_mirror_safe('ar_ap', [
            'id' => $id,
            'entry_type' => $entryType,
            'party_name' => $partyName,
            'reference_no' => $referenceNo,
            'description' => $description !== '' ? $description : null,
            'amount' => $amount,
            'balance' => $amount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'status' => $status,
        ], 'INSERT', ['id' => $id]);
    }

    return $id;
}

function arap_fetch_entries(PDO $pdo, string $entryType, array $filters = []): array
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return [];
    }

    finance_ensure_ar_ap_tracking_columns($pdo);

    $normalizedFilters = finance_build_ar_ap_filters($filters);
    $schema = finance_schema_prefix($pdo);
    $sql = "SELECT * FROM {$schema}ar_ap WHERE entry_type = :entry_type";
    $params = [
        ':entry_type' => strtoupper($entryType),
    ];

    if ($normalizedFilters['search'] !== '') {
        $sql .= " AND (
            COALESCE(party_name, '') LIKE :search
            OR COALESCE(reference_no, '') LIKE :search
            OR COALESCE(description, '') LIKE :search
        )";
        $params[':search'] = '%' . $normalizedFilters['search'] . '%';
    }

    if ($normalizedFilters['due_date'] !== '' && finance_is_valid_date($normalizedFilters['due_date'])) {
        $sql .= " AND due_date = :due_date";
        $params[':due_date'] = $normalizedFilters['due_date'];
    }

    if ($normalizedFilters['status'] !== '') {
        $sql .= " AND LOWER(COALESCE(status, 'pending')) = :status";
        $params[':status'] = strtolower($normalizedFilters['status']);
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('finance_arap_enrich_row', $stmt->fetchAll() ?: []);
}

$bootstrapMessages = finance_bootstrap($pdo);
$message = '';
$error = '';
$tab = ($_GET['tab'] ?? 'receivable') === 'payable' ? 'payable' : 'receivable';

if (($_GET['saved'] ?? '') === 'receivable') {
    $message = 'Receivable saved successfully.';
    $tab = 'receivable';
} elseif (($_GET['saved'] ?? '') === 'payable') {
    $message = 'Payable saved successfully.';
    $tab = 'payable';
}

$arFilters = [
    'search' => trim((string) ($_GET['ar_q'] ?? '')),
    'status' => trim((string) ($_GET['ar_status'] ?? '')),
    'due_date' => trim((string) ($_GET['ar_due_date'] ?? '')),
];
$apFilters = [
    'search' => trim((string) ($_GET['ap_q'] ?? '')),
    'status' => trim((string) ($_GET['ap_status'] ?? '')),
    'due_date' => trim((string) ($_GET['ap_due_date'] ?? '')),
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            throw new RuntimeException('Invalid request (CSRF failure).');
        }

        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'create_ar') {
            arap_save_entry($pdo, 'AR', $_POST);
            header('Location: ?tab=receivable&saved=receivable');
            exit;
        } elseif ($action === 'create_ap') {
            arap_save_entry($pdo, 'AP', $_POST);
            header('Location: ?tab=payable&saved=payable');
            exit;
        } elseif ($action === 'record_ar_payment') {
            finance_record_ar_payment($pdo, (int) ($_POST['record_id'] ?? 0), $_POST);
            $message = 'Receivable payment recorded and balance updated.';
            $tab = 'receivable';
        } elseif ($action === 'approve_ap') {
            finance_approve_ap_entry($pdo, (int) ($_POST['record_id'] ?? 0));
            $message = 'Payable approved for disbursement.';
            $tab = 'payable';
        } elseif ($action === 'record_ap_disbursement') {
            finance_record_ap_disbursement($pdo, (int) ($_POST['record_id'] ?? 0), $_POST);
            $message = 'Disbursement recorded and payable balance updated.';
            $tab = 'payable';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$summary = finance_get_ar_ap_summary($pdo);
$arRows = arap_fetch_entries($pdo, 'AR', $arFilters);
$apRows = arap_fetch_entries($pdo, 'AP', $apFilters);

function arap_badge_class(string $status): string
{
    return match ($status) {
        'Paid' => 'badge-paid',
        'Partially Paid' => 'badge-partial',
        'Approved' => 'badge-submitted',
        'Overdue' => 'badge-overdue',
        default => 'badge-unpaid',
    };
}

function arap_summary_value(bool $hasData, float|int $value, bool $money = true): string
{
    if (!$hasData && (float) $value <= 0) {
        return 'No data yet';
    }

    if ($money) {
        return 'PHP ' . finance_money($value);
    }

    return number_format((float) $value);
}

function arap_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

$receivableCount = count($arRows);
$payableCount = count($apRows);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Accounts Payable &amp; Receivable - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .arap-summary-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }
    .arap-summary-card {
      display: flex;
      flex-direction: column;
      gap: 8px;
      min-height: 132px;
      justify-content: space-between;
    }
    .arap-summary-copy {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .arap-summary-note {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.5;
      display: block;
    }
    .arap-summary-card small {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.5;
    }
    .arap-summary-card.attention {
      border-color: rgba(244, 179, 33, 0.24);
      box-shadow: 0 14px 28px rgba(0, 0, 0, 0.16);
    }
    .arap-summary-card.warning {
      border-color: rgba(248, 81, 73, 0.3);
      box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18);
    }
    .arap-filter-bar {
      display: grid;
      grid-template-columns: minmax(0, 1.4fr) repeat(2, minmax(0, 0.8fr)) auto;
      gap: 10px;
      margin-bottom: 12px;
      align-items: end;
    }
    .arap-table-wrap {
      overflow: auto;
      max-height: 430px;
    }
    .arap-table .money-cell {
      text-align: right;
      white-space: nowrap;
    }
    .arap-table .actions-cell {
      white-space: nowrap;
      text-align: right;
    }
    .arap-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
    .arap-row-overdue td {
      background: rgba(248, 81, 73, 0.08);
    }
    .arap-row-soon td {
      background: rgba(244, 179, 33, 0.06);
    }
    .arap-table tbody tr {
      transition: background 0.18s ease, transform 0.18s ease;
    }
    .arap-table tbody tr:hover td {
      background: rgba(61, 136, 234, 0.08);
    }
    .arap-empty {
      padding: 26px 18px;
    }
    .arap-empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      text-align: center;
      color: var(--muted);
    }
    .arap-empty-state strong {
      color: var(--text);
      font-size: 15px;
      font-weight: 700;
    }
    .arap-helper {
      font-size: 12px;
      color: var(--muted);
      margin: 4px 0 0;
      line-height: 1.5;
    }
    .arap-form-copy {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 14px;
    }
    .arap-form-copy p,
    .arap-table-copy p {
      margin: 0;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }
    .arap-system-note {
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px dashed rgba(61, 136, 234, 0.35);
      background: rgba(61, 136, 234, 0.08);
      color: var(--text);
      font-size: 12px;
      line-height: 1.5;
    }
    .arap-table-copy {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-end;
      margin-bottom: 12px;
    }
    .arap-table-copy h3,
    .arap-form-copy h3 {
      margin: 0;
    }
    .arap-action-stack {
      display: flex;
      flex-direction: column;
      gap: 4px;
      align-items: flex-end;
    }
    .arap-action-meta {
      color: var(--muted);
      font-size: 11px;
      line-height: 1.4;
    }
    .arap-modal-card {
      max-width: 560px;
    }
    .arap-detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 14px;
      margin-top: 16px;
    }
    .arap-detail-grid div {
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--stat-bg);
    }
    .arap-detail-grid span {
      display: block;
      color: var(--muted);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .arap-detail-grid strong {
      display: block;
      margin-top: 6px;
      color: var(--text);
      word-break: break-word;
    }
    .arap-modal-form {
      margin-top: 16px;
    }
    .arap-modal-form .form-row label span {
      display: block;
      color: var(--muted);
      font-size: 11px;
      font-weight: 400;
      margin-top: 4px;
    }
    .arap-modal-form .form-actions {
      margin-top: 14px;
    }
    @media (max-width: 1100px) {
      .arap-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .arap-filter-bar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 760px) {
      .arap-summary-grid,
      .arap-filter-bar,
      .arap-detail-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body data-active-tab="<?= finance_h($tab) ?>">
<div class="layout">
  <?php include __DIR__ . '/../inc/sidebar.php'; ?>
  <main class="content" role="main">
    <?php include __DIR__ . '/../inc/financial_topbar.php'; ?>

    <div class="page-header"><h1>Accounts Receivable / Accounts Payable</h1><p>Track receivables and payables with due dates, live balances, and updates from Collection and Disbursement.</p></div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>
    <?php if ($message !== ''): ?><section class="section-card"><div class="status-badge badge-paid"><?= finance_h($message) ?></div></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section><?php endif; ?>

    <section class="section-card collection-shell ap-ar-shell">
      <div class="collection-header"><div><h2 class="collection-title">AR/AP Workspace</h2><p class="collection-subtitle">Receivables and payables keep their own balances, statuses, due dates, and transaction links.</p></div></div>

      <div class="arap-summary-grid">
        <div class="stat-block arap-summary-card">
          <div class="arap-summary-copy"><span class="stat-label">Open AR</span><strong class="stat-value"><?= finance_h(arap_summary_value((bool) $summary['has_ar'], (float) $summary['open_ar'])) ?></strong></div>
          <small><?= $summary['open_ar'] > 0 ? 'Outstanding customer balances waiting for collection.' : 'No data yet. New receivables will appear here.' ?></small>
        </div>
        <div class="stat-block arap-summary-card">
          <div class="arap-summary-copy"><span class="stat-label">Open AP</span><strong class="stat-value"><?= finance_h(arap_summary_value((bool) $summary['has_ap'], (float) $summary['open_ap'])) ?></strong></div>
          <small><?= $summary['open_ap'] > 0 ? 'Pending supplier or payee obligations for release.' : 'No data yet. New payables will appear here.' ?></small>
        </div>
        <div class="stat-block arap-summary-card warning">
          <div class="arap-summary-copy"><span class="stat-label">Overdue Receivables</span><strong class="stat-value"><?= finance_h(arap_summary_value((bool) $summary['has_ar'], (int) $summary['overdue_receivables'], false)) ?></strong></div>
          <small><?= (int) $summary['overdue_receivables'] > 0 ? 'These items need collection follow-up.' : 'Nothing overdue right now.' ?></small>
        </div>
        <div class="stat-block arap-summary-card attention">
          <div class="arap-summary-copy"><span class="stat-label">Due This Week</span><strong class="stat-value"><?= finance_h(arap_summary_value((bool) ($summary['has_ar'] || $summary['has_ap']), (int) $summary['due_this_week'], false)) ?></strong></div>
          <small><?= (int) $summary['due_this_week'] > 0 ? 'Upcoming due dates from receivables and payables.' : 'No near-term dues scheduled.' ?></small>
        </div>
      </div>

      <div class="notion-tabs">
        <button class="tab-btn <?= $tab === 'receivable' ? 'active' : '' ?>" data-tab="receivable" type="button">Accounts Receivable</button>
        <button class="tab-btn <?= $tab === 'payable' ? 'active' : '' ?>" data-tab="payable" type="button">Accounts Payable</button>
      </div>

      <div class="tab-panels">
        <div class="tab-panel <?= $tab === 'receivable' ? 'active' : '' ?>" data-tab-content="receivable">
          <div class="panel-grid">
            <form class="form-card" method="post" action="?tab=receivable">
              <?= csrf_field() ?>
              <div class="arap-form-copy">
                <h3 class="form-title">Create Receivable</h3>
                <p>Register a client receivable with a due date. Balance is computed automatically from posted collections.</p>
              </div>
              <input type="hidden" name="action" value="create_ar">
              <div class="form-row"><label for="client_name">Client Name</label><input id="client_name" name="client_name" type="text" placeholder="e.g. ACME Trading Corporation" required></div>
              <div class="form-row"><label for="description_ar">Description</label><textarea id="description_ar" name="description" rows="3" placeholder="e.g. Quarterly service billing for March 2026"></textarea></div>
              <div class="form-row split">
                <div><label for="amount_ar">Amount</label><input id="amount_ar" name="amount" type="number" min="0.01" step="0.01" placeholder="15000.00" required></div>
                <div><label for="due_date_ar">Due Date</label><input id="due_date_ar" name="due_date" type="date"></div>
              </div>
              <div class="arap-system-note">Balance is system-controlled. A receivable starts at the full amount, then Collection payments reduce it to `Partially Paid` or `Paid` automatically.</div>
              <p class="arap-helper">Use this for clients, service billings, or receivables linked from approved requests.</p>
              <div class="form-actions"><button class="btn primary" type="submit">Save Receivable</button></div>
            </form>

            <div class="table-card">
              <div class="arap-table-copy">
                <div><h3 class="table-title">Accounts Receivable</h3><p>Track open client balances, due dates, and collection activity.</p></div>
                <div class="arap-summary-note"><?= $receivableCount > 0 ? finance_h(number_format($receivableCount) . ' receivable record' . ($receivableCount === 1 ? '' : 's')) : 'No receivables in the current view' ?></div>
              </div>
              <form class="arap-filter-bar" method="get">
                <input type="hidden" name="tab" value="receivable">
                <div class="form-row"><label for="ar_q">Search</label><input id="ar_q" name="ar_q" type="text" value="<?= finance_h($arFilters['search']) ?>" placeholder="Client, reference, description"></div>
                <div class="form-row"><label for="ar_status">Status</label><select id="ar_status" name="ar_status"><option value="">All statuses</option><?php foreach (['Pending', 'Partially Paid', 'Paid', 'Overdue'] as $status): ?><option value="<?= finance_h($status) ?>" <?= $arFilters['status'] === $status ? 'selected' : '' ?>><?= finance_h($status) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label for="ar_due_date">Due Date</label><input id="ar_due_date" name="ar_due_date" type="date" value="<?= finance_h($arFilters['due_date']) ?>"></div>
                <div class="form-actions"><button class="btn primary" type="submit">Filter</button><a class="btn subtle" href="?<?= arap_query(['tab' => 'receivable', 'ar_q' => '', 'ar_status' => '', 'ar_due_date' => '']) ?>">Reset</a></div>
              </form>

              <div class="arap-table-wrap table-scroll-pane"><table class="notion-table arap-table"><thead><tr><th>Reference No</th><th>Client</th><th>Description</th><th class="money-cell">Amount</th><th class="money-cell">Balance</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php if (!$arRows): ?><tr><td colspan="8" class="arap-empty"><div class="arap-empty-state"><strong>No receivables yet.</strong><span>Create your first receivable on the left to start tracking client balances.</span></div></td></tr><?php endif; ?>
                <?php foreach ($arRows as $row): ?>
                  <tr class="<?= !empty($row['is_overdue']) ? 'arap-row-overdue' : (!empty($row['is_due_soon']) ? 'arap-row-soon' : '') ?>">
                    <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['party_name'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['description'] ?? '-')) ?></td>
                    <td class="money-cell">PHP <?= finance_money($row['amount'] ?? 0) ?></td>
                    <td class="money-cell">PHP <?= finance_money($row['balance'] ?? 0) ?></td>
                    <td><?= finance_h((string) ($row['due_date'] ?? '-')) ?></td>
                    <td><span class="status-badge <?= arap_badge_class((string) ($row['status'] ?? 'Pending')) ?>"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                    <td class="actions-cell">
                      <div class="arap-action-stack">
                        <div class="arap-actions">
                        <button class="btn subtle js-arap-detail" type="button"
                          data-record='<?= finance_h(json_encode($row, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?: "{}") ?>'
                          data-party-label="Client">View Details</button>
                        <?php if (empty($row['is_paid'])): ?>
                          <button class="btn primary js-arap-action" type="button"
                            data-action="record_ar_payment"
                            data-record-id="<?= (int) ($row['id'] ?? 0) ?>"
                            data-title="Record Payment"
                            data-submit-text="Save Payment"
                            data-amount-label="Payment Amount"
                            data-date-label="Payment Date"
                            data-method-label="Collection Method"
                            data-remarks-placeholder="Add OR number, collection note, or follow-up remark"
                            data-amount="<?= finance_h((string) ($row['balance'] ?? 0)) ?>"
                            data-date="<?= finance_h(date('Y-m-d')) ?>"
                            data-method="Cash"
                            data-tab="receivable">Record Payment</button>
                        <?php endif; ?>
                        </div>
                        <div class="arap-action-meta"><?= !empty($row['is_overdue']) ? 'Past due and still unpaid' : (!empty($row['is_due_soon']) ? 'Due within 7 days' : 'Tracking normally') ?></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
            </div>
          </div>
        </div>

        <div class="tab-panel <?= $tab === 'payable' ? 'active' : '' ?>" data-tab-content="payable">
          <div class="panel-grid">
            <form class="form-card" method="post" action="?tab=payable">
              <?= csrf_field() ?>
              <div class="arap-form-copy">
                <h3 class="form-title">Create Payable</h3>
                <p>Register an obligation for a vendor or payee. Balance is reduced automatically when disbursements are posted.</p>
              </div>
              <input type="hidden" name="action" value="create_ap">
              <div class="form-row"><label for="supplier_name">Vendor / Payee</label><input id="supplier_name" name="supplier_name" type="text" placeholder="e.g. Bright Source Office Supplies" required></div>
              <div class="form-row"><label for="description_ap">Description</label><textarea id="description_ap" name="description" rows="3" placeholder="e.g. Office supply invoice for March operations"></textarea></div>
              <div class="form-row split">
                <div><label for="amount_ap">Amount</label><input id="amount_ap" name="amount" type="number" min="0.01" step="0.01" placeholder="8500.00" required></div>
                <div><label for="due_date_ap">Due Date</label><input id="due_date_ap" name="due_date" type="date"></div>
              </div>
              <div class="arap-system-note">Balance is system-controlled. Disbursement updates the payable to `Partially Paid`, `Paid`, or `Overdue` based on remaining balance and due date.</div>
              <p class="arap-helper">Use this for vendor invoices, reimbursements, and payables linked from approved requests.</p>
              <div class="form-actions"><button class="btn primary" type="submit">Save Payable</button></div>
            </form>

            <div class="table-card">
              <div class="arap-table-copy">
                <div><h3 class="table-title">Accounts Payable</h3><p>Monitor obligations, due dates, and release progress for vendors and payees.</p></div>
                <div class="arap-summary-note"><?= $payableCount > 0 ? finance_h(number_format($payableCount) . ' payable record' . ($payableCount === 1 ? '' : 's')) : 'No payables in the current view' ?></div>
              </div>
              <form class="arap-filter-bar" method="get">
                <input type="hidden" name="tab" value="payable">
                <div class="form-row"><label for="ap_q">Search</label><input id="ap_q" name="ap_q" type="text" value="<?= finance_h($apFilters['search']) ?>" placeholder="Vendor, reference, description"></div>
                <div class="form-row"><label for="ap_status">Status</label><select id="ap_status" name="ap_status"><option value="">All statuses</option><?php foreach (['Pending', 'Approved', 'Partially Paid', 'Paid', 'Overdue'] as $status): ?><option value="<?= finance_h($status) ?>" <?= $apFilters['status'] === $status ? 'selected' : '' ?>><?= finance_h($status) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label for="ap_due_date">Due Date</label><input id="ap_due_date" name="ap_due_date" type="date" value="<?= finance_h($apFilters['due_date']) ?>"></div>
                <div class="form-actions"><button class="btn primary" type="submit">Filter</button><a class="btn subtle" href="?<?= arap_query(['tab' => 'payable', 'ap_q' => '', 'ap_status' => '', 'ap_due_date' => '']) ?>">Reset</a></div>
              </form>

              <div class="arap-table-wrap table-scroll-pane"><table class="notion-table arap-table"><thead><tr><th>Reference No</th><th>Vendor / Payee</th><th>Description</th><th class="money-cell">Amount</th><th class="money-cell">Balance</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php if (!$apRows): ?><tr><td colspan="8" class="arap-empty"><div class="arap-empty-state"><strong>No payables yet.</strong><span>Create your first payable on the left to start tracking obligations and releases.</span></div></td></tr><?php endif; ?>
                <?php foreach ($apRows as $row): ?>
                  <?php $apActionLabel = abs((float) ($row['balance'] ?? 0) - (float) ($row['amount'] ?? 0)) <= 0.00001 ? 'Mark Paid' : 'Record Disbursement'; ?>
                  <tr class="<?= !empty($row['is_overdue']) ? 'arap-row-overdue' : (!empty($row['is_due_soon']) && empty($row['is_paid']) ? 'arap-row-soon' : '') ?>">
                    <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['party_name'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['description'] ?? '-')) ?></td>
                    <td class="money-cell">PHP <?= finance_money($row['amount'] ?? 0) ?></td>
                    <td class="money-cell">PHP <?= finance_money($row['balance'] ?? 0) ?></td>
                    <td><?= finance_h((string) ($row['due_date'] ?? '-')) ?></td>
                    <td><span class="status-badge <?= arap_badge_class((string) ($row['status'] ?? 'Pending')) ?>"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                    <td class="actions-cell">
                      <div class="arap-action-stack">
                        <div class="arap-actions">
                        <button class="btn subtle js-arap-detail" type="button"
                          data-record='<?= finance_h(json_encode($row, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?: "{}") ?>'
                          data-party-label="Vendor / Payee">View Details</button>
                        <?php if (($row['status'] ?? '') === 'Pending'): ?>
                          <form method="post" action="?tab=payable" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve_ap">
                            <input type="hidden" name="record_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                            <button class="btn subtle" type="submit">Approve for Release</button>
                          </form>
                        <?php endif; ?>
                        <?php if (empty($row['is_paid']) && in_array((string) ($row['status'] ?? ''), ['Approved', 'Pending', 'Partially Paid', 'Overdue'], true)): ?>
                          <button class="btn primary js-arap-action" type="button"
                            data-action="record_ap_disbursement"
                            data-record-id="<?= (int) ($row['id'] ?? 0) ?>"
                            data-title="<?= finance_h($apActionLabel) ?>"
                            data-submit-text="<?= finance_h($apActionLabel) ?>"
                            data-amount-label="Disbursement Amount"
                            data-date-label="Release Date"
                            data-method-label="Disbursement Method"
                            data-remarks-placeholder="Add voucher note, check number, or release remark"
                            data-amount="<?= finance_h((string) ($row['balance'] ?? 0)) ?>"
                            data-date="<?= finance_h(date('Y-m-d')) ?>"
                            data-method="Bank Transfer"
                            data-tab="payable"><?= finance_h($apActionLabel) ?></button>
                        <?php endif; ?>
                        </div>
                        <div class="arap-action-meta"><?= ($row['status'] ?? '') === 'Approved' ? 'Ready for disbursement' : (!empty($row['is_overdue']) ? 'Past due and still unpaid' : 'Outstanding payable balance') ?></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
</div>

<div class="sb-modal-backdrop" id="arapDetailModal" aria-hidden="true">
  <div class="sb-modal-card arap-modal-card">
    <div class="sb-modal-head">
      <h3>Entry Details</h3>
      <p>Reference, balance, due date, and linked transaction status.</p>
    </div>
    <div class="arap-detail-grid" id="arapDetailGrid"></div>
    <div class="sb-modal-actions">
      <button class="btn subtle" type="button" data-close-modal="#arapDetailModal">Close</button>
    </div>
  </div>
</div>

<div class="sb-modal-backdrop" id="arapActionModal" aria-hidden="true">
  <div class="sb-modal-card arap-modal-card">
    <div class="sb-modal-head">
      <h3 id="arapActionTitle">Record Transaction</h3>
      <p>Submit the payment or disbursement to update balance and status.</p>
    </div>
    <form method="post" class="arap-modal-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="arapActionInput" value="">
      <input type="hidden" name="record_id" id="arapRecordIdInput" value="">
      <div class="form-row"><label for="arapAmountInput" id="arapAmountLabel">Amount<span>Enter the amount to apply against the remaining balance.</span></label><input id="arapAmountInput" name="amount" type="number" min="0.01" step="0.01" required></div>
      <div class="form-row split">
        <div><label for="arapDateInput" id="arapDateLabel">Date</label><input id="arapDateInput" name="payment_date" type="date"></div>
        <div><label for="arapMethodInput" id="arapMethodLabel">Payment Method</label><select id="arapMethodInput" name="payment_method"><option>Cash</option><option>Bank Transfer</option><option>Check</option><option>Online</option></select></div>
      </div>
      <div class="form-row"><label for="arapRemarksInput">Remarks</label><textarea id="arapRemarksInput" name="remarks" rows="3" placeholder="Add supporting remarks"></textarea></div>
      <div class="sb-modal-actions">
        <button class="btn subtle" type="button" data-close-modal="#arapActionModal">Cancel</button>
        <button class="btn primary" type="submit" id="arapActionSubmit">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('[data-tab]').forEach((button) => {
  button.addEventListener('click', () => {
    const tab = button.getAttribute('data-tab');
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.location.href = url.toString();
  });
});

function openModal(selector) {
  const modal = document.querySelector(selector);
  if (!modal) return;
  modal.classList.add('open');
  document.body.classList.add('sb-modal-open');
}

function closeModal(selector) {
  const modal = document.querySelector(selector);
  if (!modal) return;
  modal.classList.remove('open');
  document.body.classList.remove('sb-modal-open');
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

document.querySelectorAll('[data-close-modal]').forEach((button) => {
  button.addEventListener('click', () => closeModal(button.getAttribute('data-close-modal')));
});

document.querySelectorAll('.sb-modal-backdrop').forEach((backdrop) => {
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      backdrop.classList.remove('open');
      document.body.classList.remove('sb-modal-open');
    }
  });
});

document.querySelectorAll('.js-arap-detail').forEach((button) => {
  button.addEventListener('click', () => {
    const payload = JSON.parse(button.dataset.record || '{}');
    const partyLabel = button.dataset.partyLabel || 'Party';
    const items = [
      ['Reference No', payload.reference_no || '-'],
      [partyLabel, payload.party_name || '-'],
      ['Entry Type', payload.entry_type || '-'],
      ['Description', payload.description || '-'],
      ['Amount', 'PHP ' + (Number(payload.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))],
      ['Balance', 'PHP ' + (Number(payload.balance || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))],
      ['Due Date', payload.due_date || '-'],
      ['Status', payload.status || '-'],
      ['Paid Date', payload.paid_at || '-'],
      ['Source Module', payload.source_module || '-'],
      ['Source Request ID', payload.source_request_id || '-'],
      ['Collection Link', payload.related_collection_id || '-'],
      ['Disbursement Link', payload.related_disbursement_id || '-'],
      ['Created', payload.created_at || '-']
    ];
    const container = document.getElementById('arapDetailGrid');
    container.innerHTML = items.map(([label, value]) => '<div><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>').join('');
    openModal('#arapDetailModal');
  });
});

document.querySelectorAll('.js-arap-action').forEach((button) => {
  button.addEventListener('click', () => {
    const action = button.dataset.action || '';
    document.getElementById('arapActionTitle').textContent = button.dataset.title || 'Record Transaction';
    document.getElementById('arapActionSubmit').textContent = button.dataset.submitText || 'Save';
    document.getElementById('arapAmountLabel').childNodes[0].nodeValue = (button.dataset.amountLabel || 'Amount');
    document.getElementById('arapDateLabel').textContent = button.dataset.dateLabel || 'Date';
    document.getElementById('arapMethodLabel').textContent = button.dataset.methodLabel || 'Payment Method';
    document.getElementById('arapActionInput').value = action;
    document.getElementById('arapRecordIdInput').value = button.dataset.recordId || '';
    document.getElementById('arapAmountInput').value = button.dataset.amount || '';
    document.getElementById('arapDateInput').name = action === 'record_ap_disbursement' ? 'disbursement_date' : 'payment_date';
    document.getElementById('arapDateInput').value = button.dataset.date || '';
    document.getElementById('arapMethodInput').value = button.dataset.method || 'Cash';
    document.getElementById('arapRemarksInput').value = '';
    document.getElementById('arapRemarksInput').placeholder = button.dataset.remarksPlaceholder || 'Add supporting remarks';
    openModal('#arapActionModal');
  });
});
</script>
</body>
</html>
