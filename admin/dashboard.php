<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/require_admin.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FinancialSM</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>

    <main class="main-content">
        <h1>Admin Dashboard</h1>
        <p><strong>Logged in as:</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></p>
        <p><strong>Role:</strong> <?= htmlspecialchars($_SESSION['role'] ?? '') ?></p>
        <p><a href="/FinancialSM/auth/logout.php">Logout</a></p>
    </main>
</div>
</body>
</html>
