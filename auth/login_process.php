<?php
declare(strict_types=1);

ob_start();
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth_helpers.php';

function login_redirect_to_form(): void
{
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

function login_set_feedback(string $message, ?string $detail = null): void
{
    $_SESSION['login_error'] = $message;
    $_SESSION['login_error_detail'] = $detail;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    login_redirect_to_form();
}

if (!verify_csrf()) {
    login_set_feedback('Invalid request.', 'Please refresh the page and try again.');
    login_redirect_to_form();
}

$identifier = trim((string) ($_POST['identifier'] ?? $_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    login_set_feedback('Username or email and password are required.');
    login_redirect_to_form();
}

if (isset($_SESSION['login_lockout_until']) && $_SESSION['login_lockout_until'] > time()) {
    $secondsRemaining = (int) ($_SESSION['login_lockout_until'] - time());
    login_set_feedback(
        'Too many failed attempts.',
        'Try again in ' . max(1, $secondsRemaining) . ' second(s).'
    );
    login_redirect_to_form();
}

try {
    $user = auth_fetch_system_user($identifier);
} catch (Throwable $e) {
    login_set_feedback('Unable to access the account directory right now.');
    login_redirect_to_form();
}

if (!$user || !auth_verify_system_user_password($user, $password)) {
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempts'] = $attempts;

    if ($attempts >= 5) {
        $_SESSION['login_lockout_until'] = time() + 60;
        $_SESSION['login_attempts'] = 0;
        login_set_feedback('Too many failed attempts.', 'Login locked for 1 minute.');
    } else {
        login_set_feedback('Invalid username or password.', 'Attempt ' . $attempts . ' of 5');
    }

    login_redirect_to_form();
}

try {
    auth_upgrade_legacy_password_if_needed($user, $password);
} catch (Throwable) {
    // Keep the login successful even if the legacy password upgrade cannot be persisted.
}

unset(
    $_SESSION['login_attempts'],
    $_SESSION['login_lockout_until'],
    $_SESSION['login_error'],
    $_SESSION['login_error_detail']
);
session_regenerate_id(true);
auth_store_user_session($user);
$_SESSION['last_activity_at'] = time();

header('Location: ' . ($_SESSION['login_redirect'] ?? (BASE_URL . '/financial/index.php')));
exit;
