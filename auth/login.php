<?php
// auth/login.php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $sessionUser = [
        'department' => (string) ($_SESSION['user_department'] ?? ''),
        'role_slug' => (string) ($_SESSION['role'] ?? ''),
        'user_type' => (string) ($_SESSION['user_type'] ?? ''),
    ];
    header('Location: ' . auth_redirect_path_for_user($sessionUser));
    exit;
}

$error = $_SESSION['login_error'] ?? '';
$errorDetail = $_SESSION['login_error_detail'] ?? '';
unset($_SESSION['login_error']);
unset($_SESSION['login_error_detail']);

$timedOut = isset($_GET['timeout']) && $_GET['timeout'] === '1';
if ($timedOut && $error === '') {
    $error = 'Your session has expired.';
    $errorDetail = 'Please sign in again to continue.';
}

// Login Throttling Logic
$loginAllowed = true;
$remainingLockout = 0;
if (isset($_SESSION['login_lockout_until']) && $_SESSION['login_lockout_until'] > time()) {
    $loginAllowed = false;
    $remainingLockout = $_SESSION['login_lockout_until'] - time();
    $error = 'Too many failed attempts.';
    $errorDetail = 'Try again in ' . max(1, $remainingLockout) . ' second(s).';
}

$customLoginView = __DIR__ . '/../user.php';
if (is_file($customLoginView) && filesize($customLoginView) > 0) {
    require $customLoginView;
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - ServiBoard Financial</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body class="auth-body">
    <div class="auth-page">
        <header class="auth-header" aria-label="Top navigation">
            <p class="auth-header-brand">ServiBoard Financial</p>
        </header>

        <section class="auth-hero" aria-label="Login and module overview">
            <div class="auth-hero-content" id="system-overview">
                <p class="auth-hero-eyebrow">ServiBoard Financial</p>
                <h1>Financial Management System</h1>
                <p class="auth-hero-tagline">Secure access to your core financial workflows in one focused workspace.</p>
                <div class="auth-hero-tags" aria-label="Financial module features">
                    <span>Collections</span>
                    <span>Disbursements</span>
                    <span>AR / AP</span>
                    <span>Budget Tools</span>
                </div>
            </div>

            <section class="auth-card<?= $error ? ' auth-card-error' : '' ?>" aria-label="Login panel">
                <div class="auth-card-intro">
                    <p class="auth-card-kicker">Sign In</p>
                    <p class="auth-brand-subtitle">ServiBoard Financial</p>
                    <h3>Continue to your financial workspace</h3>
                </div>

                <?php if ($error): ?>
                    <div class="alert-error" role="alert" aria-live="polite">
                        <p class="alert-error-title"><?= htmlspecialchars($error) ?></p>
                        <?php if ($errorDetail): ?>
                            <p class="alert-error-detail"><?= htmlspecialchars($errorDetail) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= BASE_URL ?>/auth/login_process.php" class="auth-form">
                    <?= csrf_field() ?>
                    <div class="auth-field">
                        <label for="identifier">Username or Email</label>
                        <input id="identifier" type="text" name="identifier" placeholder="Username or email" autocomplete="username" required <?= !$loginAllowed ? 'disabled' : '' ?>>
                    </div>

                    <div class="auth-field">
                        <div class="auth-label-row">
                            <label for="password">Password</label>
                            <a class="auth-inline-link" href="#account-support">Forgot?</a>
                        </div>
                        <div class="auth-password-wrap">
                            <input id="password" type="password" name="password" placeholder="Password" autocomplete="current-password" required <?= !$loginAllowed ? 'disabled' : '' ?>>
                            <button
                                type="button"
                                class="auth-password-toggle"
                                id="password-toggle"
                                aria-controls="password"
                                aria-label="Show password"
                                <?= !$loginAllowed ? 'disabled' : '' ?>
                            >
                                Show
                            </button>
                        </div>
                    </div>

                    <button id="login-btn" type="submit" class="auth-submit" <?= !$loginAllowed ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                        <span class="auth-submit-label"><?= $loginAllowed ? 'Sign In' : 'Locked (' . $remainingLockout . 's)' ?></span>
                        <span class="auth-submit-spinner" aria-hidden="true"></span>
                    </button>
                </form>
                <div class="auth-helper" id="account-support">
                    <p class="auth-helper-text">Need access help? Contact your administrator.</p>
                </div>
            </section>
        </section>
        <footer class="auth-footer">
            <p>ServiBoard Financial | v1.0</p>
        </footer>
    </div>

    <script>
        (function() {
            const form = document.querySelector('.auth-form');
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.getElementById('password-toggle');
            const loginBtn = document.getElementById('login-btn');
            const submitLabel = loginBtn ? loginBtn.querySelector('.auth-submit-label') : null;

            if (passwordInput && passwordToggle) {
                passwordToggle.addEventListener('click', function() {
                    const showingPassword = passwordInput.type === 'text';
                    passwordInput.type = showingPassword ? 'password' : 'text';
                    passwordToggle.textContent = showingPassword ? 'Show' : 'Hide';
                    passwordToggle.setAttribute('aria-label', showingPassword ? 'Show password' : 'Hide password');
                });
            }

            if (form && loginBtn && submitLabel && !loginBtn.disabled) {
                form.addEventListener('submit', function() {
                    loginBtn.disabled = true;
                    loginBtn.classList.add('is-loading');
                    submitLabel.textContent = 'Signing in...';
                    if (passwordToggle) {
                        passwordToggle.disabled = true;
                    }
                });
            }
        })();
    </script>

    <?php if (!$loginAllowed): ?>
    <script>
        (function() {
            let seconds = <?= $remainingLockout ?>;
            const btn = document.getElementById('login-btn');
            const identifier = document.getElementById('identifier');
            const password = document.getElementById('password');
            const passwordToggle = document.getElementById('password-toggle');
            const errorDetail = document.querySelector('.alert-error-detail');
            
            const timer = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(timer);
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                    const submitLabel = btn.querySelector('.auth-submit-label');
                    if (submitLabel) {
                        submitLabel.textContent = 'Sign In';
                    }
                    identifier.disabled = false;
                    password.disabled = false;
                    if (passwordToggle) {
                        passwordToggle.disabled = false;
                    }
                    if (errorDetail) {
                        errorDetail.textContent = 'You can try signing in again now.';
                    }
                } else {
                    const submitLabel = btn.querySelector('.auth-submit-label');
                    if (submitLabel) {
                        submitLabel.textContent = `Locked (${seconds}s)`;
                    }
                    if (errorDetail) {
                        errorDetail.textContent = `Try again in ${seconds} second(s).`;
                    }
                }
            }, 1000);
        })();
    </script>
    <?php endif; ?>
</body>
</html>
