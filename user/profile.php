<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_user.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/user_portal.php';

$activePage = 'profile.php';
$userId = (int)($_SESSION['user_id'] ?? 0);
$portalError = '';
$success = '';
$errors = [];

try {
    $pdo = db();
    $ctx = portal_resolve_client($pdo, $userId, (string)($_SESSION['user_name'] ?? 'User'));
    $clientId = (int)$ctx['client_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_contact') {
            if ($clientId <= 0) {
                $errors[] = 'No linked client profile found.';
            } else {
                $phone = trim((string)($_POST['phone'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $update = $pdo->prepare('UPDATE public.ar_ap SET description = ? WHERE entry_type = \'AR\' AND user_id = ?');
                $update->execute(["Phone: " . $phone . "\nAddress: " . $address, $userId]);
                $_SESSION['profile_flash'] = 'Contact information updated.';
                header('Location: /FinancialSM/user/profile.php');
                exit;
            }
        }

        if ($action === 'change_password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $errors[] = 'All password fields are required.';
            } elseif (strlen($newPassword) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'New password and confirmation do not match.';
            } else {
                $pwStmt = $pdo->prepare('SELECT password_hash FROM public.users WHERE id = ? LIMIT 1');
                $pwStmt->execute([$userId]);
                $currentHash = (string)($pwStmt->fetchColumn() ?: '');

                if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updatePw = $pdo->prepare('UPDATE public.users SET password_hash = ? WHERE id = ?');
                    $updatePw->execute([$newHash, $userId]);
                    $_SESSION['profile_flash'] = 'Password updated successfully.';
                    header('Location: /FinancialSM/user/profile.php');
                    exit;
                }
            }
        }
    }

    if (!portal_has_column($pdo, 'users', 'email')) {
        $ctx['user_email'] = '';
    }

    $success = (string)($_SESSION['profile_flash'] ?? '');
    unset($_SESSION['profile_flash']);
} catch (Throwable $e) {
    $portalError = 'Unable to load profile right now.';
    $ctx = [
        'user_name' => (string)($_SESSION['user_name'] ?? 'User'),
        'user_email' => '',
        'client_id' => 0,
        'company' => '',
        'phone' => '',
        'address' => '',
    ];
}

$topbarSearchPlaceholder = 'Search account info...';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - ServiBoard</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar_user.php'; ?>

    <main class="content" role="main">
        <?php include __DIR__ . '/../includes/header_user.php'; ?>

        <div class="page-header">
            <h1>My Profile</h1>
            <p>View account information and manage basic security settings.</p>
        </div>

        <?php if ($portalError !== ''): ?>
            <section class="section-card"><div class="alert-inline"><?= h($portalError) ?></div></section>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <section class="section-card"><div class="success-inline"><?= h($success) ?></div></section>
        <?php endif; ?>

        <?php if ($errors): ?>
            <section class="section-card"><div class="alert-inline"><?= h(implode(' ', $errors)) ?></div></section>
        <?php endif; ?>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Account Details</h3>
                <span>Client portal profile</span>
            </div>
            <div class="user-profile-grid">
                <div class="stat">
                    <h4>Name</h4>
                    <div class="value"><?= h((string)$ctx['user_name']) ?></div>
                </div>
                <div class="stat">
                    <h4>Email</h4>
                    <div class="value"><?= h((string)($ctx['user_email'] ?: 'N/A')) ?></div>
                </div>
                <div class="stat">
                    <h4>Client ID</h4>
                    <div class="value"><?= (int)($ctx['client_id'] ?? 0) > 0 ? (int)$ctx['client_id'] : 'N/A' ?></div>
                </div>
                <div class="stat">
                    <h4>Company</h4>
                    <div class="value"><?= h((string)($ctx['company'] ?: 'N/A')) ?></div>
                </div>
                <div class="stat">
                    <h4>Phone</h4>
                    <div class="value"><?= h((string)($ctx['phone'] ?: 'N/A')) ?></div>
                </div>
                <div class="stat">
                    <h4>Address</h4>
                    <div class="value"><?= h((string)($ctx['address'] ?: 'N/A')) ?></div>
                </div>
            </div>
        </section>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Update Contact Information</h3>
                <span>Optional</span>
            </div>
            <form method="post" class="form-card user-form-card">
                <input type="hidden" name="action" value="update_contact">
                <div class="form-row split">
                    <div>
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="text" value="<?= h((string)($ctx['phone'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="address">Address</label>
                        <input id="address" name="address" type="text" value="<?= h((string)($ctx['address'] ?? '')) ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn primary">Save Contact Info</button>
                </div>
            </form>
        </section>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Change Password</h3>
                <span>Security</span>
            </div>
            <form method="post" class="form-card user-form-card">
                <input type="hidden" name="action" value="change_password">
                <div class="form-row split">
                    <div>
                        <label for="currentPassword">Current Password</label>
                        <input id="currentPassword" name="current_password" type="password" required>
                    </div>
                    <div>
                        <label for="newPassword">New Password</label>
                        <input id="newPassword" name="new_password" type="password" minlength="8" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="confirmPassword">Confirm New Password</label>
                    <input id="confirmPassword" name="confirm_password" type="password" minlength="8" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn primary">Update Password</button>
                </div>
            </form>
        </section>
    </main>
</div>
</body>
</html>
