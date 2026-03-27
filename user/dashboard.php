<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_user.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/user_portal.php';

$activePage = 'dashboard.php';
$userId = (int)($_SESSION['user_id'] ?? 0);

$summary = [
    'outstanding' => 0.0,
    'total_paid' => 0.0,
    'active_invoices' => 0,
    'pending_requests' => 0,
];
$recentInvoices = [];
$recentPayments = [];
$recentRequests = [];
$portalError = '';

try {
    $pdo = db();
    $ctx = portal_resolve_client($pdo, $userId, (string)($_SESSION['user_name'] ?? 'User'));
    $clientId = (int)$ctx['client_id'];

    if ($clientId > 0) {
        // Get outstanding AR amounts for this client
        $summarySql = "SELECT
                COALESCE(SUM(balance), 0) AS outstanding,
                COUNT(*) AS active_invoices
            FROM public.ar_ap
            WHERE entry_type = 'AR' AND (user_id = ? OR party_name = ?) AND balance > 0";
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute([$userId, $ctx['party_name']]);
        $statsRow = $summaryStmt->fetch() ?: [];
        $summary['outstanding'] = (float)($statsRow['outstanding'] ?? 0);
        $summary['active_invoices'] = (int)($statsRow['active_invoices'] ?? 0);

        // Get total paid (this would be from collection table)
        $paidSql = "SELECT COALESCE(SUM(amount), 0) AS total_paid FROM public.collection WHERE payer_name = ?";
        $paidStmt = $pdo->prepare($paidSql);
        $paidStmt->execute([$ctx['party_name']]);
        $summary['total_paid'] = (float)(($paidStmt->fetch())['total_paid'] ?? 0);

        // Get recent invoices
        $recentInvSql = "SELECT id, reference_no AS invoice_no, created_at AS invoice_date, due_date, amount AS total_amount, balance
            FROM public.ar_ap
            WHERE entry_type = 'AR' AND (user_id = ? OR party_name = ?)
            ORDER BY created_at DESC, id DESC
            LIMIT 5";
        $recentInvStmt = $pdo->prepare($recentInvSql);
        $recentInvStmt->execute([$userId, $ctx['party_name']]);
        $recentInvoices = $recentInvStmt->fetchAll() ?: [];

        // Get recent payments from collection table
        $recentPaySql = "SELECT id, payment_date, reference_no, amount, payment_method
            FROM public.collection
            WHERE payer_name = ?
            ORDER BY payment_date DESC, id DESC
            LIMIT 5";

        $recentPayStmt = $pdo->prepare($recentPaySql);
        $recentPayStmt->execute([$ctx['party_name']]);
        $recentPayments = $recentPayStmt->fetchAll() ?: [];

        if (portal_table_exists($pdo, 'client_requests')) {
            $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM public.client_requests WHERE (requester_name = ? OR department = ?) AND status IN ('Pending','In Review')");
            $pendingStmt->execute([$ctx['party_name'], $ctx['department'] ?? '']);
            $summary['pending_requests'] = (int)$pendingStmt->fetchColumn();

            $requestStmt = $pdo->prepare("SELECT id, description as subject, request_type, status, created_at
                FROM public.client_requests
                WHERE requester_name = ? OR department = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 5");
            $requestStmt->execute([$ctx['party_name'], $ctx['department'] ?? '']);
            $recentRequests = $requestStmt->fetchAll() ?: [];
        }
    } else {
        $portalError = 'Your account is not yet linked to a client record. Please contact support.';
        $ctx = ['user_name' => (string)($_SESSION['user_name'] ?? 'User')];
    }
} catch (Throwable $e) {
    $portalError = 'Unable to load your dashboard right now.';
    $ctx = ['user_name' => (string)($_SESSION['user_name'] ?? 'User')];
}

$topbarSearchPlaceholder = 'Search invoices, payments, or requests...';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - ServiBoard</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar_user.php'; ?>

    <main class="content" role="main">
        <?php include __DIR__ . '/../includes/header_user.php'; ?>

        <div class="page-header">
            <h1>Welcome, <?= h((string)$ctx['user_name']) ?></h1>
            <p>Track invoices, payments, account status, and requests in one portal.</p>
        </div>

        <?php if ($portalError !== ''): ?>
            <section class="section-card">
                <div class="alert-inline"><?= h($portalError) ?></div>
            </section>
        <?php endif; ?>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Account Snapshot</h3>
                <span>Live summary</span>
            </div>
            <div class="stats-grid user-stats-grid">
                <div class="stat">
                    <h4>Outstanding Balance</h4>
                    <div class="value"><?= peso($summary['outstanding']) ?></div>
                </div>
                <div class="stat">
                    <h4>Total Paid</h4>
                    <div class="value"><?= peso($summary['total_paid']) ?></div>
                </div>
                <div class="stat">
                    <h4>Active Invoices</h4>
                    <div class="value"><?= number_format($summary['active_invoices']) ?></div>
                </div>
                <div class="stat">
                    <h4>Pending Requests</h4>
                    <div class="value"><?= number_format($summary['pending_requests']) ?></div>
                </div>
            </div>
        </section>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Recent Invoices</h3>
                <span><a class="back-link" href="/FinancialSM/user/invoices.php">View all</a></span>
            </div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentInvoices): ?>
                        <tr><td colspan="5">No invoices available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentInvoices as $row): ?>
                            <?php
                            $status = portal_invoice_status((float)$row['total_amount'], (float)$row['total_paid'], (string)($row['due_date'] ?? ''));
                            ?>
                            <tr>
                                <td><?= h((string)$row['invoice_no']) ?></td>
                                <td><?= h((string)$row['invoice_date']) ?></td>
                                <td><?= h((string)($row['due_date'] ?? '-')) ?></td>
                                <td><?= peso($row['total_amount']) ?></td>
                                <td><span class="status-badge <?= h(portal_status_badge($status)) ?>"><?= h($status) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Recent Payments</h3>
                <span><a class="back-link" href="/FinancialSM/user/payments.php">View all</a></span>
            </div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Invoice No</th>
                        <th>Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentPayments): ?>
                        <tr><td colspan="4">No payments available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentPayments as $row): ?>
                            <tr>
                                <td><?= h((string)$row['payment_date']) ?></td>
                                <td><?= h((string)($row['reference_no'] ?: 'N/A')) ?></td>
                                <td><?= h((string)$row['invoice_no']) ?></td>
                                <td><?= peso($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Recent Requests</h3>
                <span><a class="back-link" href="/FinancialSM/user/requests.php">View all</a></span>
            </div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentRequests): ?>
                        <tr><td colspan="4">No requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentRequests as $row): ?>
                            <tr>
                                <td><?= h((string)$row['created_at']) ?></td>
                                <td><?= h((string)$row['subject']) ?></td>
                                <td><?= h((string)$row['request_type']) ?></td>
                                <td><span class="status-badge <?= h(portal_status_badge((string)$row['status'])) ?>"><?= h((string)$row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
