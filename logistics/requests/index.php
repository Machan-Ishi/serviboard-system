<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/require_admin.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../inc/functions.php';
require_once __DIR__ . '/../../inc/finance_functions.php';

$flash = (string) ($_SESSION['logistics_request_flash'] ?? '');
$error = (string) ($_SESSION['logistics_request_error'] ?? '');
unset($_SESSION['logistics_request_flash'], $_SESSION['logistics_request_error']);

$logisticsRequests = [];

try {
    $logisticsRequests = finance_get_logistics_review_requests($pdo, ['status' => 'Pending'], 200, 0);
} catch (Throwable $e) {
    $error = 'Unable to load logistics requests: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics - Incoming Requests</title>
    <link rel="stylesheet" href="../../assets/financial.css">
</head>
<body>
<div class="layout">
    <main class="content" style="margin: auto; max-width: 1080px;">
        <div class="page-header">
            <h1>Logistics - Incoming Requests</h1>
            <p>Review the shared Logistics1 approval queue. Logistics2 document tracking remains in General Ledger.</p>
        </div>
        <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial Dashboard</a>

        <?php if ($flash !== ''): ?>
            <section class="section-card" style="margin-top: 20px;">
                <div class="success-inline"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
            </section>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <section class="section-card" style="margin-top: 20px;">
                <div class="alert-inline"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            </section>
        <?php endif; ?>

        <section class="section-card" style="margin-top: 20px;">
            <table class="notion-table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Requested By</th>
                        <th>Department</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logisticsRequests)): ?>
                        <tr><td colspan="6" class="muted-cell">No pending logistics requests.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logisticsRequests as $request): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars((string) ($request['request_title'] ?? $request['item_name'] ?? 'Logistics Request'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="color: var(--muted); font-size: 12px;"><?= htmlspecialchars((string) ($request['request_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td><?= htmlspecialchars((string) ($request['requested_by_name'] ?? 'Logistics Staff'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($request['department_name'] ?? 'Logistics'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($request['amount_label'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="color: var(--muted); font-size: 12px;"><?= htmlspecialchars((string) ($request['request_date_display'] ?? $request['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="table-actions">
                                <form action="process.php" method="POST" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (string) ($request['id'] ?? 0) ?>">
                                    <button type="submit" name="action" value="approve" class="btn-link success">Approve</button>
                                </form>
                                <form action="process.php" method="POST" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (string) ($request['id'] ?? 0) ?>">
                                    <button type="submit" name="action" value="reject" class="btn-link danger">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>
