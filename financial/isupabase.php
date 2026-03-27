<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/supabase.php';

$isConfigured = supabase_is_configured();
$connectionStatus = 'Disconnected';
$error = null;

if ($isConfigured) {
    // Test general API connectivity first
    try {
        if (supabase_test_connection()) {
            $connectionStatus = 'Connected';
        } else {
            // If root fails, try checking a table as fallback
            $test = supabase_get('collection', ['limit' => 1]);
            if ($test !== null) {
                $connectionStatus = 'Connected';
            } else {
                $connectionStatus = 'Connection Failed';
                $error = $lastSupabaseError ?? 'Check your API key and URL permissions.';
            }
        }
    } catch (Throwable $e) {
        $connectionStatus = 'Error';
        $error = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Supabase Integration - ServiBoard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/financial.css">
    <style>
        .status-card {
            padding: 24px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
        }
        .status-connected { background: #e6f4ea; color: #1e7e34; }
        .status-disconnected { background: #fce8e6; color: #d93025; }
        .status-pending { background: #fef7e0; color: #b05500; }
        
        .config-details {
            margin-top: 20px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .config-item {
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .config-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .config-value {
            font-family: monospace;
            word-break: break-all;
            color: #0f172a;
        }
    </style>
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <main class="content">
        <div class="page-header">
            <h1>Supabase Integration</h1>
            <p>Manage and monitor your cloud database connection status.</p>
        </div>
        <a class="back-link" href="/FinancialSM/financial/index.php">&larr; Back to Financial</a>

        <section class="section-card">
            <div class="section-head">
                <div class="section-icon">☁️</div>
                <div class="section-title">
                    <h2>Connection Status</h2>
                </div>
            </div>
            
            <div class="status-card">
                <div class="status-indicator <?= $connectionStatus === 'Connected' ? 'status-connected' : 'status-disconnected' ?>">
                    <span class="dot"></span>
                    <?= $connectionStatus ?>
                </div>

                <?php if ($error): ?>
                    <div class="error-text" style="margin-top: 16px;">
                        <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="config-details">
                    <div class="config-item">
                        <div class="config-label">Supabase URL</div>
                        <div class="config-value"><?= htmlspecialchars($supabaseUrl) ?></div>
                    </div>
                    <div class="config-item">
                        <div class="config-label">API Key</div>
                        <div class="config-value">••••••••<?= substr($supabaseKey, -8) ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-card">
            <div class="section-head">
                <div class="section-icon">⚙️</div>
                <div class="section-title">
                    <h2>How it works</h2>
                </div>
            </div>
            <p>This module uses the <strong>Supabase REST API</strong> to synchronize financial data with your cloud instance. When disbursements or collections are recorded locally, they are mirrored to Supabase for real-time reporting.</p>
            <ul style="margin-top: 16px; padding-left: 20px; color: #64748b; line-height: 1.6;">
                <li>Automatic background synchronization</li>
                <li>Secure JWT-based authentication</li>
                <li>Real-time database mirroring</li>
            </ul>
        </section>
    </main>
</div>
</body>
</html>
