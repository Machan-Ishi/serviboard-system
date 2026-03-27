<?php
$financeTopbarNotifications = [];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $financeTopbarNotifications = finance_get_topbar_notifications($pdo, 8);
    }
} catch (Throwable) {
    $financeTopbarNotifications = [];
}

$financeTopbarSettings = finance_get_topbar_settings_context();
$financeNotificationCount = count($financeTopbarNotifications);
?>
<div class="topbar js-financial-topbar">
  <button class="icon-btn menu-btn" data-sidebar-toggle aria-label="Toggle menu">
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M4 7h16M4 12h16M4 17h16"></path>
    </svg>
  </button>
  <div class="search">
    <span class="icon-inline">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="11" cy="11" r="7"></circle>
        <path d="M20 20l-3.2-3.2"></path>
      </svg>
    </span>
    <input type="text" placeholder="Search services, tasks, or documents..." aria-label="Search services, tasks, or documents">
  </div>
  <div class="top-actions topbar-tools">
    <div class="topbar-menu">
      <button
        class="icon-btn<?= $financeNotificationCount > 0 ? ' has-badge' : '' ?>"
        type="button"
        aria-label="Notifications"
        aria-expanded="false"
        aria-controls="finance-notifications-panel"
        data-topbar-toggle="notifications">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M6 9a6 6 0 0 1 12 0c0 5 2 5 2 5H4s2 0 2-5"></path>
          <path d="M9.5 19a2.5 2.5 0 0 0 5 0"></path>
        </svg>
        <?php if ($financeNotificationCount > 0): ?>
          <span class="topbar-badge" data-notification-count><?= $financeNotificationCount ?></span>
        <?php endif; ?>
      </button>
      <div class="topbar-panel" id="finance-notifications-panel" hidden>
        <div class="topbar-panel-head">
          <div class="topbar-panel-title">Notifications</div>
          <div class="topbar-panel-meta">Latest finance activity</div>
        </div>
        <?php if ($financeTopbarNotifications): ?>
          <div class="notification-list">
            <?php foreach ($financeTopbarNotifications as $item): ?>
              <a class="notification-item" href="<?= finance_h((string) ($item['href'] ?? '/FinancialSM/financial/request-action-logs.php')) ?>" data-notification-item>
                <span class="notification-chip"><?= finance_h((string) ($item['module'] ?? 'SYS')) ?></span>
                <span class="notification-copy">
                  <span class="notification-message"><?= finance_h((string) ($item['message'] ?? 'New system notification')) ?></span>
                  <span class="notification-time"><?= finance_h((string) ($item['time'] ?? 'Just now')) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="topbar-empty">No notifications yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="topbar-menu">
      <button
        class="icon-btn"
        type="button"
        aria-label="Settings"
        aria-expanded="false"
        aria-controls="finance-settings-panel"
        data-topbar-toggle="settings">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"></path>
          <path d="M19.4 15a7.7 7.7 0 0 0 .1-1 7.7 7.7 0 0 0-.1-1l2-1.5-2-3.4-2.3.9a7.6 7.6 0 0 0-1.7-1l-.3-2.4H9.9l-.3 2.4a7.6 7.6 0 0 0-1.7 1L5.6 7l-2 3.4 2 1.5a7.7 7.7 0 0 0-.1 1 7.7 7.7 0 0 0 .1 1l-2 1.5 2 3.4 2.3-.9a7.6 7.6 0 0 0 1.7 1l.3 2.4h4.2l.3-2.4a7.6 7.6 0 0 0 1.7-1l2.3.9 2-3.4-2-1.5z"></path>
        </svg>
      </button>
      <div class="topbar-panel" id="finance-settings-panel" hidden>
        <div class="topbar-panel-head">
          <div class="topbar-panel-title">Settings</div>
          <div class="topbar-panel-meta">Quick account preferences</div>
        </div>
        <div class="settings-user-card">
          <div class="settings-user-name"><?= finance_h((string) ($financeTopbarSettings['user_name'] ?? 'Admin User')) ?></div>
          <div class="settings-user-meta"><?= finance_h((string) ($financeTopbarSettings['user_role'] ?? 'Administrator')) ?></div>
          <?php if (($financeTopbarSettings['user_email'] ?? '') !== ''): ?>
            <div class="settings-user-meta"><?= finance_h((string) $financeTopbarSettings['user_email']) ?></div>
          <?php endif; ?>
        </div>
        <div class="settings-stack">
          <div class="settings-row">
            <div class="settings-row-label">
              <strong>Theme Mode</strong>
              <span>Toggle between dark and light locally</span>
            </div>
            <input class="theme-switch" type="checkbox" data-theme-toggle aria-label="Toggle theme">
          </div>
          <div class="settings-row">
            <div class="settings-row-label">
              <strong>Session Timeout</strong>
              <span>Auto logout: <?= (int) ($financeTopbarSettings['session_timeout_minutes'] ?? 15) ?> mins</span>
            </div>
          </div>
          <div class="settings-form">
            <div class="settings-row-label">
              <strong>Change Password</strong>
              <span>Placeholder form for capstone setup</span>
            </div>
            <input type="password" placeholder="Current password" aria-label="Current password">
            <input type="password" placeholder="New password" aria-label="New password">
            <div class="settings-footer">
              <button class="btn subtle" type="button" data-settings-placeholder>Update Password</button>
            </div>
            <div class="settings-note" data-settings-note hidden>Password update form is a placeholder in this panel. Keep account changes in your auth flow.</div>
          </div>
        </div>
      </div>
    </div>

    <a class="icon-btn" href="/FinancialSM/auth/logout.php" aria-label="Logout" title="Logout">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M15 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3"></path>
        <path d="M10 17l5-5-5-5"></path>
        <path d="M15 12H4"></path>
      </svg>
    </a>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/financial-topbar.js"></script>
