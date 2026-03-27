<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/finance_functions.php';
require_once __DIR__ . '/../inc/payroll_mod_functions.php';

finance_bootstrap($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request (CSRF failure).';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'delete_employee') {
            payroll_delete_employee($pdo, (int)($_POST['id'] ?? 0));
            $message = 'Employee record deleted.';
            header('Location: employees.php?msg=' . urlencode($message));
            exit;
        }

        if ($action === 'save_employee') {
            try {
                $data = [
                    'id' => (int)($_POST['id'] ?? 0),
                    'employee_code' => trim((string)($_POST['employee_code'] ?? '')),
                    'full_name' => trim((string)($_POST['full_name'] ?? '')),
                    'department' => trim((string)($_POST['department'] ?? '')),
                    'position' => trim((string)($_POST['position'] ?? '')),
                    'pay_type' => trim((string)($_POST['pay_type'] ?? 'monthly')),
                    'basic_salary' => (float)($_POST['basic_salary'] ?? 0),
                    'allowance' => (float)($_POST['allowance'] ?? 0),
                    'deduction_default' => (float)($_POST['deduction_default'] ?? 0),
                    'payment_method' => trim((string)($_POST['payment_method'] ?? 'Bank Transfer')),
                    'status' => trim((string)($_POST['status'] ?? 'Active'))
                ];

                if ($data['full_name'] === '' || $data['employee_code'] === '') {
                    throw new Exception('Employee code and full name are required.');
                }

                payroll_save_employee($pdo, $data);
                $message = $data['id'] > 0 ? 'Employee updated.' : 'Employee created.';
                header('Location: employees.php?msg=' . urlencode($message));
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$employees = payroll_get_employees($pdo);
$supabaseEmployees = payroll_get_employees_from_supabase();

// Add search filtering
$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
    $employees = array_filter($employees, function($e) use ($search) {
        return stripos($e['full_name'], $search) !== false || 
               stripos($e['employee_code'], $search) !== false ||
               stripos($e['department'], $search) !== false;
    });
}

// Calculate stats for the dashboard
$totalEmployees = count($employees);
$activeEmployees = count(array_filter($employees, fn($e) => $e['status'] === 'Active'));
$avgSalary = $totalEmployees > 0 ? array_sum(array_column($employees, 'basic_salary')) / $totalEmployees : 0;
$totalAllowance = array_sum(array_column($employees, 'allowance'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employee Management - ServiBoard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/financial.css">
    <style>
        .budget-page-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
        .summary-card { background: var(--card-bg); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow-soft); }
        .summary-label { display: block; font-size: 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
        .summary-value { display: block; font-size: 1.25rem; font-weight: 700; color: var(--text); }
        .budget-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .budget-panel { background: var(--card-bg); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow-soft); }
        .form-row { margin-bottom: 1.25rem; }
        .form-row.split { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-row label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem; color: var(--muted); }
        .form-row input, .form-row select, .form-row textarea { 
            width: 100%; 
            padding: 0.75rem; 
            background: var(--stat-bg); 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            color: var(--text);
            font-size: 0.9rem;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .form-row input:focus, .form-row select:focus {
            border-color: var(--gold);
            background: var(--main-bg);
            box-shadow: 0 0 0 2px rgba(240, 175, 28, 0.1);
            outline: none;
        }
        .error-text { background: rgba(217, 48, 37, 0.1); color: #f28b82; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid rgba(217, 48, 37, 0.2); }
    </style>
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <main class="content">
        <div class="page-header">
            <h1>Employee Management</h1>
            <p>Manage your organization's workforce profiles.</p>
        </div>
        <a class="back-link" href="/FinancialSM/financial/index.php" style="margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; color: var(--blue); font-size: 13px;">&larr; Back to Financial</a>

        <div style="margin-bottom: 20px;">
            <button class="btn primary" onclick="showAddForm()">Add New Employee</button>
        </div>

        <div class="budget-page-summary" style="margin-bottom: 30px;">
            <article class="metric-card">
                <div>
                    <span class="metric-label">Total Employees</span>
                    <strong class="metric-value"><?= $totalEmployees ?></strong>
                </div>
            </article>
            <article class="metric-card">
                <div>
                    <span class="metric-label">Active Employees</span>
                    <strong class="metric-value"><?= $activeEmployees ?></strong>
                </div>
            </article>
            <article class="metric-card">
                <div>
                    <span class="metric-label">Avg. Basic Salary</span>
                    <strong class="metric-value">PHP <?= finance_money($avgSalary) ?></strong>
                </div>
            </article>
            <article class="metric-card">
                <div>
                    <span class="metric-label">Monthly Allowance</span>
                    <strong class="metric-value">PHP <?= finance_money($totalAllowance) ?></strong>
                </div>
            </article>
        </div>

        <?php if ($message || isset($_GET['msg'])): ?>
            <div class="status-badge badge-paid"><?= finance_h($message ?: $_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-text"><?= finance_h($error) ?></div>
        <?php endif; ?>

        <section class="section-card" id="employee_form_section">
            <div class="section-head">
                <div class="section-icon">👤</div>
                <div class="section-title">
                    <h2 id="form_title">Employee Profile</h2>
                    <p id="form_subtitle">Create or update organizational workforce records.</p>
                </div>
            </div>
            <form method="post" class="budget-form-grid" style="margin-top: 10px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_employee">
                <input type="hidden" name="id" id="form_id" value="0">
                <div class="budget-panel">
                    <div class="form-row">
                        <label for="employee_code">Employee Code</label>
                        <input type="text" id="employee_code" name="employee_code" required placeholder="e.g. EMP-001">
                    </div>
                    <div class="form-row">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="Enter full name">
                    </div>
                    <div class="form-row">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" placeholder="e.g. IT, HR, Finance">
                    </div>
                    <div class="form-row">
                        <label for="position">Position</label>
                        <input type="text" id="position" name="position" placeholder="e.g. Developer">
                    </div>
                </div>
                <div class="budget-panel">
                    <div class="form-row split">
                        <div>
                            <label for="pay_type">Pay Type</label>
                            <select id="pay_type" name="pay_type">
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                                <option value="hourly">Hourly</option>
                            </select>
                        </div>
                        <div>
                            <label for="basic_salary">Basic Salary</label>
                            <input type="number" id="basic_salary" name="basic_salary" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row split">
                        <div>
                            <label for="allowance">Allowance</label>
                            <input type="number" id="allowance" name="allowance" step="0.01" value="0">
                        </div>
                        <div>
                            <label for="deduction_default">Default Deduction</label>
                            <input type="number" id="deduction_default" name="deduction_default" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="Active">Active</option>
                            <option value="Deactivated">Deactivated</option>
                        </select>
                    </div>
                    <div class="form-actions" style="margin-top: 10px; display: flex; flex-direction: column; gap: 10px;">
                        <button class="btn primary" type="submit" id="submit_btn" style="width: 100%; padding: 12px; font-weight: 600; font-size: 1rem;">Save Employee Profile</button>
                        <button class="btn subtle" type="button" id="cancel_btn" style="display:none; width: 100%;">Cancel Editing</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="section-card">
            <div class="section-head" style="margin-bottom: 10px;">
                <div class="section-icon">📋</div>
                <div class="section-title">
                    <h2>Employee Directory</h2>
                    <p>Search and manage existing organizational records.</p>
                </div>
            </div>
            <div class="filter-bar" style="margin-bottom: 20px;">
                <form method="get" style="display: flex; gap: 12px; width: 100%; background: var(--stat-bg); padding: 15px; border-radius: 12px; border: 1px solid var(--border);">
                    <div class="form-row" style="flex-grow: 1; margin-bottom: 0;">
                        <input type="text" name="search" placeholder="Search by name, code, or department..." value="<?= finance_h($search) ?>" style="width: 100%; background: var(--main-bg);">
                    </div>
                    <div class="form-actions" style="margin-top: 0; display: flex; gap: 8px;">
                        <button class="btn primary" type="submit" style="padding: 0 20px;">Search</button>
                        <?php if ($search): ?>
                            <a href="employees.php" class="btn subtle" style="display: flex; align-items: center; justify-content: center; padding: 0 15px;">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="table-wrap">
                <table class="notion-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Full Name</th>
                            <th>Department</th>
                            <th>Pay Type</th>
                            <th>Basic Salary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr><td colspan="7" class="muted-cell" style="text-align: center;">No employees found. Add your first employee using the form above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?= finance_h($emp['employee_code']) ?></td>
                                <td><?= finance_h($emp['full_name']) ?></td>
                                <td><?= finance_h($emp['department']) ?></td>
                                <td><?= finance_h($emp['pay_type']) ?></td>
                                <td>PHP <?= finance_money($emp['basic_salary']) ?></td>
                                <td><span class="status-badge <?= $emp['status'] === 'Active' ? 'badge-paid' : 'badge-cancelled' ?>"><?= $emp['status'] ?></span></td>
                                <td class="table-actions">
                                    <button class="btn-link primary" onclick='editEmployee(<?= json_encode($emp) ?>)'>Edit</button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this employee permanently?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_employee">
                                        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                        <button type="submit" class="btn-link danger">Delete</button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Change status for this employee?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="save_employee">
                                        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                        <input type="hidden" name="employee_code" value="<?= finance_h($emp['employee_code']) ?>">
                                        <input type="hidden" name="full_name" value="<?= finance_h($emp['full_name']) ?>">
                                        <input type="hidden" name="status" value="<?= $emp['status'] === 'Active' ? 'Deactivated' : 'Active' ?>">
                                        <button type="submit" class="btn-link <?= $emp['status'] === 'Active' ? 'warning' : 'success' ?>">
                                            <?= $emp['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Supabase Cloud View Section -->
        <section class="section-card" style="margin-top: 40px; border-top: 4px solid #3ecf8e; background: #f9fbf9;">
            <div class="section-head">
                <div class="section-icon" style="background: #3ecf8e;">☁</div>
                <div class="section-title">
                    <h2 style="color: #065f46;">Supabase Payroll Cloud View</h2>
                    <p>Live view of employee records currently synchronized in your Supabase cloud database.</p>
                </div>
                <div style="margin-left: auto;">
                    <span class="status-badge" style="background: #d1fae5; color: #065f46; font-weight: bold; border: 1px solid #3ecf8e;">
                        LIVE SYNC ACTIVE
                    </span>
                </div>
            </div>
            
            <div class="table-wrap">
                <table class="notion-table">
                    <thead style="background: #ecfdf5;">
                        <tr>
                            <th>Cloud ID</th>
                            <th>Employee Name</th>
                            <th>Email</th>
                            <th>Basic Salary</th>
                            <th>Role</th>
                            <th>Last Sync</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supabaseEmployees)): ?>
                            <tr><td colspan="6" class="muted-cell" style="text-align: center; padding: 40px;">
                                <div style="font-size: 24px; margin-bottom: 10px;">📡</div>
                                No records found in Supabase. New entries will appear here once saved.
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($supabaseEmployees as $cloudEmp): ?>
                            <tr>
                                <td style="font-family: monospace; font-size: 11px; color: #666;">
                                    <?= finance_h((string)($cloudEmp['id'] ?? 'N/A')) ?>
                                </td>
                                <td style="font-weight: 600; color: #111;">
                                    <?= finance_h((string)($cloudEmp['name'] ?? 'Unknown')) ?>
                                </td>
                                <td><?= finance_h((string)($cloudEmp['email'] ?? '-')) ?></td>
                                <td style="font-weight: 600; color: #065f46;">
                                    PHP <?= finance_money($cloudEmp['basic_salary'] ?? 0) ?>
                                </td>
                                <td>
                                    <span class="status-badge" style="background: #f3f4f6; color: #374151; text-transform: capitalize;">
                                        <?= finance_h((string)($cloudEmp['role'] ?? 'employee')) ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; color: #6b7280;">
                                    <?= isset($cloudEmp['created_at']) ? date('M d, Y H:i', strtotime($cloudEmp['created_at'])) : 'Recently' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 15px; font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 5px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                This table is read-only and reflects the data stored in your Supabase project.
            </div>
        </section>
    </main>
</div>

<script>
function showAddForm() {
    document.querySelector('form').reset();
    document.getElementById('form_id').value = '0';
    document.getElementById('form_title').textContent = 'Add New Employee';
    document.getElementById('submit_btn').textContent = 'Save Employee';
    document.getElementById('cancel_btn').style.display = 'none';
    
    document.getElementById('employee_form_section').scrollIntoView({ behavior: 'smooth' });
}

function editEmployee(emp) {
    document.getElementById('form_id').value = emp.id;
    document.getElementById('employee_code').value = emp.employee_code;
    document.getElementById('full_name').value = emp.full_name;
    document.getElementById('department').value = emp.department;
    document.getElementById('position').value = emp.position;
    document.getElementById('pay_type').value = emp.pay_type;
    document.getElementById('basic_salary').value = emp.basic_salary;
    document.getElementById('allowance').value = emp.allowance;
    document.getElementById('deduction_default').value = emp.deduction_default;
    document.getElementById('status').value = emp.status;
    
    document.getElementById('form_title').textContent = 'Edit Employee: ' + emp.full_name;
    document.getElementById('submit_btn').textContent = 'Update Employee';
    document.getElementById('cancel_btn').style.display = 'inline-block';
    
    document.getElementById('employee_form_section').scrollIntoView({ behavior: 'smooth' });
}

document.getElementById('cancel_btn').addEventListener('click', function() {
    document.querySelector('form').reset();
    document.getElementById('form_id').value = '0';
    document.getElementById('form_title').textContent = 'Add/Edit Employee';
    document.getElementById('submit_btn').textContent = 'Save Employee';
    this.style.display = 'none';
});
</script>
</body>
</html>
