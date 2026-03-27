<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_user.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/user_portal.php';

require_once __DIR__ . '/../inc/functions.php';

$activePage = 'requests.php';
$userId = (int)($_SESSION['user_id'] ?? 0);
$portalError = '';
$success = '';
$errors = [];
$requests = [];
$hasAttachmentColumn = false;

$form = [
    'subject' => '',
    'request_type' => 'Account Concern',
    'message' => '',
];

function portal_store_request_attachment(array $file): ?string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Attachment type is not allowed.');
    }

    $uploadDir = dirname(__DIR__) . '/uploads/client_requests';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to prepare the attachment folder.');
    }

    $token = bin2hex(random_bytes(8));
    $fileName = date('YmdHis') . '-' . $token . '.' . $extension;
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $targetPath)) {
        throw new RuntimeException('Unable to save the uploaded attachment.');
    }

    return '/FinancialSM/uploads/client_requests/' . $fileName;
}

try {
    $pdo = db();
    $ctx = portal_resolve_client($pdo, $userId, (string)($_SESSION['user_name'] ?? 'User'));
    $clientId = (int)$ctx['client_id'];

    if ($clientId <= 0 && $ctx['party_name'] === 'User') {
        $portalError = 'Your account is not yet linked to a client record.';
    } else {
        $hasAttachmentColumn = portal_has_column($pdo, 'client_requests', 'attachment_path');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!verify_csrf()) {
                $errors[] = 'Invalid request (CSRF failure).';
            } else {
                $form['subject'] = trim((string)($_POST['subject'] ?? ''));
                $form['request_type'] = trim((string)($_POST['request_type'] ?? 'Account Concern'));
                $form['message'] = trim((string)($_POST['message'] ?? ''));

                if ($form['subject'] === '') {
                    $errors[] = 'Subject is required.';
                }
                if ($form['message'] === '') {
                    $errors[] = 'Message is required.';
                }

                $attachmentPath = null;
                $attachmentUploaded = (int) (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_NO_FILE;
                if ($attachmentUploaded && !$hasAttachmentColumn) {
                    $errors[] = 'Attachment storage is not configured for client requests.';
                }
                if (!$errors && $hasAttachmentColumn) {
                    $attachmentPath = portal_store_request_attachment($_FILES['attachment'] ?? []);
                }

                if (!$errors) {
                    $requestNo = 'REQ-' . date('YmdHis') . '-' . rand(1000, 9999);
                    $description = "Subject: " . $form['subject'] . "\n\n" . $form['message'];

                    if ($hasAttachmentColumn) {
                        $insert = $pdo->prepare('INSERT INTO public.client_requests (request_no, requester_name, request_type, description, request_date, status, attachment_path) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?)');
                        $insert->execute([
                            $requestNo,
                            $ctx['party_name'],
                            $form['request_type'],
                            $description,
                            'Pending',
                            $attachmentPath,
                        ]);
                    } else {
                        $insert = $pdo->prepare('INSERT INTO public.client_requests (request_no, requester_name, request_type, description, request_date, status) VALUES (?, ?, ?, ?, CURRENT_DATE, ?)');
                        $insert->execute([
                            $requestNo,
                            $ctx['party_name'],
                            $form['request_type'],
                            $description,
                            'Pending',
                        ]);
                    }

                    $_SESSION['request_flash'] = 'Your request has been submitted successfully.';
                    header('Location: /FinancialSM/user/requests.php');
                    exit;
                }
            }
        }
    }

    $success = (string)($_SESSION['request_flash'] ?? '');
    unset($_SESSION['request_flash']);

    $attachmentSelect = $hasAttachmentColumn ? 'attachment_path' : 'NULL as attachment_path';
    $listStmt = $pdo->prepare("SELECT id, request_no as subject, request_type, description as message, {$attachmentSelect}, remarks as admin_response, status, created_at FROM public.client_requests WHERE requester_name = ? ORDER BY created_at DESC, id DESC");
    $listStmt->execute([$ctx['party_name']]);
    $requests = $listStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $portalError = 'Unable to load requests right now.';
}

$topbarSearchPlaceholder = 'Search request details...';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests - ServiBoard</title>
    <link rel="stylesheet" href="/FinancialSM/assets/css/app.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../includes/sidebar_user.php'; ?>

    <main class="content" role="main">
        <?php include __DIR__ . '/../includes/header_user.php'; ?>

        <div class="page-header">
            <h1>My Requests</h1>
            <p>Submit and track your financial support requests.</p>
        </div>

        <?php if ($portalError !== ''): ?>
            <section class="section-card"><div class="alert-inline"><?= h($portalError) ?></div></section>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <section class="section-card"><div class="success-inline"><?= h($success) ?></div></section>
        <?php endif; ?>

        <?php if ($errors): ?>
            <section class="section-card">
                <div class="alert-inline"><?= h(implode(' ', $errors)) ?></div>
            </section>
        <?php endif; ?>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Submit New Request</h3>
                <span>Authorized users only</span>
            </div>

            <form method="post" enctype="multipart/form-data" class="form-card user-form-card">
                <?= csrf_field() ?>
                <div class="form-row">
                    <label for="subject">Subject</label>
                    <input id="subject" name="subject" type="text" maxlength="180" required value="<?= h($form['subject']) ?>">
                </div>
                <div class="form-row">
                    <label for="requestType">Request Type</label>
                    <select id="requestType" name="request_type" required>
                        <?php
                        $types = ['Payment Verification', 'Receipt Request', 'Invoice Clarification', 'Account Concern', 'Billing Inquiry'];
                        foreach ($types as $type):
                        ?>
                            <option value="<?= h($type) ?>" <?= $form['request_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="4" required><?= h($form['message']) ?></textarea>
                </div>
                <div class="form-row">
                    <label for="attachment">Attachment (optional)</label>
                    <input id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit">Submit Request</button>
                </div>
            </form>
        </section>

        <section class="section-card">
            <div class="sub-head">
                <h3 class="heading-reset">Request History</h3>
                <span><?= number_format(count($requests)) ?> records</span>
            </div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Attachment</th>
                        <th>Admin Response</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                        <?php if (!$requests): ?>
                            <tr><td colspan="7">No requests available.</td></tr>
                        <?php else: ?>
                            <?php foreach ($requests as $row): ?>
                                <tr>
                                    <td><?= h((string)$row['created_at']) ?></td>
                                    <td><?= h((string)$row['subject']) ?></td>
                                    <td><?= h((string)$row['request_type']) ?></td>
                                    <td><?= h((string)$row['message']) ?></td>
                                    <td>
                                        <?php if (!empty($row['attachment_path'])): ?>
                                            <a class="btn-link" href="<?= h((string)$row['attachment_path']) ?>" target="_blank" rel="noopener">View File</a>
                                        <?php else: ?>
                                            <span class="muted-cell">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['admin_response'])): ?>
                                            <?= h((string)$row['admin_response']) ?>
                                        <?php else: ?>
                                            <span class="muted-cell">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-badge <?= h(portal_status_badge((string)$row['status'])) ?>"><?= h((string)$row['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
