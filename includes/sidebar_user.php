<?php
declare(strict_types=1);

$activePage = $activePage ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark">SB</div>
    <div>ServiBoard</div>
  </div>

  <div class="nav-group">
    <div class="nav-title">Client Portal</div>
    <a class="nav-item<?= $activePage === 'dashboard.php' ? ' active' : '' ?>" href="/FinancialSM/user/dashboard.php">Dashboard</a>
    <a class="nav-item<?= $activePage === 'invoices.php' ? ' active' : '' ?>" href="/FinancialSM/user/invoices.php">My Invoices</a>
    <a class="nav-item<?= $activePage === 'payments.php' ? ' active' : '' ?>" href="/FinancialSM/user/payments.php">My Payments</a>
    <a class="nav-item<?= $activePage === 'requests.php' ? ' active' : '' ?>" href="/FinancialSM/user/requests.php">My Requests</a>
    <a class="nav-item<?= $activePage === 'profile.php' ? ' active' : '' ?>" href="/FinancialSM/user/profile.php">Profile</a>
  </div>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar"><?= h(strtoupper(substr((string)($_SESSION['user_name'] ?? 'U'), 0, 1))) ?></div>
      <div>
        <div class="user-name"><?= h($_SESSION['user_name'] ?? 'User') ?></div>
        <div class="user-email">Client User</div>
      </div>
    </div>
    <a class="logout-btn" href="/FinancialSM/auth/logout.php">Log out</a>
  </div>
</aside>
<script src="/FinancialSM/assets/js/sidebar-toggle.js"></script>
<script src="/FinancialSM/assets/js/logout-confirm.js"></script>
