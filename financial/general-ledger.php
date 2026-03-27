<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$bootstrapMessages = finance_bootstrap($pdo);
finance_ensure_logistics_document_tracking_support_tables($pdo);
$message = '';
$error = '';
$tab = (($_GET['tab'] ?? 'entries') === 'documents') ? 'documents' : 'entries';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            throw new RuntimeException('Invalid request (CSRF failure).');
        }

        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_journal') {
            $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
            if ($referenceNo === '') {
                $referenceNo = generateJournalReference($pdo);
            }

            createJournalEntry($pdo, [
                'transaction_date' => $_POST['transaction_date'] ?? '',
                'reference_no' => $referenceNo,
                'description' => $_POST['description'] ?? '',
                'source_module' => 'manual',
                'source_id' => 0,
            ], [
                [
                    'account_title' => $_POST['debit_account_title'] ?? '',
                    'debit' => $_POST['amount'] ?? 0,
                    'credit' => 0,
                ],
                [
                    'account_title' => $_POST['credit_account_title'] ?? '',
                    'debit' => 0,
                    'credit' => $_POST['amount'] ?? 0,
                ],
            ]);
            $message = 'Manual journal entry posted.';
            $tab = 'entries';
        } elseif (in_array($action, ['verify_document', 'archive_document', 'flag_document', 'link_document_ledger'], true)) {
            $documentId = (int) ($_POST['document_id'] ?? 0);
            $remarks = trim((string) ($_POST['remarks'] ?? ''));
            $ledgerEntryId = (int) ($_POST['ledger_entry_id'] ?? 0);
            $ledgerReferenceNo = trim((string) ($_POST['ledger_reference_no'] ?? ''));

            if ($action === 'verify_document') {
                finance_update_logistics_document_tracking_record($pdo, $documentId, 'verify', ['remarks' => $remarks]);
                $message = 'Document marked as verified.';
            } elseif ($action === 'archive_document') {
                finance_update_logistics_document_tracking_record($pdo, $documentId, 'archive', ['remarks' => $remarks]);
                $message = 'Document archived for ledger support.';
            } elseif ($action === 'flag_document') {
                finance_update_logistics_document_tracking_record($pdo, $documentId, 'flag', ['remarks' => $remarks]);
                $message = 'Document flagged for review.';
            } else {
                finance_update_logistics_document_tracking_record($pdo, $documentId, 'link', [
                    'remarks' => $remarks,
                    'ledger_entry_id' => $ledgerEntryId,
                    'ledger_reference_no' => $ledgerReferenceNo,
                ]);
                $message = 'Document linked to General Ledger.';
            }
            $tab = 'documents';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $tab = 'documents';
}

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'source_module' => trim((string) ($_GET['source_module'] ?? '')),
];
$referenceFilter = trim((string) ($_GET['reference_no'] ?? ''));
$accountFilter = trim((string) ($_GET['account_title'] ?? ''));
$documentFilters = [
    'search' => trim((string) ($_GET['doc_q'] ?? '')),
    'status' => trim((string) ($_GET['doc_status'] ?? '')),
    'source_module' => trim((string) ($_GET['doc_source_module'] ?? '')),
    'link_status' => trim((string) ($_GET['doc_link_status'] ?? '')),
];

$rows = getLedgerEntries($pdo, $filters);
if ($referenceFilter !== '') {
    $rows = array_values(array_filter($rows, static fn (array $row): bool => stripos((string) ($row['reference_no'] ?? ''), $referenceFilter) !== false));
}
if ($accountFilter !== '') {
    $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['account_title'] ?? '') === $accountFilter));
}

$totalDebit = 0.0;
$totalCredit = 0.0;
foreach ($rows as $row) {
    $totalDebit += (float) ($row['debit'] ?? 0);
    $totalCredit += (float) ($row['credit'] ?? 0);
}

$trialBalance = getTrialBalance($pdo);
$accounts = listAccounts($pdo);
$ledgerEntriesAll = getLedgerEntries($pdo, []);
$documentRows = finance_get_logistics_document_tracking_records($pdo, $documentFilters);
$allDocumentRows = finance_get_logistics_document_tracking_records($pdo, []);
$documentSourceModules = finance_get_logistics_document_tracking_source_modules($pdo);
$documentStatuses = ['Pending', 'Verified', 'Archived', 'Flagged', 'Missing File', 'Linked to Ledger'];
$documentSummary = [
    'total_documents' => count($allDocumentRows),
    'verified' => 0,
    'archived' => 0,
    'flagged' => 0,
    'linked' => 0,
];
foreach ($allDocumentRows as $row) {
    $status = (string) ($row['status'] ?? 'Pending');
    if ($status === 'Verified') {
        $documentSummary['verified']++;
    } elseif ($status === 'Archived') {
        $documentSummary['archived']++;
    } elseif ($status === 'Flagged') {
        $documentSummary['flagged']++;
    }
    if (!empty($row['ledger_entry_id']) || trim((string) ($row['ledger_reference_no'] ?? '')) !== '') {
        $documentSummary['linked']++;
    }
}

function gl_document_status_class(string $status): string
{
    return match ($status) {
        'Verified' => 'verified',
        'Archived' => 'archived',
        'Flagged' => 'flagged',
        'Missing File' => 'missing',
        'Linked to Ledger' => 'linked',
        default => 'pending',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>General Ledger - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .gl-shell { gap: 18px; }
    .gl-tabs { display: flex; gap: 10px; margin: 18px 0 14px; flex-wrap: wrap; }
    .gl-tab-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 8px 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); color: var(--text); text-decoration: none; font-size: 12px; font-weight: 600; }
    .gl-tab-btn.active { background: rgba(61,136,234,0.16); border-color: rgba(61,136,234,0.35); color: #dce8ff; }
    .gl-panel { display: grid; gap: 16px; }
    .gl-doc-summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
    .gl-doc-copy { color: var(--muted); font-size: 12px; line-height: 1.5; margin-top: 4px; display: block; }
    .gl-doc-title { font-weight: 600; color: var(--text); }
    .gl-doc-meta { display: block; color: var(--muted); font-size: 11px; margin-top: 4px; }
    .gl-doc-status { display: inline-flex; align-items: center; min-height: 24px; padding: 4px 10px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.08); font-size: 11px; white-space: nowrap; }
    .gl-doc-status.pending { background: rgba(255,255,255,0.04); color: var(--text); }
    .gl-doc-status.verified { background: rgba(59,130,246,0.12); border-color: rgba(59,130,246,0.28); color: #dce8ff; }
    .gl-doc-status.archived { background: rgba(55,211,154,0.12); border-color: rgba(55,211,154,0.28); color: #9ff1c3; }
    .gl-doc-status.flagged { background: rgba(248,81,73,0.14); border-color: rgba(248,81,73,0.28); color: #ffb4b4; }
    .gl-doc-status.missing { background: rgba(240,175,28,0.12); border-color: rgba(240,175,28,0.28); color: #f6cb73; }
    .gl-doc-status.linked { background: rgba(167,139,250,0.14); border-color: rgba(167,139,250,0.28); color: #ddd2ff; }
    .gl-doc-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
    .gl-doc-actions .btn, .gl-doc-actions .btn-link { min-height: 32px; padding: 6px 10px; font-size: 11px; }
    .gl-doc-history { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
    .gl-doc-history li { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); color: var(--text); font-size: 12px; line-height: 1.45; }
    .gl-doc-modal[hidden] { display: none; }
    .gl-doc-modal { position: fixed; inset: 0; background: rgba(3,7,18,0.72); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 1000; }
    .gl-doc-card { width: min(860px, 100%); max-height: calc(100vh - 40px); overflow: auto; border-radius: 18px; border: 1px solid rgba(255,255,255,0.08); background: #111827; box-shadow: 0 24px 80px rgba(0,0,0,0.45); padding: 22px; }
    .gl-doc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 16px; }
    .gl-doc-grid > div, .gl-doc-note { padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); }
    .gl-doc-grid strong, .gl-doc-note strong { display: block; margin-bottom: 6px; }
    .gl-link-chip { display: inline-flex; align-items: center; min-height: 22px; padding: 3px 8px; border-radius: 999px; border: 1px solid rgba(61,136,234,0.28); background: rgba(61,136,234,0.12); color: #dce8ff; font-size: 11px; }
    .gl-inline-note { color: var(--muted); font-size: 11px; line-height: 1.4; }
    @media (max-width: 1100px) { .gl-doc-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 900px) { .gl-doc-summary, .gl-doc-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../inc/sidebar.php'; ?>
  <main class="content" role="main">
    <?php include __DIR__ . '/../inc/financial_topbar.php'; ?>

    <div class="page-header"><h1>General Ledger</h1><p>The General Ledger is the central accounting record for Collection, Disbursement, manual adjustments, and supporting financial archive references.</p></div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>
    <?php if ($message !== ''): ?><section class="section-card"><div class="status-badge badge-paid"><?= finance_h($message) ?></div></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section><?php endif; ?>

    <section class="section-card collection-shell gl-shell">
      <div class="collection-header">
        <div>
          <h2 class="collection-title">Ledger Summary</h2>
          <p class="collection-subtitle">Logistics1 remains the operational request flow under Collection and Disbursement. Logistics2 lives here as document tracking, archive support, and audit evidence aligned with General Ledger.</p>
        </div>
      </div>
      <div class="collection-stats">
        <div class="stat-block"><span class="stat-label">Total Debit</span><strong class="stat-value"><?= finance_money($totalDebit) ?></strong></div>
        <div class="stat-block"><span class="stat-label">Total Credit</span><strong class="stat-value"><?= finance_money($totalCredit) ?></strong></div>
        <div class="stat-block"><span class="stat-label">Ledger Lines</span><strong class="stat-value"><?= number_format(count($rows)) ?></strong></div>
      </div>

      <div class="gl-tabs">
        <a class="gl-tab-btn <?= $tab === 'entries' ? 'active' : '' ?>" href="?tab=entries">Ledger Entries</a>
        <a class="gl-tab-btn <?= $tab === 'documents' ? 'active' : '' ?>" href="?tab=documents">Supporting Documents</a>
      </div>
      <?php if ($tab === 'entries'): ?>
        <div class="gl-panel">
          <div class="panel-grid">
            <form class="form-card" method="post">
              <h3 class="form-title">Manual Journal Entry</h3>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_journal">
              <div class="form-row split">
                <div><label for="transaction_date">Date</label><input id="transaction_date" name="transaction_date" type="date" required value="<?= finance_h(date('Y-m-d')) ?>"></div>
                <div><label for="reference_no">Reference No</label><input id="reference_no" name="reference_no" type="text" placeholder="Auto-generate when blank"></div>
              </div>
              <div class="form-row"><label for="description">Description</label><textarea id="description" name="description" rows="3" required></textarea></div>
              <div class="form-row"><label for="amount">Amount</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" required></div>
              <div class="form-row split">
                <div><label for="debit_account_title">Debit Account</label><select id="debit_account_title" name="debit_account_title"><?php foreach ($accounts as $account): ?><option value="<?= finance_h($account['account_title']) ?>"><?= finance_h(trim((string) ($account['account_code'] ?? '')) . ' - ' . $account['account_title']) ?></option><?php endforeach; ?></select></div>
                <div><label for="credit_account_title">Credit Account</label><select id="credit_account_title" name="credit_account_title"><?php foreach ($accounts as $account): ?><option value="<?= finance_h($account['account_title']) ?>"><?= finance_h(trim((string) ($account['account_code'] ?? '')) . ' - ' . $account['account_title']) ?></option><?php endforeach; ?></select></div>
              </div>
              <div class="form-actions"><button class="btn primary" type="submit">Post Manual Journal</button></div>
            </form>

            <div class="table-card">
              <div class="table-title">Ledger Entries</div>
              <form class="panel-tools wrap-tools" method="get" action="">
                <input type="hidden" name="tab" value="entries">
                <input class="input subtle" type="date" name="date_from" value="<?= finance_h($filters['date_from']) ?>">
                <input class="input subtle" type="date" name="date_to" value="<?= finance_h($filters['date_to']) ?>">
                <select class="input subtle" name="account_title">
                  <option value="">All Accounts</option>
                  <?php foreach ($accounts as $account): ?><option value="<?= finance_h($account['account_title']) ?>" <?= ($accountFilter === $account['account_title']) ? 'selected' : '' ?>><?= finance_h($account['account_title']) ?></option><?php endforeach; ?>
                </select>
                <select class="input subtle" name="source_module">
                  <option value="">All Sources</option>
                  <?php foreach (['collection', 'disbursement', 'manual', 'ar_ap'] as $sourceModule): ?><option value="<?= finance_h($sourceModule) ?>" <?= ($filters['source_module'] === $sourceModule) ? 'selected' : '' ?>><?= finance_h($sourceModule) ?></option><?php endforeach; ?>
                </select>
                <input class="input subtle" type="text" name="reference_no" value="<?= finance_h($referenceFilter) ?>" placeholder="Reference No">
                <button class="btn subtle" type="submit">Apply</button>
              </form>
              <div class="table-wrap table-scroll-pane"><table class="notion-table"><thead><tr><th>Date</th><th>Reference No</th><th>Account Title</th><th>Entry Type</th><th>Description</th><th>Debit</th><th>Credit</th><th>Source Module</th></tr></thead><tbody>
                <?php if (!$rows): ?><tr><td colspan="8" class="muted-cell">No ledger entries found.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><?= finance_h((string) ($row['transaction_date'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['reference_no'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['account_title'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['entry_type'] ?? '-')) ?></td>
                    <td><?= finance_h((string) ($row['description'] ?? '-')) ?></td>
                    <td><?= finance_money($row['debit'] ?? 0) ?></td>
                    <td><?= finance_money($row['credit'] ?? 0) ?></td>
                    <td><?= finance_h((string) ($row['source_module'] ?? '-')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
            </div>
          </div>

          <div class="table-card">
            <div class="table-title">Trial Balance</div>
            <div class="table-wrap table-scroll-pane"><table class="notion-table"><thead><tr><th>Account Title</th><th>Total Debit</th><th>Total Credit</th><th>Ending Balance</th></tr></thead><tbody>
              <?php if (!$trialBalance): ?><tr><td colspan="4" class="muted-cell">No trial balance data found.</td></tr><?php endif; ?>
              <?php foreach ($trialBalance as $row): ?>
                <tr>
                  <td><?= finance_h((string) ($row['account_title'] ?? '-')) ?></td>
                  <td><?= finance_money($row['total_debit'] ?? 0) ?></td>
                  <td><?= finance_money($row['total_credit'] ?? 0) ?></td>
                  <td><?= finance_money($row['ending_balance'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
        </div>
      <?php else: ?>
        <div class="gl-panel">
          <div class="table-card">
            <div class="collection-header" style="margin-bottom: 14px;">
              <div>
                <h2 class="collection-title">Logistics2 Document Tracking</h2>
                <p class="collection-subtitle">Logistics2 is treated here as supporting financial document tracking, archive support, and audit evidence. It does not use the same approval workflow as Logistics1. Instead, finance users verify, archive, flag, and link documents to ledger references.</p>
              </div>
            </div>

            <div class="gl-doc-summary">
              <div class="stat-block"><span class="stat-label">Total Tracked Documents</span><strong class="stat-value"><?= number_format((int) $documentSummary['total_documents']) ?></strong><span class="gl-doc-copy">All Logistics2 documents available to support finance records.</span></div>
              <div class="stat-block"><span class="stat-label">Verified Documents</span><strong class="stat-value"><?= number_format((int) $documentSummary['verified']) ?></strong><span class="gl-doc-copy">Documents checked by finance for completeness and relevance.</span></div>
              <div class="stat-block"><span class="stat-label">Archived Documents</span><strong class="stat-value"><?= number_format((int) $documentSummary['archived']) ?></strong><span class="gl-doc-copy">Archive-ready records for reporting and audit reference.</span></div>
              <div class="stat-block"><span class="stat-label">Flagged / Needs Review</span><strong class="stat-value"><?= number_format((int) $documentSummary['flagged']) ?></strong><span class="gl-doc-copy">Documents needing attention, clarification, or file correction.</span></div>
              <div class="stat-block"><span class="stat-label">Linked to Ledger</span><strong class="stat-value"><?= number_format((int) $documentSummary['linked']) ?></strong><span class="gl-doc-copy">Documents already tied to a ledger entry or ledger reference.</span></div>
            </div>

            <form class="panel-tools wrap-tools" method="get" action="">
              <input type="hidden" name="tab" value="documents">
              <input class="input subtle" type="text" name="doc_q" value="<?= finance_h($documentFilters['search']) ?>" placeholder="Search tracking no, title, type">
              <select class="input subtle" name="doc_status">
                <option value="">All Statuses</option>
                <?php foreach ($documentStatuses as $statusOption): ?>
                  <option value="<?= finance_h($statusOption) ?>" <?= strcasecmp($documentFilters['status'], $statusOption) === 0 ? 'selected' : '' ?>><?= finance_h($statusOption) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="input subtle" name="doc_source_module">
                <option value="">All Source Modules</option>
                <?php foreach ($documentSourceModules as $moduleOption): ?>
                  <option value="<?= finance_h($moduleOption) ?>" <?= strcasecmp($documentFilters['source_module'], $moduleOption) === 0 ? 'selected' : '' ?>><?= finance_h($moduleOption) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="input subtle" name="doc_link_status">
                <option value="">Linked + Unlinked</option>
                <option value="linked" <?= $documentFilters['link_status'] === 'linked' ? 'selected' : '' ?>>Linked Only</option>
                <option value="unlinked" <?= $documentFilters['link_status'] === 'unlinked' ? 'selected' : '' ?>>Unlinked Only</option>
              </select>
              <button class="btn subtle" type="submit">Apply</button>
              <a class="btn subtle" href="?tab=documents">Reset</a>
            </form>
            <div class="table-wrap table-scroll-pane"><table class="notion-table">
              <thead>
                <tr>
                  <th>Tracking Number</th>
                  <th>Document Title</th>
                  <th>Document Type</th>
                  <th>Status</th>
                  <th>Source Module</th>
                  <th>Linked Ledger Entry</th>
                  <th>File / Open File</th>
                  <th>Created At</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$documentRows): ?><tr><td colspan="9" class="muted-cell">No Logistics2 supporting documents matched the current filter.</td></tr><?php endif; ?>
                <?php foreach ($documentRows as $row): ?>
                  <?php
                    $historyRows = (array) ($row['history_rows'] ?? []);
                    $historyLines = array_map(static function (array $historyRow): string {
                        $parts = [
                            date('M d, Y g:i A', strtotime((string) ($historyRow['created_at'] ?? 'now'))),
                            (string) ($historyRow['action'] ?? '-'),
                            (string) ($historyRow['status'] ?? '-'),
                        ];
                        $remarks = trim((string) ($historyRow['remarks'] ?? ''));
                        if ($remarks !== '') {
                            $parts[] = $remarks;
                        }
                        return implode(' | ', $parts);
                    }, $historyRows);
                    $ledgerEntryId = (int) ($row['ledger_entry_id'] ?? 0);
                    $ledgerReferenceNo = trim((string) ($row['ledger_reference_no'] ?? ''));
                    $linkedLedgerLabel = (string) ($row['linked_ledger_label'] ?? 'Unlinked');
                  ?>
                  <tr>
                    <td><?= finance_h((string) ($row['tracking_number'] ?? '-')) ?></td>
                    <td>
                      <span class="gl-doc-title"><?= finance_h((string) ($row['doc_title'] ?? '-')) ?></span>
                      <span class="gl-doc-meta"><?= finance_h(trim((string) ($row['review_reason'] ?? $row['remarks'] ?? '')) !== '' ? (string) ($row['review_reason'] ?? $row['remarks'] ?? '') : 'Supporting ledger archive reference') ?></span>
                      <?php if (trim((string) ($row['flagged_by'] ?? '')) !== '' || trim((string) ($row['flagged_at_display'] ?? '')) !== ''): ?>
                        <span class="gl-doc-meta"><?= finance_h(trim((string) ($row['flagged_by'] ?? '')) !== '' ? 'Flagged by ' . (string) ($row['flagged_by'] ?? '') . (trim((string) ($row['flagged_at_display'] ?? '')) !== '' ? ' on ' . (string) ($row['flagged_at_display'] ?? '') : '') : 'Flagged on ' . (string) ($row['flagged_at_display'] ?? '')) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= finance_h((string) ($row['doc_type'] ?? '-')) ?></td>
                    <td><span class="gl-doc-status <?= finance_h(gl_document_status_class((string) ($row['status'] ?? 'Pending'))) ?>"><?= finance_h((string) ($row['status'] ?? 'Pending')) ?></span></td>
                    <td><?= finance_h((string) ($row['source_module'] ?? '-')) ?></td>
                    <td><?php if ($ledgerEntryId > 0 || $ledgerReferenceNo !== ''): ?><span class="gl-link-chip"><?= finance_h($linkedLedgerLabel) ?></span><?php else: ?><span class="gl-inline-note">Unlinked</span><?php endif; ?></td>
                    <td><?php if (trim((string) ($row['file_url'] ?? '')) !== ''): ?><a class="btn-link success" href="<?= finance_h((string) $row['file_url']) ?>" target="_blank" rel="noopener noreferrer">Open File</a><?php else: ?><span class="gl-inline-note">No file attached</span><?php endif; ?></td>
                    <td><?= finance_h((string) ($row['created_at_display'] ?? '-')) ?></td>
                    <td>
                      <div class="gl-doc-actions">
                        <button type="button" class="btn subtle js-doc-detail" data-record='<?= finance_h(json_encode([
                            'tracking_number' => (string) ($row['tracking_number'] ?? ''),
                            'doc_title' => (string) ($row['doc_title'] ?? ''),
                            'doc_type' => (string) ($row['doc_type'] ?? ''),
                            'status' => (string) ($row['status'] ?? ''),
                            'source_module' => (string) ($row['source_module'] ?? ''),
                            'file_url' => (string) ($row['file_url'] ?? ''),
                            'created_at_display' => (string) ($row['created_at_display'] ?? ''),
                            'linked_ledger_label' => $linkedLedgerLabel,
                            'remarks' => (string) ($row['remarks'] ?? ''),
                            'review_reason' => (string) ($row['review_reason'] ?? ''),
                            'flagged_by' => (string) ($row['flagged_by'] ?? ''),
                            'flagged_at_display' => (string) ($row['flagged_at_display'] ?? ''),
                            'history' => $historyLines,
                        ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT) ?: "{}") ?>'>View Details</button>
                        <?php if (trim((string) ($row['file_url'] ?? '')) !== ''): ?><button type="button" class="btn subtle js-doc-copy" data-url="<?= finance_h((string) $row['file_url']) ?>">Copy Link</button><?php endif; ?>
                        <form class="inline-form" method="post" onsubmit="return docSimplePrompt(this, 'Verify this document?', 'Optional verification note');"><?= csrf_field() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= (int) ($row['id'] ?? 0) ?>"><input type="hidden" name="remarks" value="" class="js-doc-remarks"><button type="submit" class="btn subtle">Verify</button></form>
                        <form class="inline-form" method="post" onsubmit="return docSimplePrompt(this, 'Archive this document for financial reference?', 'Optional archive note');"><?= csrf_field() ?><input type="hidden" name="action" value="archive_document"><input type="hidden" name="document_id" value="<?= (int) ($row['id'] ?? 0) ?>"><input type="hidden" name="remarks" value="" class="js-doc-remarks"><button type="submit" class="btn subtle">Archive</button></form>
                        <form class="inline-form" method="post" onsubmit="return docSimplePrompt(this, 'Flag this document for review?', 'Reason for review', true);"><?= csrf_field() ?><input type="hidden" name="action" value="flag_document"><input type="hidden" name="document_id" value="<?= (int) ($row['id'] ?? 0) ?>"><input type="hidden" name="remarks" value="" class="js-doc-remarks"><button type="submit" class="btn subtle">Flag</button></form>
                        <form class="inline-form" method="post" onsubmit="return docLinkPrompt(this, 'Link this document to a ledger entry or reference.');"><?= csrf_field() ?><input type="hidden" name="action" value="link_document_ledger"><input type="hidden" name="document_id" value="<?= (int) ($row['id'] ?? 0) ?>"><input type="hidden" name="ledger_entry_id" value="" class="js-doc-ledger-entry-id"><input type="hidden" name="ledger_reference_no" value="" class="js-doc-ledger-reference-no"><input type="hidden" name="remarks" value="" class="js-doc-remarks"><button type="submit" class="btn subtle">Link to Ledger</button></form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>
<div id="gl-doc-modal" class="gl-doc-modal" hidden>
  <div class="gl-doc-card">
    <div class="section-head">
      <div class="section-title">
        <h3 id="gl-doc-title">Supporting Document</h3>
        <p id="gl-doc-subtitle">Logistics2 document tracking reference for ledger-related audit support.</p>
      </div>
      <button type="button" class="btn subtle" onclick="closeDocModal()">Close</button>
    </div>
    <div class="gl-doc-grid">
      <div><strong>Tracking Number</strong><span id="gl-doc-tracking">-</span></div>
      <div><strong>Document Title</strong><span id="gl-doc-title-value">-</span></div>
      <div><strong>Document Type</strong><span id="gl-doc-type">-</span></div>
      <div><strong>Current Status</strong><span id="gl-doc-status">-</span></div>
      <div><strong>Source Module</strong><span id="gl-doc-source">-</span></div>
      <div><strong>Created At</strong><span id="gl-doc-created">-</span></div>
      <div><strong>Linked Ledger Entry</strong><span id="gl-doc-ledger">-</span></div>
      <div><strong>File URL</strong><span id="gl-doc-file">-</span></div>
      <div><strong>Flagged By</strong><span id="gl-doc-flagged-by">-</span></div>
      <div><strong>Flagged At</strong><span id="gl-doc-flagged-at">-</span></div>
    </div>
    <div class="gl-doc-note" style="margin-top: 14px;">
      <strong>Review Reason / Notes</strong>
      <div id="gl-doc-remarks">No remarks recorded.</div>
    </div>
    <div class="gl-doc-note" style="margin-top: 14px;">
      <strong>Verification / Archive History</strong>
      <ul id="gl-doc-history" class="gl-doc-history"></ul>
    </div>
    <div class="gl-doc-note" style="margin-top: 14px;">
      <strong>BPA Alignment</strong>
      <div>Logistics1 remains an operational request source in Collection. Logistics2 serves as supporting document tracking and archive evidence for the General Ledger, audits, and reporting.</div>
    </div>
  </div>
</div>
<script>
  function closeDocModal() {
    document.getElementById('gl-doc-modal').hidden = true;
  }
  function docSimplePrompt(form, confirmText, promptText, required = false) {
    if (!window.confirm(confirmText)) {
      return false;
    }
    const value = window.prompt(promptText, '') || '';
    if (required && value.trim() === '') {
      window.alert('A review reason is required.');
      return false;
    }
    const remarksInput = form.querySelector('.js-doc-remarks');
    if (remarksInput) {
      remarksInput.value = value.trim();
    }
    return true;
  }
  function docLinkPrompt(form, confirmText) {
    if (!window.confirm(confirmText)) {
      return false;
    }
    const ledgerEntryId = (window.prompt('Enter Ledger Entry ID if available:', '') || '').trim();
    const ledgerReferenceNo = (window.prompt('Or enter Ledger Reference No if available:', '') || '').trim();
    if (ledgerEntryId === '' && ledgerReferenceNo === '') {
      window.alert('A Ledger Entry ID or Ledger Reference No is required.');
      return false;
    }
    const remarks = (window.prompt('Optional linking note:', '') || '').trim();
    const entryInput = form.querySelector('.js-doc-ledger-entry-id');
    const refInput = form.querySelector('.js-doc-ledger-reference-no');
    const remarksInput = form.querySelector('.js-doc-remarks');
    if (entryInput) entryInput.value = ledgerEntryId;
    if (refInput) refInput.value = ledgerReferenceNo;
    if (remarksInput) remarksInput.value = remarks;
    return true;
  }
  document.querySelectorAll('.js-doc-detail').forEach((button) => {
    button.addEventListener('click', () => {
      let record = {};
      try { record = JSON.parse(button.dataset.record || '{}'); } catch (error) { record = {}; }
      document.getElementById('gl-doc-title').textContent = record.doc_title || 'Supporting Document';
      document.getElementById('gl-doc-subtitle').textContent = (record.doc_type || 'Document') + ' archive reference from Logistics2.';
      document.getElementById('gl-doc-tracking').textContent = record.tracking_number || '-';
      document.getElementById('gl-doc-title-value').textContent = record.doc_title || '-';
      document.getElementById('gl-doc-type').textContent = record.doc_type || '-';
      document.getElementById('gl-doc-status').textContent = record.status || '-';
      document.getElementById('gl-doc-source').textContent = record.source_module || '-';
      document.getElementById('gl-doc-created').textContent = record.created_at_display || '-';
      document.getElementById('gl-doc-ledger').textContent = record.linked_ledger_label || '-';
      document.getElementById('gl-doc-file').textContent = record.file_url || 'No file URL';
      document.getElementById('gl-doc-flagged-by').textContent = record.flagged_by || '-';
      document.getElementById('gl-doc-flagged-at').textContent = record.flagged_at_display || '-';
      document.getElementById('gl-doc-remarks').textContent = record.review_reason || record.remarks || 'No remarks recorded.';
      const historyList = document.getElementById('gl-doc-history');
      historyList.innerHTML = '';
      const lines = Array.isArray(record.history) ? record.history : [];
      if (lines.length === 0) {
        const item = document.createElement('li');
        item.textContent = 'No verification or archive history recorded yet.';
        historyList.appendChild(item);
      } else {
        lines.forEach((line) => {
          const item = document.createElement('li');
          item.textContent = line;
          historyList.appendChild(item);
        });
      }
      document.getElementById('gl-doc-modal').hidden = false;
    });
  });
  document.querySelectorAll('.js-doc-copy').forEach((button) => {
    button.addEventListener('click', async () => {
      const url = button.dataset.url || '';
      if (!url) return;
      try {
        await navigator.clipboard.writeText(url);
        const original = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => { button.textContent = original; }, 1200);
      } catch (error) {
        window.prompt('Copy this link:', url);
      }
    });
  });
</script>
</body>
</html>
