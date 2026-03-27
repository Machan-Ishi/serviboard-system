<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_user.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/user_portal.php';

$activePage = 'invoices.php';
$userId = (int)($_SESSION['user_id'] ?? 0);
$invoices = [];
$invoiceDetail = null;
$invoicePayments = [];
$portalError = '';

try {
    $pdo = db();
    $ctx = portal_resolve_client($pdo, $userId, (string)($_SESSION['user_name'] ?? 'User'));
    $clientId = (int)$ctx['client_id'];

    if ($clientId <= 0 && $ctx['party_name'] === 'User') {
        $portalError = 'Your account is not yet linked to a client record.';
    } else {
        $sql = "SELECT id, reference_no AS invoice_no, created_at AS invoice_date, due_date, amount AS total_amount,
                (amount - balance) AS total_paid,
                balance,
                id AS latest_payment_id
            FROM public.ar_ap
            WHERE entry_type = 'AR' AND (user_id = ? OR party_name = ?)
            ORDER BY created_at DESC, id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $ctx['party_name']]);
        $invoices = $stmt->fetchAll() ?: [];

        $viewInvoiceId = (int)($_GET['view'] ?? 0);
        if ($viewInvoiceId > 0) {
            $detailSql = "SELECT id, reference_no AS invoice_no, created_at AS invoice_date, due_date, description, amount AS total_amount,
                    party_name AS client_name,
                    (amount - balance) AS total_paid
                FROM public.ar_ap
                WHERE entry_type = 'AR' AND id = ? AND (user_id = ? OR party_name = ?)
                LIMIT 1";
            $detailStmt = $pdo->prepare($detailSql);
            $detailStmt->execute([$viewInvoiceId, $userId, $ctx['party_name']]);
            $invoiceDetail = $detailStmt->fetch() ?: null;

            if ($invoiceDetail) {
                $paySql = "SELECT id, payment_date, amount, reference_no, payment_method, remarks as notes
                    FROM public.collection
                    WHERE source_type = 'AR' AND source_id = ? AND payer_name = ?
                    ORDER BY payment_date DESC, id DESC";

                $payStmt = $pdo->prepare($paySql);
                $payStmt->execute([$viewInvoiceId, $ctx['party_name']]);
                $invoicePayments = $payStmt->fetchAll() ?: [];
            }
        }
    }
} catch (Throwable $e) {
    $portalError = 'Unable to load invoices right now.';
    $ctx = ['client_name' => (string)($_SESSION['user_name'] ?? 'User')];
}

$topbarSearchPlaceholder = 'Search invoice number...';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Invoices - ServiBoard</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar_user.php'; ?>

    <main class="content" role="main">
        <?php include __DIR__ . '/../includes/header_user.php'; ?>

        <div class="page-header">
            <h1>My Invoices</h1>
            <p>View invoice balances, status, and payment history.</p>
        </div>

        <?php if ($portalError !== ''): ?>
            <section class="section-card">
                <div class="alert-inline"><?= h($portalError) ?></div>
            </section>
        <?php endif; ?>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Invoice List</h3>
                <span><?= number_format(count($invoices)) ?> records</span>
            </div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Total Amount</th>
                        <th>Total Paid</th>
                        <th>Remaining Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$invoices): ?>
                        <tr><td colspan="8">No invoices available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $row): ?>
                            <?php
                            $status = portal_invoice_status((float)$row['total_amount'], (float)$row['total_paid'], (string)($row['due_date'] ?? ''));
                            $canDownloadReceipt = ((float)$row['total_paid'] > 0.00001) && ((int)$row['latest_payment_id'] > 0);
                            ?>
                            <tr>
                                <td><?= h((string)$row['invoice_no']) ?></td>
                                <td><?= h((string)$row['invoice_date']) ?></td>
                                <td><?= h((string)($row['due_date'] ?? '-')) ?></td>
                                <td><?= peso($row['total_amount']) ?></td>
                                <td><?= peso($row['total_paid']) ?></td>
                                <td><?= peso($row['balance']) ?></td>
                                <td><span class="status-badge <?= h(portal_status_badge($status)) ?>"><?= h($status) ?></span></td>
                                <td class="table-actions">
                                    <a class="btn-link" href="/FinancialSM/user/invoices.php?view=<?= (int)$row['id'] ?>">View Invoice</a>
                                    <?php if ($canDownloadReceipt): ?>
                                        <a class="btn-link" href="/FinancialSM/user/payments.php?receipt_id=<?= (int)$row['latest_payment_id'] ?>&download=1">Download Receipt</a>
                                    <?php else: ?>
                                        <span class="muted-cell">No receipt yet</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($invoiceDetail): ?>
            <?php
            $detailStatus = portal_invoice_status((float)$invoiceDetail['total_amount'], (float)$invoiceDetail['total_paid'], (string)($invoiceDetail['due_date'] ?? ''));
            $detailBalance = max(0, (float)$invoiceDetail['total_amount'] - (float)$invoiceDetail['total_paid']);
            ?>
            <section class="section-card">
                <div class="sub-head">
                    <h3 class="heading-reset">Invoice Details</h3>
                    <span><span class="status-badge <?= h(portal_status_badge($detailStatus)) ?>"><?= h($detailStatus) ?></span></span>
                </div>

                <div class="payment-props user-meta-grid">
                    <div><span>Client Name</span><strong><?= h((string)$invoiceDetail['client_name']) ?></strong></div>
                    <div><span>Invoice Number</span><strong><?= h((string)$invoiceDetail['invoice_no']) ?></strong></div>
                    <div><span>Invoice Date</span><strong><?= h((string)$invoiceDetail['invoice_date']) ?></strong></div>
                    <div><span>Due Date</span><strong><?= h((string)($invoiceDetail['due_date'] ?? '-')) ?></strong></div>
                    <div><span>Invoice Total</span><strong><?= peso($invoiceDetail['total_amount']) ?></strong></div>
                    <div><span>Balance</span><strong><?= peso($detailBalance) ?></strong></div>
                </div>

                <div class="table-wrap">
                    <table class="notion-table">
                        <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Notes</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$invoicePayments): ?>
                            <tr><td colspan="5">No payment history available for this invoice.</td></tr>
                        <?php else: ?>
                            <?php foreach ($invoicePayments as $pay): ?>
                                <tr>
                                    <td><?= h((string)$pay['payment_date']) ?></td>
                                    <td><?= h((string)($pay['reference_no'] ?: 'N/A')) ?></td>
                                    <td><?= peso($pay['amount']) ?></td>
                                    <td><?= h((string)($pay['payment_method'] ?: 'N/A')) ?></td>
                                    <td><?= h((string)($pay['notes'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
