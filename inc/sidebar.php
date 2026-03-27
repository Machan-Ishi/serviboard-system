<?php
$currentPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$isFinancialModule = strpos($currentPath, '/financial/') !== false;

$financialSections = [
    'Core Modules' => [
        ['/financial/index.php', 'Overview', 'OV'],
        ['/financial/collection.php', 'Collection', 'CO'],
        ['/financial/disbursement.php', 'Disbursement', 'DI'],
        ['/financial/ap-ar.php', 'AR / AP', 'AR'],
    ],
    'Accounting' => [
        ['/financial/general-ledger.php', 'General Ledger', 'GL'],
        ['/financial/budget-management.php', 'Budget', 'BG'],
    ],
    'HR / Internal' => [
        ['/payroll/employees.php', 'Employees', 'EM'],
        ['/payroll/attendance.php', 'Attendance', 'AT'],
        ['/payroll/payroll-run.php', 'Payroll Run', 'PR'],
    ],
    'System' => [
        ['/payroll/payroll-reports.php', 'Reports', 'RP'],
        ['/financial/request-action-logs.php', 'Request Logs', 'RL'],
        ['/financial/isupabase.php', 'Supabase', 'SB'],
    ],
];

$isActivePath = static function (string $path) use ($currentPath): bool {
    return $currentPath === $path;
};
?>

<aside class="sidebar<?= $isFinancialModule ? ' sidebar-financial' : '' ?>">
  <div class="sidebar-top">
    <div class="brand">
      <div class="brand-mark">SB</div>
      <div>ServiBoard</div>
    </div>
  </div>

  <div class="sidebar-nav">
    <div class="nav-group">
      <div class="nav-title">Dashboard</div>
      <a class="nav-item<?= $isActivePath('/index.php') ? ' active' : '' ?>" href="<?= BASE_URL ?>/index.php">
        Dashboard
      </a>
    </div>

    <?php if ($isFinancialModule): ?>
      <div class="nav-group">
        <div class="nav-title">Financial</div>
        <a class="nav-item<?= strpos($currentPath, '/financial/') !== false ? ' active' : '' ?>" href="<?= BASE_URL ?>/financial/index.php">
          <span class="nav-icon nav-icon-accent">$</span>
          Financial
        </a>
        <div class="nav-subgroup">
          <?php foreach ($financialSections as $sectionTitle => $links): ?>
            <div class="nav-subsection">
              <div class="nav-section-label"><?= htmlspecialchars($sectionTitle) ?></div>
              <?php foreach ($links as [$path, $label, $icon]): ?>
                <a class="nav-item nav-subitem<?= $isActivePath($path) ? ' active' : '' ?>" href="<?= BASE_URL . $path ?>">
                  <span class="nav-icon"><?= htmlspecialchars($icon) ?></span>
                  <span><?= htmlspecialchars($label) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="nav-group">
        <div class="nav-title">Modules</div>
        <a class="nav-item" href="<?= BASE_URL ?>/index.php">
          <span>HR</span>
          Human Resources
        </a>
        <a class="nav-item" href="<?= BASE_URL ?>/index.php">
          <span>CS</span>
          Core Services
        </a>
        <a class="nav-item" href="<?= BASE_URL ?>/index.php">
          <span>LG</span>
          Logistics
        </a>
        <a class="nav-item<?= strpos($currentPath, '/financial/') !== false ? ' active' : '' ?>" href="<?= BASE_URL ?>/financial/index.php">
          <span class="nav-icon nav-icon-accent">$</span>
          Financial
        </a>
        <div class="nav-subgroup">
          <?php foreach ($financialSections as $sectionTitle => $links): ?>
            <div class="nav-subsection">
              <div class="nav-section-label"><?= htmlspecialchars($sectionTitle) ?></div>
              <?php foreach ($links as [$path, $label, $icon]): ?>
                <a class="nav-item nav-subitem<?= $isActivePath($path) ? ' active' : '' ?>" href="<?= BASE_URL . $path ?>">
                  <span class="nav-icon"><?= htmlspecialchars($icon) ?></span>
                  <span><?= htmlspecialchars($label) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <a class="nav-item" href="<?= BASE_URL ?>/index.php">
          <span>AD</span>
          Administrative
        </a>
      </div>
    <?php endif; ?>
  </div>

  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 2)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin User') ?></div>
        <div class="user-email"><?= htmlspecialchars($_SESSION['user_role'] ?? 'Administrator') ?></div>
      </div>
    </div>
    <a class="logout-btn" href="<?= BASE_URL ?>/auth/logout.php">Log out</a>
  </div>
</aside>
<script src="<?= BASE_URL ?>/assets/js/sidebar-toggle.js"></script>
<script src="<?= BASE_URL ?>/assets/js/logout-confirm.js"></script>
