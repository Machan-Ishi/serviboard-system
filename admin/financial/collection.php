<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/require_admin.php';
require_once __DIR__ . '/../../config/db.php';

$pdo = db();

$todayCollectedStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM public.collection WHERE payment_date = CURRENT_DATE");
$todayCollected = (float)$todayCollectedStmt->fetchColumn();

$monthCollectedStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM public.collection WHERE TO_CHAR(payment_date, 'YYYY-MM') = TO_CHAR(CURRENT_DATE, 'YYYY-MM')");
$monthCollected = (float)$monthCollectedStmt->fetchColumn();

$outstandingStmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) FROM public.ar_ap WHERE entry_type = 'AR' AND balance > 0");
$outstanding = (float)$outstandingStmt->fetchColumn();

$overdueStmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) FROM public.ar_ap WHERE entry_type = 'AR' AND balance > 0 AND due_date < CURRENT_DATE");
$overdue = (float)$overdueStmt->fetchColumn();

$invoiceRows = $pdo->query("SELECT id, party_name AS client_name, reference_no AS invoice_no, created_at AS invoice_date, amount AS total_amount,
    (amount - balance) AS total_paid,
    balance,
    CASE
      WHEN balance <= 0 THEN 'PAID'
      WHEN balance < amount THEN 'PARTIAL'
      ELSE 'UNPAID'
    END AS status
  FROM public.ar_ap
  WHERE entry_type = 'AR'
  ORDER BY id DESC
  LIMIT 20")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection - Admin - FinancialSM</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../../includes/sidebar_admin.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <h1>Collection Workspace</h1>
            <p>Admin view for receivables and collection monitoring.</p>
        </header>

        <section class="card-grid">
            <div class="card"><h3>Collected Today</h3><p><?= number_format($todayCollected, 2) ?></p></div>
            <div class="card"><h3>Collected This Month</h3><p><?= number_format($monthCollected, 2) ?></p></div>
            <div class="card"><h3>Outstanding</h3><p><?= number_format($outstanding, 2) ?></p></div>
            <div class="card"><h3>Overdue</h3><p><?= number_format($overdue, 2) ?></p></div>
        </section>

        <section class="table-card">
            <h2>Latest Invoices</h2>
            <table>
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($invoiceRows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                        <td><?= htmlspecialchars($row['client_name']) ?></td>
                        <td><?= htmlspecialchars($row['invoice_date']) ?></td>
                        <td><?= number_format((float)$row['total_amount'], 2) ?></td>
                        <td><?= number_format((float)$row['total_paid'], 2) ?></td>
                        <td><?= number_format((float)$row['balance'], 2) ?></td>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>
