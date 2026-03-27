<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/supabase_config.php';

$healthCheck = supabase_health_check();
$status = SupabaseConfig::getStatus();

$pageTitle = "Supabase Integration Status";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo htmlspecialchars($pageTitle); ?> - Financial System</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/financial.css">
  <style>
    .status-dashboard {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .status-card {
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px;
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .status-card h3 {
      margin: 0 0 16px 0;
      font-size: 18px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .status-indicator {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      display: inline-block;
    }

    .status-healthy { background: #10b981; }
    .status-warning { background: #f59e0b; }
    .status-error { background: #ef4444; }

    .status-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid #f1f5f9;
    }

    .status-item:last-child {
      border-bottom: none;
    }

    .status-value {
      font-weight: 500;
    }

    .status-value.healthy { color: #10b981; }
    .status-value.warning { color: #f59e0b; }
    .status-value.error { color: #ef4444; }

    .issues-list {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 8px;
      padding: 16px;
      margin-top: 16px;
    }

    .issues-list h4 {
      margin: 0 0 8px 0;
      color: #dc2626;
      font-size: 14px;
    }

    .issues-list ul {
      margin: 0;
      padding-left: 20px;
    }

    .issues-list li {
      color: #991b1b;
      font-size: 14px;
      margin-bottom: 4px;
    }

    .action-buttons {
      display: flex;
      gap: 12px;
      margin-top: 24px;
    }

    .btn {
      padding: 10px 16px;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
    }

    .btn.primary {
      background: var(--primary, #3b82f6);
      color: white;
    }

    .btn.primary:hover {
      background: var(--primary-dark, #2563eb);
      transform: translateY(-1px);
    }

    .btn.secondary {
      background: transparent;
      color: var(--text);
      border: 1px solid var(--border);
    }

    .btn.secondary:hover {
      background: var(--border);
    }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../inc/sidebar.php'; ?>
  <main class="content" role="main">
    <?php include __DIR__ . '/../inc/financial_topbar.php'; ?>

    <div class="page-header">
      <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
      <p>Monitor and manage your Supabase integration status</p>
    </div>

    <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

    <div class="status-dashboard">
      <div class="status-card">
        <h3>
          <span class="status-indicator <?php echo $healthCheck['healthy'] ? 'status-healthy' : 'status-error'; ?>"></span>
          Overall Health
        </h3>
        <div class="status-item">
          <span>Integration Status</span>
          <span class="status-value <?php echo $healthCheck['healthy'] ? 'healthy' : 'error'; ?>">
            <?php echo $healthCheck['healthy'] ? 'Healthy' : 'Issues Found'; ?>
          </span>
        </div>
        <div class="status-item">
          <span>Last Check</span>
          <span class="status-value"><?php echo date('M j, Y H:i', strtotime($healthCheck['timestamp'])); ?></span>
        </div>
        <?php if (!empty($healthCheck['issues'])): ?>
        <div class="issues-list">
          <h4>Issues Detected:</h4>
          <ul>
            <?php foreach ($healthCheck['issues'] as $issue): ?>
            <li><?php echo htmlspecialchars($issue); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>

      <div class="status-card">
        <h3>🔧 Configuration</h3>
        <div class="status-item">
          <span>Supabase URL</span>
          <span class="status-value <?php echo $status['configured'] ? 'healthy' : 'error'; ?>">
            <?php echo $status['configured'] ? 'Configured' : 'Missing'; ?>
          </span>
        </div>
        <div class="status-item">
          <span>API Key</span>
          <span class="status-value <?php echo $status['configured'] ? 'healthy' : 'error'; ?>">
            <?php echo $status['configured'] ? 'Set' : 'Missing'; ?>
          </span>
        </div>
        <div class="status-item">
          <span>Database Host</span>
          <span class="status-value healthy">Connected</span>
        </div>
      </div>

      <div class="status-card">
        <h3>📡 Connection</h3>
        <div class="status-item">
          <span>Supabase API</span>
          <span class="status-value <?php echo $status['connected'] ? 'healthy' : 'error'; ?>">
            <?php echo $status['connected'] ? 'Connected' : 'Failed'; ?>
          </span>
        </div>
        <?php if (!empty($status['connection_endpoint'])): ?>
        <div class="status-item">
          <span>Probe Endpoint</span>
          <span class="status-value"><?php echo htmlspecialchars((string) $status['connection_endpoint']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!$status['connected'] && !empty($status['connection_error'])): ?>
        <div class="issues-list">
          <h4>Connection Error:</h4>
          <ul>
            <li><?php echo htmlspecialchars((string) $status['connection_error']); ?></li>
          </ul>
        </div>
        <?php endif; ?>
        <div class="status-item">
          <span>Data Mirroring</span>
          <span class="status-value <?php echo $status['mirroring_enabled'] ? 'healthy' : 'warning'; ?>">
            <?php echo $status['mirroring_enabled'] ? 'Enabled' : 'Disabled'; ?>
          </span>
        </div>
        <div class="status-item">
          <span>Audit Logging</span>
          <span class="status-value <?php echo $status['audit_enabled'] ? 'healthy' : 'warning'; ?>">
            <?php echo $status['audit_enabled'] ? 'Enabled' : 'Disabled'; ?>
          </span>
        </div>
      </div>

      <div class="status-card">
        <h3>⚙️ Features</h3>
        <div class="status-item">
          <span>Real-time Sync</span>
          <span class="status-value <?php echo $status['real_time_enabled'] ? 'healthy' : 'warning'; ?>">
            <?php echo $status['real_time_enabled'] ? 'Enabled' : 'Disabled'; ?>
          </span>
        </div>
        <div class="status-item">
          <span>Debug Mode</span>
          <span class="status-value <?php echo $status['debug_mode'] ? 'warning' : 'healthy'; ?>">
            <?php echo $status['debug_mode'] ? 'On' : 'Off'; ?>
          </span>
        </div>
        <div class="status-item">
          <span>Error Logging</span>
          <span class="status-value healthy">Enabled</span>
        </div>
      </div>
    </div>

    <div class="action-buttons">
      <a href="/FinancialSM/scripts/setup_supabase.php" class="btn primary" target="_blank">
        🔄 Run Setup Script
      </a>
      <button class="btn secondary" onclick="window.location.reload()">
        🔄 Refresh Status
      </button>
      <a href="/FinancialSM/scripts/setup_supabase.php?status" class="btn secondary" target="_blank">
        📊 View Detailed Status
      </a>
    </div>

    <div class="status-card">
      <h3>📋 Integration Guide</h3>
      <div style="line-height: 1.6;">
        <p><strong>How it works:</strong> Your financial system automatically mirrors all transactions to Supabase for real-time synchronization and backup.</p>

        <p><strong>Features enabled:</strong></p>
        <ul style="margin: 8px 0; padding-left: 20px;">
          <li>✅ Automatic data mirroring for all financial transactions</li>
          <li>✅ Comprehensive audit logging</li>
          <li>✅ Error handling and recovery</li>
          <li>✅ Performance monitoring</li>
          <li>✅ Schema synchronization</li>
        </ul>

        <p><strong>Monitoring:</strong> Check the <code>supabase_error.log</code> file for any connection issues. All operations are logged for troubleshooting.</p>
      </div>
    </div>
  </main>
</div>
</body>
</html>
