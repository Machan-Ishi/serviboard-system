<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/../inc/payroll_mod_functions.php';

finance_bootstrap($pdo);

$message = '';
$error = '';

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    if (!verify_csrf()) {
        $error = 'Invalid request (CSRF failure).';
    } else {
        try {
            $pdo->beginTransaction();
            foreach ($_POST['attendance'] as $empId => $attendance) {
                payroll_save_attendance($pdo, [
                    'employee_id' => (int)$empId,
                    'payroll_period_start' => $start,
                    'payroll_period_end' => $end,
                    'days_worked' => (float)$attendance['days_worked'],
                    'hours_worked' => (float)$attendance['hours_worked'],
                    'overtime_hours' => (float)$attendance['overtime_hours'],
                    'absences' => (float)$attendance['absences'],
                    'late_minutes' => (int)$attendance['late_minutes']
                ]);
            }
            $pdo->commit();
            $message = 'Attendance records saved successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$employees = payroll_get_employees($pdo);
$records = [];
$attendanceData = payroll_get_attendance($pdo, $start, $end);
foreach ($attendanceData as $rec) {
    $records[$rec['employee_id']] = $rec;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Input - ServiBoard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/financial.css">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <main class="content">
        <div class="page-header">
            <h1>Attendance Input</h1>
            <p>Input work data for employees for the selected period.</p>
        </div>
        <a class="back-link" href="/FinancialSM/financial/index.php" style="margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; color: var(--blue); font-size: 13px;">&larr; Back to Financial</a>

        <?php if ($message): ?>
            <div class="status-badge badge-paid"><?= finance_h($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-text"><?= finance_h($error) ?></div>
        <?php endif; ?>

        <section class="section-card">
            <div class="section-head">
                <div class="section-title">
                    <h2>Select Period</h2>
                </div>
            </div>
            <form method="get" class="filter-bar">
                <div class="form-row">
                    <label>Period Start</label>
                    <input type="date" name="start" value="<?= $start ?>">
                </div>
                <div class="form-row">
                    <label>Period End</label>
                    <input type="date" name="end" value="<?= $end ?>">
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit">View Period</button>
                </div>
            </form>
        </section>

        <section class="section-card">
            <div class="table-title">Work Data Entry (<?= $start ?> to <?= $end ?>)</div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_attendance">
                <div class="table-wrap">
                    <table class="notion-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Days Worked</th>
                                <th>Hours Worked</th>
                                <th>Overtime Hours</th>
                                <th>Absences</th>
                                <th>Late Minutes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees)): ?>
                                <tr><td colspan="6" class="muted-cell" style="text-align: center;">No employees found. Please add employees first.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($employees as $emp): ?>
                                <?php 
                                    $rec = $records[$emp['id']] ?? [];
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= finance_h($emp['full_name']) ?></strong><br>
                                        <small><?= $emp['employee_code'] ?></small>
                                    </td>
                                    <td><input type="number" name="attendance[<?= $emp['id'] ?>][days_worked]" step="0.5" value="<?= $rec['days_worked'] ?? 0 ?>" style="width: 80px;"></td>
                                    <td><input type="number" name="attendance[<?= $emp['id'] ?>][hours_worked]" step="0.5" value="<?= $rec['hours_worked'] ?? 0 ?>" style="width: 80px;"></td>
                                    <td><input type="number" name="attendance[<?= $emp['id'] ?>][overtime_hours]" step="0.5" value="<?= $rec['overtime_hours'] ?? 0 ?>" style="width: 80px;"></td>
                                    <td><input type="number" name="attendance[<?= $emp['id'] ?>][absences]" step="0.5" value="<?= $rec['absences'] ?? 0 ?>" style="width: 80px;"></td>
                                    <td><input type="number" name="attendance[<?= $emp['id'] ?>][late_minutes]" step="1" value="<?= $rec['late_minutes'] ?? 0 ?>" style="width: 80px;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($employees)): ?>
                    <div class="form-actions" style="margin-top: 20px;">
                        <button class="btn primary" type="submit">Save Attendance Records</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    </main>
</div>
</body>
</html>
