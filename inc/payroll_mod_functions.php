<?php
declare(strict_types=1);

/**
 * Payroll Module Helper Functions
 */

function payroll_get_employees(PDO $pdo): array
{
    finance_bootstrap($pdo);
    try {
        $stmt = $pdo->query("SELECT * FROM public.employees ORDER BY name ASC");
        $rows = $stmt->fetchAll() ?: [];
        // Map 'name' back to 'full_name' for UI compatibility if needed
        return array_map(function($r) {
            if (!isset($r['full_name']) && isset($r['name'])) {
                $r['full_name'] = $r['name'];
            }
            return $r;
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function payroll_get_employee(PDO $pdo, int $id): ?array
{
    finance_bootstrap($pdo);
    $stmt = $pdo->prepare("SELECT * FROM public.employees WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: null;
    if ($row && !isset($row['full_name']) && isset($row['name'])) {
        $row['full_name'] = $row['name'];
    }
    return $row;
}

function payroll_get_employees_from_supabase(): array
{
    $client = supabase_init();
    if (!$client) return [];
    
    $res = $client->get('employees', ['order' => 'name.asc']);
    if ($res['status'] === 200) {
        return $res['data'] ?: [];
    }
    return [];
}

function payroll_save_employee(PDO $pdo, array $data): int
{
    $id = (int)($data['id'] ?? 0);
    
    // Ensure the employees table exists and has all columns
    finance_bootstrap($pdo);

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE public.employees 
            SET name = ?, employee_code = ?, department = ?, position = ?, 
                pay_type = ?, basic_salary = ?, allowance = ?, deduction_default = ?, 
                payment_method = ?, status = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([
            $data['full_name'], $data['employee_code'], $data['department'], $data['position'],
            $data['pay_type'], $data['basic_salary'], $data['allowance'], $data['deduction_default'],
            $data['payment_method'], $data['status'], $id
        ]);
        
        // Mirror to Supabase REST API
        $payload = [
            'id' => $id,
            'name' => $data['full_name'],
            'employee_code' => $data['employee_code'],
            'department' => $data['department'],
            'position' => $data['position'],
            'pay_type' => $data['pay_type'],
            'basic_salary' => $data['basic_salary'],
            'allowance' => $data['allowance'],
            'deduction_default' => $data['deduction_default'],
            'payment_method' => $data['payment_method'],
            'status' => $data['status']
        ];
        
        supabase_mirror('employees', $payload, 'UPDATE', ['id' => $id]);
        
        return $id;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO public.employees (
                name, employee_code, department, position, 
                pay_type, basic_salary, allowance, deduction_default, 
                payment_method, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['full_name'], $data['employee_code'], $data['department'], $data['position'],
            $data['pay_type'], $data['basic_salary'], $data['allowance'], $data['deduction_default'],
            $data['payment_method'], $data['status']
        ]);
        $id = (int)$pdo->lastInsertId();
        
        // Mirror to Supabase REST API
        $payload = [
            'id' => $id,
            'name' => $data['full_name'],
            'employee_code' => $data['employee_code'],
            'department' => $data['department'],
            'position' => $data['position'],
            'pay_type' => $data['pay_type'],
            'basic_salary' => $data['basic_salary'],
            'allowance' => $data['allowance'],
            'deduction_default' => $data['deduction_default'],
            'payment_method' => $data['payment_method'],
            'status' => $data['status']
        ];
        
        supabase_mirror('employees', $payload);
        
        return $id;
    }
}

function payroll_delete_employee(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("DELETE FROM public.employees WHERE id = ?");
    $stmt->execute([$id]);
    supabase_mirror('employees', [], 'DELETE', ['id' => $id]);
}

function payroll_delete_run(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM public.payroll_run_items WHERE payroll_run_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM public.payroll_runs WHERE id = ?")->execute([$id]);
        $pdo->commit();
        
        // Items are deleted via cascade in Supabase as well
        supabase_mirror('payroll_runs', [], 'DELETE', ['id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function payroll_get_attendance(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare("
        SELECT a.*, e.full_name, e.employee_code 
        FROM public.attendance_records a 
        JOIN public.employees e ON e.id = a.employee_id 
        WHERE a.payroll_period_start = ? AND a.payroll_period_end = ?
    ");
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll();
}

function payroll_save_attendance(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO public.attendance_records (
            employee_id, payroll_period_start, payroll_period_end, 
            days_worked, hours_worked, overtime_hours, absences, late_minutes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (employee_id, payroll_period_start, payroll_period_end) DO UPDATE SET
            days_worked = EXCLUDED.days_worked,
            hours_worked = EXCLUDED.hours_worked,
            overtime_hours = EXCLUDED.overtime_hours,
            absences = EXCLUDED.absences,
            late_minutes = EXCLUDED.late_minutes
    ");
    $stmt->execute([
        $data['employee_id'], $data['payroll_period_start'], $data['payroll_period_end'],
        $data['days_worked'], $data['hours_worked'], $data['overtime_hours'], 
        $data['absences'], $data['late_minutes']
    ]);

    supabase_mirror('attendance_records', $data, 'INSERT'); // Using INSERT as upsert logic is handled by Supabase if UNIQUE constraint exists
}

function payroll_generate_run(PDO $pdo, string $start, string $end, ?int $budgetId = null): int
{
    $pdo->beginTransaction();
    try {
        // Create payroll run header
        $stmt = $pdo->prepare("INSERT INTO public.payroll_runs (period_start, period_end, budget_id) VALUES (?, ?, ?)");
        $stmt->execute([$start, $end, $budgetId]);
        $runId = (int)$pdo->lastInsertId();

        // Get employees and their attendance
        $stmt = $pdo->prepare("
            SELECT e.*, a.days_worked, a.hours_worked, a.overtime_hours, a.absences, a.late_minutes
            FROM public.employees e
            LEFT JOIN public.attendance_records a ON a.employee_id = e.id 
                AND a.payroll_period_start = ? AND a.payroll_period_end = ?
            WHERE e.status = 'Active'
        ");
        $stmt->execute([$start, $end]);
        $employees = $stmt->fetchAll();

        $totalGross = 0;
        $totalNet = 0;

        foreach ($employees as $emp) {
            $basic = (float)$emp['basic_salary'];
            $allowance = (float)$emp['allowance'];
            $defaultDeduction = (float)$emp['deduction_default'];
            
            // Computation Logic
            $dailyRate = ($emp['pay_type'] === 'monthly') ? ($basic / 22) : (($emp['pay_type'] === 'hourly') ? ($basic * 8) : $basic);
            $hourlyRate = $dailyRate / 8;
            $minuteRate = $hourlyRate / 60;

            // Overtime Pay (125% rate)
            $overtimePay = (float)($emp['overtime_hours'] ?? 0) * $hourlyRate * 1.25;

            // Deductions
            $absenceDeduction = (float)($emp['absences'] ?? 0) * $dailyRate;
            $lateDeduction = (int)($emp['late_minutes'] ?? 0) * $minuteRate;
            
            $totalDeductions = $defaultDeduction + $absenceDeduction + $lateDeduction;

            $gross = $basic + $overtimePay + $allowance;
            $net = $gross - $totalDeductions;

            $stmt = $pdo->prepare("
                INSERT INTO public.payroll_run_items (
                    payroll_run_id, employee_id, basic_salary, overtime_pay, 
                    allowances, deductions, absence_deduction, late_deduction, gross_pay, net_pay
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $runId, $emp['id'], $basic, $overtimePay, $allowance, $defaultDeduction, $absenceDeduction, $lateDeduction, $gross, $net
            ]);

            $itemId = (int)$pdo->lastInsertId();
            supabase_mirror('payroll_run_items', [
                'id' => $itemId,
                'payroll_run_id' => $runId,
                'employee_id' => $emp['id'],
                'basic_salary' => $basic,
                'overtime_pay' => $overtimePay,
                'allowances' => $allowance,
                'deductions' => $defaultDeduction,
                'absence_deduction' => $absenceDeduction,
                'late_deduction' => $lateDeduction,
                'gross_pay' => $gross,
                'net_pay' => $net
            ]);

            $totalGross += $gross;
            $totalNet += $net;
        }

        // Update totals
        $stmt = $pdo->prepare("UPDATE public.payroll_runs SET total_gross = ?, total_net = ? WHERE id = ?");
        $stmt->execute([$totalGross, $totalNet, $runId]);

        supabase_mirror('payroll_runs', [
            'id' => $runId,
            'period_start' => $start,
            'period_end' => $end,
            'total_gross' => $totalGross,
            'total_net' => $totalNet,
            'approval_status' => 'Pending',
            'budget_id' => $budgetId
        ]);

        $pdo->commit();
        return $runId;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function payroll_approve_run(PDO $pdo, int $runId, int $userId): void
{
    $stmt = $pdo->prepare("
        UPDATE public.payroll_runs 
        SET approval_status = 'Approved', approved_by = ?, approved_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$userId, $runId]);

    supabase_mirror('payroll_runs', [
        'approval_status' => 'Approved',
        'approved_by' => $userId,
        'approved_at' => date('Y-m-d H:i:s')
    ], 'UPDATE', ['id' => $runId]);
}

function payroll_create_payment_request(PDO $pdo, int $runId): int
{
    $run = $pdo->query("SELECT * FROM public.payroll_runs WHERE id = $runId")->fetch();
    if (!$run) throw new Exception("Payroll run not found.");

    $requestNo = "PAY-REQ-" . str_pad((string)$runId, 5, '0', STR_PAD_LEFT);
    $requestDate = date('Y-m-d');
    $budgetId = isset($run['budget_id']) ? (int)$run['budget_id'] : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO public.payroll_payment_requests (payroll_run_id, request_no, total_amount, request_date, related_budget_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$runId, $requestNo, $run['total_net'], $requestDate, $budgetId]);
    $requestId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("UPDATE public.payroll_runs SET payment_request_id = ? WHERE id = ?");
    $stmt->execute([$requestId, $runId]);

    supabase_mirror('payroll_payment_requests', [
        'id' => $requestId,
        'payroll_run_id' => $runId,
        'request_no' => $requestNo,
        'total_amount' => $run['total_net'],
        'request_date' => $requestDate,
        'status' => 'Pending',
        'related_budget_id' => $budgetId
    ]);

    supabase_mirror('payroll_runs', ['payment_request_id' => $requestId], 'UPDATE', ['id' => $runId]);

    return $requestId;
}
