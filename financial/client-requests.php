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
    'status' => trim((string) ($_GET['status'] ?? '')),
    'request_type' => trim((string) ($_GET['request_type'] ?? '')),
    'department' => trim((string) ($_GET['department'] ?? '')),
    'requester_name' => trim((string) ($_GET['requester_name'] ?? '')),
    'request_date' => trim((string) ($_GET['request_date'] ?? '')),
];

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? getClientRequestById($pdo, $editId) : null;

function process_client_request(PDO $pdo, int $id): void
{
    $request = getClientRequestById($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Client request not found.');
    }
    if (($request['status'] ?? '') === 'Processed') {
        throw new RuntimeException('Client request is already processed.');
    }

    $type = (string) ($request['request_type'] ?? '');
    $description = trim((string) ($request['description'] ?? ''));
    $remarks = trim((string) ($request['remarks'] ?? ''));
    $requestNo = (string) ($request['request_no'] ?? '');
    $requestDate = (string) ($request['request_date'] ?? date('Y-m-d'));
    $dueDate = (string) ($request['due_date'] ?? '');
    $requesterName = (string) ($request['requester_name'] ?? '');
    $department = (string) ($request['department'] ?? '');
    $amount = (float) ($request['amount'] ?? 0);

    $pdo->beginTransaction();
    try {
        $linkedModule = '';
        $linkedRecordId = 0;

        if ($type === 'Collection') {
            $linkedRecordId = createCollection($pdo, [
                'reference_no' => '',
                'source_type' => 'MANUAL',
                'source_id' => 0,
                'payer_name' => $requesterName,
                'amount' => $amount,
                'payment_method' => 'Cash',
                'payment_date' => $requestDate,
                'status' => 'Posted',
                'remarks' => trim($requestNo . ' ' . $description . ' ' . $remarks),
            ]);
            $linkedModule = 'collection';
        } elseif ($type === 'AR') {
            $linkedRecordId = createAR($pdo, [
                'client_name' => $requesterName,
                'description' => $description,
                'amount' => $amount,
                'due_date' => $dueDate !== '' ? $dueDate : null,
            ]);
            $linkedModule = 'ar_ap';
        } elseif ($type === 'AP') {
            $linkedRecordId = createAP($pdo, [
                'supplier_name' => $requesterName,
                'description' => $description,
                'amount' => $amount,
                'due_date' => $dueDate !== '' ? $dueDate : null,
            ]);
            $linkedModule = 'ar_ap';
        } elseif ($type === 'Disbursement') {
            $linkedRecordId = createDisbursement($pdo, [
                'reference_no' => '',
                'payee_name' => $requesterName,
                'request_source' => 'MANUAL',
                'request_id' => 0,
                'amount' => $amount,
                'disbursement_date' => $requestDate,
                'payment_method' => 'Cash',
                'status' => 'Released',
                'remarks' => trim($requestNo . ' ' . $description . ' ' . $remarks),
                'ledger_mode' => 'expense',
            ]);
            $linkedModule = 'disbursement';
        } elseif ($type === 'Budget') {
            $linkedRecordId = createBudget($pdo, [
                'budget_name' => $description !== '' ? $description : ('Budget Review ' . $requestNo),
                'department' => $department !== '' ? $department : 'Unassigned',
                'allocated_amount' => $amount,
                'used_amount' => 0,
                'period_start' => $requestDate,
                'period_end' => $dueDate !== '' ? $dueDate : $requestDate,
                'status' => 'Pending',
                'notes' => trim($requestNo . ' ' . $remarks),
            ]);
            $linkedModule = 'budget_management';
        } else {
            throw new RuntimeException('Unsupported request type.');
        }

        markClientRequestProcessed($pdo, $id, $linkedModule, $linkedRecordId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_request') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                updateClientRequest($pdo, $id, $_POST);
                $message = 'Client request updated.';
                $editRow = getClientRequestById($pdo, $id);
                $editId = $id;
            } else {
                $savedId = createClientRequest($pdo, $_POST);
                $message = 'Client request submitted.';
                $editRow = getClientRequestById($pdo, $savedId);
                $editId = $savedId;
            }
        }

        if ($action === 'approve_request') {
            approveClientRequest($pdo, (int) ($_POST['id'] ?? 0));
            $message = 'Client request approved.';
        }

        if ($action === 'reject_request') {
            rejectClientRequest($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['remarks'] ?? ''));
            $message = 'Client request rejected.';
        }

        if ($action === 'process_request') {
            process_client_request($pdo, (int) ($_POST['id'] ?? 0));
            $message = 'Client request processed into the target financial module.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$rows = getClientRequests($pdo, $filters);
$allRows = getClientRequests($pdo);

$summary = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'processed' => 0,
    'month_total' => 0.0,
];

$departments = [];
foreach ($allRows as $row) {
    $status = (string) ($row['status'] ?? '');
    if ($status === 'Pending') {
        $summary['pending']++;
    } elseif ($status === 'Approved') {
        $summary['approved']++;
    } elseif ($status === 'Rejected') {
        $summary['rejected']++;
    } elseif ($status === 'Processed') {
        $summary['processed']++;
    }

    if (str_starts_with((string) ($row['request_date'] ?? ''), date('Y-m'))) {
        $summary['month_total'] += (float) ($row['amount'] ?? 0);
    }

    $department = trim((string) ($row['department'] ?? ''));
    if ($department !== '') {
        $departments[$department] = $department;
    }
}
ksort($departments);

$form = $editRow ?: [
    'id' => 0,
    'request_no' => '',
    'requester_name' => '',
    'department' => '',
    'request_type' => 'Collection',
    'description' => '',
    'amount' => '',
    'request_date' => date('Y-m-d'),
    'due_date' => '',
    'status' => 'Pending',
    'remarks' => '',
];

function client_request_badge_class(string $status): string
{
    return match ($status) {
        'Approved', 'Processed' => 'badge-paid',
        'Pending' => 'badge-pending',
        'Rejected' => 'badge-cancelled',
        default => 'badge-partial',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Client Requests - Financial - ServiBoard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .cr-page-summary {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 14px;
    }

    .cr-page-summary .summary-card {
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

    .cr-page-summary .summary-card:hover {
      border-color: var(--gold);
    }

    .cr-page-summary .summary-card::after {
      content: "";
      position: absolute;
      right: -18px;
      bottom: -18px;
      width: 84px;
      height: 84px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(240, 175, 28, 0.1) 0%, rgba(240, 175, 28, 0) 74%);
    }

    .cr-page-summary .summary-label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.7px;
    }

    .cr-page-summary .summary-value {
      display: block;
      font-size: 22px;
      line-height: 1.1;
      font-weight: 700;
      color: var(--text);
      word-break: break-word;
    }

    .cr-panel {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 18px;
      background: var(--card-bg);
      box-shadow: var(--shadow-soft);
    }

    .cr-panel .form-title,
    .cr-panel .table-title {
      margin-bottom: 14px;
      font-size: 15px;
      color: var(--text);
    }

    .cr-queue .table-title {
      margin-bottom: 16px;
    }

    .cr-message {
      min-height: auto;
      padding: 10px 14px;
    }

    @media (max-width: 1320px) {
      .cr-page-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 980px) {
      .cr-workspace-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 760px) {
      .cr-page-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 520px) {
      .cr-page-summary {
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

    <div class="page-header"><h1>Client Requests</h1><p>Capture, review, and convert financial requests into operational records with traceability.</p></div>
    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <?php foreach ($bootstrapMessages as $bootstrapMessage): ?><section class="section-card"><div class="error-text"><?= finance_h($bootstrapMessage) ?></div></section><?php endforeach; ?>
    <?php if ($message !== ''): ?><section class="section-card cr-message"><div class="status-badge badge-paid"><?= finance_h($message) ?></div></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="section-card"><div class="error-text"><?= finance_h($error) ?></div></section><?php endif; ?>

    <section class="cr-page-summary">
      <article class="summary-card"><span class="summary-label">Pending</span><strong class="summary-value"><?= number_format($summary['pending']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Approved</span><strong class="summary-value"><?= number_format($summary['approved']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Rejected</span><strong class="summary-value"><?= number_format($summary['rejected']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Processed</span><strong class="summary-value"><?= number_format($summary['processed']) ?></strong></article>
      <article class="summary-card"><span class="summary-label">Requested This Month</span><strong class="summary-value">PHP <?= finance_money($summary['month_total']) ?></strong></article>
    </section>

    <section class="section-card collection-shell cr-page-shell">
      <div class="cr-workspace-header">
        <div>
          <h2 class="collection-title">Request Intake</h2>
          <p class="collection-subtitle">Requests enter here first, then Finance can approve, reject, or process them into Collection, AR, AP, Disbursement, or Budget.</p>
        </div>
      </div>

      <div class="cr-workspace-grid">
        <form class="cr-panel" method="post">
          <h3 class="form-title"><?= $editRow ? 'Edit Request' : 'Create Request' ?></h3>
          <input type="hidden" name="action" value="save_request">
          <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">

          <div class="form-row"><label for="request_no">Request No</label><input id="request_no" name="request_no" type="text" value="<?= finance_h((string) ($form['request_no'] ?? '')) ?>" placeholder="Auto-generated if empty"></div>
          <div class="form-row"><label for="requester_name">Requester Name</label><input id="requester_name" name="requester_name" type="text" required value="<?= finance_h((string) ($form['requester_name'] ?? '')) ?>"></div>
          <div class="form-row"><label for="department">Department</label><input id="department" name="department" type="text" value="<?= finance_h((string) ($form['department'] ?? '')) ?>"></div>
          <div class="form-row"><label for="request_type">Request Type</label><select id="request_type" name="request_type"><?php foreach (['Collection', 'AR', 'AP', 'Disbursement', 'Budget'] as $type): ?><option value="<?= finance_h($type) ?>" <?= (($form['request_type'] ?? 'Collection') === $type) ? 'selected' : '' ?>><?= finance_h($type) ?></option><?php endforeach; ?></select></div>
          <div class="form-row"><label for="description">Description</label><textarea id="description" name="description" rows="3" required><?= finance_h((string) ($form['description'] ?? '')) ?></textarea></div>
          <div class="form-row split">
            <div><label for="amount">Amount</label><input id="amount" name="amount" type="number" min="0" step="0.01" value="<?= finance_h((string) ($form['amount'] ?? '')) ?>"></div>
            <div><label for="status">Status</label><select id="status" name="status"><?php foreach (['Pending', 'Approved', 'Rejected', 'Processed'] as $status): ?><option value="<?= finance_h($status) ?>" <?= (($form['status'] ?? 'Pending') === $status) ? 'selected' : '' ?>><?= finance_h($status) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row split">
            <div><label for="request_date">Request Date</label><input id="request_date" name="request_date" type="date" required value="<?= finance_h((string) ($form['request_date'] ?? date('Y-m-d'))) ?>"></div>
            <div><label for="due_date">Due Date</label><input id="due_date" name="due_date" type="date" value="<?= finance_h((string) ($form['due_date'] ?? '')) ?>"></div>
          </div>
          <div class="form-row"><label for="remarks">Remarks</label><textarea id="remarks" name="remarks" rows="3"><?= finance_h((string) ($form['remarks'] ?? '')) ?></textarea></div>
          <div class="form-actions"><?php if ($editRow): ?><a class="btn subtle" href="client-requests.php">Cancel</a><?php endif; ?><button class="btn primary" type="submit"><?= $editRow ? 'Update Request' : 'Submit Request' ?></button></div>
        </form>

        <div class="cr-panel">
          <div class="table-title">Filter Requests</div>
          <form class="filter-bar" method="get">
            <div class="form-row"><label for="filter_requester_name">Requester</label><input id="filter_requester_name" name="requester_name" type="text" value="<?= finance_h($filters['requester_name']) ?>" placeholder="Search requester"></div>
            <div class="form-row"><label for="filter_department">Department</label><select id="filter_department" name="department"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= finance_h($department) ?>" <?= $filters['department'] === $department ? 'selected' : '' ?>><?= finance_h($department) ?></option><?php endforeach; ?></select></div>
            <div class="form-row split">
              <div><label for="filter_request_type">Request Type</label><select id="filter_request_type" name="request_type"><option value="">All types</option><?php foreach (['Collection', 'AR', 'AP', 'Disbursement', 'Budget'] as $type): ?><option value="<?= finance_h($type) ?>" <?= $filters['request_type'] === $type ? 'selected' : '' ?>><?= finance_h($type) ?></option><?php endforeach; ?></select></div>
              <div><label for="filter_status">Status</label><select id="filter_status" name="status"><option value="">All statuses</option><?php foreach (['Pending', 'Approved', 'Rejected', 'Processed'] as $status): ?><option value="<?= finance_h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= finance_h($status) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row"><label for="filter_request_date">Request Date</label><input id="filter_request_date" name="request_date" type="date" value="<?= finance_h($filters['request_date']) ?>"></div>
            <div class="form-actions"><button class="btn primary" type="submit">Apply Filters</button><a class="btn subtle" href="client-requests.php">Reset</a></div>
          </form>
        </div>
      </div>
    </section>

    <section class="section-card cr-queue">
      <div class="table-title">Request Queue</div>
      <div class="table-wrap table-scroll-pane">
        <table class="notion-table">
          <thead>
            <tr>
              <th>Request No</th>
              <th>Requester</th>
              <th>Department</th>
              <th>Type</th>
              <th>Description</th>
              <th>Amount</th>
              <th>Request Date</th>
              <th>Due Date</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?><tr><td colspan="10" class="muted-cell">No client requests found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= finance_h((string) ($row['request_no'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['requester_name'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['department'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['request_type'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['description'] ?? '-')) ?></td>
                <td>PHP <?= finance_money($row['amount'] ?? 0) ?></td>
                <td><?= finance_h((string) ($row['request_date'] ?? '-')) ?></td>
                <td><?= finance_h((string) ($row['due_date'] ?? '-')) ?></td>
                <td><span class="status-badge <?= client_request_badge_class((string) ($row['status'] ?? '')) ?>"><?= finance_h((string) ($row['status'] ?? '-')) ?></span></td>
                <td class="table-actions">
                  <a class="btn-link" href="client-requests.php?edit=<?= (int) $row['id'] ?>">View / Edit</a>
                  <?php if (($row['status'] ?? '') === 'Pending'): ?>
                    <form class="inline-form" method="post">
                      <input type="hidden" name="action" value="approve_request">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn-link" type="submit">Approve</button>
                    </form>
                    <form class="inline-form" method="post">
                      <input type="hidden" name="action" value="reject_request">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <input type="hidden" name="remarks" value="Rejected during finance review.">
                      <button class="btn-link danger" type="submit">Reject</button>
                    </form>
                  <?php endif; ?>
                  <?php if (in_array((string) ($row['status'] ?? ''), ['Approved', 'Pending'], true)): ?>
                    <form class="inline-form" method="post" onsubmit="return confirm('Process this request into its target financial module?');">
                      <input type="hidden" name="action" value="process_request">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn-link" type="submit">Mark as Processed</button>
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
