<?php
declare(strict_types=1);

/*
 * Finance helper aligned to the current Supabase PostgreSQL schema.
 */
require_once __DIR__ . '/../financial/supabase.php';
require_once __DIR__ . '/../config/supabase_config.php';

/**
 * Audit Logging
 */
function finance_log_audit(PDO $pdo, string $action, string $module, ?int $recordId = null, $oldValues = null, $newValues = null): void
{
    if (!finance_table_exists($pdo, 'audit_logs')) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO public.audit_logs (
            user_id, action, table_name, record_id, old_values, new_values, ip_address
        ) VALUES (
            :user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address
        )
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':action' => $action,
        ':table_name' => $module,
        ':record_id' => $recordId,
        ':old_values' => $oldValues !== null ? json_encode($oldValues) : null,
        ':new_values' => $newValues !== null ? json_encode($newValues) : null,
        ':ip_address' => filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null,
    ]);
}

/**
 * Sync Job Posting Payments from Supabase to local DB
 */
function sync_job_posting_payments_from_supabase(PDO $pdo): int
{
    if (supabase_mode() !== 'mirror') {
        return 0;
    }

    require_once __DIR__ . '/../financial/supabase.php';
    $client = supabase_init();
    if (!$client) return 0;

    // Fetch pending requests from Supabase, including legacy/external status labels.
    $res = $client->get('job_posting_payments', finance_job_posting_status_query('pending'));
    if ($res['status'] !== 200 || !is_array($res['data'])) {
        return 0;
    }

    $count = 0;
    $isPg = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');

    if ($isPg) {
         $stmt = $pdo->prepare("
            INSERT INTO job_posting_payments (id, job_title, company_name, amount, status, created_at, updated_at)
            VALUES (:id, :job_title, :company_name, :amount, :status, :created_at, :updated_at)
            ON CONFLICT (id) DO UPDATE SET
                job_title = EXCLUDED.job_title,
                company_name = EXCLUDED.company_name,
                amount = EXCLUDED.amount,
                status = EXCLUDED.status,
                updated_at = EXCLUDED.updated_at
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO job_posting_payments (id, job_title, company_name, amount, status, created_at, updated_at)
            VALUES (:id, :job_title, :company_name, :amount, :status, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                job_title = VALUES(job_title),
                company_name = VALUES(company_name),
                amount = VALUES(amount),
                status = VALUES(status),
                updated_at = VALUES(updated_at)
        ");
    }

    foreach ($res['data'] as $row) {
        try {
            $stmt->execute([
                ':id' => $row['id'],
                ':job_title' => $row['job_title'],
                ':company_name' => $row['company_name'],
                ':amount' => $row['amount'],
                ':status' => $row['status'],
                ':created_at' => $row['created_at'],
                ':updated_at' => $row['updated_at'],
            ]);
            $count++;
        } catch (Throwable $e) {
            error_log("Sync error for job_posting_payments ID " . ($row['id'] ?? 'unknown') . ": " . $e->getMessage());
        }
    }

    return $count;
}

function finance_job_posting_status_values(string $status): array
{
    return match (strtolower(trim($status))) {
        'all' => [],
        'pending' => ['pending', 'Pending', 'Waiting for Approval'],
        'approved' => ['approved', 'Approved'],
        'released' => ['released', 'Released'],
        'rejected' => ['rejected', 'Rejected', 'declined', 'Declined'],
        'revision', 'needs revision', 'revision requested' => ['needs revision', 'Needs Revision', 'revision', 'Revision Requested'],
        default => [$status],
    };
}

function finance_job_posting_status_query(string $status): array
{
    $values = finance_job_posting_status_values($status);
    if ($values === []) {
        return [];
    }

    $filters = array_map(
        static fn (string $value): string => 'status.eq.' . $value,
        $values
    );

    if (count($filters) === 1) {
        return ['status' => 'eq.' . $values[0]];
    }

    return ['or' => '(' . implode(',', $filters) . ')'];
}

function finance_ensure_core_request_tracking_columns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !finance_table_exists($pdo, 'job_posting_payments')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $columnMap = [
        'request_type' => $isPg ? 'VARCHAR(80)' : 'VARCHAR(80)',
        'requested_by' => $isPg ? 'VARCHAR(150)' : 'VARCHAR(150)',
        'department' => $isPg ? 'VARCHAR(120)' : 'VARCHAR(120)',
        'remarks' => 'TEXT',
        'related_budget_id' => 'BIGINT',
        'related_ar_ap_id' => 'BIGINT',
        'related_disbursement_id' => 'BIGINT',
    ];

    foreach ($columnMap as $column => $definition) {
        if (!finance_column_exists($pdo, 'job_posting_payments', $column)) {
            $pdo->exec("ALTER TABLE {$schema}job_posting_payments ADD COLUMN {$column} {$definition}");
        }
    }

    $ensured = true;
}

function finance_ensure_logistics_request_tracking_columns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !finance_table_exists($pdo, 'logistic_requests')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $columnMap = [
        'request_type' => $isPg ? 'VARCHAR(80)' : 'VARCHAR(80)',
        'description' => 'TEXT',
        'amount' => $isPg ? 'NUMERIC(14,2)' : 'DECIMAL(14,2)',
        'due_date' => 'DATE',
        'remarks' => 'TEXT',
        'related_ar_ap_id' => 'BIGINT',
        'related_disbursement_id' => 'BIGINT',
    ];

    foreach ($columnMap as $column => $definition) {
        if (!finance_column_exists($pdo, 'logistic_requests', $column)) {
            $pdo->exec("ALTER TABLE {$schema}logistic_requests ADD COLUMN {$column} {$definition}");
        }
    }

    $ensured = true;
}

function finance_ensure_ar_ap_link_columns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !finance_table_exists($pdo, 'ar_ap')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $columnMap = [
        'source_module' => $isPg ? 'VARCHAR(50)' : 'VARCHAR(50)',
        'source_request_id' => 'BIGINT',
        'related_budget_id' => 'BIGINT',
    ];

    foreach ($columnMap as $column => $definition) {
        if (!finance_column_exists($pdo, 'ar_ap', $column)) {
            $pdo->exec("ALTER TABLE {$schema}ar_ap ADD COLUMN {$column} {$definition}");
        }
    }

    $ensured = true;
}

function finance_ensure_disbursement_link_columns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !finance_table_exists($pdo, 'disbursement')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $columnMap = [
        'budget_id' => 'BIGINT',
        'related_ap_id' => 'BIGINT',
        'related_budget_id' => 'BIGINT',
        'related_payroll_id' => 'BIGINT',
        'ledger_mode' => 'VARCHAR(20)',
    ];

    foreach ($columnMap as $column => $definition) {
        if (!finance_column_exists($pdo, 'disbursement', $column)) {
            $pdo->exec("ALTER TABLE {$schema}disbursement ADD COLUMN {$column} {$definition}");
        }
    }

    $ensured = true;
}

function finance_core_request_code(int $id, ?string $requestCode = null): string
{
    $candidate = trim((string) $requestCode);
    if ($candidate !== '') {
        return $candidate;
    }

    return 'CORE-' . str_pad((string) max(0, $id), 4, '0', STR_PAD_LEFT);
}

function finance_core_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'released' => 'Released',
        'approved' => 'Approved',
        'rejected', 'declined' => 'Rejected',
        'needs revision', 'revision', 'revision requested', 'for revision', 'needs_review', 'needs review' => 'Revision Requested',
        'ready for disbursement' => 'Ready for Disbursement',
        'linked to ar', 'linked to ar/ap', 'linked to ap' => 'Linked to AR/AP',
        'linked to budget' => 'Linked to Budget',
        default => 'Pending',
    };
}

function finance_core_is_actionable(array $request): bool
{
    return finance_core_status_label((string) ($request['status'] ?? 'Pending')) === 'Pending';
}

function finance_core_review_pick(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];
        if ($value === null) {
            continue;
        }

        if (is_string($value) && trim($value) === '') {
            continue;
        }

        return $value;
    }

    return $default;
}

function finance_normalize_core_review_row(array $row, string $source = 'local'): array
{
    global $pdo;

    if (isset($pdo) && $pdo instanceof PDO) {
        finance_ensure_core_request_tracking_columns($pdo);
        if ((int) ($row['id'] ?? 0) > 0 && finance_table_exists($pdo, 'job_posting_payments')) {
            $schema = finance_schema_prefix($pdo);
            $stmt = $pdo->prepare("SELECT * FROM {$schema}job_posting_payments WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int) ($row['id'] ?? 0)]);
            $localRow = $stmt->fetch() ?: null;
            if (is_array($localRow)) {
                $row = array_merge($row, $localRow);
            }
        }
    }

    $id = (int) ($row['id'] ?? 0);
    $rawStatus = (string) finance_core_review_pick($row, ['status'], 'Pending');
    $description = trim((string) finance_core_review_pick($row, ['job_title', 'description', 'request_description', 'title'], 'CORE request'));
    $department = trim((string) finance_core_review_pick($row, ['department', 'department_name', 'module_name'], 'CORE'));
    $requestedBy = trim((string) finance_core_review_pick($row, ['requested_by_name', 'requester_name', 'requested_by', 'submitted_by', 'created_by_name', 'created_by', 'owner_name'], ''));
    $companyName = trim((string) finance_core_review_pick($row, ['company_name', 'client_name'], ''));
    if ($requestedBy === '') {
        $requestedBy = $companyName !== '' ? $companyName : 'CORE Team';
    }

    $linkedBudgetId = (int) finance_core_review_pick($row, ['related_budget_id'], 0);
    $linkedArApId = (int) finance_core_review_pick($row, ['related_ar_ap_id'], 0);
    $linkedDisbursementId = (int) finance_core_review_pick($row, ['related_disbursement_id'], 0);
    $status = finance_core_status_label($rawStatus);
    if ($linkedDisbursementId > 0) {
        $status = 'Released';
    } elseif ($status === 'Approved' && $linkedArApId > 0) {
        $status = 'Linked to AR/AP';
    } elseif ($status === 'Approved' && $linkedBudgetId > 0) {
        $status = 'Linked to Budget';
    }

    return [
        'id' => $id,
        'request_code' => finance_core_request_code($id, (string) finance_core_review_pick($row, ['request_code', 'request_id', 'code'], '')),
        'request_type' => trim((string) finance_core_review_pick($row, ['request_type', 'type', 'payment_type'], 'Job Posting Payment')),
        'requested_by' => $requestedBy,
        'department' => $department !== '' ? $department : 'CORE',
        'description' => $description,
        'job_title' => trim((string) finance_core_review_pick($row, ['job_title', 'title'], $description)),
        'company_name' => $companyName,
        'amount' => isset($row['amount']) ? (float) $row['amount'] : (float) finance_core_review_pick($row, ['approved_amount', 'requested_amount'], 0.0),
        'status' => $status,
        'remarks' => trim((string) finance_core_review_pick($row, ['remarks', 'notes', 'comment'], '')),
        'created_at' => (string) finance_core_review_pick($row, ['created_at', 'request_date', 'submitted_at'], ''),
        'updated_at' => (string) finance_core_review_pick($row, ['updated_at', 'reviewed_at', 'approved_at'], ''),
        'related_budget_id' => $linkedBudgetId,
        'related_ar_ap_id' => $linkedArApId,
        'related_disbursement_id' => $linkedDisbursementId,
        'source' => $source,
    ];
}

function finance_get_core_review_requests(PDO $pdo, array $filters = []): array
{
    return finance_filter_core_review_rows(finance_get_core_review_request_pool($pdo), $filters);
}

function finance_get_core_review_request_pool(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rows = [];
    $client = supabase_init();

    if ($client) {
        $res = $client->get('job_posting_payments', [
            'select' => '*',
            'order' => 'created_at.desc',
        ]);

        if (($res['status'] ?? 0) === 200 && is_array($res['data'] ?? null)) {
            $rows = array_map(
                static fn (array $row): array => finance_normalize_core_review_row($row, 'supabase'),
                $res['data']
            );
        }
    }

    if ($rows === [] && finance_table_exists($pdo, 'job_posting_payments')) {
        $schema = finance_schema_prefix($pdo);
        $stmt = $pdo->query("SELECT * FROM {$schema}job_posting_payments ORDER BY created_at DESC, id DESC");
        $rows = array_map(
            static fn (array $row): array => finance_normalize_core_review_row($row, 'local'),
            $stmt ? ($stmt->fetchAll() ?: []) : []
        );
    }

    $cache = $rows;

    return $cache;
}

function finance_filter_core_review_rows(array $rows, array $filters = []): array
{
    $search = strtolower(trim((string) ($filters['search'] ?? '')));
    $statusFilter = trim((string) ($filters['status'] ?? 'All'));

    if ($statusFilter !== '' && strcasecmp($statusFilter, 'All') !== 0) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($statusFilter): bool {
            return finance_core_status_label((string) ($row['status'] ?? 'Pending')) === $statusFilter;
        }));
    }

    if ($search !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
            $haystack = strtolower(implode(' ', [
                (string) ($row['request_code'] ?? ''),
                (string) ($row['request_type'] ?? ''),
                (string) ($row['requested_by'] ?? ''),
                (string) ($row['department'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($row['company_name'] ?? ''),
                (string) ($row['remarks'] ?? ''),
            ]));

            return str_contains($haystack, $search);
        }));
    }

    return $rows;
}

function finance_get_core_request_summary(PDO $pdo, ?array $rows = null): array
{
    return finance_get_core_request_summary_from_rows($rows ?? finance_get_core_review_request_pool($pdo));
}

function finance_get_core_request_summary_from_rows(array $rows): array
{
    $today = date('Y-m-d');
    $summary = [
        'pending' => 0,
        'approved_today' => 0,
        'rejected_today' => 0,
        'ready_for_disbursement' => 0,
        'linked_arap' => 0,
        'linked_budget' => 0,
    ];

    foreach ($rows as $row) {
        $status = finance_core_status_label((string) ($row['status'] ?? 'Pending'));
        $actionDate = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
        if ($status === 'Pending') {
            $summary['pending']++;
        } elseif ($status === 'Ready for Disbursement') {
            $summary['ready_for_disbursement']++;
        } elseif ($status === 'Linked to AR/AP') {
            $summary['linked_arap']++;
        } elseif ($status === 'Linked to Budget') {
            $summary['linked_budget']++;
        }

        if ($actionDate !== '' && date('Y-m-d', strtotime($actionDate)) === $today) {
            if ($status === 'Approved') {
                $summary['approved_today']++;
            } elseif ($status === 'Rejected') {
                $summary['rejected_today']++;
            }
        }
    }

    return $summary;
}

function finance_get_job_posting_payments(PDO $pdo, string $status = 'pending'): array
{
    if (strtolower(trim($status)) === 'all') {
        return finance_get_core_review_requests($pdo, ['status' => 'All']);
    }

    $status = strtolower(trim($status));
    $client = supabase_init();

    if ($client) {
        $query = finance_job_posting_status_query($status);
        $query['order'] = 'created_at.desc';
        $res = $client->get('job_posting_payments', $query);

        if (($res['status'] ?? 0) === 200 && !empty($res['data']) && is_array($res['data'])) {
            return array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'description' => (string) ($row['job_title'] ?? ''),
                    'job_title' => (string) ($row['job_title'] ?? ''),
                    'company_name' => (string) ($row['company_name'] ?? ''),
                    'amount' => isset($row['amount']) ? (float) $row['amount'] : 0.0,
                    'status' => (string) ($row['status'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'source' => 'supabase',
                ];
            }, $res['data']);
        }
    }

    if (!finance_table_exists($pdo, 'job_posting_payments')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $statusValues = finance_job_posting_status_values($status);
    $placeholders = [];
    $params = [];
    foreach ($statusValues as $index => $value) {
        $placeholder = ':status_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            job_title AS description,
            job_title,
            company_name,
            amount,
            status,
            created_at,
            updated_at
        FROM {$schema}job_posting_payments
        WHERE status IN (" . implode(', ', $placeholders) . ")
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);

    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['source'] = 'local';
    }

    return $rows;
}

function finance_get_job_posting_payment(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    finance_ensure_core_request_tracking_columns($pdo);
    $localRow = null;
    if (finance_table_exists($pdo, 'job_posting_payments')) {
        $schema = finance_schema_prefix($pdo);
        $localStmt = $pdo->prepare("SELECT * FROM {$schema}job_posting_payments WHERE id = :id LIMIT 1");
        $localStmt->execute([':id' => $id]);
        $localRow = $localStmt->fetch() ?: null;
    }

    $client = supabase_init();
    if ($client) {
        $res = $client->get('job_posting_payments', [
            'id' => 'eq.' . $id,
            'limit' => '1',
        ]);
        if (($res['status'] ?? 0) === 200 && !empty($res['data'][0]) && is_array($res['data'][0])) {
            $row = $res['data'][0];
            $normalized = [
                'id' => (int) ($row['id'] ?? 0),
                'job_title' => (string) ($row['job_title'] ?? ''),
                'company_name' => (string) ($row['company_name'] ?? ''),
                'amount' => isset($row['amount']) ? (float) $row['amount'] : 0.0,
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'source' => 'supabase',
            ];
            if (is_array($localRow)) {
                $normalized = array_merge($normalized, $localRow);
            }
            return $normalized;
        }
    }

    if (!finance_table_exists($pdo, 'job_posting_payments')) {
        return null;
    }

    if (!$localRow) {
        return null;
    }

    $localRow['source'] = 'local';
    return $localRow;
}

function getPayrollPaymentRequestList(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'payroll_payment_requests')) {
        return [];
    }
    $stmt = $pdo->query("SELECT * FROM public.payroll_payment_requests WHERE status = 'Pending' ORDER BY request_date DESC");
    return $stmt->fetchAll();
}

function getPayrollPaymentRequestById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM public.payroll_payment_requests WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function finance_db(): PDO
{
    // Use the global PDO connection established in db.php
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }
    return $GLOBALS['pdo'];
}

function finance_schema_prefix(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'public.' : '';
}

function finance_money(float|string|null $amount): string
{
    return number_format((float) $amount, 2);
}

function finance_like_operator(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'ILIKE' : 'LIKE';
}

function finance_accounts_order_clause(PDO $pdo): string
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        return 'account_code ASC NULLS LAST, account_title ASC';
    }

    return 'account_code IS NULL, account_code ASC, account_title ASC';
}

function finance_is_valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
}

function finance_ensure_approved_request_logs_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $schema = finance_schema_prefix($pdo);

    $sql = $isPg
        ? "CREATE TABLE IF NOT EXISTS {$schema}approved_request_logs (
            id BIGSERIAL PRIMARY KEY,
            module VARCHAR(30) NOT NULL,
            source_table VARCHAR(80) NOT NULL,
            source_id VARCHAR(80) NOT NULL,
            description TEXT NOT NULL,
            amount NUMERIC(14,2),
            approved_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            approved_by BIGINT,
            remarks TEXT
        )"
        : "CREATE TABLE IF NOT EXISTS {$schema}approved_request_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(30) NOT NULL,
            source_table VARCHAR(80) NOT NULL,
            source_id VARCHAR(80) NOT NULL,
            description TEXT NOT NULL,
            amount DECIMAL(14,2) NULL,
            approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by BIGINT NULL,
            remarks TEXT NULL
        )";

    $pdo->exec($sql);

    if (!finance_column_exists($pdo, 'approved_request_logs', 'action')) {
        $pdo->exec(
            $isPg
                ? "ALTER TABLE {$schema}approved_request_logs ADD COLUMN action VARCHAR(20) NOT NULL DEFAULT 'APPROVE'"
                : "ALTER TABLE {$schema}approved_request_logs ADD COLUMN action VARCHAR(20) NOT NULL DEFAULT 'APPROVE'"
        );
    }

    try {
        if ($isPg) {
            $pdo->exec("
                ALTER TABLE {$schema}approved_request_logs
                ALTER COLUMN source_id TYPE VARCHAR(80) USING source_id::text
            ");
        } else {
            $pdo->exec("
                ALTER TABLE {$schema}approved_request_logs
                MODIFY source_id VARCHAR(80) NOT NULL
            ");
        }
    } catch (Throwable) {
    }

    $ensured = true;
}

function finance_request_action_log_normalize_action(string $action): string
{
    $normalized = strtoupper(trim($action));

    return match ($normalized) {
        'APPROVED', 'APPROVE' => 'APPROVE',
        'REJECTED', 'REJECT' => 'REJECT',
        'REVISION REQUESTED', 'REQUEST_REVISION', 'REQUEST REVISION', 'NEEDS REVISION', 'REVISION' => 'REVISION',
        'RELEASED', 'RELEASE', 'RELEASE FUNDS' => 'RELEASE',
        default => $normalized,
    };
}

function finance_record_approved_request_log(
    PDO $pdo,
    string $action,
    string $module,
    string $sourceTable,
    string $sourceId,
    string $description,
    ?float $amount = null,
    ?string $remarks = null,
    ?string $approvedAt = null
): void {
    finance_ensure_approved_request_logs_table($pdo);

    $schema = finance_schema_prefix($pdo);
    $approvedAtExpr = $approvedAt !== null && trim($approvedAt) !== '' ? ':approved_at' : 'NOW()';
    $stmt = $pdo->prepare("
        INSERT INTO {$schema}approved_request_logs (
            action,
            module,
            source_table,
            source_id,
            description,
            amount,
            approved_at,
            approved_by,
            remarks
        ) VALUES (
            :action,
            :module,
            :source_table,
            :source_id,
            :description,
            :amount,
            {$approvedAtExpr},
            :approved_by,
            :remarks
        )
    ");
    $params = [
        ':action' => finance_request_action_log_normalize_action($action),
        ':module' => strtoupper(trim($module)),
        ':source_table' => trim($sourceTable),
        ':source_id' => trim($sourceId),
        ':description' => trim($description) !== '' ? trim($description) : 'Approved request',
        ':amount' => $amount,
        ':approved_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        ':remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
    ];
    if ($approvedAt !== null && trim($approvedAt) !== '') {
        $params[':approved_at'] = trim($approvedAt);
    }

    $stmt->execute($params);
}

function finance_get_approved_request_logs(PDO $pdo, int $limit = 100): array
{
    finance_ensure_approved_request_logs_table($pdo);

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT id, action, module, source_table, source_id, description, amount, approved_at, remarks
        FROM {$schema}approved_request_logs
        ORDER BY approved_at DESC, id DESC
        LIMIT :limit_value
    ");
    $stmt->bindValue(':limit_value', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function finance_get_request_action_logs(PDO $pdo, int $limit = 100): array
{
    return finance_get_approved_request_logs($pdo, $limit);
}

function finance_request_action_logs_need_backfill(PDO $pdo): bool
{
    finance_ensure_approved_request_logs_table($pdo);

    static $cache = [];
    $cacheKey = spl_object_hash($pdo);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->query("SELECT COUNT(*) FROM {$schema}approved_request_logs");
    $cache[$cacheKey] = ((int) ($stmt ? $stmt->fetchColumn() : 0)) === 0;

    return $cache[$cacheKey];
}

function finance_ensure_request_action_logs_ready(PDO $pdo): void
{
    finance_backfill_request_action_logs($pdo);
}

function finance_format_relative_time(?string $dateTime): string
{
    $dateTime = trim((string) $dateTime);
    if ($dateTime === '') {
        return 'Just now';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return 'Just now';
    }

    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $minutes = max(1, (int) floor($diff / 60));
        return $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hours = max(1, (int) floor($diff / 3600));
        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = max(1, (int) floor($diff / 86400));
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('M j, Y', $timestamp);
}

function finance_notification_message(string $module, string $action, string $description = ''): string
{
    $module = strtoupper(trim($module));
    $action = strtoupper(trim($action));
    $description = trim($description);

    $message = match ($module . ':' . $action) {
        'HR:APPROVE', 'HR:APPROVED' => 'Approved HR request',
        'HR:REJECT', 'HR:REJECTED' => 'Rejected HR request',
        'HR:REQUEST_REVISION', 'HR:REVISION', 'HR:NEEDS REVISION' => 'HR request needs revision',
        'LOGISTICS:APPROVE', 'LOGISTICS:APPROVED' => 'Approved Logistics request',
        'LOGISTICS:REJECT', 'LOGISTICS:REJECTED' => 'Rejected Logistics request',
        'LOGISTICS:REQUEST_REVISION', 'LOGISTICS:REVISION', 'LOGISTICS:NEEDS REVISION' => 'Logistics request needs revision',
        'CORE:APPROVE', 'CORE:APPROVED' => 'Approved CORE request',
        'CORE:REJECT', 'CORE:REJECTED' => 'Rejected CORE request',
        'COLLECTION:RECORDED', 'COLLECTION:APPROVE', 'COLLECTION:APPROVED' => 'New collection recorded',
        'DISBURSEMENT:RELEASE', 'DISBURSEMENT:RELEASED' => 'Disbursement released',
        'DISBURSEMENT:CREATED' => 'Disbursement created',
        default => trim(($action !== '' ? ucwords(strtolower(str_replace('_', ' ', $action))) : 'Updated') . ' ' . ($module !== '' ? $module : 'record')),
    };

    if ($description !== '') {
        $snippet = strlen($description) > 70 ? substr($description, 0, 67) . '...' : $description;
        return $message . ': ' . $snippet;
    }

    return $message;
}

function finance_get_topbar_notifications(PDO $pdo, int $limit = 8): array
{
    $schema = finance_schema_prefix($pdo);
    $notifications = [];

    try {
        finance_ensure_request_action_logs_ready($pdo);
        foreach (finance_get_request_action_logs($pdo, $limit) as $row) {
            $timestamp = (string) ($row['approved_at'] ?? '');
            $notifications[] = [
                'module' => strtoupper(trim((string) ($row['module'] ?? 'SYSTEM'))),
                'message' => finance_notification_message(
                    (string) ($row['module'] ?? 'SYSTEM'),
                    (string) ($row['action'] ?? 'UPDATE'),
                    (string) ($row['description'] ?? '')
                ),
                'time' => finance_format_relative_time($timestamp),
                'timestamp' => $timestamp,
                'href' => '/FinancialSM/financial/request-action-logs.php',
            ];
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'collection')) {
            $rows = $pdo->query("
                SELECT payer_name, amount, payment_date
                FROM {$schema}collection
                ORDER BY payment_date DESC, id DESC
                LIMIT 3
            ")->fetchAll() ?: [];

            foreach ($rows as $row) {
                $timestamp = (string) ($row['payment_date'] ?? '');
                $notifications[] = [
                    'module' => 'COLLECTION',
                    'message' => finance_notification_message(
                        'COLLECTION',
                        'RECORDED',
                        'Collection from ' . (string) ($row['payer_name'] ?? 'payer')
                    ),
                    'time' => finance_format_relative_time($timestamp),
                    'timestamp' => $timestamp,
                    'href' => '/FinancialSM/financial/collection.php',
                ];
            }
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'disbursement')) {
            $rows = $pdo->query("
                SELECT payee_name, amount, disbursement_date, status
                FROM {$schema}disbursement
                ORDER BY disbursement_date DESC, id DESC
                LIMIT 3
            ")->fetchAll() ?: [];

            foreach ($rows as $row) {
                $timestamp = (string) ($row['disbursement_date'] ?? '');
                $notifications[] = [
                    'module' => 'DISBURSEMENT',
                    'message' => finance_notification_message(
                        'DISBURSEMENT',
                        (string) ($row['status'] ?? 'CREATED'),
                        'Disbursement for ' . (string) ($row['payee_name'] ?? 'payee')
                    ),
                    'time' => finance_format_relative_time($timestamp),
                    'timestamp' => $timestamp,
                    'href' => '/FinancialSM/financial/disbursement.php',
                ];
            }
        }
    } catch (Throwable) {
    }

    usort($notifications, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['timestamp'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['timestamp'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });

    return array_slice($notifications, 0, max(1, $limit));
}

function finance_get_topbar_settings_context(): array
{
    $timeoutSeconds = (int) ini_get('session.gc_maxlifetime');
    if ($timeoutSeconds <= 0) {
        $timeoutSeconds = 900;
    }

    return [
        'user_name' => (string) ($_SESSION['user_name'] ?? 'Admin User'),
        'user_role' => (string) ($_SESSION['user_role'] ?? 'Administrator'),
        'user_email' => (string) ($_SESSION['user_email'] ?? ''),
        'session_timeout_minutes' => max(1, (int) round($timeoutSeconds / 60)),
    ];
}

function finance_search_request_action_logs(PDO $pdo, array $filters = [], int $limit = 25, int $offset = 0): array
{
    finance_ensure_approved_request_logs_table($pdo);

    $schema = finance_schema_prefix($pdo);
    $sql = "
        SELECT id, action, module, source_table, source_id, description, amount, approved_at, remarks
        FROM {$schema}approved_request_logs
        WHERE 1=1
    ";
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= " AND (
            description LIKE :search
            OR COALESCE(remarks, '') LIKE :search
            OR module LIKE :search
            OR action LIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }

    $module = strtoupper(trim((string) ($filters['module'] ?? '')));
    if ($module !== '' && $module !== 'ALL') {
        $sql .= " AND module = :module";
        $params[':module'] = $module;
    }

    $action = finance_request_action_log_normalize_action((string) ($filters['action'] ?? ''));
    if ($action !== '' && $action !== 'ALL') {
        $sql .= " AND action = :action";
        $params[':action'] = $action;
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $sql .= " AND approved_at >= :date_from";
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $sql .= " AND approved_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $sql .= " ORDER BY approved_at DESC, id DESC LIMIT :limit_value OFFSET :offset_value";
    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit_value', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset_value', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function finance_get_request_action_log_by_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    finance_ensure_approved_request_logs_table($pdo);

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT id, action, module, source_table, source_id, description, amount, approved_at, remarks
        FROM {$schema}approved_request_logs
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function finance_request_action_log_module_key(array $row): string
{
    $module = strtoupper(trim((string) ($row['module'] ?? '')));
    if ($module !== '') {
        return $module;
    }

    return strtoupper(trim((string) ($row['source_table'] ?? '')));
}

function finance_request_action_log_action_key(array $row): string
{
    $action = strtolower(finance_request_action_log_normalize_action((string) ($row['action'] ?? '')));

    return match (true) {
        in_array($action, ['approve', 'posted', 'collect', 'collected', 'payment approved', 'payment posted'], true) => 'approved',
        in_array($action, ['release', 'released', 'release funds'], true) => 'released',
        in_array($action, ['reject'], true) => 'rejected',
        in_array($action, ['revision', 'request revision', 'revision requested', 'needs revision'], true) => 'revision',
        default => $action,
    };
}

function finance_request_action_log_display_action(array $row): string
{
    return match (finance_request_action_log_action_key($row)) {
        'approved' => 'Approved',
        'released' => 'Released',
        'rejected' => 'Rejected',
        'revision' => 'Revision Requested',
        default => trim((string) ($row['action'] ?? '')) !== '' ? ucwords(strtolower(trim((string) $row['action']))) : 'Action',
    };
}

function finance_request_action_log_badge_class(array $row): string
{
    return match (finance_request_action_log_action_key($row)) {
        'approved' => 'badge-paid',
        'released' => 'badge-partial',
        'rejected' => 'badge-overdue',
        'revision' => 'badge-overdue',
        default => 'badge-pending',
    };
}

function finance_request_action_log_has_receipt(array $row): bool
{
    $module = finance_request_action_log_module_key($row);
    $actionKey = finance_request_action_log_action_key($row);

    return in_array($module, ['CORE', 'COLLECTION'], true)
        && $actionKey === 'approved';
}

function finance_request_action_log_has_logistics_approval_slip(array $row): bool
{
    $module = finance_request_action_log_module_key($row);
    $actionKey = finance_request_action_log_action_key($row);

    return $module === 'LOGISTICS'
        && $actionKey === 'approved';
}

function finance_request_action_log_has_hr_summary(array $row): bool
{
    $module = finance_request_action_log_module_key($row);
    $actionKey = finance_request_action_log_action_key($row);

    return $module === 'HR'
        && $actionKey === 'approved';
}

function finance_request_action_log_has_disbursement_voucher(array $row): bool
{
    $module = finance_request_action_log_module_key($row);
    $actionKey = finance_request_action_log_action_key($row);

    return $module === 'DISBURSEMENT'
        && $actionKey === 'released';
}

function finance_get_request_action_log_receipt_data(PDO $pdo, int $logId): ?array
{
    $logRow = finance_get_request_action_log_by_id($pdo, $logId);
    if (!$logRow || !finance_request_action_log_has_receipt($logRow)) {
        return null;
    }

    $module = strtoupper(trim((string) ($logRow['module'] ?? '')));
    $sourceTable = trim((string) ($logRow['source_table'] ?? ''));
    $sourceId = trim((string) ($logRow['source_id'] ?? ''));
    $amount = isset($logRow['amount']) ? (float) $logRow['amount'] : 0.0;

    $receipt = [
        'log_id' => (int) ($logRow['id'] ?? 0),
        'reference' => $module . '-' . $sourceId,
        'approval_date' => (string) ($logRow['approved_at'] ?? ''),
        'module' => $module,
        'description' => (string) ($logRow['description'] ?? 'Approved payment'),
        'amount_paid' => $amount,
        'remarks' => trim((string) ($logRow['remarks'] ?? '')),
        'payer_source' => '',
        'generated_date' => date('Y-m-d H:i:s'),
    ];

    $schema = finance_schema_prefix($pdo);

    if ($module === 'CORE' && $sourceId !== '') {
        $coreRow = finance_get_job_posting_payment($pdo, (int) $sourceId);
        if ($coreRow) {
            $receipt['reference'] = 'CORE-' . str_pad((string) ((int) $sourceId), 4, '0', STR_PAD_LEFT);
            $receipt['description'] = (string) ($coreRow['job_title'] ?? $receipt['description']);
            $receipt['amount_paid'] = isset($coreRow['amount']) ? (float) $coreRow['amount'] : $receipt['amount_paid'];
            $receipt['remarks'] = trim((string) ($logRow['remarks'] ?? ($coreRow['company_name'] ?? '')));
            $receipt['payer_source'] = trim((string) ($coreRow['company_name'] ?? ''));
        }
    } elseif ($module === 'COLLECTION' && $sourceId !== '' && finance_table_exists($pdo, 'collection')) {
        $stmt = $pdo->prepare("
            SELECT id, reference_no, payer_name, amount, payment_date, remarks, source_type
            FROM {$schema}collection
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => (int) $sourceId]);
        $collectionRow = $stmt->fetch();
        if ($collectionRow) {
            $receipt['reference'] = trim((string) ($collectionRow['reference_no'] ?? '')) !== ''
                ? (string) $collectionRow['reference_no']
                : ('COL-' . $sourceId);
            $receipt['approval_date'] = (string) ($collectionRow['payment_date'] ?? $receipt['approval_date']);
            $receipt['description'] = (string) ($logRow['description'] ?? 'Collection payment');
            $receipt['amount_paid'] = isset($collectionRow['amount']) ? (float) $collectionRow['amount'] : $receipt['amount_paid'];
            $receipt['remarks'] = trim((string) ($collectionRow['remarks'] ?? $logRow['remarks'] ?? ''));
            $payerName = trim((string) ($collectionRow['payer_name'] ?? ''));
            $sourceType = trim((string) ($collectionRow['source_type'] ?? ''));
            $receipt['payer_source'] = $payerName !== '' ? $payerName : $sourceType;
        }
    } elseif ($sourceTable === 'collection' && $sourceId !== '' && finance_table_exists($pdo, 'collection')) {
        $stmt = $pdo->prepare("
            SELECT id, reference_no, payer_name, amount, payment_date, remarks, source_type
            FROM {$schema}collection
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => (int) $sourceId]);
        $collectionRow = $stmt->fetch();
        if ($collectionRow) {
            $receipt['reference'] = trim((string) ($collectionRow['reference_no'] ?? '')) !== ''
                ? (string) ($collectionRow['reference_no'] ?? '')
                : ('COL-' . $sourceId);
            $receipt['approval_date'] = (string) ($collectionRow['payment_date'] ?? $receipt['approval_date']);
            $receipt['amount_paid'] = isset($collectionRow['amount']) ? (float) $collectionRow['amount'] : $receipt['amount_paid'];
            $receipt['remarks'] = trim((string) ($collectionRow['remarks'] ?? $logRow['remarks'] ?? ''));
            $payerName = trim((string) ($collectionRow['payer_name'] ?? ''));
            $sourceType = trim((string) ($collectionRow['source_type'] ?? ''));
            $receipt['payer_source'] = $payerName !== '' ? $payerName : $sourceType;
        }
    }

    return $receipt;
}

function finance_get_request_action_log_logistics_slip_data(PDO $pdo, int $logId): ?array
{
    $logRow = finance_get_request_action_log_by_id($pdo, $logId);
    if (!$logRow || !finance_request_action_log_has_logistics_approval_slip($logRow)) {
        return null;
    }

    $sourceId = (int) trim((string) ($logRow['source_id'] ?? '0'));
    if ($sourceId <= 0) {
        return null;
    }

    $request = finance_get_logistics_request_source_row($pdo, $sourceId);
    if (!$request) {
        return null;
    }

    return [
        'log_id' => (int) ($logRow['id'] ?? 0),
        'request_id' => (string) ($request['request_code'] ?? finance_logistics_request_code($sourceId, '')),
        'approval_date' => (string) ($logRow['approved_at'] ?? ''),
        'module' => 'LOGISTICS',
        'description' => (string) ($request['request_title'] ?? $request['item_name'] ?? $logRow['description'] ?? 'Logistics request'),
        'approved_amount' => isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== ''
            ? (float) $request['amount']
            : (isset($logRow['amount']) ? (float) $logRow['amount'] : 0.0),
        'department' => (string) ($request['department_name'] ?? 'Logistics'),
        'requested_by' => (string) ($request['requested_by_name'] ?? 'Logistics Staff'),
        'remarks' => trim((string) ($logRow['remarks'] ?? $request['remarks'] ?? '')),
        'generated_date' => date('Y-m-d H:i:s'),
    ];
}

function finance_get_request_action_log_hr_summary_data(PDO $pdo, int $logId): ?array
{
    $logRow = finance_get_request_action_log_by_id($pdo, $logId);
    if (!$logRow || !finance_request_action_log_has_hr_summary($logRow)) {
        return null;
    }

    $sourceId = trim((string) ($logRow['source_id'] ?? ''));
    if ($sourceId === '') {
        return null;
    }

    $request = finance_get_hr_request_source_row($pdo, $sourceId);
    if (!$request) {
        return null;
    }

    return [
        'log_id' => (int) ($logRow['id'] ?? 0),
        'request_id' => (string) ($request['request_code'] ?? finance_hr_request_code($sourceId, '')),
        'approval_date' => (string) ($logRow['approved_at'] ?? ''),
        'employee_name' => (string) ($request['employee_name'] ?? 'HR Staff'),
        'request_type' => (string) ($request['request_type_label'] ?? finance_hr_request_type_label($request)),
        'description' => (string) ($request['request_details'] ?? $request['request_title'] ?? $logRow['description'] ?? 'HR request'),
        'amount' => isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== ''
            ? (float) $request['amount']
            : (isset($logRow['amount']) ? (float) $logRow['amount'] : 0.0),
        'department' => (string) ($request['department_name'] ?? 'Human Resources'),
        'remarks' => trim((string) ($logRow['remarks'] ?? $request['remarks'] ?? '')),
        'generated_date' => date('Y-m-d H:i:s'),
    ];
}

function finance_get_request_action_log_disbursement_voucher_data(PDO $pdo, int $logId): ?array
{
    $logRow = finance_get_request_action_log_by_id($pdo, $logId);
    if (!$logRow || !finance_request_action_log_has_disbursement_voucher($logRow)) {
        return null;
    }

    $sourceId = (int) trim((string) ($logRow['source_id'] ?? '0'));
    if ($sourceId <= 0 || !finance_table_exists($pdo, 'disbursement')) {
        return null;
    }

    $disbursement = getDisbursementById($pdo, $sourceId);
    if (!$disbursement) {
        return null;
    }

    return [
        'log_id' => (int) ($logRow['id'] ?? 0),
        'reference_no' => (string) ($disbursement['reference_no'] ?? ('DIS-' . $sourceId)),
        'release_date' => (string) ($disbursement['disbursement_date'] ?? $logRow['approved_at'] ?? ''),
        'description' => (string) ($logRow['description'] ?? ('Disbursement for ' . (string) ($disbursement['payee_name'] ?? 'payee'))),
        'amount_released' => isset($disbursement['amount']) ? (float) $disbursement['amount'] : (float) ($logRow['amount'] ?? 0),
        'payment_method' => (string) ($disbursement['payment_method'] ?? ''),
        'payee' => (string) ($disbursement['payee_name'] ?? ''),
        'remarks' => trim((string) ($disbursement['remarks'] ?? $logRow['remarks'] ?? '')),
        'generated_date' => date('Y-m-d H:i:s'),
    ];
}

function finance_count_request_action_logs(PDO $pdo, array $filters = []): int
{
    finance_ensure_approved_request_logs_table($pdo);

    $schema = finance_schema_prefix($pdo);
    $sql = "
        SELECT COUNT(*) AS total_rows
        FROM {$schema}approved_request_logs
        WHERE 1=1
    ";
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= " AND (
            description LIKE :search
            OR COALESCE(remarks, '') LIKE :search
            OR module LIKE :search
            OR action LIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }

    $module = strtoupper(trim((string) ($filters['module'] ?? '')));
    if ($module !== '' && $module !== 'ALL') {
        $sql .= " AND module = :module";
        $params[':module'] = $module;
    }

    $action = finance_request_action_log_normalize_action((string) ($filters['action'] ?? ''));
    if ($action !== '' && $action !== 'ALL') {
        $sql .= " AND action = :action";
        $params[':action'] = $action;
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $sql .= " AND approved_at >= :date_from";
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $sql .= " AND approved_at <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

function finance_record_request_history(
    PDO $pdo,
    string $module,
    string $requestId,
    string $requestCode,
    string $action,
    string $status,
    ?string $remarks = null
): void {
    if (!finance_table_exists($pdo, 'request_history')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    static $requestHistoryColumnEnsured = false;
    if (!$requestHistoryColumnEnsured) {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $pdo->exec("ALTER TABLE {$schema}request_history ALTER COLUMN request_id TYPE VARCHAR(80) USING request_id::text");
            } else {
                $pdo->exec("ALTER TABLE {$schema}request_history MODIFY request_id VARCHAR(80) NOT NULL");
            }
        } catch (Throwable) {
        }
        $requestHistoryColumnEnsured = true;
    }

    $stmt = $pdo->prepare("
        INSERT INTO {$schema}request_history (
            module,
            request_id,
            request_code,
            action,
            status,
            remarks,
            acted_by,
            created_at
        ) VALUES (
            :module,
            :request_id,
            :request_code,
            :action,
            :status,
            :remarks,
            :acted_by,
            NOW()
        )
    ");
    $stmt->execute([
        ':module' => strtoupper(trim($module)),
        ':request_id' => trim($requestId),
        ':request_code' => trim($requestCode),
        ':action' => strtoupper(trim($action)),
        ':status' => trim($status),
        ':remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
        ':acted_by' => trim((string) ($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Finance Reviewer')),
    ]);
}

function finance_logistics_request_code(int $id, ?string $requestCode = null): string
{
    $candidate = trim((string) $requestCode);
    if ($candidate !== '') {
        return $candidate;
    }

    return 'LOG-' . str_pad((string) max(0, $id), 4, '0', STR_PAD_LEFT);
}

function finance_logistics_status_label(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        'paid', 'approved', 'completed', 'settled', 'payment received', 'audit verified', 'delivered', 'pod uploaded' => 'Approved',
        'ready for disbursement', 'ready_for_disbursement', 'for disbursement' => 'Ready for Disbursement',
        'released', 'released funds', 'funds released' => 'Released',
        'rejected' => 'Rejected',
        'needs revision', 'needs_revision', 'revision' => 'Needs Revision',
        'unpaid', 'pending', 'for approval', 'waiting for approval' => 'Pending',
        default => trim((string) $status) !== '' ? ucwords(str_replace('_', ' ', (string) $status)) : 'Pending',
    };
}

function finance_logistics_meta_value(array $metaData, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $metaData) && $metaData[$key] !== null && $metaData[$key] !== '') {
            return $metaData[$key];
        }
    }

    return $default;
}

function finance_numeric_from_mixed(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', $trimmed);
    if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function finance_logistics_meta_amount(array $metaData): ?float
{
    $direct = finance_logistics_meta_value($metaData, [
        'amount',
        'total_amount',
        'requested_amount',
        'estimated_cost',
        'price',
        'total',
        'total_price',
        'contract_amount',
        'invoice_amount',
        'payment_amount',
        'budget_amount',
        'grand_total',
    ], null);

    $parsedDirect = finance_numeric_from_mixed($direct);
    if ($parsedDirect !== null) {
        return $parsedDirect;
    }

    foreach ($metaData as $key => $value) {
        if (is_array($value)) {
            $nested = finance_logistics_meta_amount($value);
            if ($nested !== null) {
                return $nested;
            }
            continue;
        }

        $keyName = strtolower(trim((string) $key));
        if (str_contains($keyName, 'amount') || str_contains($keyName, 'total') || str_contains($keyName, 'price') || str_contains($keyName, 'cost')) {
            $parsed = finance_numeric_from_mixed($value);
            if ($parsed !== null) {
                return $parsed;
            }
        }
    }

    return null;
}

function finance_logistics_amount_from_report_url(string $url): ?float
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return null;
    }

    $candidates = [];

    $query = parse_url($trimmed, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $params);
        foreach (['amount', 'total', 'value', 'price', 'cost'] as $key) {
            if (array_key_exists($key, $params)) {
                $candidates[] = $params[$key];
            }
        }
    }

    if (preg_match_all('/(?:amount|total|price|cost)[^0-9\-]*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $trimmed, $matches) && !empty($matches[1])) {
        foreach ($matches[1] as $match) {
            $candidates[] = $match;
        }
    }

    if ($candidates === [] && preg_match_all('/([0-9][0-9,]*(?:\.[0-9]{1,2})?)/', $trimmed, $matches) && !empty($matches[1])) {
        foreach ($matches[1] as $match) {
            $candidates[] = $match;
        }
    }

    foreach ($candidates as $candidate) {
        $parsed = finance_numeric_from_mixed($candidate);
        if ($parsed !== null && $parsed > 0) {
            return $parsed;
        }
    }

    return null;
}

function finance_logistics_status_badge_class(?string $status): string
{
    return match (finance_logistics_status_label($status)) {
        'Approved' => 'approved',
        'Ready for Disbursement' => 'badge-submitted',
        'Rejected' => 'rejected',
        'Needs Revision' => 'revision',
        default => 'pending',
    };
}

function finance_logistics_request_type_label(array $row): string
{
    $value = trim((string) ($row['request_type'] ?? ''));
    if ($value !== '') {
        return $value;
    }

    $haystack = strtolower(trim((string) (($row['title'] ?? '') . ' ' . ($row['description'] ?? '') . ' ' . ($row['item_name'] ?? ''))));
    if (str_contains($haystack, 'restock') || str_contains($haystack, 'supply')) {
        return 'Restocking';
    }
    if (str_contains($haystack, 'repair') || str_contains($haystack, 'maintenance') || str_contains($haystack, 'fuel')) {
        return 'Maintenance';
    }

    return 'Procurement';
}

function finance_logistics_amount_label(array $row): string
{
    if (isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== '' && (float) $row['amount'] > 0) {
        return 'PHP ' . finance_money((float) $row['amount']);
    }

    return 'Pending Quotation';
}

function finance_logistics_has_final_amount(array $row): bool
{
    $amountValue = finance_numeric_from_mixed($row['amount'] ?? null);
    return $amountValue !== null && $amountValue > 0;
}

function finance_logistics_display_status_label(array $row): string
{
    $statusLabel = finance_logistics_status_label((string) ($row['status'] ?? 'Pending'));
    if ($statusLabel === 'Approved' && !finance_logistics_has_final_amount($row)) {
        return 'Awaiting Quotation';
    }

    return $statusLabel;
}

function finance_logistics_display_status_badge_class(array $row): string
{
    return match (finance_logistics_display_status_label($row)) {
        'Approved' => 'approved',
        'Ready for Disbursement' => 'badge-submitted',
        'Released' => 'badge-partial',
        'Rejected' => 'rejected',
        'Needs Revision' => 'revision',
        default => 'pending',
    };
}

function finance_logistics_is_actionable(array $row): bool
{
    return in_array(finance_logistics_display_status_label($row), ['Pending', 'Awaiting Quotation'], true);
}

function finance_hr_request_code(int|string $id, ?string $requestCode = null): string
{
    $candidate = trim((string) $requestCode);
    if ($candidate !== '') {
        return $candidate;
    }

    if (is_int($id) || ctype_digit((string) $id)) {
        return 'HR-' . str_pad((string) max(0, (int) $id), 4, '0', STR_PAD_LEFT);
    }

    return 'HR-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $id) ?: 'REQ', 0, 8));
}

function finance_hr_status_label(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        'approved', 'approve' => 'Approved',
        'released', 'released funds', 'funds released' => 'Released',
        'rejected', 'reject', 'declined' => 'Rejected',
        'needs revision', 'needs_revision', 'revision', 'needs review', 'needs_review', 'for revision' => 'Needs Revision',
        'pending', 'waiting for approval', 'for approval', 'submitted', 'under review' => 'Pending',
        default => 'Pending',
    };
}

function finance_hr_status_badge_class(?string $status): string
{
    return match (finance_hr_status_label($status)) {
        'Approved' => 'approved',
        'Released' => 'badge-partial',
        'Rejected' => 'rejected',
        'Needs Revision' => 'revision',
        default => 'pending',
    };
}

function finance_hr_request_type_label(array $row): string
{
    $value = trim((string) ($row['request_type'] ?? ''));
    if ($value !== '') {
        return $value;
    }

    $haystack = strtolower(trim((string) (($row['title'] ?? '') . ' ' . ($row['description'] ?? '') . ' ' . ($row['request_details'] ?? ''))));
    if (str_contains($haystack, 'salary')) {
        return 'Salary Adjustment';
    }
    if (str_contains($haystack, 'payroll')) {
        return 'Payroll Concern';
    }
    if (str_contains($haystack, 'reimburse')) {
        return 'Reimbursement';
    }
    if (str_contains($haystack, 'training')) {
        return 'Training Request';
    }
    if (str_contains($haystack, 'benefit')) {
        return 'Benefits Request';
    }

    return 'New Hire Equipment';
}

function finance_hr_amount_label(array $row): string
{
    if (isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== '') {
        return 'PHP ' . finance_money((float) $row['amount']);
    }

    return 'Pending Review';
}

function finance_hr_review_filters(array $filters): array
{
    return [
        'search' => trim((string) ($filters['search'] ?? '')),
        'status' => trim((string) ($filters['status'] ?? 'Pending')),
    ];
}

function finance_hr_status_values(string $status): array
{
    return match (strtolower(trim($status))) {
        'all' => [],
        'approved' => ['approved', 'approve'],
        'released' => ['released', 'released funds', 'funds released'],
        'rejected' => ['rejected', 'reject', 'declined'],
        'needs revision' => ['needs revision', 'needs_revision', 'revision', 'needs review', 'needs_review', 'for revision'],
        'pending' => ['pending', 'waiting for approval', 'for approval', 'submitted', 'under review'],
        default => [strtolower(trim($status))],
    };
}

function finance_hr_supabase_status_field(array $row): string
{
    foreach (['approval_status', 'request_status', 'status'] as $candidate) {
        if (array_key_exists($candidate, $row)) {
            return $candidate;
        }
    }

    return 'status';
}

function finance_hr_supabase_table(): string
{
    return 'hr3_salary_requests';
}

function finance_supabase_users_map(SupabaseClient $client, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $ids
    ), static fn (string $id): bool => $id !== '')));

    if ($ids === []) {
        return [];
    }

    $res = $client->get('users', [
        'id' => 'in.(' . implode(',', $ids) . ')',
    ]);

    if (($res['status'] ?? 0) !== 200 || !is_array($res['data'] ?? null)) {
        return [];
    }

    $map = [];
    foreach ($res['data'] as $row) {
        if (!is_array($row) || !isset($row['id'])) {
            continue;
        }

        $userId = trim((string) $row['id']);
        if ($userId === '') {
            continue;
        }

        $map[$userId] = $row;
    }

    return $map;
}

function finance_hr_supabase_pick(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }

    return $default;
}

function finance_normalize_hr_supabase_row(array $row, array $usersMap = []): array
{
    $employeeId = (string) finance_hr_supabase_pick($row, ['employee_id', 'user_id', 'requester_id'], '');
    $userRow = $employeeId !== '' && isset($usersMap[$employeeId]) && is_array($usersMap[$employeeId]) ? $usersMap[$employeeId] : [];

    $title = (string) finance_hr_supabase_pick($row, ['title', 'subject', 'request_title'], '');
    $description = (string) finance_hr_supabase_pick($row, ['description', 'request_details', 'reason', 'justification', 'notes'], '');
    $requestType = (string) finance_hr_supabase_pick($row, ['request_type', 'type', 'request_category', 'category'], 'Salary Adjustment');
    $statusField = finance_hr_supabase_status_field($row);
    $status = (string) ($row[$statusField] ?? 'Pending');
    $amount = finance_hr_supabase_pick($row, ['amount', 'requested_amount', 'proposed_salary', 'new_salary', 'salary_amount'], null);
    $department = (string) finance_hr_supabase_pick($row, ['department_name', 'department', 'team'], '');
    $employeeName = (string) finance_hr_supabase_pick($row, ['employee_name', 'employee_full_name', 'requester_name', 'name'], '');
    if ($employeeName === '' && $userRow !== []) {
        $employeeName = trim((string) ($userRow['name'] ?? $userRow['full_name'] ?? $userRow['email'] ?? ''));
    }

    $normalized = [
        'id' => $row['id'] ?? 0,
        'request_code' => (string) finance_hr_supabase_pick($row, ['request_code', 'code', 'request_id'], ''),
        'request_date' => (string) finance_hr_supabase_pick($row, ['request_date', 'submitted_at', 'requested_at', 'created_at', 'effective_date'], ''),
        'request_type' => $requestType,
        'title' => $title,
        'description' => $description,
        'request_details' => $description !== '' ? $description : $title,
        'status' => $status,
        'amount' => $amount !== null && $amount !== '' ? (float) $amount : null,
        'remarks' => (string) finance_hr_supabase_pick($row, ['remarks', 'reviewer_remarks', 'admin_remarks', 'comment'], ''),
        'created_at' => (string) finance_hr_supabase_pick($row, ['created_at', 'submitted_at', 'requested_at'], ''),
        'updated_at' => (string) finance_hr_supabase_pick($row, ['updated_at', 'reviewed_at', 'approved_at'], ''),
        'employee_id' => $employeeId,
        'department_id' => finance_hr_supabase_pick($row, ['department_id'], null),
        'department' => $department,
        'employee_name' => $employeeName !== '' ? $employeeName : 'HR Staff',
        'department_name' => $department !== '' ? $department : 'Human Resources',
        'source' => 'supabase',
        '_raw' => $row,
    ];

    $normalized['request_code'] = finance_hr_request_code((int) ($normalized['id'] ?? 0), (string) ($normalized['request_code'] ?? ''));
    $normalized['request_type_label'] = finance_hr_request_type_label($normalized);
    $normalized['status_label'] = finance_hr_status_label((string) ($normalized['status'] ?? ''));
    $normalized['status_badge_class'] = finance_hr_status_badge_class((string) ($normalized['status'] ?? ''));
    $normalized['amount_label'] = finance_hr_amount_label($normalized);
    $normalized['request_title'] = trim((string) $normalized['title']) !== ''
        ? (string) $normalized['title']
        : (trim((string) $normalized['request_details']) !== '' ? (string) $normalized['request_details'] : 'HR Request');
    $normalized['request_description'] = trim((string) $normalized['description']) !== ''
        ? (string) $normalized['description']
        : (trim((string) $normalized['request_details']) !== '' ? (string) $normalized['request_details'] : 'No description provided.');
    $normalized['request_date_display'] = (string) ($normalized['request_date'] !== '' ? $normalized['request_date'] : ($normalized['created_at'] ?? ''));

    return $normalized;
}

function finance_get_hr_supabase_request_pool(): ?array
{
    static $cacheReady = false;
    static $cache = null;

    if ($cacheReady) {
        return $cache;
    }

    $cacheReady = true;
    $client = supabase_init();
    if (!$client) {
        $cache = null;
        return null;
    }

    $res = $client->get(finance_hr_supabase_table());
    if (($res['status'] ?? 0) !== 200 || !is_array($res['data'] ?? null)) {
        $cache = null;
        return null;
    }

    $employeeIds = [];
    foreach ($res['data'] as $row) {
        if (is_array($row) && isset($row['employee_id']) && $row['employee_id'] !== null && $row['employee_id'] !== '') {
            $employeeIds[] = (string) $row['employee_id'];
        }
    }

    $usersMap = finance_supabase_users_map($client, $employeeIds);
    $rows = array_map(
        static fn (array $row): array => finance_normalize_hr_supabase_row($row, $usersMap),
        array_values(array_filter($res['data'], static fn ($row): bool => is_array($row)))
    );

    usort($rows, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['request_date_display'] ?? $a['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['request_date_display'] ?? $b['created_at'] ?? '')) ?: 0;
        if ($aTs === $bTs) {
            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        }
        return $bTs <=> $aTs;
    });

    $cache = $rows;

    return $cache;
}

function finance_filter_hr_supabase_rows(array $rows, array $filters): array
{
    $normalizedFilters = finance_hr_review_filters($filters);
    $search = strtolower($normalizedFilters['search']);
    $status = strtolower($normalizedFilters['status']);

    return array_values(array_filter($rows, static function (array $row) use ($search, $status): bool {
        if ($status !== '' && $status !== 'all') {
            if (strtolower(finance_hr_status_label((string) ($row['status'] ?? ''))) !== $status) {
                return false;
            }
        }

        if ($search === '') {
            return true;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) ($row['request_code'] ?? ''),
            (string) ($row['request_type_label'] ?? $row['request_type'] ?? ''),
            (string) ($row['employee_name'] ?? ''),
            (string) ($row['department_name'] ?? ''),
            (string) ($row['request_title'] ?? ''),
            (string) ($row['request_description'] ?? ''),
        ])));

        return str_contains($haystack, $search);
    }));
}

function finance_get_hr_request_source_row(PDO $pdo, string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }

    $supabaseRows = finance_get_hr_supabase_request_pool();
    if ($supabaseRows !== null) {
        foreach ($supabaseRows as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    return finance_get_hr_request_detail($pdo, $id);
}

function finance_hr_supabase_update_payload(array $request, string $action, string $remarks = ''): array
{
    $raw = isset($request['_raw']) && is_array($request['_raw']) ? $request['_raw'] : [];
    $payload = [];
    $statusField = finance_hr_supabase_status_field($raw);

    $normalizedAction = strtolower(trim($action));
    $payload[$statusField] = match ($normalizedAction) {
        'approve' => 'approved',
        'revision', 'request_revision', 'return' => 'needs revision',
        default => 'rejected',
    };

    $remarksValue = trim($remarks);
    if ($remarksValue !== '') {
        foreach (['remarks', 'reviewer_remarks', 'admin_remarks', 'comment'] as $candidate) {
            if (array_key_exists($candidate, $raw)) {
                $payload[$candidate] = $remarksValue;
                break;
            }
        }
    }

    foreach (['updated_at', 'reviewed_at', 'approved_at'] as $candidate) {
        if (array_key_exists($candidate, $raw)) {
            $payload[$candidate] = date('c');
            break;
        }
    }

    if (!isset($payload[$statusField])) {
        $payload[$statusField] = match ($normalizedAction) {
            'approve' => 'approved',
            'revision', 'request_revision', 'return' => 'needs revision',
            default => 'rejected',
        };
    }

    return $payload;
}

function finance_get_hr_review_requests(PDO $pdo, array $filters = [], int $limit = 10, int $offset = 0): array
{
    $supabaseRows = finance_get_hr_supabase_request_pool();
    if ($supabaseRows !== null) {
        return array_slice(finance_filter_hr_supabase_rows($supabaseRows, $filters), max(0, $offset), max(0, $limit));
    }

    finance_bootstrap($pdo);
    if (!finance_table_exists($pdo, 'hr_requests')) {
        return [];
    }

    $normalizedFilters = finance_hr_review_filters($filters);
    $schema = finance_schema_prefix($pdo);
    $likeOp = finance_like_operator($pdo);
    $hasEmployees = finance_table_exists($pdo, 'employees');
    $hasDepartments = finance_table_exists($pdo, 'departments');
    $hasRequestCode = finance_column_exists($pdo, 'hr_requests', 'request_code');
    $hasRequestDate = finance_column_exists($pdo, 'hr_requests', 'request_date');
    $hasRequestType = finance_column_exists($pdo, 'hr_requests', 'request_type');
    $hasEmployeeId = finance_column_exists($pdo, 'hr_requests', 'employee_id');
    $hasTitle = finance_column_exists($pdo, 'hr_requests', 'title');
    $hasDescription = finance_column_exists($pdo, 'hr_requests', 'description');
    $hasDepartmentId = finance_column_exists($pdo, 'hr_requests', 'department_id');
    $hasDepartment = finance_column_exists($pdo, 'hr_requests', 'department');
    $hasAmount = finance_column_exists($pdo, 'hr_requests', 'amount');
    $hasRemarks = finance_column_exists($pdo, 'hr_requests', 'remarks');

    $select = [
        'hr.id',
        $hasRequestCode ? 'hr.request_code' : "'' AS request_code",
        $hasRequestDate ? 'hr.request_date' : 'NULL AS request_date',
        $hasRequestType ? 'hr.request_type' : "'' AS request_type",
        $hasTitle ? 'hr.title' : "'' AS title",
        $hasDescription ? 'hr.description' : "'' AS description",
        'hr.request_details',
        'hr.status',
        $hasAmount ? 'hr.amount' : 'NULL AS amount',
        $hasRemarks ? 'hr.remarks' : 'NULL AS remarks',
        'hr.created_at',
        'hr.updated_at',
    ];

    $select[] = $hasEmployeeId ? 'hr.employee_id' : 'NULL AS employee_id';
    $select[] = $hasDepartmentId ? 'hr.department_id' : 'NULL AS department_id';
    $select[] = $hasDepartment ? 'hr.department' : "'' AS department";

    if ($hasEmployees && $hasEmployeeId && finance_column_exists($pdo, 'employees', 'id') && finance_column_exists($pdo, 'employees', 'name')) {
        $select[] = 'emp.name AS employee_name';
    } else {
        $select[] = 'NULL AS employee_name';
    }
    if ($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'id') && finance_column_exists($pdo, 'departments', 'name')) {
        $select[] = 'dept.name AS department_name';
    } else {
        $select[] = 'NULL AS department_name';
    }

    $sql = "SELECT " . implode(', ', $select) . " FROM {$schema}hr_requests hr";
    if ($hasEmployees && $hasEmployeeId && finance_column_exists($pdo, 'employees', 'id') && finance_column_exists($pdo, 'employees', 'name')) {
        $sql .= " LEFT JOIN {$schema}employees emp ON emp.id = hr.employee_id";
    }
    if ($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'id') && finance_column_exists($pdo, 'departments', 'name')) {
        $sql .= " LEFT JOIN {$schema}departments dept ON dept.id = hr.department_id";
    }
    $sql .= " WHERE 1 = 1";
    $params = [];

    if ($normalizedFilters['search'] !== '') {
        $sql .= " AND (
            COALESCE(" . ($hasRequestCode ? 'hr.request_code' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasTitle ? 'hr.title' : 'hr.request_details') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasDescription ? 'hr.description' : 'hr.request_details') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasRequestType ? 'hr.request_type' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasEmployees && $hasEmployeeId && finance_column_exists($pdo, 'employees', 'name')) ? 'emp.name' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'name')) ? 'dept.name' : ($hasDepartment ? 'hr.department' : "''")) . ", '') {$likeOp} :search
        )";
        $params[':search'] = '%' . $normalizedFilters['search'] . '%';
    }

    if ($normalizedFilters['status'] !== '' && strtolower($normalizedFilters['status']) !== 'all') {
        $statusValues = finance_hr_status_values($normalizedFilters['status']);
        if ($statusValues !== []) {
            $statusPlaceholders = [];
            foreach ($statusValues as $index => $statusValue) {
                $placeholder = ':status_' . $index;
                $statusPlaceholders[] = $placeholder;
                $params[$placeholder] = strtolower($statusValue);
            }
            $sql .= " AND LOWER(COALESCE(hr.status, 'pending')) IN (" . implode(', ', $statusPlaceholders) . ")";
        }
    }

    $sql .= " ORDER BY COALESCE(hr.request_date, DATE(hr.created_at)) DESC, hr.id DESC LIMIT :limit_value OFFSET :offset_value";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit_value', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_value', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['request_code'] = finance_hr_request_code((int) ($row['id'] ?? 0), (string) ($row['request_code'] ?? ''));
        $row['request_type_label'] = finance_hr_request_type_label($row);
        $row['employee_name'] = trim((string) ($row['employee_name'] ?? '')) !== ''
            ? (string) $row['employee_name']
            : 'HR Staff';
        $row['department_name'] = trim((string) ($row['department_name'] ?? '')) !== ''
            ? (string) $row['department_name']
            : (trim((string) ($row['department'] ?? '')) !== '' ? (string) $row['department'] : 'Human Resources');
        $row['status_label'] = finance_hr_status_label((string) ($row['status'] ?? ''));
        $row['status_badge_class'] = finance_hr_status_badge_class((string) ($row['status'] ?? ''));
        $row['amount_label'] = finance_hr_amount_label($row);
        $row['request_title'] = trim((string) ($row['title'] ?? '')) !== ''
            ? (string) $row['title']
            : (string) ($row['request_details'] ?? 'HR Request');
        $row['request_description'] = trim((string) ($row['description'] ?? '')) !== ''
            ? (string) $row['description']
            : (string) ($row['request_details'] ?? 'No description provided.');
        $row['request_date_display'] = (string) ($row['request_date'] ?? $row['created_at'] ?? '');
    }

    return $rows;
}

function finance_count_hr_review_requests(PDO $pdo, array $filters = []): int
{
    $supabaseRows = finance_get_hr_supabase_request_pool();
    if ($supabaseRows !== null) {
        return count(finance_filter_hr_supabase_rows($supabaseRows, $filters));
    }

    finance_bootstrap($pdo);
    if (!finance_table_exists($pdo, 'hr_requests')) {
        return 0;
    }

    $normalizedFilters = finance_hr_review_filters($filters);
    $schema = finance_schema_prefix($pdo);
    $likeOp = finance_like_operator($pdo);
    $hasEmployees = finance_table_exists($pdo, 'employees');
    $hasDepartments = finance_table_exists($pdo, 'departments');
    $hasRequestCode = finance_column_exists($pdo, 'hr_requests', 'request_code');
    $hasRequestType = finance_column_exists($pdo, 'hr_requests', 'request_type');
    $hasEmployeeId = finance_column_exists($pdo, 'hr_requests', 'employee_id');
    $hasTitle = finance_column_exists($pdo, 'hr_requests', 'title');
    $hasDescription = finance_column_exists($pdo, 'hr_requests', 'description');
    $hasDepartmentId = finance_column_exists($pdo, 'hr_requests', 'department_id');
    $hasDepartment = finance_column_exists($pdo, 'hr_requests', 'department');

    $sql = "SELECT COUNT(*) FROM {$schema}hr_requests hr";
    if ($hasEmployees && $hasEmployeeId && finance_column_exists($pdo, 'employees', 'id') && finance_column_exists($pdo, 'employees', 'name')) {
        $sql .= " LEFT JOIN {$schema}employees emp ON emp.id = hr.employee_id";
    }
    if ($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'id') && finance_column_exists($pdo, 'departments', 'name')) {
        $sql .= " LEFT JOIN {$schema}departments dept ON dept.id = hr.department_id";
    }
    $sql .= " WHERE 1 = 1";
    $params = [];

    if ($normalizedFilters['search'] !== '') {
        $sql .= " AND (
            COALESCE(" . ($hasRequestCode ? 'hr.request_code' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasTitle ? 'hr.title' : 'hr.request_details') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasDescription ? 'hr.description' : 'hr.request_details') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasRequestType ? 'hr.request_type' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasEmployees && $hasEmployeeId && finance_column_exists($pdo, 'employees', 'name')) ? 'emp.name' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'name')) ? 'dept.name' : ($hasDepartment ? 'hr.department' : "''")) . ", '') {$likeOp} :search
        )";
        $params[':search'] = '%' . $normalizedFilters['search'] . '%';
    }

    if ($normalizedFilters['status'] !== '' && strtolower($normalizedFilters['status']) !== 'all') {
        $statusValues = finance_hr_status_values($normalizedFilters['status']);
        if ($statusValues !== []) {
            $statusPlaceholders = [];
            foreach ($statusValues as $index => $statusValue) {
                $placeholder = ':status_' . $index;
                $statusPlaceholders[] = $placeholder;
                $params[$placeholder] = strtolower($statusValue);
            }
            $sql .= " AND LOWER(COALESCE(hr.status, 'pending')) IN (" . implode(', ', $statusPlaceholders) . ")";
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function finance_get_hr_request_detail(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $supabaseRows = finance_get_hr_supabase_request_pool();
    if ($supabaseRows !== null) {
        foreach ($supabaseRows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    if (!finance_table_exists($pdo, 'hr_requests')) {
        return null;
    }

    $rows = finance_get_hr_review_requests($pdo, ['status' => 'all'], 1000, 0);
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
}

function finance_get_request_history_entries(PDO $pdo, string $module, string $requestId, int $limit = 5): array
{
    $requestId = trim($requestId);
    if ($requestId === '' || !finance_table_exists($pdo, 'request_history')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT action, status, remarks, acted_by, created_at
        FROM {$schema}request_history
        WHERE module = :module AND request_id = :request_id
        ORDER BY created_at DESC, id DESC
        LIMIT :limit_value
    ");
    $stmt->bindValue(':module', strtoupper(trim($module)), PDO::PARAM_STR);
    $stmt->bindValue(':request_id', $requestId, PDO::PARAM_STR);
    $stmt->bindValue(':limit_value', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function finance_get_request_history_map(PDO $pdo, string $module, array $requestIds, int $limit = 5): array
{
    $requestIds = array_values(array_unique(array_filter(array_map(static fn ($id): string => trim((string) $id), $requestIds), static fn (string $id): bool => $id !== '')));
    if ($requestIds === [] || !finance_table_exists($pdo, 'request_history')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $placeholders = [];
    $params = [
        ':module' => strtoupper(trim($module)),
    ];

    foreach ($requestIds as $index => $requestId) {
        $placeholder = ':request_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $requestId;
    }

    $stmt = $pdo->prepare("
        SELECT request_id, action, status, remarks, acted_by, created_at
        FROM {$schema}request_history
        WHERE module = :module
          AND request_id IN (" . implode(', ', $placeholders) . ")
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute($params);

    $grouped = [];
    $limit = max(1, $limit);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $requestId = trim((string) ($row['request_id'] ?? ''));
        if ($requestId === '') {
            continue;
        }
        $grouped[$requestId] ??= [];
        if (count($grouped[$requestId]) >= $limit) {
            continue;
        }
        $grouped[$requestId][] = $row;
    }

    foreach ($requestIds as $requestId) {
        $grouped[$requestId] ??= [];
    }

    return $grouped;
}

function finance_get_hr_request_summary(PDO $pdo, ?array $rows = null): array
{
    return finance_get_hr_request_summary_from_rows($rows ?? finance_get_hr_review_requests($pdo, ['status' => 'All'], 1000, 0));
}

function finance_get_hr_request_summary_from_rows(array $rows): array
{
    $today = date('Y-m-d');
    $summary = [
        'total_pending_requests' => 0,
        'approved_today' => 0,
        'rejected_requests' => 0,
        'total_pending_amount' => 0.0,
    ];

    foreach ($rows as $row) {
        $statusLabel = finance_hr_status_label((string) ($row['status'] ?? 'Pending'));
        $amountValue = finance_numeric_from_mixed($row['amount'] ?? null);
        $requestDate = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');

        if ($statusLabel === 'Pending') {
            $summary['total_pending_requests']++;
            if ($amountValue !== null) {
                $summary['total_pending_amount'] += $amountValue;
            }
        } elseif ($statusLabel === 'Rejected') {
            $summary['rejected_requests']++;
        }

        if ($statusLabel === 'Approved' && $requestDate !== '' && date('Y-m-d', strtotime($requestDate)) === $today) {
            $summary['approved_today']++;
        }
    }

    return $summary;
}

function finance_get_logistics_request_summary(PDO $pdo, ?array $rows = null): array
{
    return finance_get_logistics_request_summary_from_rows($rows ?? finance_get_logistics_review_requests($pdo, ['status' => 'All'], 1000, 0));
}

function finance_get_logistics_request_summary_from_rows(array $rows): array
{
    $today = date('Y-m-d');
    $summary = [
        'total_pending_requests' => 0,
        'approved_today' => 0,
        'total_amount' => 0.0,
        'pending_disbursement' => 0,
        'released_today' => 0,
    ];

    foreach ($rows as $row) {
        $displayStatus = finance_logistics_display_status_label($row);
        $amountValue = finance_numeric_from_mixed($row['amount'] ?? null);
        $updatedAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');

        if ($displayStatus === 'Pending') {
            $summary['total_pending_requests']++;
        }
        if (in_array($displayStatus, ['Approved', 'Ready for Disbursement'], true)) {
            $summary['pending_disbursement']++;
        }
        if ($amountValue) {
            $summary['total_amount'] += $amountValue;
        }
        if ($updatedAt !== '' && date('Y-m-d', strtotime($updatedAt)) === $today) {
            if (in_array($displayStatus, ['Approved', 'Ready for Disbursement'], true)) {
                $summary['approved_today']++;
            }
            if ($displayStatus === 'Released') {
                $summary['released_today']++;
            }
        }
    }

    return $summary;
}

function finance_get_logistics_linked_records(PDO $pdo, int $requestId): array
{
    $requestId = (int) $requestId;
    if ($requestId <= 0) {
        return [
            'disbursement' => null,
            'ap' => null,
        ];
    }

    $linkedDisbursement = null;
    foreach (getDisbursementList($pdo, ['request_source' => 'LOGISTICS']) as $row) {
        if ((int) ($row['request_id'] ?? 0) === $requestId) {
            $linkedDisbursement = $row;
            break;
        }
    }

    $linkedAp = null;
    foreach (getAPList($pdo) as $row) {
        if (strtoupper((string) ($row['source_module'] ?? '')) === 'LOGISTICS' && (int) ($row['source_request_id'] ?? 0) === $requestId) {
            $linkedAp = $row;
            break;
        }
    }

    return [
        'disbursement' => $linkedDisbursement,
        'ap' => $linkedAp,
    ];
}

function finance_logistics_review_filters(array $filters): array
{
    return [
        'search' => trim((string) ($filters['search'] ?? '')),
        'status' => trim((string) ($filters['status'] ?? 'Pending')),
    ];
}

function finance_logistics_supabase_table(): string
{
    return 'log1_procurement';
}

function finance_logistics_supabase_invoice_table(): string
{
    return 'log1_procurement_invoices';
}

function finance_logistics_supabase_collection_table(): string
{
    return 'log2_collection';
}

function finance_logistics_document_tracking_table(): string
{
    return 'log2_document_tracking';
}

function finance_reset_logistics_document_tracking_cache(): void
{
    $GLOBALS['finance_logistics_document_tracking_cache_ready'] = false;
    $GLOBALS['finance_logistics_document_tracking_cache'] = [];
}

function finance_ensure_logistics_document_tracking_support_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

    $metaSql = $isPg
        ? "CREATE TABLE IF NOT EXISTS {$schema}logistics_document_tracking_meta (
            id BIGSERIAL PRIMARY KEY,
            document_id BIGINT NOT NULL UNIQUE,
            local_status VARCHAR(40) NULL,
            ledger_entry_id BIGINT NULL,
            ledger_reference_no VARCHAR(100) NULL,
            remarks TEXT NULL,
            verified_at TIMESTAMPTZ NULL,
            archived_at TIMESTAMPTZ NULL,
            flagged_at TIMESTAMPTZ NULL,
            updated_at TIMESTAMPTZ DEFAULT NOW()
        )"
        : "CREATE TABLE IF NOT EXISTS {$schema}logistics_document_tracking_meta (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            document_id BIGINT NOT NULL UNIQUE,
            local_status VARCHAR(40) NULL,
            ledger_entry_id BIGINT NULL,
            ledger_reference_no VARCHAR(100) NULL,
            remarks TEXT NULL,
            verified_at DATETIME NULL,
            archived_at DATETIME NULL,
            flagged_at DATETIME NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";

    $historySql = $isPg
        ? "CREATE TABLE IF NOT EXISTS {$schema}logistics_document_tracking_history (
            id BIGSERIAL PRIMARY KEY,
            document_id BIGINT NOT NULL,
            action VARCHAR(40) NOT NULL,
            status VARCHAR(40) NULL,
            remarks TEXT NULL,
            ledger_entry_id BIGINT NULL,
            ledger_reference_no VARCHAR(100) NULL,
            acted_by VARCHAR(150) NULL,
            created_at TIMESTAMPTZ DEFAULT NOW()
        )"
        : "CREATE TABLE IF NOT EXISTS {$schema}logistics_document_tracking_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            document_id BIGINT NOT NULL,
            action VARCHAR(40) NOT NULL,
            status VARCHAR(40) NULL,
            remarks TEXT NULL,
            ledger_entry_id BIGINT NULL,
            ledger_reference_no VARCHAR(100) NULL,
            acted_by VARCHAR(150) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";

    $pdo->exec($metaSql);
    $pdo->exec($historySql);
    $ensured = true;
}

function finance_logistics_document_tracking_meta_map(PDO $pdo, array $documentIds): array
{
    finance_ensure_logistics_document_tracking_support_tables($pdo);
    $documentIds = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $documentIds), static fn (int $id): bool => $id > 0)));
    if ($documentIds === []) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $placeholders = [];
    $params = [];
    foreach ($documentIds as $index => $documentId) {
        $placeholder = ':document_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $documentId;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM {$schema}logistics_document_tracking_meta
        WHERE document_id IN (" . implode(', ', $placeholders) . ")
    ");
    $stmt->execute($params);

    $map = [];
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $map[(int) ($row['document_id'] ?? 0)] = $row;
    }

    return $map;
}

function finance_logistics_document_tracking_history_map(PDO $pdo, array $documentIds, int $limit = 10): array
{
    finance_ensure_logistics_document_tracking_support_tables($pdo);
    $documentIds = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $documentIds), static fn (int $id): bool => $id > 0)));
    if ($documentIds === []) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $placeholders = [];
    $params = [];
    foreach ($documentIds as $index => $documentId) {
        $placeholder = ':document_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $documentId;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM {$schema}logistics_document_tracking_history
        WHERE document_id IN (" . implode(', ', $placeholders) . ")
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute($params);

    $grouped = [];
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $documentId = (int) ($row['document_id'] ?? 0);
        if ($documentId <= 0) {
            continue;
        }
        $grouped[$documentId] ??= [];
        if (count($grouped[$documentId]) >= max(1, $limit)) {
            continue;
        }
        $grouped[$documentId][] = $row;
    }

    foreach ($documentIds as $documentId) {
        $grouped[$documentId] ??= [];
    }

    return $grouped;
}

function finance_logistics_document_tracking_effective_status(array $row, ?array $metaRow = null): string
{
    $status = trim((string) ($metaRow['local_status'] ?? $row['status'] ?? ''));
    if ($status === '') {
        $status = trim((string) ($row['file_url'] ?? '')) === '' ? 'Missing File' : 'Pending';
    }

    return match (strtolower($status)) {
        'verified' => 'Verified',
        'archived' => 'Archived',
        'flagged', 'flagged for review', 'for review' => 'Flagged',
        'missing file' => 'Missing File',
        'linked to ledger', 'linked' => 'Linked to Ledger',
        default => 'Pending',
    };
}

function finance_logistics_document_tracking_shared_reason(array $row): string
{
    foreach (['review_reason', 'review_notes', 'remarks', 'notes', 'comment'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    foreach (['meta_data', 'metadata'] as $key) {
        $meta = $row[$key] ?? null;
        if (!is_array($meta)) {
            continue;
        }
        foreach (['review_reason', 'review_notes', 'remarks', 'notes', 'comment'] as $metaKey) {
            $value = trim((string) ($meta[$metaKey] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function finance_logistics_document_tracking_supabase_payload(array $document, string $nextStatus, string $remarks = '', ?int $ledgerEntryId = null, string $ledgerReferenceNo = ''): array
{
    $raw = isset($document['_raw']) && is_array($document['_raw']) ? $document['_raw'] : [];
    $payload = ['status' => $nextStatus];
    $trimmedRemarks = trim($remarks);
    $isFlagAction = strcasecmp($nextStatus, 'Flagged') === 0;
    $flaggedBy = trim((string) ($_SESSION['user_name'] ?? $_SESSION['email'] ?? $_SESSION['username'] ?? 'Finance User'));
    $flaggedAt = date('c');

    if ($trimmedRemarks !== '') {
        $payload['review_reason'] = $trimmedRemarks;
        $payload['review_notes'] = $trimmedRemarks;
        $payload['remarks'] = $trimmedRemarks;
        $payload['notes'] = $trimmedRemarks;
        $payload['comment'] = $trimmedRemarks;
    }

    if ($ledgerEntryId !== null) {
        $payload['ledger_entry_id'] = $ledgerEntryId;
    }
    if ($ledgerReferenceNo !== '') {
        $payload['ledger_reference_no'] = $ledgerReferenceNo;
    }
    if ($isFlagAction) {
        $payload['flagged_by'] = $flaggedBy;
        $payload['flagged_at'] = $flaggedAt;
    }

    foreach (['meta_data', 'metadata'] as $key) {
        $meta = $raw[$key] ?? null;
        if (!is_array($meta)) {
            $meta = [];
        }
        if ($trimmedRemarks !== '') {
            $meta['review_reason'] = $trimmedRemarks;
            $meta['review_notes'] = $trimmedRemarks;
            $meta['remarks'] = $trimmedRemarks;
        }
        $meta['review_status'] = $nextStatus;
        if ($isFlagAction) {
            $meta['flagged_by'] = $flaggedBy;
            $meta['flagged_at'] = $flaggedAt;
        }
        if ($ledgerEntryId !== null) {
            $meta['ledger_entry_id'] = $ledgerEntryId;
        }
        if ($ledgerReferenceNo !== '') {
            $meta['ledger_reference_no'] = $ledgerReferenceNo;
        }
        $payload[$key] = $meta;
    }

    return $payload;
}

function finance_get_ledger_entry_by_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !finance_table_exists($pdo, 'general_ledger')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("SELECT * FROM {$schema}general_ledger WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function finance_logistics_document_tracking_record_history(PDO $pdo, int $documentId, string $action, ?string $status = null, string $remarks = '', ?int $ledgerEntryId = null, ?string $ledgerReferenceNo = null): void
{
    finance_ensure_logistics_document_tracking_support_tables($pdo);
    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO {$schema}logistics_document_tracking_history (
            document_id,
            action,
            status,
            remarks,
            ledger_entry_id,
            ledger_reference_no,
            acted_by,
            created_at
        ) VALUES (
            :document_id,
            :action,
            :status,
            :remarks,
            :ledger_entry_id,
            :ledger_reference_no,
            :acted_by,
            NOW()
        )
    ");
    $stmt->execute([
        ':document_id' => $documentId,
        ':action' => strtoupper(trim($action)),
        ':status' => $status !== null ? trim($status) : null,
        ':remarks' => trim($remarks) !== '' ? trim($remarks) : null,
        ':ledger_entry_id' => $ledgerEntryId,
        ':ledger_reference_no' => $ledgerReferenceNo !== null && trim($ledgerReferenceNo) !== '' ? trim($ledgerReferenceNo) : null,
        ':acted_by' => (string) ($_SESSION['user_name'] ?? 'Finance User'),
    ]);
}

function finance_update_logistics_document_tracking_record(PDO $pdo, int $documentId, string $action, array $payload = []): void
{
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Document ID is required.');
    }

    finance_ensure_logistics_document_tracking_support_tables($pdo);
    $client = supabase_init();
    if (!$client) {
        throw new RuntimeException('Supabase Logistics2 connection is not available.');
    }

    $records = finance_get_logistics_document_tracking_records($pdo);
    $document = null;
    foreach ($records as $record) {
        if ((int) ($record['id'] ?? 0) === $documentId) {
            $document = $record;
            break;
        }
    }
    if (!$document) {
        throw new RuntimeException('Logistics2 document not found.');
    }

    $action = strtolower(trim($action));
    $nextStatus = match ($action) {
        'verify' => 'Verified',
        'archive' => 'Archived',
        'flag' => 'Flagged',
        'link' => 'Linked to Ledger',
        default => throw new InvalidArgumentException('Unsupported document action.'),
    };

    $remarks = trim((string) ($payload['remarks'] ?? ''));
    if ($action === 'flag' && $remarks === '') {
        throw new InvalidArgumentException('Review reason is required.');
    }
    $ledgerEntryId = isset($payload['ledger_entry_id']) && (int) $payload['ledger_entry_id'] > 0 ? (int) $payload['ledger_entry_id'] : null;
    $ledgerReferenceNo = trim((string) ($payload['ledger_reference_no'] ?? ''));
    if ($action === 'link' && $ledgerEntryId === null && $ledgerReferenceNo === '') {
        throw new InvalidArgumentException('Ledger entry ID or ledger reference number is required.');
    }
    if ($ledgerEntryId !== null && !finance_get_ledger_entry_by_id($pdo, $ledgerEntryId)) {
        throw new RuntimeException('Selected ledger entry was not found.');
    }

    $statusField = 'status';
    if (isset($document['_raw']) && is_array($document['_raw']) && array_key_exists('status', $document['_raw'])) {
        $statusField = 'status';
    }
    $supabasePayload = finance_logistics_document_tracking_supabase_payload($document, $nextStatus, $remarks, $ledgerEntryId, $ledgerReferenceNo);
    if ($statusField !== 'status') {
        $supabasePayload[$statusField] = $nextStatus;
    }
    $result = $client->update(finance_logistics_document_tracking_table(), $supabasePayload, 'id=eq.' . rawurlencode((string) $documentId));
    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
        throw new RuntimeException('Failed to update Logistics2 document status.');
    }

    $now = date('c');
    $schema = finance_schema_prefix($pdo);
    $existingStmt = $pdo->prepare("SELECT * FROM {$schema}logistics_document_tracking_meta WHERE document_id = :document_id LIMIT 1");
    $existingStmt->execute([':document_id' => $documentId]);
    $existingMeta = $existingStmt->fetch() ?: null;

    if ($existingMeta) {
        $metaStmt = $pdo->prepare("
            UPDATE {$schema}logistics_document_tracking_meta
            SET local_status = :local_status,
                ledger_entry_id = :ledger_entry_id,
                ledger_reference_no = :ledger_reference_no,
                remarks = :remarks,
                verified_at = :verified_at,
                archived_at = :archived_at,
                flagged_at = :flagged_at,
                updated_at = NOW()
            WHERE document_id = :document_id
        ");
        $metaStmt->execute([
            ':document_id' => $documentId,
            ':local_status' => $nextStatus,
            ':ledger_entry_id' => $ledgerEntryId ?? ($existingMeta['ledger_entry_id'] ?? null),
            ':ledger_reference_no' => $ledgerReferenceNo !== '' ? $ledgerReferenceNo : ($existingMeta['ledger_reference_no'] ?? null),
            ':remarks' => $remarks !== '' ? $remarks : ($existingMeta['remarks'] ?? null),
            ':verified_at' => $action === 'verify' ? $now : ($existingMeta['verified_at'] ?? null),
            ':archived_at' => $action === 'archive' ? $now : ($existingMeta['archived_at'] ?? null),
            ':flagged_at' => $action === 'flag' ? $now : ($existingMeta['flagged_at'] ?? null),
        ]);
    } else {
        $metaStmt = $pdo->prepare("
            INSERT INTO {$schema}logistics_document_tracking_meta (
                document_id,
                local_status,
                ledger_entry_id,
                ledger_reference_no,
                remarks,
                verified_at,
                archived_at,
                flagged_at,
                updated_at
            ) VALUES (
                :document_id,
                :local_status,
                :ledger_entry_id,
                :ledger_reference_no,
                :remarks,
                :verified_at,
                :archived_at,
                :flagged_at,
                NOW()
            )
        ");
        $metaStmt->execute([
            ':document_id' => $documentId,
            ':local_status' => $nextStatus,
            ':ledger_entry_id' => $ledgerEntryId,
            ':ledger_reference_no' => $ledgerReferenceNo !== '' ? $ledgerReferenceNo : null,
            ':remarks' => $remarks !== '' ? $remarks : null,
            ':verified_at' => $action === 'verify' ? $now : null,
            ':archived_at' => $action === 'archive' ? $now : null,
            ':flagged_at' => $action === 'flag' ? $now : null,
        ]);
    }

    finance_logistics_document_tracking_record_history($pdo, $documentId, $action, $nextStatus, $remarks, $ledgerEntryId, $ledgerReferenceNo !== '' ? $ledgerReferenceNo : null);
    finance_reset_logistics_document_tracking_cache();
}

function finance_get_logistics_document_tracking_records(PDO $pdo, array $filters = []): array
{
    $cacheReady = (bool) ($GLOBALS['finance_logistics_document_tracking_cache_ready'] ?? false);
    $cache = (array) ($GLOBALS['finance_logistics_document_tracking_cache'] ?? []);

    if (!$cacheReady) {
        $cacheReady = true;
        $client = supabase_init();
        if ($client) {
            $res = $client->get(finance_logistics_document_tracking_table(), [
                'select' => '*',
                'order' => 'created_at.desc',
            ]);

            if (($res['status'] ?? 0) === 200 && is_array($res['data'] ?? null)) {
                $cache = array_map(static function (array $row): array {
                    $trackingNumber = trim((string) ($row['tracking_number'] ?? ''));
                    $docTitle = trim((string) ($row['doc_title'] ?? ''));
                    $docType = trim((string) ($row['doc_type'] ?? ''));
                    $status = trim((string) ($row['status'] ?? 'Unspecified'));
                    $sourceModule = trim((string) ($row['source_module'] ?? ''));
                    $fileUrl = trim((string) ($row['file_url'] ?? ''));
                    $createdAt = trim((string) ($row['created_at'] ?? ''));

                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'tracking_number' => $trackingNumber,
                        'doc_title' => $docTitle !== '' ? $docTitle : 'Supporting Logistics Document',
                        'doc_type' => $docType !== '' ? $docType : 'Document',
                        'status' => $status !== '' ? $status : 'Unspecified',
                        'source_module' => $sourceModule !== '' ? $sourceModule : 'Logistics2',
                        'file_url' => $fileUrl,
                        'review_reason' => trim((string) ($row['review_reason'] ?? '')),
                        'flagged_by' => trim((string) ($row['flagged_by'] ?? '')),
                        'flagged_at' => trim((string) ($row['flagged_at'] ?? '')),
                        'created_at' => $createdAt,
                        'created_at_display' => $createdAt !== '' ? date('M d, Y g:i A', strtotime($createdAt)) : '-',
                        '_raw' => $row,
                    ];
                }, array_values(array_filter($res['data'], static fn ($row): bool => is_array($row))));
            }
        }
        $GLOBALS['finance_logistics_document_tracking_cache_ready'] = $cacheReady;
        $GLOBALS['finance_logistics_document_tracking_cache'] = $cache;
    }

    $metaMap = finance_logistics_document_tracking_meta_map($pdo, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $cache));
    $historyMap = finance_logistics_document_tracking_history_map($pdo, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $cache), 8);
    $enriched = array_map(static function (array $row) use ($metaMap, $historyMap, $pdo): array {
        $documentId = (int) ($row['id'] ?? 0);
        $metaRow = $metaMap[$documentId] ?? null;
        $ledgerEntryId = (int) ($metaRow['ledger_entry_id'] ?? 0);
        $ledgerEntry = $ledgerEntryId > 0 ? finance_get_ledger_entry_by_id($pdo, $ledgerEntryId) : null;
        $effectiveStatus = finance_logistics_document_tracking_effective_status($row, $metaRow);
        $remarks = trim((string) ($row['review_reason'] ?? ''));
        if ($remarks === '') {
            $remarks = trim((string) ($metaRow['remarks'] ?? ''));
        }
        if ($remarks === '') {
            $remarks = finance_logistics_document_tracking_shared_reason((array) ($row['_raw'] ?? []));
        }
        if ($remarks === '' && $effectiveStatus === 'Missing File') {
            $remarks = 'No supporting file URL is currently attached.';
        }
        $flaggedBy = trim((string) ($row['flagged_by'] ?? ''));
        if ($flaggedBy === '' && isset($row['_raw']) && is_array($row['_raw'])) {
            $flaggedBy = trim((string) (($row['_raw']['meta_data']['flagged_by'] ?? $row['_raw']['metadata']['flagged_by'] ?? '')));
        }
        $flaggedAtRaw = trim((string) ($row['flagged_at'] ?? ''));
        if ($flaggedAtRaw === '' && isset($row['_raw']) && is_array($row['_raw'])) {
            $flaggedAtRaw = trim((string) (($row['_raw']['meta_data']['flagged_at'] ?? $row['_raw']['metadata']['flagged_at'] ?? '')));
        }
        $flaggedAtDisplay = $flaggedAtRaw !== '' ? date('M d, Y g:i A', strtotime($flaggedAtRaw)) : '';

        $row['status'] = $effectiveStatus;
        $row['remarks'] = $remarks;
        $row['review_reason'] = $remarks;
        $row['flagged_by'] = $flaggedBy;
        $row['flagged_at'] = $flaggedAtRaw;
        $row['flagged_at_display'] = $flaggedAtDisplay;
        $row['ledger_entry_id'] = $ledgerEntryId > 0 ? $ledgerEntryId : null;
        $row['ledger_reference_no'] = trim((string) ($metaRow['ledger_reference_no'] ?? ($ledgerEntry['reference_no'] ?? '')));
        $row['linked_ledger_label'] = $ledgerEntry
            ? ('Ledger #' . (string) ($ledgerEntry['id'] ?? $ledgerEntryId) . ' / ' . (string) ($ledgerEntry['reference_no'] ?? '-'))
            : ($row['ledger_reference_no'] !== '' ? 'Ref ' . $row['ledger_reference_no'] : 'Unlinked');
        $row['history_rows'] = $historyMap[$documentId] ?? [];

        return $row;
    }, $cache);

    $search = strtolower(trim((string) ($filters['search'] ?? '')));
    $statusFilter = strtolower(trim((string) ($filters['status'] ?? '')));
    $moduleFilter = strtolower(trim((string) ($filters['source_module'] ?? '')));
    $linkFilter = strtolower(trim((string) ($filters['link_status'] ?? '')));

    return array_values(array_filter($enriched, static function (array $row) use ($search, $statusFilter, $moduleFilter, $linkFilter): bool {
        if ($statusFilter !== '' && $statusFilter !== 'all' && strtolower((string) ($row['status'] ?? '')) !== $statusFilter) {
            return false;
        }
        if ($moduleFilter !== '' && $moduleFilter !== 'all' && strtolower((string) ($row['source_module'] ?? '')) !== $moduleFilter) {
            return false;
        }
        if ($linkFilter === 'linked' && empty($row['ledger_entry_id']) && trim((string) ($row['ledger_reference_no'] ?? '')) === '') {
            return false;
        }
        if ($linkFilter === 'unlinked' && (!empty($row['ledger_entry_id']) || trim((string) ($row['ledger_reference_no'] ?? '')) !== '')) {
            return false;
        }
        if ($search === '') {
            return true;
        }

        $haystack = strtolower(implode(' ', [
            (string) ($row['tracking_number'] ?? ''),
            (string) ($row['doc_title'] ?? ''),
            (string) ($row['doc_type'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['source_module'] ?? ''),
            (string) ($row['remarks'] ?? ''),
            (string) ($row['flagged_by'] ?? ''),
        ]));

        return str_contains($haystack, $search);
    }));
}

function finance_get_logistics_document_tracking_source_modules(PDO $pdo): array
{
    $modules = [];
    foreach (finance_get_logistics_document_tracking_records($pdo) as $row) {
        $module = trim((string) ($row['source_module'] ?? ''));
        if ($module !== '') {
            $modules[$module] = $module;
        }
    }

    ksort($modules);
    return array_values($modules);
}

function finance_logistics_supabase_table_candidates(): array
{
    return [
        finance_logistics_supabase_table(),
        finance_logistics_supabase_invoice_table(),
    ];
}

function finance_logistics_supabase_pick(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }

    return $default;
}

function finance_logistics_supabase_resolve_table(array $response, string $attemptedTable): string
{
    if (($response['status'] ?? 0) === 404 && is_array($response['data'] ?? null)) {
        $hint = (string) ($response['data']['hint'] ?? '');
        if (preg_match("/table 'public\\.([^']+)'/i", $hint, $matches)) {
            return trim((string) $matches[1]);
        }
    }

    return $attemptedTable;
}

function finance_normalize_logistics_supabase_row(array $row): array
{
    $sourceTable = trim((string) finance_logistics_supabase_pick($row, ['_source_table'], finance_logistics_supabase_table()));
    if ($sourceTable === finance_logistics_document_tracking_table() || array_key_exists('tracking_number', $row) || array_key_exists('doc_title', $row)) {
        $trackingNumber = trim((string) ($row['tracking_number'] ?? ''));
        $docTitle = trim((string) ($row['doc_title'] ?? ''));
        $docType = trim((string) ($row['doc_type'] ?? ''));
        $documentStatus = trim((string) ($row['status'] ?? ''));
        $sourceModule = trim((string) ($row['source_module'] ?? ''));
        $fileUrl = trim((string) ($row['file_url'] ?? ''));
        $createdAt = trim((string) ($row['created_at'] ?? ''));

        $remarks = [];
        $reviewReason = trim((string) ($row['review_reason'] ?? ''));
        if ($fileUrl !== '') {
            $remarks[] = 'File URL: ' . $fileUrl;
        }
        if ($reviewReason !== '') {
            $remarks[] = 'Review Reason: ' . $reviewReason;
        }

        $normalized = [
            'id' => (int) ($row['id'] ?? 0),
            'request_code' => $trackingNumber !== '' ? 'LOG-' . $trackingNumber : finance_logistics_request_code((int) ($row['id'] ?? 0), ''),
            'request_date' => $createdAt,
            'request_type' => $docType !== '' ? $docType : 'Document',
            'title' => $docTitle !== '' ? $docTitle : 'Tracked Logistics Document',
            'description' => $docTitle !== '' ? $docTitle : 'Tracked logistics document',
            'item_name' => $docTitle !== '' ? $docTitle : 'Tracked logistics document',
            'quantity' => null,
            'destination' => $fileUrl,
            'status' => $documentStatus !== '' ? $documentStatus : 'Pending',
            'amount' => null,
            'remarks' => implode(' | ', $remarks),
            'review_reason' => $reviewReason,
            'flagged_by' => trim((string) ($row['flagged_by'] ?? '')),
            'flagged_at' => trim((string) ($row['flagged_at'] ?? '')),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'requested_by' => $sourceModule !== '' ? $sourceModule : 'Logistics',
            'requested_by_name' => $sourceModule !== '' ? $sourceModule : 'Logistics',
            'department_id' => null,
            'department_name' => 'Logistics',
        'payment_method' => '',
        'vendor_id' => null,
        'related_ar_ap_id' => (int) finance_logistics_supabase_pick($row, ['related_ar_ap_id'], 0),
        'related_disbursement_id' => (int) finance_logistics_supabase_pick($row, ['related_disbursement_id'], 0),
        'due_date' => '',
        'tracking_number' => $trackingNumber !== '' ? (int) $trackingNumber : null,
        'source' => 'supabase',
            'source_table' => $sourceTable,
            '_raw' => $row,
        ];

        $normalized['request_type_label'] = finance_logistics_request_type_label($normalized);
        $normalized['status_label'] = finance_logistics_status_label((string) ($normalized['status'] ?? ''));
        $normalized['status_badge_class'] = finance_logistics_status_badge_class((string) ($normalized['status'] ?? ''));
        $normalized['amount_label'] = finance_logistics_amount_label($normalized);
        $normalized['request_title'] = $normalized['title'];
        $normalized['request_description'] = $normalized['description'];
        $normalized['request_date_display'] = $createdAt;

        return $normalized;
    }

    $linkedInvoice = isset($row['_linked_invoice']) && is_array($row['_linked_invoice']) ? $row['_linked_invoice'] : [];
    $rawMetaData = $row['meta_data'] ?? $row['metadata'] ?? null;
    $metaData = is_array($rawMetaData) ? $rawMetaData : [];
    $requestType = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['request_type', 'type', 'category', 'procurement_type'], ''));
    if ($requestType === '') {
        $requestType = trim((string) finance_logistics_meta_value($metaData, ['request_type', 'type', 'category', 'procurement_type'], ''));
    }
    if ($requestType === '') {
        $requestType = 'Procurement';
    }

    $itemName = trim((string) finance_logistics_supabase_pick($row, ['item', 'item_name'], ''));
    $quantityValue = finance_logistics_supabase_pick($row, ['quantity'], null);
    $quantity = $quantityValue !== null && $quantityValue !== '' ? (int) $quantityValue : null;
    $invoiceDescription = trim((string) finance_logistics_supabase_pick($linkedInvoice, ['description'], ''));
    $description = $invoiceDescription !== ''
        ? $invoiceDescription
        : trim((string) finance_logistics_meta_value($metaData, ['description', 'item_description', 'title', 'purpose'], ''));
    if ($description === '') {
        $description = $itemName !== '' ? $itemName : trim((string) finance_logistics_supabase_pick($row, ['description', 'item_description', 'title', 'purpose'], ''));
    }
    $requestedBy = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['requested_by', 'requester_name', 'requested_by_name', 'created_by_name'], ''));
    if ($requestedBy === '') {
        $requestedBy = trim((string) finance_logistics_meta_value($metaData, ['requested_by', 'requester_name', 'requested_by_name', 'created_by_name'], ''));
    }
    if ($requestedBy === '') {
        $requestedBy = 'Logistics Staff';
    }

    $department = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['department', 'department_name', 'team'], ''));
    if ($department === '') {
        $department = trim((string) finance_logistics_meta_value($metaData, ['department', 'department_name', 'team'], ''));
    }
    if ($department === '') {
        $department = 'Logistics';
    }

    $paymentStatus = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['payment_status', 'approval_status', 'status', 'request_status'], ''));
    if ($paymentStatus === '') {
        $paymentStatus = 'Unpaid';
    }

    $paymentMethod = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['payment_method', 'mode_of_payment'], ''));
    if ($paymentMethod === '') {
        $paymentMethod = 'Bank Transfer';
    }

    $amountValue = finance_logistics_supabase_pick($linkedInvoice + $row, ['amount', 'total_amount', 'requested_amount', 'estimated_cost'], null);
    $amount = finance_numeric_from_mixed($amountValue);
    if ($amount === null) {
        $amount = finance_logistics_meta_amount($metaData);
    }
    $dueDate = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['due_date', 'needed_by', 'request_date'], ''));
    if ($dueDate === '') {
        $dueDate = trim((string) finance_logistics_meta_value($metaData, ['due_date', 'needed_by', 'request_date'], ''));
    }
    $createdAt = trim((string) finance_logistics_supabase_pick($linkedInvoice + $row, ['created_at', 'request_date', 'submitted_at'], ''));
    $metaData = finance_logistics_supabase_pick($linkedInvoice + $row, ['meta_data', 'metadata'], $metaData);
    $financialReportUrl = trim((string) finance_logistics_supabase_pick($row, ['financial_report_url'], ''));
    if ($amount === null) {
        $amount = finance_logistics_amount_from_report_url($financialReportUrl);
    }

    $remarks = [];
    if (isset($row['vendor_id']) && $row['vendor_id'] !== null && $row['vendor_id'] !== '') {
        $remarks[] = 'Vendor ID: ' . (string) $row['vendor_id'];
    }
    if ($itemName !== '') {
        $remarks[] = 'Item: ' . $itemName;
    }
    if ($quantity !== null && $quantity > 0) {
        $remarks[] = 'Quantity: ' . $quantity;
    }
    if ($paymentMethod !== '') {
        $remarks[] = 'Payment Method: ' . $paymentMethod;
    }
    if ($dueDate !== '') {
        $remarks[] = 'Due Date: ' . $dueDate;
    }
    if ($financialReportUrl !== '') {
        $remarks[] = 'Financial Report: ' . $financialReportUrl;
    }
    if (is_array($metaData) && $metaData !== []) {
        $remarks[] = 'Meta: ' . json_encode($metaData);
    }

    $title = $itemName !== '' ? $itemName : $requestType;
    if ($quantity !== null && $quantity > 0 && $itemName !== '') {
        $title .= ' x' . $quantity;
    }

    $normalized = [
        'id' => (int) ($row['id'] ?? 0),
        'request_code' => '',
        'request_date' => $createdAt,
        'request_type' => $requestType,
        'title' => $title,
        'description' => $description,
        'item_name' => $itemName !== '' ? $itemName : $description,
        'quantity' => $quantity,
        'destination' => $financialReportUrl !== '' ? $financialReportUrl : $dueDate,
        'status' => $paymentStatus,
        'amount' => $amount !== null && $amount !== '' ? (float) $amount : null,
        'remarks' => implode(' | ', $remarks),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'requested_by' => $requestedBy,
        'requested_by_name' => $requestedBy !== '' ? $requestedBy : 'Logistics Staff',
        'department_id' => null,
        'department_name' => $department !== '' ? $department : 'Logistics',
        'payment_method' => $paymentMethod,
        'vendor_id' => isset($row['vendor_id']) && $row['vendor_id'] !== '' ? (int) $row['vendor_id'] : null,
        'related_ar_ap_id' => (int) finance_logistics_supabase_pick($linkedInvoice + $row, ['related_ar_ap_id'], 0),
        'related_disbursement_id' => (int) finance_logistics_supabase_pick($linkedInvoice + $row, ['related_disbursement_id'], 0),
        'due_date' => $dueDate,
        'financial_report_url' => $financialReportUrl,
        'linked_invoice_id' => isset($linkedInvoice['id']) && $linkedInvoice['id'] !== '' ? (int) $linkedInvoice['id'] : null,
        'source' => 'supabase',
        'source_table' => $sourceTable,
        '_raw' => $row,
        '_linked_invoice' => $linkedInvoice,
    ];

    $normalized['request_code'] = finance_logistics_request_code((int) ($normalized['id'] ?? 0), '');
    $normalized['request_type_label'] = finance_logistics_request_type_label($normalized);
    $normalized['status_label'] = finance_logistics_status_label((string) ($normalized['status'] ?? ''));
    $normalized['status_badge_class'] = finance_logistics_status_badge_class((string) ($normalized['status'] ?? ''));
    $normalized['amount_label'] = finance_logistics_amount_label($normalized);
    $normalized['request_title'] = trim($title) !== '' ? $title : 'Logistics Request';
    $normalized['request_description'] = trim($description) !== ''
        ? $description
        : ($itemName !== '' ? $itemName : 'No description provided.');
    $normalized['request_date_display'] = $createdAt;

    return $normalized;
}

function finance_get_logistics_supabase_request_pool(): ?array
{
    static $cacheReady = false;
    static $cache = null;

    if ($cacheReady) {
        return $cache;
    }

    $cacheReady = true;
    $client = supabase_init();
    if (!$client) {
        $cache = null;
        return null;
    }

    $procurementRes = $client->get(finance_logistics_supabase_table(), [
        'order' => 'created_at.desc',
    ]);
    if (($procurementRes['status'] ?? 0) !== 200 || !is_array($procurementRes['data'] ?? null)) {
        $cache = null;
        return null;
    }

    $invoiceRes = $client->get(finance_logistics_supabase_invoice_table(), [
        'select' => 'id,request_type,description,requested_by,department,vendor_id,amount,due_date,payment_status,payment_method,meta_data,created_at',
        'order' => 'created_at.desc',
    ]);

    $invoiceRows = (($invoiceRes['status'] ?? 0) === 200 && is_array($invoiceRes['data'] ?? null))
        ? array_values(array_filter($invoiceRes['data'], static fn ($row): bool => is_array($row)))
        : [];

    $invoiceByVendor = [];
    $invoiceById = [];
    foreach ($invoiceRows as $invoiceRow) {
        if (isset($invoiceRow['vendor_id']) && $invoiceRow['vendor_id'] !== null && $invoiceRow['vendor_id'] !== '') {
            $invoiceByVendor[(string) $invoiceRow['vendor_id']][] = $invoiceRow;
        }
        if (isset($invoiceRow['id']) && $invoiceRow['id'] !== null && $invoiceRow['id'] !== '') {
            $invoiceById[(string) $invoiceRow['id']] = $invoiceRow;
        }
    }

    $rows = array_map(
        static function (array $row) use ($invoiceByVendor, $invoiceById): array {
            $row['_source_table'] = finance_logistics_supabase_table();
            $linkedInvoice = null;
            $vendorKey = isset($row['vendor_id']) && $row['vendor_id'] !== null && $row['vendor_id'] !== '' ? (string) $row['vendor_id'] : '';
            if ($vendorKey !== '' && !empty($invoiceByVendor[$vendorKey])) {
                $linkedInvoice = $invoiceByVendor[$vendorKey][0];
            } elseif (isset($row['id']) && isset($invoiceById[(string) $row['id']])) {
                $linkedInvoice = $invoiceById[(string) $row['id']];
            }

            if (is_array($linkedInvoice)) {
                $row['_linked_invoice'] = $linkedInvoice;
            }
            return finance_normalize_logistics_supabase_row($row);
        },
        array_values(array_filter($procurementRes['data'], static fn ($row): bool => is_array($row)))
    );

    usort($rows, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['request_date_display'] ?? $a['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['request_date_display'] ?? $b['created_at'] ?? '')) ?: 0;
        if ($aTs === $bTs) {
            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        }

        return $bTs <=> $aTs;
    });

    $cache = $rows;

    return $cache;
}

function finance_logistics_supabase_required(): bool
{
    return supabase_is_configured();
}

function finance_filter_logistics_supabase_rows(array $rows, array $filters): array
{
    $normalizedFilters = finance_logistics_review_filters($filters);
    $search = strtolower($normalizedFilters['search']);
    $status = strtolower($normalizedFilters['status']);

    return array_values(array_filter($rows, static function (array $row) use ($search, $status): bool {
        if (finance_logistics_is_document_tracking_row($row)) {
            return false;
        }

        if ($status !== '' && $status !== 'all') {
            if (strtolower(finance_logistics_status_label((string) ($row['status'] ?? ''))) !== $status) {
                return false;
            }
        }

        if ($search === '') {
            return true;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) ($row['request_code'] ?? ''),
            (string) ($row['request_type_label'] ?? $row['request_type'] ?? ''),
            (string) ($row['requested_by_name'] ?? ''),
            (string) ($row['department_name'] ?? ''),
            (string) ($row['request_title'] ?? ''),
            (string) ($row['request_description'] ?? ''),
            (string) ($row['payment_method'] ?? ''),
        ])));

        return str_contains($haystack, $search);
    }));
}

function finance_get_logistics_request_source_row(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $supabaseRows = finance_get_logistics_supabase_request_pool();
    if ($supabaseRows !== null) {
        foreach ($supabaseRows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    if (finance_logistics_supabase_required()) {
        return null;
    }

    return finance_get_logistics_request_detail($pdo, $id);
}

function finance_logistics_is_document_tracking_row(array $request): bool
{
    $sourceTable = trim((string) ($request['source_table'] ?? ''));
    if ($sourceTable === finance_logistics_document_tracking_table()) {
        return true;
    }

    return array_key_exists('tracking_number', $request) || array_key_exists('doc_title', $request);
}

function finance_logistics_supabase_update_payload(array $request, string $action, string $remarks = ''): array
{
    $raw = isset($request['_raw']) && is_array($request['_raw']) ? $request['_raw'] : [];
    $payload = [];
    $sourceTable = trim((string) ($request['source_table'] ?? finance_logistics_supabase_table()));

    if ($sourceTable === finance_logistics_document_tracking_table()) {
        $payload['status'] = strtolower($action) === 'approve' ? 'Payment Received' : 'Rejected';
        return $payload;
    }

    $normalizedAction = strtolower(trim($action));
    $payload['payment_status'] = match ($normalizedAction) {
        'approve' => 'Paid',
        'revision', 'request_revision', 'return' => 'Needs Revision',
        default => 'Rejected',
    };

    $remarksValue = trim($remarks);
    if ($remarksValue !== '') {
        $metaData = [];
        if (isset($raw['meta_data']) && is_array($raw['meta_data'])) {
            $metaData = $raw['meta_data'];
        }
        $metaData['finance_remarks'] = $remarksValue;
        $payload['meta_data'] = $metaData;
    }

    return $payload;
}

function finance_get_logistics_review_requests(PDO $pdo, array $filters = [], int $limit = 10, int $offset = 0): array
{
    finance_ensure_logistics_request_tracking_columns($pdo);

    $supabaseRows = finance_get_logistics_supabase_request_pool();
    if ($supabaseRows !== null) {
        return array_slice(finance_filter_logistics_supabase_rows($supabaseRows, $filters), max(0, $offset), max(0, $limit));
    }

    if (finance_logistics_supabase_required()) {
        return [];
    }

    finance_bootstrap($pdo);
    if (!finance_table_exists($pdo, 'logistic_requests')) {
        return [];
    }

    $normalizedFilters = finance_logistics_review_filters($filters);
    $schema = finance_schema_prefix($pdo);
    $likeOp = finance_like_operator($pdo);
    $hasEmployees = finance_table_exists($pdo, 'employees');
    $hasDepartments = finance_table_exists($pdo, 'departments');
    $hasRequestCode = finance_column_exists($pdo, 'logistic_requests', 'request_code');
    $hasRequestDate = finance_column_exists($pdo, 'logistic_requests', 'request_date');
    $hasRequestType = finance_column_exists($pdo, 'logistic_requests', 'request_type');
    $hasTitle = finance_column_exists($pdo, 'logistic_requests', 'title');
    $hasDescription = finance_column_exists($pdo, 'logistic_requests', 'description');
    $hasRequestedBy = finance_column_exists($pdo, 'logistic_requests', 'requested_by');
    $hasDepartmentId = finance_column_exists($pdo, 'logistic_requests', 'department_id');
    $hasAmount = finance_column_exists($pdo, 'logistic_requests', 'amount');
    $hasDueDate = finance_column_exists($pdo, 'logistic_requests', 'due_date');
    $hasRemarks = finance_column_exists($pdo, 'logistic_requests', 'remarks');
    $hasRelatedArApId = finance_column_exists($pdo, 'logistic_requests', 'related_ar_ap_id');
    $hasRelatedDisbursementId = finance_column_exists($pdo, 'logistic_requests', 'related_disbursement_id');

    $select = [
        'lr.id',
        $hasRequestCode ? 'lr.request_code' : "'' AS request_code",
        $hasRequestDate ? 'lr.request_date' : 'NULL AS request_date',
        $hasRequestType ? 'lr.request_type' : "'' AS request_type",
        $hasTitle ? 'lr.title' : "'' AS title",
        $hasDescription ? 'lr.description' : "'' AS description",
        'lr.item_name',
        'lr.quantity',
        'lr.destination',
        'lr.status',
        $hasAmount ? 'lr.amount' : 'NULL AS amount',
        $hasDueDate ? 'lr.due_date' : 'NULL AS due_date',
        $hasRemarks ? 'lr.remarks' : 'NULL AS remarks',
        'lr.created_at',
        'lr.updated_at',
    ];

    if ($hasRequestedBy) {
        $select[] = 'lr.requested_by';
    } else {
        $select[] = 'NULL AS requested_by';
    }
    if ($hasDepartmentId) {
        $select[] = 'lr.department_id';
    } else {
        $select[] = 'NULL AS department_id';
    }
    $select[] = $hasRelatedArApId ? 'lr.related_ar_ap_id' : 'NULL AS related_ar_ap_id';
    $select[] = $hasRelatedDisbursementId ? 'lr.related_disbursement_id' : 'NULL AS related_disbursement_id';

    if ($hasEmployees && $hasRequestedBy && finance_column_exists($pdo, 'employees', 'id') && finance_column_exists($pdo, 'employees', 'name')) {
        $select[] = 'emp.name AS requested_by_name';
    } else {
        $select[] = "NULL AS requested_by_name";
    }
    if ($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'id') && finance_column_exists($pdo, 'departments', 'name')) {
        $select[] = 'dept.name AS department_name';
    } else {
        $select[] = "NULL AS department_name";
    }

    $sql = "SELECT " . implode(', ', $select) . " FROM {$schema}logistic_requests lr";
    if ($hasEmployees && $hasRequestedBy && finance_column_exists($pdo, 'employees', 'id') && finance_column_exists($pdo, 'employees', 'name')) {
        $sql .= " LEFT JOIN {$schema}employees emp ON emp.id = lr.requested_by";
    }
    if ($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'id') && finance_column_exists($pdo, 'departments', 'name')) {
        $sql .= " LEFT JOIN {$schema}departments dept ON dept.id = lr.department_id";
    }

    $sql .= " WHERE 1 = 1";
    $params = [];

    if ($normalizedFilters['search'] !== '') {
        $sql .= " AND (
            COALESCE(" . ($hasRequestCode ? 'lr.request_code' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasTitle ? 'lr.title' : 'lr.item_name') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasDescription ? 'lr.description' : 'lr.destination') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasRequestType ? 'lr.request_type' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasEmployees && $hasRequestedBy && finance_column_exists($pdo, 'employees', 'name')) ? 'emp.name' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'name')) ? 'dept.name' : "''") . ", '') {$likeOp} :search
        )";
        $params[':search'] = '%' . $normalizedFilters['search'] . '%';
    }

    if ($normalizedFilters['status'] !== '' && strtolower($normalizedFilters['status']) !== 'all') {
        $sql .= " AND LOWER(COALESCE(lr.status, 'pending')) = :status";
        $params[':status'] = strtolower($normalizedFilters['status']);
    }

    $sql .= " ORDER BY COALESCE(lr.request_date, DATE(lr.created_at)) DESC, lr.id DESC LIMIT :limit_value OFFSET :offset_value";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit_value', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_value', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['request_code'] = finance_logistics_request_code((int) ($row['id'] ?? 0), (string) ($row['request_code'] ?? ''));
        $row['request_type_label'] = finance_logistics_request_type_label($row);
        $row['requested_by_name'] = trim((string) ($row['requested_by_name'] ?? '')) !== ''
            ? (string) $row['requested_by_name']
            : 'Logistics Staff';
        $row['department_name'] = trim((string) ($row['department_name'] ?? '')) !== ''
            ? (string) $row['department_name']
            : 'Logistics';
        $row['status_label'] = finance_logistics_status_label((string) ($row['status'] ?? ''));
        $row['status_badge_class'] = finance_logistics_status_badge_class((string) ($row['status'] ?? ''));
        $row['amount_label'] = finance_logistics_amount_label($row);
        $row['request_title'] = trim((string) ($row['title'] ?? '')) !== ''
            ? (string) $row['title']
            : (string) ($row['item_name'] ?? 'Logistics Request');
        $row['request_description'] = trim((string) ($row['description'] ?? '')) !== ''
            ? (string) $row['description']
            : (string) ($row['destination'] ?? 'No description provided.');
        $row['request_date_display'] = (string) ($row['request_date'] ?? $row['created_at'] ?? '');
    }

    return $rows;
}

function finance_count_logistics_review_requests(PDO $pdo, array $filters = []): int
{
    $supabaseRows = finance_get_logistics_supabase_request_pool();
    if ($supabaseRows !== null) {
        return count(finance_filter_logistics_supabase_rows($supabaseRows, $filters));
    }

    if (finance_logistics_supabase_required()) {
        return 0;
    }

    finance_bootstrap($pdo);
    if (!finance_table_exists($pdo, 'logistic_requests')) {
        return 0;
    }

    $normalizedFilters = finance_logistics_review_filters($filters);
    $schema = finance_schema_prefix($pdo);
    $likeOp = finance_like_operator($pdo);
    $hasEmployees = finance_table_exists($pdo, 'employees');
    $hasDepartments = finance_table_exists($pdo, 'departments');
    $hasRequestCode = finance_column_exists($pdo, 'logistic_requests', 'request_code');
    $hasRequestType = finance_column_exists($pdo, 'logistic_requests', 'request_type');
    $hasTitle = finance_column_exists($pdo, 'logistic_requests', 'title');
    $hasDescription = finance_column_exists($pdo, 'logistic_requests', 'description');
    $hasRequestedBy = finance_column_exists($pdo, 'logistic_requests', 'requested_by');
    $hasDepartmentId = finance_column_exists($pdo, 'logistic_requests', 'department_id');

    $sql = "SELECT COUNT(*) FROM {$schema}logistic_requests lr";
    if ($hasEmployees && $hasRequestedBy && finance_column_exists($pdo, 'employees', 'id') && finance_column_exists($pdo, 'employees', 'name')) {
        $sql .= " LEFT JOIN {$schema}employees emp ON emp.id = lr.requested_by";
    }
    if ($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'id') && finance_column_exists($pdo, 'departments', 'name')) {
        $sql .= " LEFT JOIN {$schema}departments dept ON dept.id = lr.department_id";
    }
    $sql .= " WHERE 1 = 1";
    $params = [];

    if ($normalizedFilters['search'] !== '') {
        $sql .= " AND (
            COALESCE(" . ($hasRequestCode ? 'lr.request_code' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasTitle ? 'lr.title' : 'lr.item_name') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasDescription ? 'lr.description' : 'lr.destination') . ", '') {$likeOp} :search
            OR COALESCE(" . ($hasRequestType ? 'lr.request_type' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasEmployees && $hasRequestedBy && finance_column_exists($pdo, 'employees', 'name')) ? 'emp.name' : "''") . ", '') {$likeOp} :search
            OR COALESCE(" . (($hasDepartments && $hasDepartmentId && finance_column_exists($pdo, 'departments', 'name')) ? 'dept.name' : "''") . ", '') {$likeOp} :search
        )";
        $params[':search'] = '%' . $normalizedFilters['search'] . '%';
    }

    if ($normalizedFilters['status'] !== '' && strtolower($normalizedFilters['status']) !== 'all') {
        $sql .= " AND LOWER(COALESCE(lr.status, 'pending')) = :status";
        $params[':status'] = strtolower($normalizedFilters['status']);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function finance_get_logistics_request_detail(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $supabaseRows = finance_get_logistics_supabase_request_pool();
    if ($supabaseRows !== null) {
        foreach ($supabaseRows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    if (finance_logistics_supabase_required()) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    if (!finance_table_exists($pdo, 'logistic_requests')) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM {$schema}logistic_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        return null;
    }

    $rows = finance_get_logistics_review_requests($pdo, ['status' => 'all'], 1000, 0);
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
}

function finance_request_action_log_exists(PDO $pdo, string $sourceTable, string $sourceId, string $action): bool
{
    finance_ensure_approved_request_logs_table($pdo);

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT 1
        FROM {$schema}approved_request_logs
        WHERE source_table = :source_table
          AND source_id = :source_id
          AND action = :action
        LIMIT 1
    ");
    $stmt->execute([
        ':source_table' => trim($sourceTable),
        ':source_id' => trim($sourceId),
        ':action' => finance_request_action_log_normalize_action($action),
    ]);

    return (bool) $stmt->fetchColumn();
}

function finance_backfill_request_action_logs(PDO $pdo): void
{
    static $backfilled = false;
    if ($backfilled) {
        return;
    }

    finance_ensure_approved_request_logs_table($pdo);
    $schema = finance_schema_prefix($pdo);

    $backfill = static function (
        string $table,
        string $module,
        string $descriptionColumn,
        array $statusToAction,
        ?string $amountColumn = null,
        ?string $remarksColumn = null
    ) use ($pdo, $schema): void {
        if (!finance_table_exists($pdo, $table)) {
            return;
        }

        $select = ["id", $descriptionColumn . " AS description", "status", "COALESCE(updated_at, created_at) AS action_at"];
        if ($amountColumn !== null && finance_column_exists($pdo, $table, $amountColumn)) {
            $select[] = $amountColumn . " AS amount";
        }
        if ($remarksColumn !== null && finance_column_exists($pdo, $table, $remarksColumn)) {
            $select[] = $remarksColumn . " AS remarks";
        }

        $statuses = array_keys($statusToAction);
        $placeholders = [];
        $params = [];
        foreach ($statuses as $index => $status) {
            $placeholder = ':status_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = strtolower($status);
        }

        $sql = "
            SELECT " . implode(', ', $select) . "
            FROM {$schema}{$table}
            WHERE LOWER(COALESCE(status, '')) IN (" . implode(', ', $placeholders) . ")
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $action = $statusToAction[$status] ?? null;
            $sourceId = trim((string) ($row['id'] ?? ''));
            if ($action === null || $sourceId === '' || $sourceId === '0') {
                continue;
            }

            if (finance_request_action_log_exists($pdo, $table, $sourceId, $action)) {
                continue;
            }

            finance_record_approved_request_log(
                $pdo,
                $action,
                $module,
                $table,
                $sourceId,
                (string) ($row['description'] ?? ($module . ' request ' . strtolower($action))),
                isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== '' ? (float) $row['amount'] : null,
                isset($row['remarks']) ? (string) $row['remarks'] : null,
                (string) ($row['action_at'] ?? '')
            );
        }
    };

    $backfill('hr_requests', 'HR', 'request_details', [
        'approved' => 'APPROVE',
        'rejected' => 'REJECT',
        'needs revision' => 'REVISION',
        'released' => 'RELEASE',
    ], 'amount', 'remarks');

    $backfill('logistic_requests', 'LOGISTICS', 'item_name', [
        'approved' => 'APPROVE',
        'rejected' => 'REJECT',
        'needs revision' => 'REVISION',
        'released' => 'RELEASE',
    ], 'amount', 'remarks');

    $backfill('job_posting_payments', 'CORE', 'job_title', [
        'approved' => 'APPROVE',
        'rejected' => 'REJECT',
        'needs revision' => 'REVISION',
        'released' => 'RELEASE',
    ], 'amount', 'company_name');

    $backfill('client_requests', 'CLIENT', 'description', [
        'approved' => 'APPROVE',
        'rejected' => 'REJECT',
    ], 'amount', 'remarks');

    if (finance_table_exists($pdo, 'disbursement')) {
        $stmt = $pdo->query("
            SELECT id, reference_no, payee_name, amount, status, disbursement_date, remarks
            FROM {$schema}disbursement
            WHERE UPPER(COALESCE(status, '')) IN ('RELEASED', 'POSTED')
            ORDER BY disbursement_date DESC, id DESC
        ");
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];

        foreach ($rows as $row) {
            $sourceId = trim((string) ($row['id'] ?? ''));
            if ($sourceId === '' || finance_request_action_log_exists($pdo, 'disbursement', $sourceId, 'RELEASE')) {
                continue;
            }

            finance_record_approved_request_log(
                $pdo,
                'RELEASE',
                'DISBURSEMENT',
                'disbursement',
                $sourceId,
                'Funds released to ' . (string) ($row['payee_name'] ?? 'payee'),
                isset($row['amount']) ? (float) $row['amount'] : null,
                (string) ($row['remarks'] ?? ''),
                (string) ($row['disbursement_date'] ?? '')
            );
        }
    }

    $backfilled = true;
}

function finance_backfill_approved_collections(PDO $pdo): void
{
    static $backfilled = false;
    if ($backfilled) {
        return;
    }

    $schema = finance_schema_prefix($pdo);

    $backfill = static function (
        string $table,
        string $sourceType,
        string $payerName,
        string $descriptionColumn,
        array $approvedStatuses,
        ?string $amountColumn = null,
        ?callable $remarksBuilder = null
    ) use ($pdo, $schema): void {
        if (!finance_table_exists($pdo, $table)) {
            return;
        }

        $select = ['id', $descriptionColumn . ' AS description', 'status'];
        if ($amountColumn !== null && finance_column_exists($pdo, $table, $amountColumn)) {
            $select[] = $amountColumn . ' AS amount';
        }

        if ($table === 'job_posting_payments' && finance_column_exists($pdo, $table, 'company_name')) {
            $select[] = 'company_name';
        }
        if ($table === 'client_requests' && finance_column_exists($pdo, $table, 'client_name')) {
            $select[] = 'client_name';
        }

        $placeholders = [];
        $params = [];
        foreach ($approvedStatuses as $index => $status) {
            $placeholder = ':status_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = strtolower($status);
        }

        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $select) . "
            FROM {$schema}{$table}
            WHERE LOWER(COALESCE(status, '')) IN (" . implode(', ', $placeholders) . ")
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $sourceId = (int) ($row['id'] ?? 0);
            $amount = isset($row['amount']) && $row['amount'] !== null && $row['amount'] !== '' ? (float) $row['amount'] : 0.0;
            if ($sourceId <= 0 || $amount <= 0) {
                continue;
            }

            $resolvedPayer = $payerName;
            if ($table === 'job_posting_payments') {
                $resolvedPayer = (string) ($row['company_name'] ?? $payerName);
            } elseif ($table === 'client_requests') {
                $resolvedPayer = (string) ($row['client_name'] ?? $payerName);
            }

            $remarks = $remarksBuilder ? (string) $remarksBuilder($row) : ('Approved ' . $sourceType . ' request: ' . (string) ($row['description'] ?? $sourceType . ' Request #' . $sourceId));

            finance_create_request_collection_if_missing(
                $pdo,
                $sourceType,
                $sourceId,
                $resolvedPayer !== '' ? $resolvedPayer : $sourceType,
                $amount,
                $remarks,
                $sourceType . '-' . $sourceId
            );
        }
    };

    $backfill('job_posting_payments', 'CORE', 'CORE', 'job_title', ['approved'], 'amount', static function (array $row): string {
        return 'Approved Job Posting: ' . (string) ($row['description'] ?? 'CORE Request');
    });
    $backfill('client_requests', 'CLIENT', 'Client Request', 'description', ['approved'], 'amount');

    $backfilled = true;
}

function finance_bootstrap(PDO $pdo): array
{
    static $bootstrapped = false;
    static $messages = [];

    if ($bootstrapped) {
        return $messages;
    }

    $messages = [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPg = ($driver === 'pgsql');
    $schema = $isPg ? "public." : "";

    // Optimization: skip full bootstrap only when the core finance tables already exist.
    try {
        if (
            finance_table_exists($pdo, 'users')
            && finance_table_exists($pdo, 'collection')
            && finance_table_exists($pdo, 'general_ledger')
            && finance_table_exists($pdo, 'departments')
            && finance_table_exists($pdo, 'request_history')
        ) {
            $bootstrapped = true;
            return [];
        }
    } catch (Throwable $e) {
        // Continue to bootstrap if check fails
    }

    // Keep bootstrap practical: create the target tables if missing,
    // and only add indexes when the target columns already exist.
    $tableSql = [
        "users" => "CREATE TABLE IF NOT EXISTS {$schema}users (
            id " . ($isPg ? "UUID DEFAULT gen_random_uuid() PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            name " . ($isPg ? "TEXT" : "VARCHAR(150)") . " NOT NULL,
            role_slug " . ($isPg ? "TEXT" : "VARCHAR(50)") . " NOT NULL,
            email " . ($isPg ? "TEXT UNIQUE" : "VARCHAR(150) UNIQUE") . ",
            password_hash TEXT,
            created_at " . ($isPg ? "TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())" : "DATETIME DEFAULT CURRENT_TIMESTAMP") . "
        )",
        "accounts" => "CREATE TABLE IF NOT EXISTS {$schema}accounts (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            account_code VARCHAR(20),
            account_title VARCHAR(120) NOT NULL,
            account_type VARCHAR(50),
            description TEXT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . ",
            updated_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "ar_ap" => "CREATE TABLE IF NOT EXISTS {$schema}ar_ap (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            entry_type VARCHAR(10) NOT NULL,
            party_name VARCHAR(150) NOT NULL,
            reference_no VARCHAR(60),
            description TEXT,
            amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            balance NUMERIC(14,2) NOT NULL DEFAULT 0,
            due_date DATE,
            status VARCHAR(30),
            related_collection_id BIGINT,
            related_disbursement_id BIGINT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . ",
            updated_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "collection" => "CREATE TABLE IF NOT EXISTS {$schema}collection (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            reference_no VARCHAR(60),
            source_type VARCHAR(50),
            source_id BIGINT,
            payer_name VARCHAR(150) NOT NULL,
            amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40),
            payment_date DATE NOT NULL,
            status VARCHAR(30),
            remarks TEXT,
            related_budget_id BIGINT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . ",
            updated_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "general_ledger" => "CREATE TABLE IF NOT EXISTS {$schema}general_ledger (
            id " . ($isPg ? "UUID DEFAULT gen_random_uuid() PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            entry_date DATE DEFAULT " . ($isPg ? "CURRENT_DATE" : "CURDATE()") . ",
            account_name " . ($isPg ? "TEXT" : "VARCHAR(120)") . ",
            description " . ($isPg ? "TEXT" : "VARCHAR(255)") . ",
            debit NUMERIC(15,2) DEFAULT 0,
            credit NUMERIC(15,2) DEFAULT 0,
            category " . ($isPg ? "TEXT" : "VARCHAR(100)") . ",
            reference_no " . ($isPg ? "TEXT" : "VARCHAR(100)") . ",
            source_module " . ($isPg ? "TEXT" : "VARCHAR(50)") . ",
            source_id BIGINT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "employees" => "CREATE TABLE IF NOT EXISTS {$schema}employees (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            name " . ($isPg ? "TEXT" : "VARCHAR(255)") . " NOT NULL,
            email " . ($isPg ? "TEXT" : "VARCHAR(255)") . ",
            job_id " . ($isPg ? "UUID" : "VARCHAR(36)") . ",
            role " . ($isPg ? "TEXT DEFAULT 'employee'" : "VARCHAR(50) DEFAULT 'employee'") . ",
            username " . ($isPg ? "TEXT UNIQUE" : "VARCHAR(100) UNIQUE") . ",
            password TEXT,
            created_at " . ($isPg ? "TIMESTAMPTZ NOT NULL DEFAULT NOW()" : "DATETIME DEFAULT CURRENT_TIMESTAMP") . "
        )",
        "budget_management" => "CREATE TABLE IF NOT EXISTS {$schema}budget_management (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            budget_name VARCHAR(150) NOT NULL,
            department VARCHAR(120),
            allocated_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            used_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            remaining_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            funded_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            period_start DATE,
            period_end DATE,
            status VARCHAR(30),
            notes TEXT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . ",
            updated_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "disbursement" => "CREATE TABLE IF NOT EXISTS {$schema}disbursement (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            reference_no VARCHAR(60),
            payee_name VARCHAR(150) NOT NULL,
            request_source VARCHAR(50),
            request_id BIGINT,
            budget_id BIGINT,
            amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            disbursement_date DATE NOT NULL,
            payment_method VARCHAR(40),
            status VARCHAR(30),
            remarks TEXT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . ",
            updated_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "payroll_runs" => "CREATE TABLE IF NOT EXISTS {$schema}payroll_runs (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            total_gross NUMERIC(14,2) DEFAULT 0,
            total_net NUMERIC(14,2) DEFAULT 0,
            approval_status VARCHAR(20) DEFAULT 'Pending',
            approved_by " . ($isPg ? "UUID" : "INT") . ",
            approved_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . ",
            payment_request_id BIGINT,
            budget_id BIGINT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "payroll_payment_requests" => "CREATE TABLE IF NOT EXISTS {$schema}payroll_payment_requests (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            payroll_run_id BIGINT NOT NULL,
            request_no VARCHAR(60) UNIQUE NOT NULL,
            total_amount NUMERIC(14,2) NOT NULL,
            request_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'Pending',
            related_budget_id BIGINT,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . ",
            updated_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "departments" => "CREATE TABLE IF NOT EXISTS {$schema}departments (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            name VARCHAR(120) NOT NULL,
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )",
        "request_history" => "CREATE TABLE IF NOT EXISTS {$schema}request_history (
            id " . ($isPg ? "BIGSERIAL PRIMARY KEY" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
            module VARCHAR(50) NOT NULL,
            request_id VARCHAR(80) NOT NULL,
            request_code VARCHAR(40),
            action VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL,
            remarks TEXT,
            acted_by VARCHAR(150),
            created_at " . ($isPg ? "TIMESTAMPTZ" : "DATETIME") . " DEFAULT " . ($isPg ? "NOW()" : "CURRENT_TIMESTAMP") . "
        )"
    ];

    foreach ($tableSql as $table => $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            $messages[] = "Bootstrap skipped {$table}: " . $e->getMessage();
        }
    }

    try {
        if (finance_table_exists($pdo, 'request_history')) {
            $pdo->exec($isPg
                ? "ALTER TABLE {$schema}request_history ALTER COLUMN request_id TYPE VARCHAR(80) USING request_id::text"
                : "ALTER TABLE {$schema}request_history MODIFY request_id VARCHAR(80) NOT NULL"
            );
        }
    } catch (Throwable) {
    }

    if (finance_table_exists($pdo, 'general_ledger')) {
        $glCols = [
            'reference_no' => $isPg ? "TEXT" : "VARCHAR(100)",
            'source_module' => $isPg ? "TEXT" : "VARCHAR(50)",
            'source_id' => "BIGINT"
        ];
        foreach ($glCols as $col => $def) {
            if (!finance_column_exists($pdo, 'general_ledger', $col)) {
                try {
                    $pdo->exec($isPg ? "ALTER TABLE public.general_ledger ADD COLUMN {$col} {$def}" : "ALTER TABLE general_ledger ADD COLUMN {$col} {$def}");
                } catch (Throwable $e) {}
            }
        }
    }

    if (finance_table_exists($pdo, 'logistic_requests')) {
        $logisticsColumns = [
            'request_code' => $isPg ? "VARCHAR(40)" : "VARCHAR(40)",
            'request_date' => $isPg ? "DATE DEFAULT CURRENT_DATE" : "DATE NULL",
            'request_type' => $isPg ? "VARCHAR(80)" : "VARCHAR(80)",
            'title' => $isPg ? "VARCHAR(255)" : "VARCHAR(255)",
            'description' => "TEXT",
            'requested_by' => "BIGINT",
            'department_id' => "BIGINT",
            'amount' => $isPg ? "NUMERIC(14,2)" : "DECIMAL(14,2)",
            'remarks' => "TEXT",
        ];

        foreach ($logisticsColumns as $col => $definition) {
            if (!finance_column_exists($pdo, 'logistic_requests', $col)) {
                try {
                    $pdo->exec($isPg ? "ALTER TABLE public.logistic_requests ADD COLUMN {$col} {$definition}" : "ALTER TABLE logistic_requests ADD COLUMN {$col} {$definition}");
                } catch (Throwable $e) {}
            }
        }
    }

    if (finance_table_exists($pdo, 'hr_requests')) {
        $hrColumns = [
            'request_code' => $isPg ? "VARCHAR(40)" : "VARCHAR(40)",
            'request_date' => $isPg ? "DATE DEFAULT CURRENT_DATE" : "DATE NULL",
            'request_type' => $isPg ? "VARCHAR(80)" : "VARCHAR(80)",
            'employee_id' => "BIGINT",
            'title' => $isPg ? "VARCHAR(255)" : "VARCHAR(255)",
            'description' => "TEXT",
            'department_id' => "BIGINT",
            'amount' => $isPg ? "NUMERIC(14,2)" : "DECIMAL(14,2)",
            'remarks' => "TEXT",
        ];

        foreach ($hrColumns as $col => $definition) {
            if (!finance_column_exists($pdo, 'hr_requests', $col)) {
                try {
                    $pdo->exec($isPg ? "ALTER TABLE public.hr_requests ADD COLUMN {$col} {$definition}" : "ALTER TABLE hr_requests ADD COLUMN {$col} {$definition}");
                } catch (Throwable $e) {}
            }
        }
    }

    // Ensure employees table is up to date with both system needs and supabase.sql
    if (finance_table_exists($pdo, 'employees')) {
        // Handle name vs full_name
        if (!finance_column_exists($pdo, 'employees', 'name') && finance_column_exists($pdo, 'employees', 'full_name')) {
            try {
                $pdo->exec($isPg ? "ALTER TABLE public.employees RENAME COLUMN full_name TO name" : "ALTER TABLE employees CHANGE full_name name TEXT");
            } catch (Throwable $e) {}
        } elseif (!finance_column_exists($pdo, 'employees', 'name')) {
            try {
                $pdo->exec($isPg ? "ALTER TABLE public.employees ADD COLUMN name TEXT NOT NULL DEFAULT ''" : "ALTER TABLE employees ADD COLUMN name TEXT NOT NULL");
            } catch (Throwable $e) {}
        }

        // Ensure other required columns for Payroll module exist
        $requiredColumns = [
            'employee_code' => $isPg ? "VARCHAR(30)" : "VARCHAR(30)",
            'department' => $isPg ? "VARCHAR(100)" : "VARCHAR(100)",
            'position' => $isPg ? "VARCHAR(100)" : "VARCHAR(100)",
            'pay_type' => $isPg ? "VARCHAR(20) DEFAULT 'monthly'" : "VARCHAR(20) DEFAULT 'monthly'",
            'basic_salary' => $isPg ? "NUMERIC(14,2) NOT NULL DEFAULT 0" : "DECIMAL(14,2) NOT NULL DEFAULT 0",
            'allowance' => $isPg ? "NUMERIC(14,2) NOT NULL DEFAULT 0" : "DECIMAL(14,2) NOT NULL DEFAULT 0",
            'deduction_default' => $isPg ? "NUMERIC(14,2) NOT NULL DEFAULT 0" : "DECIMAL(14,2) NOT NULL DEFAULT 0",
            'payment_method' => $isPg ? "VARCHAR(50) DEFAULT 'Bank Transfer'" : "VARCHAR(50) DEFAULT 'Bank Transfer'",
            'status' => $isPg ? "VARCHAR(20) DEFAULT 'Active'" : "VARCHAR(20) DEFAULT 'Active'"
        ];

        foreach ($requiredColumns as $col => $definition) {
            if (!finance_column_exists($pdo, 'employees', $col)) {
                try {
                    $pdo->exec($isPg ? "ALTER TABLE public.employees ADD COLUMN {$col} {$definition}" : "ALTER TABLE employees ADD COLUMN {$col} {$definition}");
                } catch (Throwable $e) {}
            }
        }
    }

    seed_default_accounts($pdo);

    $bootstrapped = true;
    return $messages;
}

function finance_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPg = ($driver === 'pgsql');

    try {
        if ($isPg) {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1");
        }

        $stmt->execute([':table' => strtolower($table)]);
        $exists = (bool) $stmt->fetchColumn();
        if ($exists) {
            $probeTable = $isPg ? 'public.' . $table : $table;
            $pdo->query("SELECT 1 FROM {$probeTable} LIMIT 1");
        }
        $cache[$key] = $exists;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function finance_column_exists(PDO $pdo, string $table, string $column): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table AND column_name = :column LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1");
    }
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool) $stmt->fetchColumn();
}

function finance_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_indexes
            WHERE schemaname = 'public'
              AND tablename = :table
              AND indexname = :index_name
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':index_name' => $indexName]);
    } else {
        $stmt = $pdo->prepare("
            SHOW INDEX FROM {$table} WHERE Key_name = :index_name
        ");
        $stmt->execute([':index_name' => $indexName]);
    }

    return (bool) $stmt->fetchColumn();
}

function finance_create_index_if_possible(PDO $pdo, string $table, string $indexName, string $columnList): void
{
    if (!finance_table_exists($pdo, $table) || finance_index_exists($pdo, $table, $indexName)) {
        return;
    }

    $columns = array_map('trim', explode(',', $columnList));
    foreach ($columns as $column) {
        if (!finance_column_exists($pdo, $table, $column)) {
            return;
        }
    }

    $pdo->exec(sprintf(
        'CREATE INDEX IF NOT EXISTS %s ON public.%s (%s)',
        $indexName,
        $table,
        $columnList
    ));
}

function seed_default_accounts(PDO $pdo): void
{
    if (
        !finance_table_exists($pdo, 'accounts')
        || !finance_column_exists($pdo, 'accounts', 'account_title')
    ) {
        return;
    }

    $accounts = [
        ['1010', 'Cash', 'Asset', 'Default cash account'],
        ['1100', 'Accounts Receivable', 'Asset', 'Default receivable account'],
        ['2000', 'Accounts Payable', 'Liability', 'Default payable account'],
        ['4010', 'Service Revenue', 'Revenue', 'Default revenue account'],
        ['5100', 'Operating Expense', 'Expense', 'Default expense account'],
    ];

    $isPg = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
    $schema = $isPg ? "public." : "";

    if ($isPg) {
        $stmt = $pdo->prepare("
            INSERT INTO {$schema}accounts (account_code, account_title, account_type, description)
            SELECT :insert_account_code, :insert_account_title, :insert_account_type, :insert_description
            WHERE NOT EXISTS (
                SELECT 1
                FROM {$schema}accounts
                WHERE account_title = :where_account_title
            )
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO accounts (account_code, account_title, account_type, description)
            SELECT * FROM (SELECT :account_code AS code, :account_title AS title, :account_type AS type, :description AS descr) AS tmp
            WHERE NOT EXISTS (
                SELECT 1 FROM accounts WHERE account_title = :account_title
            ) LIMIT 1
        ");
    }

    foreach ($accounts as [$code, $title, $type, $description]) {
        if ($isPg) {
            $stmt->execute([
                ':insert_account_code' => $code,
                ':insert_account_title' => $title,
                ':insert_account_type' => $type,
                ':insert_description' => $description,
                ':where_account_title' => $title,
            ]);
        } else {
            $stmt->execute([
                ':account_code' => $code,
                ':account_title' => $title,
                ':account_type' => $type,
                ':description' => $description,
            ]);
        }
    }
}

function listAccounts(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'accounts')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->query("
        SELECT id, account_code, account_title, account_type, description
        FROM {$schema}accounts
        ORDER BY " . finance_accounts_order_clause($pdo) . "
    ");

    return $stmt->fetchAll();
}

function finance_account_exists(PDO $pdo, string $accountTitle): bool
{
    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT 1
        FROM {$schema}accounts
        WHERE account_title = :account_title
        LIMIT 1
    ");
    $stmt->execute([':account_title' => $accountTitle]);

    return (bool) $stmt->fetchColumn();
}

function finance_next_reference(PDO $pdo, string $table, string $prefix): string
{
    if (!finance_table_exists($pdo, $table) || !finance_column_exists($pdo, $table, 'reference_no')) {
        return $prefix . '-' . date('YmdHis');
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT reference_no
        FROM {$schema}{$table}
        WHERE reference_no LIKE :pattern
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':pattern' => $prefix . '-' . date('Y') . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;

    if ($last !== '' && preg_match('/(\d+)$/', $last, $matches) === 1) {
        $next = ((int) $matches[1]) + 1;
    }

    return sprintf('%s-%s-%05d', $prefix, date('Y'), $next);
}

function createJournalEntry(PDO $pdo, array $header, array $lines): string
{
    finance_bootstrap($pdo);

    if (!finance_table_exists($pdo, 'general_ledger')) {
        throw new RuntimeException('general_ledger table is missing.');
    }

    $transactionDate = trim((string) ($header['transaction_date'] ?? ''));
    $referenceNo = trim((string) ($header['reference_no'] ?? ''));
    $description = trim((string) ($header['description'] ?? ''));
    $sourceModule = trim((string) ($header['source_module'] ?? 'manual'));
    $sourceId = isset($header['source_id']) ? (int) $header['source_id'] : null;

    if (!finance_is_valid_date($transactionDate)) {
        throw new InvalidArgumentException('Invalid transaction date.');
    }
    if ($description === '') {
        throw new InvalidArgumentException('General ledger description is required.');
    }
    if ($referenceNo === '') {
        $referenceNo = 'GL-' . date('YmdHis');
    }

    $validatedLines = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach ($lines as $line) {
        $accountTitle = trim((string) ($line['account_title'] ?? ''));
        $debit = round((float) ($line['debit'] ?? 0), 2);
        $credit = round((float) ($line['credit'] ?? 0), 2);
        $category = trim((string) ($line['category'] ?? $sourceModule));

        if ($accountTitle === '') {
            throw new InvalidArgumentException('General ledger account title is required.');
        }
        if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
            throw new InvalidArgumentException('Each ledger line must be debit-only or credit-only.');
        }

        $validatedLines[] = [
            'account_title' => $accountTitle,
            'debit' => $debit,
            'credit' => $credit,
            'category' => $category,
        ];
        $totalDebit += $debit;
        $totalCredit += $credit;
    }

    if (round($totalDebit, 2) !== round($totalCredit, 2)) {
        throw new InvalidArgumentException('General ledger entry is not balanced.');
    }

    $insert = $pdo->prepare("
        INSERT INTO public.general_ledger (
            entry_date,
            account_name,
            description,
            debit,
            credit,
            category,
            reference_no,
            source_module,
            source_id
        ) VALUES (
            :entry_date,
            :account_name,
            :description,
            :debit,
            :credit,
            :category,
            :reference_no,
            :source_module,
            :source_id
        )
    ");

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($validatedLines as $line) {
            $insert->execute([
                ':entry_date' => $transactionDate,
                ':account_name' => $line['account_title'],
                ':description' => $description,
                ':debit' => $line['debit'],
                ':credit' => $line['credit'],
                ':category' => $line['category'],
                ':reference_no' => $referenceNo,
                ':source_module' => $sourceModule !== '' ? $sourceModule : null,
                ':source_id' => $sourceId > 0 ? $sourceId : null,
            ]);

            supabase_mirror_safe('general_ledger', [
                'entry_date' => $transactionDate,
                'account_name' => $line['account_title'],
                'description' => $description,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'category' => $line['category']
            ]);
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $referenceNo;
}

function deleteJournalEntriesBySource(PDO $pdo, string $sourceModule, int $sourceId): void
{
    if (!finance_table_exists($pdo, 'general_ledger')) {
        return;
    }

    $stmt = $pdo->prepare("
        DELETE FROM public.general_ledger
        WHERE source_module = :source_module
          AND source_id = :source_id
    ");
    $stmt->execute([
        ':source_module' => $sourceModule,
        ':source_id' => $sourceId,
    ]);
}

function listGeneralLedger(PDO $pdo, array $filters = []): array
{
    if (!finance_table_exists($pdo, 'general_ledger')) {
        return [];
    }

    $sql = "
        SELECT
            entry_date AS transaction_date,
            reference_no,
            account_name AS account_title,
            CASE
                WHEN COALESCE(debit, 0) > 0 THEN 'Debit'
                ELSE 'Credit'
            END AS entry_type,
            description,
            debit,
            credit,
            source_module,
            source_id
        FROM public.general_ledger
        WHERE 1 = 1
    ";
    $params = [];

    if (!empty($filters['date_from'])) {
        $sql .= ' AND entry_date >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $sql .= ' AND entry_date <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }
    if (!empty($filters['source_module'])) {
        $sql .= ' AND source_module = :source_module';
        $params[':source_module'] = $filters['source_module'];
    }

    $sql .= ' ORDER BY entry_date DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function validate_collection_payload(array $data): array
{
    $payload = [
        'reference_no' => trim((string) ($data['reference_no'] ?? '')),
        'source_type' => strtoupper(trim((string) ($data['source_type'] ?? 'manual'))),
        'source_id' => (int) ($data['source_id'] ?? 0),
        'payer_name' => trim((string) ($data['payer_name'] ?? '')),
        'amount' => round((float) ($data['amount'] ?? 0), 2),
        'payment_method' => trim((string) ($data['payment_method'] ?? '')),
        'payment_date' => trim((string) ($data['payment_date'] ?? '')),
        'status' => trim((string) ($data['status'] ?? 'Posted')),
        'remarks' => trim((string) ($data['remarks'] ?? '')),
        'related_budget_id' => (int) ($data['related_budget_id'] ?? 0),
        // Not stored in collection table, only used to post GL.
        'debit_account_title' => trim((string) ($data['debit_account_title'] ?? 'Cash')),
        'credit_account_title' => trim((string) ($data['credit_account_title'] ?? 'Accounts Receivable')),
    ];

    if ($payload['payer_name'] === '') {
        throw new InvalidArgumentException('Payer name is required.');
    }
    if ($payload['amount'] <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }
    if (!finance_is_valid_date($payload['payment_date'])) {
        throw new InvalidArgumentException('Payment date is invalid.');
    }
    if (!in_array($payload['source_type'], ['MANUAL', 'AR', 'CORE', 'HR', 'LOGISTICS', 'CLIENT'], true)) {
        throw new InvalidArgumentException('Source type must be MANUAL, AR, CORE, HR, LOGISTICS, or CLIENT.');
    }
    if ($payload['source_type'] === 'AR' && $payload['source_id'] <= 0) {
        throw new InvalidArgumentException('Source ID is required for AR collections.');
    }
    if (!in_array($payload['source_type'], ['AR', 'CORE', 'HR', 'LOGISTICS', 'CLIENT'], true)) {
        $payload['source_id'] = 0;
    }
    if ($payload['reference_no'] !== '' && mb_strlen($payload['reference_no']) > 60) {
        throw new InvalidArgumentException('Reference number is too long.');
    }
    return $payload;
}

function generateReferenceNo(PDO $pdo): string
{
    return finance_next_reference($pdo, 'collection', 'COL');
}

function generateJournalReference(PDO $pdo): string
{
    return finance_next_reference($pdo, 'general_ledger', 'GL');
}

function collection_exists_for_source(PDO $pdo, string $sourceType, int $sourceId): bool
{
    if ($sourceId <= 0 || !finance_table_exists($pdo, 'collection')) {
        return false;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT 1
        FROM {$schema}collection
        WHERE source_type = :source_type
          AND source_id = :source_id
        LIMIT 1
    ");
    $stmt->execute([
        ':source_type' => strtoupper(trim($sourceType)),
        ':source_id' => $sourceId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function finance_create_request_collection_if_missing(
    PDO $pdo,
    string $sourceType,
    int $sourceId,
    string $payerName,
    float $amount,
    string $remarks,
    ?string $referenceNo = null
): ?int {
    $normalizedSourceType = strtoupper(trim($sourceType));
    if ($sourceId <= 0 || $amount <= 0 || $payerName === '') {
        return null;
    }

    if (collection_exists_for_source($pdo, $normalizedSourceType, $sourceId)) {
        return null;
    }

    return createCollection($pdo, [
        'reference_no' => $referenceNo ?? ($normalizedSourceType . '-' . $sourceId),
        'source_type' => $normalizedSourceType,
        'source_id' => $sourceId,
        'payer_name' => $payerName,
        'amount' => $amount,
        'payment_method' => 'Bank Transfer',
        'payment_date' => date('Y-m-d'),
        'status' => 'Posted',
        'remarks' => $remarks,
    ]);
}

function ledger_source_exists(PDO $pdo, string $sourceModule, int $sourceId): bool
{
    if (!finance_table_exists($pdo, 'general_ledger')) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM public.general_ledger
        WHERE source_module = :source_module
          AND source_id = :source_id
        LIMIT 1
    ");
    $stmt->execute([
        ':source_module' => $sourceModule,
        ':source_id' => $sourceId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function updateARBalance(PDO $pdo, int $collectionId, float $amount): void
{
    $collectionStmt = $pdo->prepare("
        SELECT id, source_type, source_id
        FROM public.collection
        WHERE id = :id
        LIMIT 1
    ");
    $collectionStmt->execute([':id' => $collectionId]);
    $collection = $collectionStmt->fetch();

    if (!$collection || strtoupper((string) ($collection['source_type'] ?? '')) !== 'AR') {
        return;
    }

    $arId = (int) ($collection['source_id'] ?? 0);
    if ($arId <= 0) {
        throw new RuntimeException('AR source record is missing.');
    }

    $stmt = $pdo->prepare("
        SELECT id, amount, balance
        FROM public.ar_ap
        WHERE id = :id
          AND entry_type = 'AR'
        LIMIT 1
    ");
    $stmt->execute([':id' => $arId]);
    $ar = $stmt->fetch();

    if (!$ar) {
        throw new RuntimeException('Linked AR record was not found.');
    }

    $currentBalance = (float) $ar['balance'];
    if ($amount > $currentBalance + 0.00001) {
        throw new RuntimeException('Collection amount cannot exceed the AR balance.');
    }

    $newBalance = max(0, round($currentBalance - $amount, 2));
    $newStatus = $newBalance <= 0.00001 ? 'Paid' : 'Pending';

    $update = $pdo->prepare("
        UPDATE public.ar_ap
        SET balance = :balance,
            status = :status,
            related_collection_id = :related_collection_id,
            updated_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':balance' => $newBalance,
        ':status' => $newStatus,
        ':related_collection_id' => $collectionId,
        ':id' => $arId,
    ]);

    finance_set_ar_ap_paid_at($pdo, $arId, $newBalance <= 0.00001 ? date('Y-m-d H:i:s') : null);
    finance_sync_ar_ap_status($pdo, $arId);

    supabase_mirror_safe('ar_ap', [
        'balance' => $newBalance,
        'status' => finance_arap_status_from_row([
            'entry_type' => 'AR',
            'amount' => (float) $ar['amount'],
            'balance' => $newBalance,
            'due_date' => $ar['due_date'] ?? null,
            'status' => $newStatus,
        ]),
        'related_collection_id' => $collectionId
    ], 'UPDATE', ['id' => $arId]);
}

function postCollectionJournal(PDO $pdo, array $collection): void
{
    $referenceNo = trim((string) ($collection['reference_no'] ?? ''));
    $amount = round((float) ($collection['amount'] ?? 0), 2);
    $paymentDate = (string) ($collection['payment_date'] ?? '');
    $payerName = trim((string) ($collection['payer_name'] ?? ''));
    $collectionId = (int) ($collection['id'] ?? 0);

    if ($referenceNo === '' || $amount <= 0 || !finance_is_valid_date($paymentDate)) {
        throw new InvalidArgumentException('Collection journal payload is invalid.');
    }

    $description = 'Collection received from ' . $payerName;
    $insert = $pdo->prepare("
        INSERT INTO public.general_ledger (
            entry_date,
            account_name,
            description,
            debit,
            credit,
            category,
            reference_no,
            source_module,
            source_id
        ) VALUES (
            :entry_date,
            :account_name,
            :description,
            :debit,
            :credit,
            'collection',
            :reference_no,
            'collection',
            :source_id
        )
    ");

    $insert->execute([
        ':entry_date' => $paymentDate,
        ':account_name' => 'Cash',
        ':description' => $description,
        ':debit' => $amount,
        ':credit' => 0,
        ':reference_no' => $referenceNo,
        ':source_id' => $collectionId,
    ]);

    $insert->execute([
        ':entry_date' => $paymentDate,
        ':account_name' => 'Accounts Receivable',
        ':description' => $description,
        ':debit' => 0,
        ':credit' => $amount,
        ':reference_no' => $referenceNo,
        ':source_id' => $collectionId,
    ]);
}

function finance_resolve_disbursement_expense_account(PDO $pdo, array $disbursement): string
{
    $requested = trim((string) ($disbursement['expense_account_title'] ?? 'Expense'));
    if ($requested !== '' && finance_account_exists($pdo, $requested)) {
        return $requested;
    }
    if (finance_account_exists($pdo, 'Expense')) {
        return 'Expense';
    }
    if (finance_account_exists($pdo, 'Operating Expense')) {
        return 'Operating Expense';
    }

    throw new RuntimeException('Expense account is missing. Create "Expense" or "Operating Expense" first.');
}

function postDisbursementToLedger(PDO $pdo, array $disbursement, string $ledgerMode = 'expense'): void
{
    $referenceNo = trim((string) ($disbursement['reference_no'] ?? ''));
    $amount = round((float) ($disbursement['amount'] ?? 0), 2);
    $date = (string) ($disbursement['disbursement_date'] ?? '');
    $payeeName = trim((string) ($disbursement['payee_name'] ?? ''));
    $disbursementId = (int) ($disbursement['id'] ?? 0);
    $ledgerMode = strtolower(trim($ledgerMode));

    if ($referenceNo === '' || $amount <= 0 || !finance_is_valid_date($date)) {
        throw new InvalidArgumentException('Disbursement journal payload is invalid.');
    }

    $description = 'Disbursement to ' . $payeeName;
    $debitAccountTitle = $ledgerMode === 'ap'
        ? 'Accounts Payable'
        : finance_resolve_disbursement_expense_account($pdo, $disbursement);
    $insert = $pdo->prepare("
        INSERT INTO public.general_ledger (
            entry_date,
            account_name,
            description,
            debit,
            credit,
            category,
            reference_no,
            source_module,
            source_id
        ) VALUES (
            :entry_date,
            :account_name,
            :description,
            :debit,
            :credit,
            'disbursement',
            :reference_no,
            'disbursement',
            :source_id
        )
    ");

    $insert->execute([
        ':entry_date' => $date,
        ':account_name' => $debitAccountTitle,
        ':description' => $description,
        ':debit' => $amount,
        ':credit' => 0,
        ':reference_no' => $referenceNo,
        ':source_id' => $disbursementId,
    ]);

    $insert->execute([
        ':entry_date' => $date,
        ':account_name' => 'Cash',
        ':description' => $description,
        ':debit' => 0,
        ':credit' => $amount,
        ':reference_no' => $referenceNo,
        ':source_id' => $disbursementId,
    ]);
}

function generateARReference(PDO $pdo): string
{
    return finance_next_reference($pdo, 'ar_ap', 'AR');
}

function generateAPReference(PDO $pdo): string
{
    return finance_next_reference($pdo, 'ar_ap', 'AP');
}

function generateDisbursementReference(PDO $pdo): string
{
    return finance_next_reference($pdo, 'disbursement', 'DIS');
}

function createAR(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);
    finance_ensure_ar_ap_link_columns($pdo);

    $partyName = trim((string) ($data['client_name'] ?? $data['party_name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    $referenceNo = trim((string) ($data['reference_no'] ?? ''));

    if ($partyName === '') {
        throw new InvalidArgumentException('Client name is required.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }
    if ($dueDate !== '' && !finance_is_valid_date($dueDate)) {
        throw new InvalidArgumentException('Due date is invalid.');
    }

    $referenceNo = $referenceNo !== '' ? $referenceNo : generateARReference($pdo);
    $schema = finance_schema_prefix($pdo);
    $hasSourceModule = finance_column_exists($pdo, 'ar_ap', 'source_module');
    $hasSourceRequestId = finance_column_exists($pdo, 'ar_ap', 'source_request_id');
    $hasRelatedBudgetId = finance_column_exists($pdo, 'ar_ap', 'related_budget_id');
    $columns = ['entry_type', 'party_name', 'reference_no', 'description', 'amount', 'balance', 'due_date', 'status'];
    $values = ["'AR'", ':party_name', ':reference_no', ':description', ':amount', ':balance', ':due_date', "'Pending'"];
    $params = [
        ':party_name' => $partyName,
        ':reference_no' => $referenceNo,
        ':description' => $description !== '' ? $description : null,
        ':amount' => $amount,
        ':balance' => $amount,
        ':due_date' => $dueDate !== '' ? $dueDate : null,
    ];
    if ($hasSourceModule) {
        $columns[] = 'source_module';
        $values[] = ':source_module';
        $params[':source_module'] = trim((string) ($data['source_module'] ?? '')) !== '' ? strtoupper(trim((string) $data['source_module'])) : null;
    }
    if ($hasSourceRequestId) {
        $columns[] = 'source_request_id';
        $values[] = ':source_request_id';
        $params[':source_request_id'] = (int) ($data['source_request_id'] ?? 0) > 0 ? (int) $data['source_request_id'] : null;
    }
    if ($hasRelatedBudgetId) {
        $columns[] = 'related_budget_id';
        $values[] = ':related_budget_id';
        $params[':related_budget_id'] = (int) ($data['related_budget_id'] ?? 0) > 0 ? (int) $data['related_budget_id'] : null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO {$schema}ar_ap (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $values) . ")
    ");
    $stmt->execute($params);

    $id = (int) $pdo->lastInsertId();
    supabase_mirror('ar_ap', array_merge(['id' => $id, 'entry_type' => 'AR'], $data));
    return $id;
}

function createAP(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);
    finance_ensure_ar_ap_link_columns($pdo);

    $partyName = trim((string) ($data['supplier_name'] ?? $data['party_name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    $referenceNo = trim((string) ($data['reference_no'] ?? ''));

    if ($partyName === '') {
        throw new InvalidArgumentException('Supplier name is required.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }
    if ($dueDate !== '' && !finance_is_valid_date($dueDate)) {
        throw new InvalidArgumentException('Due date is invalid.');
    }

    $referenceNo = $referenceNo !== '' ? $referenceNo : generateAPReference($pdo);
    $schema = finance_schema_prefix($pdo);
    $hasSourceModule = finance_column_exists($pdo, 'ar_ap', 'source_module');
    $hasSourceRequestId = finance_column_exists($pdo, 'ar_ap', 'source_request_id');
    $hasRelatedBudgetId = finance_column_exists($pdo, 'ar_ap', 'related_budget_id');
    $columns = ['entry_type', 'party_name', 'reference_no', 'description', 'amount', 'balance', 'due_date', 'status'];
    $values = ["'AP'", ':party_name', ':reference_no', ':description', ':amount', ':balance', ':due_date', "'Pending'"];
    $params = [
        ':party_name' => $partyName,
        ':reference_no' => $referenceNo,
        ':description' => $description !== '' ? $description : null,
        ':amount' => $amount,
        ':balance' => $amount,
        ':due_date' => $dueDate !== '' ? $dueDate : null,
    ];
    if ($hasSourceModule) {
        $columns[] = 'source_module';
        $values[] = ':source_module';
        $params[':source_module'] = trim((string) ($data['source_module'] ?? '')) !== '' ? strtoupper(trim((string) $data['source_module'])) : null;
    }
    if ($hasSourceRequestId) {
        $columns[] = 'source_request_id';
        $values[] = ':source_request_id';
        $params[':source_request_id'] = (int) ($data['source_request_id'] ?? 0) > 0 ? (int) $data['source_request_id'] : null;
    }
    if ($hasRelatedBudgetId) {
        $columns[] = 'related_budget_id';
        $values[] = ':related_budget_id';
        $params[':related_budget_id'] = (int) ($data['related_budget_id'] ?? 0) > 0 ? (int) $data['related_budget_id'] : null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO {$schema}ar_ap (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $values) . ")
    ");
    $stmt->execute($params);

    $id = (int) $pdo->lastInsertId();
    supabase_mirror('ar_ap', array_merge(['id' => $id, 'entry_type' => 'AP'], $data));
    return $id;
}

function finance_ensure_ar_ap_tracking_columns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !finance_table_exists($pdo, 'ar_ap')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

    if (!finance_column_exists($pdo, 'ar_ap', 'paid_at')) {
        $pdo->exec($isPg
            ? "ALTER TABLE {$schema}ar_ap ADD COLUMN paid_at TIMESTAMPTZ NULL"
            : "ALTER TABLE {$schema}ar_ap ADD COLUMN paid_at DATETIME NULL");
    }

    $ensured = true;
}

function finance_arap_status_from_row(array $row): string
{
    $entryType = strtoupper(trim((string) ($row['entry_type'] ?? '')));
    $amount = round((float) ($row['amount'] ?? 0), 2);
    $balance = max(0, round((float) ($row['balance'] ?? $amount), 2));
    $dueDate = trim((string) ($row['due_date'] ?? ''));
    $status = trim((string) ($row['status'] ?? ''));
    $today = date('Y-m-d');

    if ($balance <= 0.00001) {
        return 'Paid';
    }

    if ($entryType === 'AR') {
        if ($dueDate !== '' && finance_is_valid_date($dueDate) && $dueDate < $today) {
            return 'Overdue';
        }
        if ($balance < $amount - 0.00001) {
            return 'Partially Paid';
        }

        return 'Pending';
    }

    if ($entryType === 'AP') {
        if ($dueDate !== '' && finance_is_valid_date($dueDate) && $dueDate < $today) {
            return 'Overdue';
        }
        if ($balance < $amount - 0.00001) {
            return 'Partially Paid';
        }
        return strtoupper($status) === 'APPROVED' ? 'Approved' : 'Pending';
    }

    return $status !== '' ? $status : 'Pending';
}

function finance_sync_ar_ap_status(PDO $pdo, int $id): void
{
    $row = getArAp($pdo, $id);
    if (!$row) {
        return;
    }

    $status = finance_arap_status_from_row($row);
    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        UPDATE {$schema}ar_ap
        SET status = :status,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $status,
        ':id' => $id,
    ]);

    supabase_mirror_safe('ar_ap', ['status' => $status], 'UPDATE', ['id' => $id]);
}

function finance_merge_remarks(?string $existing, string $incoming): ?string
{
    $existing = trim((string) $existing);
    $incoming = trim($incoming);

    if ($incoming === '') {
        return $existing !== '' ? $existing : null;
    }
    if ($existing === '') {
        return $incoming;
    }

    return $existing . ' | ' . $incoming;
}

function finance_arap_enrich_row(array $row): array
{
    $row['amount'] = round((float) ($row['amount'] ?? 0), 2);
    $row['balance'] = max(0, round((float) ($row['balance'] ?? $row['amount'] ?? 0), 2));
    $row['status'] = finance_arap_status_from_row($row);
    $row['is_paid'] = $row['balance'] <= 0.00001;
    $row['is_overdue'] = $row['status'] === 'Overdue';
    $row['is_due_soon'] = false;
    $dueDate = trim((string) ($row['due_date'] ?? ''));
    if ($dueDate !== '' && finance_is_valid_date($dueDate) && !$row['is_paid']) {
        $daysUntilDue = (int) floor((strtotime($dueDate) - strtotime(date('Y-m-d'))) / 86400);
        $row['is_due_soon'] = $daysUntilDue >= 0 && $daysUntilDue <= 7;
    }

    return $row;
}

function finance_build_ar_ap_filters(array $filters = []): array
{
    return [
        'search' => trim((string) ($filters['search'] ?? '')),
        'status' => trim((string) ($filters['status'] ?? '')),
        'due_date' => trim((string) ($filters['due_date'] ?? '')),
    ];
}

function normalizeBudgetStatus(array $budget): string
{
    $allocated = round((float) ($budget['allocated_amount'] ?? 0), 2);
    $used = round((float) ($budget['used_amount'] ?? 0), 2);
    $periodStart = trim((string) ($budget['period_start'] ?? ''));
    $periodEnd = trim((string) ($budget['period_end'] ?? ''));
    $status = trim((string) ($budget['status'] ?? 'Active'));
    $today = date('Y-m-d');

    if ($allocated < 0 || $used < 0) {
        throw new InvalidArgumentException('Budget amounts cannot be negative.');
    }
    if (strcasecmp($status, 'Closed') === 0) {
        return 'Closed';
    }
    if ($used > $allocated + 0.00001) {
        return 'Over Budget';
    }
    if ($periodEnd !== '' && finance_is_valid_date($periodEnd) && $today > $periodEnd) {
        return 'Expired';
    }
    if ($periodStart !== '' && finance_is_valid_date($periodStart) && $today < $periodStart) {
        return 'Pending';
    }
    if ($status === '' || !in_array($status, ['Pending', 'Active', 'Expired', 'Over Budget', 'Closed'], true)) {
        return 'Active';
    }

    return in_array($status, ['Pending', 'Active'], true) ? 'Active' : $status;
}

function createBudget(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);

    $budgetName = trim((string) ($data['budget_name'] ?? ''));
    $department = trim((string) ($data['department'] ?? ''));
    $allocatedAmount = round((float) ($data['allocated_amount'] ?? 0), 2);
    $periodStart = trim((string) ($data['period_start'] ?? ''));
    $periodEnd = trim((string) ($data['period_end'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($budgetName === '') {
        throw new InvalidArgumentException('Budget name is required.');
    }
    if ($department === '') {
        throw new InvalidArgumentException('Department is required.');
    }
    if ($allocatedAmount < 0) {
        throw new InvalidArgumentException('Allocated amount cannot be negative.');
    }
    if ($periodStart !== '' && !finance_is_valid_date($periodStart)) {
        throw new InvalidArgumentException('Period start is invalid.');
    }
    if ($periodEnd !== '' && !finance_is_valid_date($periodEnd)) {
        throw new InvalidArgumentException('Period end is invalid.');
    }
    if ($periodStart !== '' && $periodEnd !== '' && $periodStart > $periodEnd) {
        throw new InvalidArgumentException('Period start must be on or before period end.');
    }

    $usedAmount = 0.0;
    $remainingAmount = $allocatedAmount;
    $status = normalizeBudgetStatus([
        'allocated_amount' => $allocatedAmount,
        'used_amount' => $usedAmount,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => trim((string) ($data['status'] ?? 'Active')),
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO public.budget_management (
            budget_name,
            department,
            allocated_amount,
            used_amount,
            remaining_amount,
            period_start,
            period_end,
            status,
            notes
        ) VALUES (
            :budget_name,
            :department,
            :allocated_amount,
            :used_amount,
            :remaining_amount,
            :period_start,
            :period_end,
            :status,
            :notes
        )
    ");
    $stmt->execute([
        ':budget_name' => $budgetName,
        ':department' => $department,
        ':allocated_amount' => $allocatedAmount,
        ':used_amount' => $usedAmount,
        ':remaining_amount' => $remainingAmount,
        ':period_start' => $periodStart !== '' ? $periodStart : null,
        ':period_end' => $periodEnd !== '' ? $periodEnd : null,
        ':status' => $status,
        ':notes' => $notes !== '' ? $notes : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    supabase_mirror('budget_management', array_merge(['id' => $id], $data, [
        'used_amount' => 0,
        'remaining_amount' => $allocatedAmount,
        'status' => $status,
    ]));
    return $id;
}

function createCollection(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);
    $payload = validate_collection_payload($data);
    $payload['reference_no'] = $payload['reference_no'] !== '' ? $payload['reference_no'] : generateReferenceNo($pdo);

    $schema = finance_schema_prefix($pdo);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO {$schema}collection (
                reference_no,
                source_type,
                source_id,
                payer_name,
                amount,
                payment_method,
                payment_date,
                status,
                remarks,
                related_budget_id
            ) VALUES (
                :reference_no,
                :source_type,
                :source_id,
                :payer_name,
                :amount,
                :payment_method,
                :payment_date,
                :status,
                :remarks,
                :related_budget_id
            )
        ");
        $insert->execute([
            ':reference_no' => $payload['reference_no'],
            ':source_type' => $payload['source_type'],
            ':source_id' => $payload['source_id'] > 0 ? $payload['source_id'] : null,
            ':payer_name' => $payload['payer_name'],
            ':amount' => $payload['amount'],
            ':payment_method' => $payload['payment_method'] !== '' ? $payload['payment_method'] : null,
            ':payment_date' => $payload['payment_date'],
            ':status' => $payload['status'] !== '' ? $payload['status'] : null,
            ':remarks' => $payload['remarks'] !== '' ? $payload['remarks'] : null,
            ':related_budget_id' => $payload['related_budget_id'] > 0 ? $payload['related_budget_id'] : null,
        ]);

        $collectionId = (int) $pdo->lastInsertId();

        finance_log_audit($pdo, 'Created Collection', 'Collection', $collectionId, null, $payload);
        supabase_mirror('collection', array_merge(['id' => $collectionId], $payload));

        if ($payload['source_type'] === 'AR') {
            updateARBalance($pdo, $collectionId, $payload['amount']);
        }

        if ($payload['related_budget_id'] > 0) {
            updateBudgetFunding($pdo, $payload['related_budget_id']);
        }

        if ($payload['status'] === 'Posted') {
            postCollectionJournal($pdo, [
                'id' => $collectionId,
                'reference_no' => $payload['reference_no'],
                'payer_name' => $payload['payer_name'],
                'amount' => $payload['amount'],
                'payment_date' => $payload['payment_date'],
            ]);
        }

        $pdo->commit();
        return $collectionId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function updateCollection(PDO $pdo, int $id, array $data): void
{
    finance_bootstrap($pdo);
    $payload = validate_collection_payload($data);
    $existing = getCollection($pdo, $id);

    if (!$existing) {
        throw new RuntimeException('Collection record not found.');
    }

    if ($payload['reference_no'] === '') {
        $payload['reference_no'] = (string) $existing['reference_no'];
    }

    $schema = finance_schema_prefix($pdo);
    $pdo->beginTransaction();
    try {
        if (strtoupper((string) ($existing['source_type'] ?? '')) === 'AR') {
            $rollbackAr = $pdo->prepare("
                UPDATE {$schema}ar_ap
                SET balance = balance + :amount,
                    related_collection_id = NULL,
                    updated_at = NOW()
                WHERE id = :id
                  AND entry_type = 'AR'
            ");
            $rollbackAr->execute([
                ':amount' => (float) $existing['amount'],
                ':id' => (int) $existing['source_id'],
            ]);
            finance_set_ar_ap_paid_at($pdo, (int) $existing['source_id'], null);
            finance_sync_ar_ap_status($pdo, (int) $existing['source_id']);
        }

        $update = $pdo->prepare("
            UPDATE {$schema}collection
            SET reference_no = :reference_no,
                source_type = :source_type,
                source_id = :source_id,
                payer_name = :payer_name,
                amount = :amount,
                payment_method = :payment_method,
                payment_date = :payment_date,
                status = :status,
                remarks = :remarks,
                related_budget_id = :related_budget_id,
                updated_at = NOW()
            WHERE id = :id
        ");
        $update->execute([
            ':id' => $id,
            ':reference_no' => $payload['reference_no'],
            ':source_type' => $payload['source_type'],
            ':source_id' => $payload['source_id'] > 0 ? $payload['source_id'] : null,
            ':payer_name' => $payload['payer_name'],
            ':amount' => $payload['amount'],
            ':payment_method' => $payload['payment_method'] !== '' ? $payload['payment_method'] : null,
            ':payment_date' => $payload['payment_date'],
            ':status' => $payload['status'] !== '' ? $payload['status'] : null,
            ':remarks' => $payload['remarks'] !== '' ? $payload['remarks'] : null,
            ':related_budget_id' => $payload['related_budget_id'] > 0 ? $payload['related_budget_id'] : null,
        ]);

        supabase_mirror('collection', array_merge(['id' => $id], $payload), 'UPDATE', ['id' => $id]);

        deleteJournalEntriesBySource($pdo, 'collection', $id);
        if ($payload['source_type'] === 'AR') {
            updateARBalance($pdo, $id, $payload['amount']);
        }
        if ($payload['status'] === 'Posted') {
            postCollectionJournal($pdo, [
                'id' => $id,
                'reference_no' => $payload['reference_no'],
                'payer_name' => $payload['payer_name'],
                'amount' => $payload['amount'],
                'payment_date' => $payload['payment_date'],
            ]);
        }
        foreach (array_unique(array_filter([
            (int) ($existing['related_budget_id'] ?? 0),
            $payload['related_budget_id'],
        ])) as $budgetId) {
            updateBudgetFunding($pdo, (int) $budgetId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deleteCollection(PDO $pdo, int $id): void
{
    finance_bootstrap($pdo);
    $existing = getCollection($pdo, $id);
    $schema = finance_schema_prefix($pdo);
    $pdo->beginTransaction();
    try {
        if ($existing && strtoupper((string) ($existing['source_type'] ?? '')) === 'AR') {
            $rollbackAr = $pdo->prepare("
                UPDATE {$schema}ar_ap
                SET balance = balance + :amount,
                    related_collection_id = NULL,
                    updated_at = NOW()
                WHERE id = :id
                  AND entry_type = 'AR'
            ");
            $rollbackAr->execute([
                ':amount' => (float) $existing['amount'],
                ':id' => (int) $existing['source_id'],
            ]);
            finance_set_ar_ap_paid_at($pdo, (int) $existing['source_id'], null);
            finance_sync_ar_ap_status($pdo, (int) $existing['source_id']);
        }
        deleteJournalEntriesBySource($pdo, 'collection', $id);

        $stmt = $pdo->prepare("DELETE FROM {$schema}collection WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($existing) {
            supabase_mirror('collection', [], 'DELETE', ['id' => $id]);
        }

        if (!empty($existing['related_budget_id'])) {
            updateBudgetFunding($pdo, (int) $existing['related_budget_id']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getCollection(PDO $pdo, int $id): ?array
{
    if (!finance_table_exists($pdo, 'collection')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);

    $stmt = $pdo->prepare("SELECT * FROM {$schema}collection WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function listCollections(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'collection')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $collectionTable = $schema . 'collection';
    $ledgerTable = $schema . 'general_ledger';

    $stmt = $pdo->query("
        SELECT
            c.*,
            COALESCE(
                (SELECT reference_no FROM {$ledgerTable} WHERE source_module = 'collection' AND source_id = c.id ORDER BY id ASC LIMIT 1),
                c.reference_no
            ) AS gl_reference_no
        FROM {$collectionTable} c
        ORDER BY c.payment_date DESC, c.id DESC
    ");

    return $stmt->fetchAll();
}

function countCollections(PDO $pdo, string $search = ''): int
{
    if (!finance_table_exists($pdo, 'collection')) {
        return 0;
    }

    $schema = finance_schema_prefix($pdo);
    $table = $schema . 'collection';
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $likeOp = $isPg ? 'ILIKE' : 'LIKE';

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM {$table}
        WHERE (
            :search = ''
            OR reference_no {$likeOp} :search_like
            OR payer_name {$likeOp} :search_like
            OR COALESCE(payment_method, '') {$likeOp} :search_like
            OR COALESCE(status, '') {$likeOp} :search_like
        )
    ");
    $stmt->execute([
        ':search' => $search,
        ':search_like' => '%' . $search . '%',
    ]);

    return (int) $stmt->fetchColumn();
}

function listCollectionsPaginated(PDO $pdo, string $search = '', int $limit = 10, int $offset = 0): array
{
    if (!finance_table_exists($pdo, 'collection')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $collectionTable = $schema . 'collection';
    $ledgerTable = $schema . 'general_ledger';
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $likeOp = $isPg ? 'ILIKE' : 'LIKE';

    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COALESCE(
                (SELECT reference_no FROM {$ledgerTable} WHERE source_module = 'collection' AND source_id = c.id ORDER BY id ASC LIMIT 1),
                c.reference_no
            ) AS gl_reference_no
        FROM {$collectionTable} c
        WHERE (
            :search = ''
            OR c.reference_no {$likeOp} :search_like
            OR c.payer_name {$likeOp} :search_like
            OR COALESCE(c.payment_method, '') {$likeOp} :search_like
            OR COALESCE(c.status, '') {$likeOp} :search_like
        )
        ORDER BY c.payment_date DESC, c.id DESC
        LIMIT :limit_value OFFSET :offset_value
    ");
    $stmt->bindValue(':search', $search, PDO::PARAM_STR);
    $stmt->bindValue(':search_like', '%' . $search . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit_value', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset_value', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function getCollectionsThisMonth(PDO $pdo): float
{
    finance_ensure_approved_request_logs_table($pdo);
    if (finance_table_exists($pdo, 'approved_request_logs')) {
        $schema = finance_schema_prefix($pdo);
        $table = $schema . 'approved_request_logs';
        $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

        if ($isPg) {
            $sql = "
                SELECT COALESCE(SUM(amount), 0)
                FROM {$table}
                WHERE action = 'APPROVE'
                  AND amount IS NOT NULL
                  AND DATE_TRUNC('month', approved_at) = DATE_TRUNC('month', CURRENT_DATE)
            ";
        } else {
            $sql = "
                SELECT COALESCE(SUM(amount), 0)
                FROM {$table}
                WHERE action = 'APPROVE'
                  AND amount IS NOT NULL
                  AND DATE_FORMAT(approved_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            ";
        }

        $stmt = $pdo->query($sql);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    if (!finance_table_exists($pdo, 'collection')) {
        return 0.0;
    }

    $schema = finance_schema_prefix($pdo);
    $table = $schema . 'collection';
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

    if ($isPg) {
        $sql = "
            SELECT COALESCE(SUM(amount), 0)
            FROM {$table}
            WHERE DATE_TRUNC('month', payment_date) = DATE_TRUNC('month', CURRENT_DATE)
        ";
    } else {
        $sql = "
            SELECT COALESCE(SUM(amount), 0)
            FROM {$table}
            WHERE DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ";
    }

    $stmt = $pdo->query($sql);
    return (float) ($stmt->fetchColumn() ?: 0);
}

function finance_ensure_request_release_columns(PDO $pdo, string $table): void
{
    if (!finance_table_exists($pdo, $table)) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

    if (!finance_column_exists($pdo, $table, 'approved_amount')) {
        $pdo->exec($isPg
            ? "ALTER TABLE {$schema}{$table} ADD COLUMN approved_amount NUMERIC(14,2) NULL"
            : "ALTER TABLE {$schema}{$table} ADD COLUMN approved_amount DECIMAL(14,2) NULL");
    }
    if (!finance_column_exists($pdo, $table, 'released_at')) {
        $pdo->exec($isPg
            ? "ALTER TABLE {$schema}{$table} ADD COLUMN released_at TIMESTAMPTZ NULL"
            : "ALTER TABLE {$schema}{$table} ADD COLUMN released_at DATETIME NULL");
    }
}

function finance_find_module_arap_link(PDO $pdo, string $module, string $requestId): ?array
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return null;
    }

    finance_ensure_ar_ap_link_columns($pdo);
    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT *
        FROM {$schema}ar_ap
        WHERE UPPER(COALESCE(source_module, '')) = :module
          AND source_request_id = :source_request_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':module' => strtoupper(trim($module)),
        ':source_request_id' => (int) $requestId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function finance_find_module_disbursement_link(PDO $pdo, string $module, string $requestId): ?array
{
    if (!finance_table_exists($pdo, 'disbursement')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT *
        FROM {$schema}disbursement
        WHERE UPPER(COALESCE(request_source, '')) = :module
          AND request_id = :request_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':module' => strtoupper(trim($module)),
        ':request_id' => (int) $requestId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function finance_find_collection_by_arap(PDO $pdo, int $arApId): ?array
{
    if ($arApId <= 0 || !finance_table_exists($pdo, 'collection')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT *
        FROM {$schema}collection
        WHERE UPPER(COALESCE(source_type, '')) = 'AR'
          AND source_id = :source_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':source_id' => $arApId]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function finance_request_flow_snapshot(PDO $pdo, string $module, string $requestId, array $row = []): array
{
    $module = strtoupper(trim($module));
    $requestId = trim($requestId);
    $status = trim((string) ($row['status'] ?? ''));
    $approved = false;
    $linked = false;
    $disbursed = false;
    $recorded = false;
    $badges = [];
    $linkedRecords = [];
    $impactFlags = [
        'arap' => false,
        'budget' => false,
        'disbursement' => false,
        'ledger' => false,
    ];

    if ($requestId === '') {
        return [
            'stage' => 'Pending',
            'approved' => false,
            'linked' => false,
            'disbursed' => false,
            'recorded' => false,
            'steps' => ['Approved' => false, 'Linked' => false, 'Disbursed' => false, 'Recorded' => false],
            'badges' => [],
            'linked_records' => [],
            'impact_flags' => $impactFlags,
        ];
    }

    if ($module === 'CORE') {
        $statusLabel = finance_core_status_label($status !== '' ? $status : 'Pending');
        $relatedArApId = (int) ($row['related_ar_ap_id'] ?? 0);
        $relatedBudgetId = (int) ($row['related_budget_id'] ?? 0);
        $relatedDisbursementId = (int) ($row['related_disbursement_id'] ?? 0);
        $approved = in_array($statusLabel, ['Approved', 'Linked to AR/AP', 'Linked to Budget', 'Ready for Disbursement', 'Released'], true);
        $linked = in_array($statusLabel, ['Linked to AR/AP', 'Linked to Budget', 'Ready for Disbursement', 'Released'], true)
            || $relatedArApId > 0
            || $relatedBudgetId > 0;
        $disbursed = $statusLabel === 'Released' || $relatedDisbursementId > 0;

        if ($relatedArApId > 0) {
            $impactFlags['arap'] = true;
            $linkedRecords[] = 'AR/AP #' . $relatedArApId;
            $badges[] = 'Linked to AR/AP';
            $arApRow = getArAp($pdo, $relatedArApId);
            if ($arApRow && !empty($arApRow['reference_no'])) {
                $linkedRecords[count($linkedRecords) - 1] = (string) ($arApRow['entry_type'] ?? 'AR/AP') . ' #' . (string) $arApRow['reference_no'];
            }
            $collectionRow = finance_find_collection_by_arap($pdo, $relatedArApId);
            if ($collectionRow && ledger_source_exists($pdo, 'collection', (int) ($collectionRow['id'] ?? 0))) {
                $recorded = true;
                $impactFlags['ledger'] = true;
            }
        }
        if ($relatedBudgetId > 0) {
            $impactFlags['budget'] = true;
            $badges[] = 'Budget Deducted';
            $budgetRow = getBudgetById($pdo, $relatedBudgetId);
            $linkedRecords[] = 'Budget #' . (string) ($budgetRow['budget_name'] ?? $relatedBudgetId);
        }
        if (in_array($statusLabel, ['Ready for Disbursement', 'Released'], true)) {
            $badges[] = 'Ready for Disbursement';
        }
        if ($relatedDisbursementId > 0) {
            $impactFlags['disbursement'] = true;
            $disbursementRow = getDisbursementById($pdo, $relatedDisbursementId);
            $linkedRecords[] = 'Disbursement #' . (string) ($disbursementRow['reference_no'] ?? $relatedDisbursementId);
            if (ledger_source_exists($pdo, 'disbursement', $relatedDisbursementId)) {
                $recorded = true;
                $impactFlags['ledger'] = true;
            }
        }
    } elseif ($module === 'HR') {
        $statusLabel = finance_hr_status_label($status !== '' ? $status : 'Pending');
        $approved = in_array($statusLabel, ['Approved', 'Released'], true);
        $linked = false;
        $disbursementRow = finance_find_module_disbursement_link($pdo, 'HR', $requestId);
        if ($statusLabel === 'Approved') {
            $badges[] = 'Ready for Disbursement';
        }
        if ($disbursementRow) {
            $disbursed = true;
            $linked = true;
            $impactFlags['disbursement'] = true;
            $linkedRecords[] = 'Disbursement #' . (string) ($disbursementRow['reference_no'] ?? $disbursementRow['id'] ?? $requestId);
            if (ledger_source_exists($pdo, 'disbursement', (int) ($disbursementRow['id'] ?? 0))) {
                $recorded = true;
                $impactFlags['ledger'] = true;
            }
        } else {
            $disbursed = $statusLabel === 'Released';
        }
    } elseif ($module === 'LOGISTICS') {
        $statusLabel = finance_logistics_display_status_label($row !== [] ? $row : ['status' => $status]);
        $approved = in_array($statusLabel, ['Approved', 'Ready for Disbursement', 'Released'], true);
        $linkedRecordsRow = ctype_digit($requestId) ? finance_get_logistics_linked_records($pdo, (int) $requestId) : ['ap' => null, 'disbursement' => null];
        $apRow = $linkedRecordsRow['ap'] ?? null;
        $disbursementRow = $linkedRecordsRow['disbursement'] ?? finance_find_module_disbursement_link($pdo, 'LOGISTICS', $requestId);
        $linked = $apRow !== null || $disbursementRow !== null || $statusLabel === 'Ready for Disbursement';

        if ($apRow) {
            $impactFlags['arap'] = true;
            $badges[] = 'Linked to AR/AP';
            $linkedRecords[] = 'AP #' . (string) ($apRow['reference_no'] ?? $apRow['id']);
            if ((int) ($apRow['related_disbursement_id'] ?? 0) > 0) {
                $impactFlags['disbursement'] = true;
                $disbursed = true;
                if (ledger_source_exists($pdo, 'disbursement', (int) $apRow['related_disbursement_id'])) {
                    $recorded = true;
                    $impactFlags['ledger'] = true;
                }
            }
        }
        if ($statusLabel === 'Approved' && $disbursementRow === null && $apRow === null) {
            $badges[] = 'Ready for Disbursement';
        }
        if ($disbursementRow) {
            $impactFlags['disbursement'] = true;
            $disbursed = true;
            $linkedRecords[] = 'Disbursement #' . (string) ($disbursementRow['reference_no'] ?? $disbursementRow['id'] ?? $requestId);
            if (ledger_source_exists($pdo, 'disbursement', (int) ($disbursementRow['id'] ?? 0))) {
                $recorded = true;
                $impactFlags['ledger'] = true;
            }
        } elseif ($statusLabel === 'Released') {
            $disbursed = true;
        }
    } else {
        $approved = in_array(strtolower($status), ['approved', 'released'], true);
    }

    if ($impactFlags['ledger']) {
        $badges[] = 'Recorded in Ledger';
    }

    $badges = array_values(array_unique(array_filter($badges, static fn ($value): bool => trim((string) $value) !== '')));
    $linkedRecords = array_values(array_unique(array_filter($linkedRecords, static fn ($value): bool => trim((string) $value) !== '')));

    $stage = 'Pending';
    if ($recorded) {
        $stage = 'Recorded';
    } elseif ($disbursed) {
        $stage = 'Disbursed';
    } elseif ($linked) {
        $stage = 'Linked';
    } elseif ($approved) {
        $stage = 'Approved';
    }

    return [
        'stage' => $stage,
        'approved' => $approved,
        'linked' => $linked,
        'disbursed' => $disbursed,
        'recorded' => $recorded,
        'steps' => [
            'Approved' => $approved,
            'Linked' => $linked,
            'Disbursed' => $disbursed,
            'Recorded' => $recorded,
        ],
        'badges' => $badges,
        'linked_records' => $linkedRecords,
        'impact_flags' => $impactFlags,
    ];
}

function finance_get_approved_requests_for_disbursement(PDO $pdo): array
{
    $rows = [];

    foreach (finance_get_core_review_request_pool($pdo) as $row) {
        $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
        $displayStatus = finance_core_status_label((string) ($row['status'] ?? 'Pending'));
        if ($amount <= 0 || !in_array($displayStatus, ['Approved', 'Ready for Disbursement', 'Released'], true)) {
            continue;
        }
        $rows[] = [
            'request_id' => (string) ((int) ($row['id'] ?? 0)),
            'source_module' => 'CORE',
            'description' => (string) ($row['job_title'] ?? $row['description'] ?? 'Core request'),
            'approved_amount' => $amount,
            'status' => $displayStatus,
            'payee_name' => (string) ($row['company_name'] ?? 'Core Services'),
            'request_code' => (string) ($row['request_code'] ?? finance_core_request_code((int) ($row['id'] ?? 0))),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'related_budget_id' => (int) ($row['related_budget_id'] ?? 0),
        ];
    }

    foreach (finance_get_hr_review_requests($pdo, ['status' => 'All'], 1000, 0) as $row) {
        $statusLabel = finance_hr_status_label((string) ($row['status'] ?? 'Approved'));
        if (!in_array($statusLabel, ['Approved', 'Released'], true)) {
            continue;
        }
        $amount = finance_numeric_from_mixed($row['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            continue;
        }
        $rows[] = [
            'request_id' => (string) ($row['id'] ?? ''),
            'source_module' => 'HR',
            'description' => (string) ($row['request_title'] ?? $row['request_details'] ?? 'HR request'),
            'approved_amount' => $amount,
            'status' => $statusLabel,
            'payee_name' => (string) ($row['employee_name'] ?? 'HR Department'),
            'request_code' => (string) ($row['request_code'] ?? finance_hr_request_code((int) ($row['id'] ?? 0))),
            'created_at' => (string) ($row['request_date_display'] ?? $row['created_at'] ?? ''),
            'related_budget_id' => (int) ($row['related_budget_id'] ?? 0),
        ];
    }

    foreach (finance_get_logistics_review_requests($pdo, ['status' => 'All'], 1000, 0) as $row) {
        $statusLabel = finance_logistics_status_label((string) ($row['status'] ?? 'Approved'));
        if (!in_array($statusLabel, ['Approved', 'Ready for Disbursement', 'Released'], true)) {
            continue;
        }
        $amount = finance_numeric_from_mixed($row['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            continue;
        }
        $rows[] = [
            'request_id' => (string) ($row['id'] ?? ''),
            'source_module' => 'LOGISTICS',
            'description' => (string) ($row['request_title'] ?? $row['item_name'] ?? 'Logistics request'),
            'approved_amount' => $amount,
            'status' => $statusLabel,
            'payee_name' => (string) ($row['requested_by_name'] ?? 'Logistics Department'),
            'request_code' => (string) ($row['request_code'] ?? finance_logistics_request_code((int) ($row['id'] ?? 0))),
            'created_at' => (string) ($row['request_date_display'] ?? $row['created_at'] ?? ''),
            'related_budget_id' => (int) ($row['related_budget_id'] ?? 0),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $aReleased = (($a['status'] ?? '') === 'Released') ? 1 : 0;
        $bReleased = (($b['status'] ?? '') === 'Released') ? 1 : 0;
        if ($aReleased !== $bReleased) {
            return $aReleased <=> $bReleased;
        }
        $aTs = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });

    return $rows;
}

function validate_disbursement_payload(array $data): array
{
    $payload = [
        'reference_no' => trim((string) ($data['reference_no'] ?? '')),
        'payee_name' => trim((string) ($data['payee_name'] ?? '')),
        'request_source' => strtoupper(trim((string) ($data['request_source'] ?? 'MANUAL'))),
        'request_id' => (int) ($data['request_id'] ?? 0),
        'related_ap_id' => (int) ($data['related_ap_id'] ?? 0),
        'related_budget_id' => (int) ($data['related_budget_id'] ?? 0),
        'related_payroll_id' => (int) ($data['related_payroll_id'] ?? 0),
        'amount' => round((float) ($data['amount'] ?? 0), 2),
        'disbursement_date' => trim((string) ($data['disbursement_date'] ?? '')),
        'payment_method' => trim((string) ($data['payment_method'] ?? '')),
        'status' => trim((string) ($data['status'] ?? 'Released')),
        'remarks' => trim((string) ($data['remarks'] ?? '')),
        'ledger_mode' => strtolower(trim((string) ($data['ledger_mode'] ?? ''))),
    ];

    if ($payload['payee_name'] === '') {
        throw new InvalidArgumentException('Payee name is required.');
    }
    if ($payload['amount'] <= 0) {
        throw new InvalidArgumentException('Disbursement amount must be greater than zero.');
    }
    if (!finance_is_valid_date($payload['disbursement_date'])) {
        throw new InvalidArgumentException('Disbursement date is invalid.');
    }
    if (!in_array($payload['request_source'], ['MANUAL', 'AP', 'BUDGET', 'PAYROLL', 'CORE', 'HR', 'LOGISTICS'], true)) {
        throw new InvalidArgumentException('Request source must be MANUAL, AP, BUDGET, PAYROLL, CORE, HR, or LOGISTICS.');
    }

    if ($payload['request_source'] === 'AP') {
        $payload['request_id'] = $payload['related_ap_id'] > 0 ? $payload['related_ap_id'] : $payload['request_id'];
        if ($payload['request_id'] <= 0) {
            throw new InvalidArgumentException('Select an AP record for AP-linked disbursements.');
        }
    } elseif ($payload['request_source'] === 'BUDGET') {
        $payload['request_id'] = $payload['related_budget_id'] > 0 ? $payload['related_budget_id'] : $payload['request_id'];
        if ($payload['request_id'] <= 0) {
            throw new InvalidArgumentException('Select a budget record for budget-linked disbursements.');
        }
    } elseif ($payload['request_source'] === 'PAYROLL') {
        $payload['request_id'] = $payload['related_payroll_id'] > 0 ? $payload['related_payroll_id'] : $payload['request_id'];
        if ($payload['request_id'] <= 0) {
            throw new InvalidArgumentException('Select a payroll request for payroll-linked disbursements.');
        }
    } elseif (in_array($payload['request_source'], ['CORE', 'HR', 'LOGISTICS'], true)) {
        if ($payload['request_id'] <= 0) {
            throw new InvalidArgumentException('A source request is required for module-linked disbursements.');
        }
    } else {
        $payload['request_id'] = 0;
    }

    if (!in_array($payload['status'], ['Released', 'Pending', 'Cancelled', 'Posted'], true)) {
        $payload['status'] = 'Released';
    }
    if (!in_array($payload['ledger_mode'], ['expense', 'ap'], true)) {
        $payload['ledger_mode'] = $payload['request_source'] === 'AP' ? 'ap' : 'expense';
    }

    return $payload;
}

function finance_current_user_is_admin(): bool
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));

    return $role === 'admin';
}

function finance_disbursement_affects_budget(array $payload): bool
{
    return in_array((string) ($payload['status'] ?? ''), ['Released', 'Posted'], true);
}

function finance_get_disbursement_budget_column_expr(PDO $pdo, string $tableAlias = ''): string
{
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';
    $parts = [];

    if (finance_column_exists($pdo, 'disbursement', 'budget_id')) {
        $parts[] = $prefix . 'budget_id';
    }
    if (finance_column_exists($pdo, 'disbursement', 'related_budget_id')) {
        $parts[] = $prefix . 'related_budget_id';
    }

    $parts[] = "CASE WHEN UPPER(COALESCE({$prefix}request_source, '')) = 'BUDGET' THEN {$prefix}request_id END";

    return 'COALESCE(' . implode(', ', $parts) . ')';
}

function finance_get_disbursement_budget_id(array $row): int
{
    if ((int) ($row['budget_id'] ?? 0) > 0) {
        return (int) $row['budget_id'];
    }
    if ((int) ($row['related_budget_id'] ?? 0) > 0) {
        return (int) $row['related_budget_id'];
    }
    if (strtoupper(trim((string) ($row['request_source'] ?? ''))) === 'BUDGET') {
        return (int) ($row['request_id'] ?? 0);
    }

    return 0;
}

function finance_resolve_disbursement_budget_id(array $payload): int
{
    return finance_get_disbursement_budget_id($payload);
}

function finance_get_budget_remaining_amount(PDO $pdo, int $budgetId, int $excludeDisbursementId = 0): float
{
    $budget = getBudgetById($pdo, $budgetId);
    if (!$budget) {
        throw new RuntimeException('Budget record not found.');
    }

    $schema = finance_schema_prefix($pdo);
    $budgetExpr = finance_get_disbursement_budget_column_expr($pdo);
    $params = [':budget_id' => $budgetId];
    $sql = "
        SELECT COALESCE(SUM(amount), 0)
        FROM {$schema}disbursement
        WHERE {$budgetExpr} = :budget_id
          AND UPPER(COALESCE(status, '')) IN ('RELEASED', 'POSTED')
    ";

    if ($excludeDisbursementId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeDisbursementId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usedAmount = round((float) ($stmt->fetchColumn() ?: 0), 2);

    return round((float) ($budget['allocated_amount'] ?? 0) - $usedAmount, 2);
}

function finance_assert_budget_disbursement_allowed(PDO $pdo, int $budgetId, float $amount, bool $allowOverride = false, int $excludeDisbursementId = 0): void
{
    if ($budgetId <= 0 || $amount <= 0) {
        return;
    }

    $budget = getBudgetById($pdo, $budgetId);
    if (!$budget) {
        throw new RuntimeException('Budget record not found.');
    }
    if (strcasecmp((string) ($budget['status'] ?? ''), 'Closed') === 0) {
        throw new RuntimeException('Archived budgets can no longer be used for disbursements.');
    }

    $remainingAmount = finance_get_budget_remaining_amount($pdo, $budgetId, $excludeDisbursementId);
    if ($amount > $remainingAmount + 0.00001 && !$allowOverride) {
        throw new RuntimeException('This will exceed the allocated budget.');
    }
}

function finance_restore_ap_disbursement_link(PDO $pdo, array $disbursement): void
{
    $requestSource = strtoupper(trim((string) ($disbursement['request_source'] ?? '')));
    $apId = (int) ($disbursement['request_id'] ?? 0);
    $amount = round((float) ($disbursement['amount'] ?? 0), 2);

    if ($requestSource !== 'AP' || $apId <= 0 || $amount <= 0) {
        return;
    }

    $rollback = $pdo->prepare("
        UPDATE public.ar_ap
        SET balance = balance + :amount,
            status = CASE
                WHEN balance + :amount <= 0 THEN 'Paid'
                WHEN balance + :amount < amount THEN 'Partially Paid'
                ELSE 'Pending'
            END,
            related_disbursement_id = NULL,
            updated_at = NOW()
        WHERE id = :id
          AND entry_type = 'AP'
    ");
    $rollback->execute([
        ':amount' => $amount,
        ':id' => $apId,
    ]);
}

function finance_restore_module_request_release(PDO $pdo, array $disbursement): void
{
    $requestSource = strtoupper(trim((string) ($disbursement['request_source'] ?? '')));
    $requestId = trim((string) ($disbursement['request_id'] ?? ''));
    if ($requestId === '' || !in_array($requestSource, ['CORE', 'HR', 'LOGISTICS'], true)) {
        return;
    }

    if ($requestSource === 'CORE') {
        $request = finance_get_job_posting_payment($pdo, (int) $requestId);
        if (!$request || strtolower(trim((string) ($request['status'] ?? ''))) !== 'released') {
            return;
        }

        if (($request['source'] ?? 'local') === 'supabase') {
            $client = supabase_init();
            if ($client) {
                $client->update('job_posting_payments', [
                    'status' => 'approved',
                    'released_at' => null,
                ], 'id=eq.' . rawurlencode($requestId));
            }
        } else {
            finance_ensure_request_release_columns($pdo, 'job_posting_payments');
            $schema = finance_schema_prefix($pdo);
            $stmt = $pdo->prepare("UPDATE {$schema}job_posting_payments SET status = 'approved', released_at = NULL, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => (int) $requestId]);
        }

        return;
    }

    if ($requestSource === 'HR') {
        $request = finance_get_hr_request_source_row($pdo, $requestId);
        if (!$request || finance_hr_status_label((string) ($request['status'] ?? '')) !== 'Released') {
            return;
        }

        if (($request['source'] ?? 'local') === 'supabase') {
            $client = supabase_init();
            if ($client) {
                $client->update(finance_hr_supabase_table(), [
                    'status' => 'approved',
                    'released_at' => null,
                ], 'id=eq.' . rawurlencode($requestId));
            }
        } else {
            finance_ensure_request_release_columns($pdo, 'hr_requests');
            $schema = finance_schema_prefix($pdo);
            $stmt = $pdo->prepare("UPDATE {$schema}hr_requests SET status = 'approved', released_at = NULL, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $requestId]);
        }

        return;
    }

    $request = finance_get_logistics_request_source_row($pdo, (int) $requestId);
    if (!$request || finance_logistics_status_label((string) ($request['status'] ?? '')) !== 'Released') {
        return;
    }

    if (($request['source'] ?? 'local') === 'supabase') {
        $client = supabase_init();
        if ($client) {
            $sourceTable = trim((string) ($request['source_table'] ?? finance_logistics_supabase_table()));
            $payload = $sourceTable === finance_logistics_document_tracking_table()
                ? ['status' => 'Payment Received']
                : ['payment_status' => 'Paid', 'released_at' => null];
            $client->update($sourceTable, $payload, 'id=eq.' . rawurlencode($requestId));
        }
    } else {
        finance_ensure_request_release_columns($pdo, 'logistic_requests');
        $schema = finance_schema_prefix($pdo);
        $stmt = $pdo->prepare("UPDATE {$schema}logistic_requests SET status = 'approved', released_at = NULL, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => (int) $requestId]);
    }
}

function updatePayrollRequestStatus(PDO $pdo, int $requestId, string $status): void
{
    $stmt = $pdo->prepare("UPDATE public.payroll_payment_requests SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $requestId]);
}

function finance_release_request_for_disbursement(PDO $pdo, string $module, string $requestId, string $paymentMethod = 'Bank Transfer'): int
{
    $module = strtoupper(trim($module));
    $requestId = trim($requestId);
    if ($module === '' || $requestId === '') {
        throw new InvalidArgumentException('Module and request ID are required.');
    }

    $requestCode = $module . '-' . $requestId;
    $description = 'Approved request';
    $payeeName = 'Department';
    $amount = 0.0;
    $sourceRow = null;
    $startedTransaction = !$pdo->inTransaction();

    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
    if ($module === 'CORE') {
        finance_ensure_core_request_tracking_columns($pdo);
        $sourceRow = finance_get_job_posting_payment($pdo, (int) $requestId);
        if (!$sourceRow) {
            throw new RuntimeException('Core request not found.');
        }
        $status = finance_core_status_label((string) ($sourceRow['status'] ?? ''));
        if ($status === 'Released') {
            throw new RuntimeException('This request has already been released.');
        }
        if (!in_array($status, ['Approved', 'Ready for Disbursement'], true)) {
            throw new RuntimeException('Only approved CORE requests that are ready for disbursement can be released.');
        }
        $amount = (float) ($sourceRow['amount'] ?? 0);
        $description = (string) ($sourceRow['job_title'] ?? 'Core request');
        $payeeName = (string) ($sourceRow['company_name'] ?? 'Core Services');
        $requestCode = 'CORE-' . str_pad((string) ((int) $requestId), 4, '0', STR_PAD_LEFT);
    } elseif ($module === 'HR') {
        $sourceRow = finance_get_hr_request_source_row($pdo, $requestId);
        if (!$sourceRow) {
            throw new RuntimeException('HR request not found.');
        }
        $status = finance_hr_status_label((string) ($sourceRow['status'] ?? ''));
        if ($status === 'Released') {
            throw new RuntimeException('This request has already been released.');
        }
        if ($status !== 'Approved') {
            throw new RuntimeException('Only approved requests can be released.');
        }
        $amount = finance_numeric_from_mixed($sourceRow['amount'] ?? null) ?? 0.0;
        $description = (string) ($sourceRow['request_title'] ?? $sourceRow['request_details'] ?? 'HR request');
        $payeeName = (string) ($sourceRow['employee_name'] ?? 'HR Department');
        $requestCode = (string) ($sourceRow['request_code'] ?? finance_hr_request_code($requestId));
    } elseif ($module === 'LOGISTICS') {
        finance_ensure_logistics_request_tracking_columns($pdo);
        $sourceRow = finance_get_logistics_request_source_row($pdo, (int) $requestId);
        if (!$sourceRow) {
            throw new RuntimeException('Logistics request not found.');
        }
        $linkedRecords = finance_get_logistics_linked_records($pdo, (int) $requestId);
        $status = finance_logistics_status_label((string) ($sourceRow['status'] ?? ''));
        if ($status === 'Released') {
            throw new RuntimeException('This request has already been released.');
        }
        if (!in_array($status, ['Approved', 'Ready for Disbursement'], true)) {
            throw new RuntimeException('Only approved logistics requests can be released.');
        }
        if ($linkedRecords['ap'] !== null) {
            throw new RuntimeException('This logistics request is already tracked through Accounts Payable.');
        }
        $amount = finance_numeric_from_mixed($sourceRow['amount'] ?? null) ?? 0.0;
        $description = (string) ($sourceRow['request_title'] ?? $sourceRow['item_name'] ?? 'Logistics request');
        $payeeName = (string) ($sourceRow['requested_by_name'] ?? 'Logistics Department');
        $requestCode = (string) ($sourceRow['request_code'] ?? finance_logistics_request_code((int) $requestId));
    } else {
        throw new InvalidArgumentException('Unsupported request module.');
    }

    if ($amount <= 0) {
        throw new RuntimeException('Approved amount must be greater than zero before releasing funds.');
    }

    if ($module === 'LOGISTICS') {
        if (($linkedRecords['disbursement'] ?? null) !== null) {
            throw new RuntimeException('Funds have already been released for this request.');
        }
    } else {
        foreach (getDisbursementList($pdo, ['request_source' => $module]) as $existingRow) {
            if ((string) ($existingRow['request_id'] ?? '') === $requestId) {
                throw new RuntimeException('Funds have already been released for this request.');
            }
        }
    }

    if ($module === 'HR') {
        if (($sourceRow['source'] ?? '') === 'supabase') {
            $client = supabase_init();
            if (!$client) {
                throw new RuntimeException('Supabase HR connection is not available.');
            }
            $result = $client->update(
                finance_hr_supabase_table(),
                ['status' => 'Released', 'approved_amount' => $amount, 'released_at' => date('c')],
                'id=eq.' . rawurlencode($requestId)
            );
            if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
                throw new RuntimeException('Failed to update HR request status.');
            }
        } else {
            finance_ensure_request_release_columns($pdo, 'hr_requests');
            $schema = finance_schema_prefix($pdo);
            $stmt = $pdo->prepare("UPDATE {$schema}hr_requests SET status = 'Released', approved_amount = :approved_amount, released_at = NOW() WHERE id = :id");
            $stmt->execute([':approved_amount' => $amount, ':id' => $requestId]);
        }
        finance_record_request_history($pdo, 'HR', $requestId, $requestCode, 'RELEASE', 'Released', 'Released through Disbursement module.');
    } elseif ($module === 'LOGISTICS') {
        if ($status === 'Approved') {
            finance_record_request_history($pdo, 'LOGISTICS', $requestId, $requestCode, 'READY', 'Ready for Disbursement', 'Prepared for disbursement release.');
        }
        if (($sourceRow['source'] ?? '') === 'supabase') {
            $client = supabase_init();
            if (!$client) {
                throw new RuntimeException('Supabase Logistics connection is not available.');
            }
            $sourceTable = trim((string) ($sourceRow['source_table'] ?? finance_logistics_supabase_table()));
            $result = $client->update(
                $sourceTable,
                ['payment_status' => 'Released', 'approved_amount' => $amount, 'released_at' => date('c')],
                'id=eq.' . rawurlencode($requestId)
            );
            if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
                throw new RuntimeException('Failed to update Logistics request status.');
            }
        } else {
            finance_ensure_request_release_columns($pdo, 'logistic_requests');
            $schema = finance_schema_prefix($pdo);
            $stmt = $pdo->prepare("UPDATE {$schema}logistic_requests SET status = 'Released', approved_amount = :approved_amount, released_at = NOW() WHERE id = :id");
            $stmt->execute([':approved_amount' => $amount, ':id' => (int) $requestId]);
            if (finance_column_exists($pdo, 'logistic_requests', 'related_disbursement_id')) {
                $stmt = $pdo->prepare("UPDATE {$schema}logistic_requests SET related_disbursement_id = :related_disbursement_id WHERE id = :id");
                $stmt->execute([':related_disbursement_id' => 0, ':id' => (int) $requestId]);
            }
        }
        finance_record_request_history($pdo, 'LOGISTICS', $requestId, $requestCode, 'RELEASE', 'Released', 'Released through Disbursement module.');
    }

    $disbursementId = createDisbursement($pdo, [
        'payee_name' => $payeeName,
        'request_source' => $module,
        'request_id' => (int) $requestId,
        'related_budget_id' => $module === 'CORE' ? (int) ($sourceRow['related_budget_id'] ?? 0) : 0,
        'amount' => $amount,
        'disbursement_date' => date('Y-m-d'),
        'payment_method' => $paymentMethod,
        'status' => 'Released',
        'remarks' => 'Released through Disbursement module. ' . $description,
        'ledger_mode' => 'expense',
    ]);

    finance_record_approved_request_log(
        $pdo,
        'RELEASE',
        $module,
        match ($module) {
            'CORE' => 'job_posting_payments',
            'HR' => (($sourceRow['source'] ?? '') === 'supabase') ? finance_hr_supabase_table() : 'hr_requests',
            'LOGISTICS' => (($sourceRow['source'] ?? '') === 'supabase')
                ? trim((string) ($sourceRow['source_table'] ?? finance_logistics_supabase_table()))
                : 'logistic_requests',
            default => 'disbursement',
        },
        $requestId,
        'Released through Disbursement: ' . $description,
        $amount,
        'Disbursement #' . $disbursementId . ' released to ' . $payeeName,
        date('Y-m-d H:i:s')
    );

    if ($module === 'CORE') {
        finance_ensure_request_release_columns($pdo, 'job_posting_payments');
        $schema = finance_schema_prefix($pdo);
        $stmt = $pdo->prepare("
            UPDATE {$schema}job_posting_payments
            SET status = 'Released',
                related_disbursement_id = :related_disbursement_id,
                approved_amount = :approved_amount,
                released_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':related_disbursement_id' => $disbursementId,
            ':approved_amount' => $amount,
            ':id' => (int) $requestId,
        ]);

        supabase_mirror_safe('job_posting_payments', [
            'id' => (int) $requestId,
            'status' => 'Released',
            'related_disbursement_id' => $disbursementId,
            'approved_amount' => $amount,
            'released_at' => date('c'),
            'updated_at' => date('c'),
        ], 'UPDATE', ['id' => (int) $requestId]);

        finance_record_request_history($pdo, 'CORE', $requestId, $requestCode, 'RELEASE', 'Released', 'Released through Disbursement module.');
    } elseif ($module === 'LOGISTICS' && ($sourceRow['source'] ?? '') !== 'supabase') {
        $schema = finance_schema_prefix($pdo);
        if (finance_column_exists($pdo, 'logistic_requests', 'related_disbursement_id')) {
            $stmt = $pdo->prepare("UPDATE {$schema}logistic_requests SET related_disbursement_id = :related_disbursement_id, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                ':related_disbursement_id' => $disbursementId,
                ':id' => (int) $requestId,
            ]);
        }
    }

    if ($startedTransaction && $pdo->inTransaction()) {
        $pdo->commit();
    }

    return $disbursementId;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function createDisbursement(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);
    finance_ensure_disbursement_link_columns($pdo);
    $payload = validate_disbursement_payload($data);
    $payload['reference_no'] = $payload['reference_no'] !== '' ? $payload['reference_no'] : generateDisbursementReference($pdo);
    $payload['budget_id'] = finance_resolve_disbursement_budget_id($payload);
    $allowBudgetOverride = ((int) ($data['budget_override'] ?? 0) === 1) && finance_current_user_is_admin();

    if ($payload['budget_id'] > 0 && finance_disbursement_affects_budget($payload)) {
        finance_assert_budget_disbursement_allowed($pdo, $payload['budget_id'], $payload['amount'], $allowBudgetOverride);
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $schema = finance_schema_prefix($pdo);
        $columns = ['reference_no', 'payee_name', 'request_source', 'request_id', 'amount', 'disbursement_date', 'payment_method', 'status', 'remarks'];
        $values = [':reference_no', ':payee_name', ':request_source', ':request_id', ':amount', ':disbursement_date', ':payment_method', ':status', ':remarks'];
        $params = [
            ':reference_no' => $payload['reference_no'],
            ':payee_name' => $payload['payee_name'],
            ':request_source' => $payload['request_source'],
            ':request_id' => $payload['request_id'] > 0 ? $payload['request_id'] : null,
            ':amount' => $payload['amount'],
            ':disbursement_date' => $payload['disbursement_date'],
            ':payment_method' => $payload['payment_method'] !== '' ? $payload['payment_method'] : null,
            ':status' => $payload['status'],
            ':remarks' => $payload['remarks'] !== '' ? $payload['remarks'] : null,
        ];
        foreach (['budget_id', 'related_ap_id', 'related_budget_id', 'related_payroll_id', 'ledger_mode'] as $column) {
            if (finance_column_exists($pdo, 'disbursement', $column)) {
                $columns[] = $column;
                $values[] = ':' . $column;
                $params[':' . $column] = match ($column) {
                    'ledger_mode' => $payload['ledger_mode'] !== '' ? $payload['ledger_mode'] : null,
                    default => (int) ($payload[$column] ?? 0) > 0 ? (int) $payload[$column] : null,
                };
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$schema}disbursement (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
        ");
        $stmt->execute($params);

        $id = (int) $pdo->lastInsertId();

        finance_log_audit($pdo, 'Created Disbursement', 'Disbursement', $id, null, $payload);
        supabase_mirror('disbursement', array_merge(['id' => $id], $payload));

        if (in_array($payload['status'], ['Released', 'Posted'], true)) {
            finance_record_approved_request_log(
                $pdo,
                'RELEASE',
                'DISBURSEMENT',
                'disbursement',
                (string) $id,
                'Funds released to ' . (string) ($payload['payee_name'] ?? 'payee'),
                (float) ($payload['amount'] ?? 0),
                (string) ($payload['remarks'] ?? ''),
                (string) ($payload['disbursement_date'] ?? date('Y-m-d'))
            );
        }

        if ($payload['request_source'] === 'AP') {
            updateAPBalance($pdo, $payload['request_id'], $payload['amount'], $id);
        }
        
        // Connect Budget Management to Payroll and other disbursements
        if ($payload['budget_id'] > 0) {
            recalculateBudget($pdo, $payload['budget_id']);
        }

        if ($payload['request_source'] === 'PAYROLL' && in_array($payload['status'], ['Released', 'Posted'], true)) {
            updatePayrollRequestStatus($pdo, $payload['request_id'], 'Paid');
        }

        postDisbursementToLedger($pdo, [
            'id' => $id,
            'reference_no' => $payload['reference_no'],
            'payee_name' => $payload['payee_name'],
            'amount' => $payload['amount'],
            'disbursement_date' => $payload['disbursement_date'],
            'expense_account_title' => $payload['ledger_mode'] === 'expense' ? 'Expense' : 'Accounts Payable',
        ], $payload['ledger_mode']);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getDisbursementById(PDO $pdo, int $id): ?array
{
    if (!finance_table_exists($pdo, 'disbursement')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("SELECT * FROM {$schema}disbursement WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function getDisbursement(PDO $pdo, int $id): ?array
{
    return getDisbursementById($pdo, $id);
}

function getDisbursementList(PDO $pdo, array $filters = []): array
{
    if (!finance_table_exists($pdo, 'disbursement')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $likeOp = finance_like_operator($pdo);
    $sql = "
        SELECT *
        FROM {$schema}disbursement
        WHERE 1 = 1
    ";
    $params = [];

    if (!empty($filters['date'])) {
        $sql .= ' AND disbursement_date = :date';
        $params[':date'] = $filters['date'];
    }
    if (!empty($filters['date_from'])) {
        $sql .= ' AND disbursement_date >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $sql .= ' AND disbursement_date <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }
    if (!empty($filters['status'])) {
        $sql .= ' AND status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['request_source'])) {
        $sql .= ' AND UPPER(COALESCE(request_source, \'\')) = :request_source';
        $params[':request_source'] = strtoupper((string) $filters['request_source']);
    }
    if (!empty($filters['payee'])) {
        $sql .= " AND payee_name {$likeOp} :payee";
        $params[':payee'] = '%' . trim((string) $filters['payee']) . '%';
    }
    if (!empty($filters['reference_no'])) {
        $sql .= " AND reference_no {$likeOp} :reference_no";
        $params[':reference_no'] = '%' . trim((string) $filters['reference_no']) . '%';
    }

    $sql .= ' ORDER BY disbursement_date DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function listDisbursements(PDO $pdo): array
{
    return getDisbursementList($pdo);
}

function updateDisbursement(PDO $pdo, int $id, array $data): void
{
    finance_bootstrap($pdo);
    $payload = validate_disbursement_payload($data);
    $existing = getDisbursementById($pdo, $id);

    if (!$existing) {
        throw new RuntimeException('Disbursement record not found.');
    }

    if ($payload['reference_no'] === '') {
        $payload['reference_no'] = (string) $existing['reference_no'];
    }

    $existingBudgetId = finance_get_disbursement_budget_id($existing);
    $payload['budget_id'] = finance_resolve_disbursement_budget_id($payload);
    $allowBudgetOverride = ((int) ($data['budget_override'] ?? 0) === 1) && finance_current_user_is_admin();

    if ($payload['budget_id'] > 0 && finance_disbursement_affects_budget($payload)) {
        finance_assert_budget_disbursement_allowed($pdo, $payload['budget_id'], $payload['amount'], $allowBudgetOverride, $id);
    }

    $pdo->beginTransaction();
    try {
        finance_restore_ap_disbursement_link($pdo, $existing);
        finance_restore_module_request_release($pdo, $existing);
        if ($existingBudgetId > 0) {
            recalculateBudget($pdo, $existingBudgetId);
        }

        deleteJournalEntriesBySource($pdo, 'disbursement', $id);

        $schema = finance_schema_prefix($pdo);
        $assignments = [
            'reference_no = :reference_no',
            'payee_name = :payee_name',
            'request_source = :request_source',
            'request_id = :request_id',
            'amount = :amount',
            'disbursement_date = :disbursement_date',
            'payment_method = :payment_method',
            'status = :status',
            'remarks = :remarks',
        ];
        $params = [
            ':id' => $id,
            ':reference_no' => $payload['reference_no'],
            ':payee_name' => $payload['payee_name'],
            ':request_source' => $payload['request_source'],
            ':request_id' => $payload['request_id'] > 0 ? $payload['request_id'] : null,
            ':amount' => $payload['amount'],
            ':disbursement_date' => $payload['disbursement_date'],
            ':payment_method' => $payload['payment_method'] !== '' ? $payload['payment_method'] : null,
            ':status' => $payload['status'],
            ':remarks' => $payload['remarks'] !== '' ? $payload['remarks'] : null,
        ];
        foreach (['budget_id', 'related_ap_id', 'related_budget_id', 'related_payroll_id', 'ledger_mode'] as $column) {
            if (!finance_column_exists($pdo, 'disbursement', $column)) {
                continue;
            }
            $assignments[] = $column . ' = :' . $column;
            $params[':' . $column] = match ($column) {
                'ledger_mode' => $payload['ledger_mode'] !== '' ? $payload['ledger_mode'] : null,
                default => (int) ($payload[$column] ?? 0) > 0 ? (int) $payload[$column] : null,
            };
        }
        $stmt = $pdo->prepare("
            UPDATE {$schema}disbursement
            SET " . implode(",\n                ", $assignments) . ",
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute($params);

        if ($payload['request_source'] === 'AP') {
            updateAPBalance($pdo, $payload['request_id'], $payload['amount'], $id);
        }
        if ($payload['budget_id'] > 0) {
            recalculateBudget($pdo, $payload['budget_id']);
        }
        if ($existingBudgetId > 0 && $existingBudgetId !== $payload['budget_id']) {
            recalculateBudget($pdo, $existingBudgetId);
        }

        postDisbursementToLedger($pdo, [
            'id' => $id,
            'reference_no' => $payload['reference_no'],
            'payee_name' => $payload['payee_name'],
            'amount' => $payload['amount'],
            'disbursement_date' => $payload['disbursement_date'],
            'expense_account_title' => $payload['ledger_mode'] === 'expense' ? 'Expense' : 'Accounts Payable',
        ], $payload['ledger_mode']);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deleteDisbursement(PDO $pdo, int $id): void
{
    if (!finance_table_exists($pdo, 'disbursement')) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $existing = getDisbursementById($pdo, $id);
        $existingBudgetId = $existing ? finance_get_disbursement_budget_id($existing) : 0;
        if ($existing) {
            finance_restore_ap_disbursement_link($pdo, $existing);
            finance_restore_module_request_release($pdo, $existing);
        }

        deleteJournalEntriesBySource($pdo, 'disbursement', $id);
        $schema = finance_schema_prefix($pdo);
        $stmt = $pdo->prepare("DELETE FROM {$schema}disbursement WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($existingBudgetId > 0) {
            recalculateBudget($pdo, $existingBudgetId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function recordARAP(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);

    $entryType = strtoupper(trim((string) ($data['entry_type'] ?? '')));
    $partyName = trim((string) ($data['party_name'] ?? ''));
    $referenceNo = trim((string) ($data['reference_no'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $balance = round((float) ($data['balance'] ?? $amount), 2);
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    $status = trim((string) ($data['status'] ?? ($balance <= 0.00001 ? 'Paid' : ($balance < $amount ? 'Partially Paid' : 'Pending'))));

    if (!in_array($entryType, ['AR', 'AP'], true) || $partyName === '' || $amount <= 0) {
        throw new InvalidArgumentException('Invalid AR/AP data.');
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO {$schema}ar_ap (
            entry_type,
            party_name,
            reference_no,
            description,
            amount,
            balance,
            due_date,
            status,
            related_collection_id,
            related_disbursement_id
        ) VALUES (
            :entry_type,
            :party_name,
            :reference_no,
            :description,
            :amount,
            :balance,
            :due_date,
            :status,
            :related_collection_id,
            :related_disbursement_id
        )
    ");
    $stmt->execute([
        ':entry_type' => $entryType,
        ':party_name' => $partyName,
        ':reference_no' => $referenceNo !== '' ? $referenceNo : null,
        ':description' => $description !== '' ? $description : null,
        ':amount' => $amount,
        ':balance' => $balance,
        ':due_date' => finance_is_valid_date($dueDate) ? $dueDate : null,
        ':status' => $status !== '' ? $status : null,
        ':related_collection_id' => !empty($data['related_collection_id']) ? (int) $data['related_collection_id'] : null,
        ':related_disbursement_id' => !empty($data['related_disbursement_id']) ? (int) $data['related_disbursement_id'] : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    supabase_mirror('ar_ap', array_merge(['id' => $id, 'entry_type' => $entryType], [
        'party_name' => $partyName,
        'reference_no' => $referenceNo,
        'description' => $description,
        'amount' => $amount,
        'balance' => $balance,
        'due_date' => $dueDate,
        'status' => $status,
        'related_collection_id' => !empty($data['related_collection_id']) ? (int) $data['related_collection_id'] : null,
        'related_disbursement_id' => !empty($data['related_disbursement_id']) ? (int) $data['related_disbursement_id'] : null,
    ]));

    return $id;
}

function listArAp(PDO $pdo, ?string $entryType = null, array $filters = []): array
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return [];
    }

    finance_ensure_ar_ap_tracking_columns($pdo);

    $schema = finance_schema_prefix($pdo);
    $sql = "SELECT * FROM {$schema}ar_ap";
    $params = [];
    $where = [];
    $normalizedFilters = finance_build_ar_ap_filters($filters);

    if ($entryType !== null && $entryType !== '') {
        $where[] = 'entry_type = :entry_type';
        $params[':entry_type'] = strtoupper($entryType);
    }

    if ($normalizedFilters['search'] !== '') {
        $where[] = "(COALESCE(party_name, '') LIKE :search OR COALESCE(reference_no, '') LIKE :search OR COALESCE(description, '') LIKE :search)";
        $params[':search'] = '%' . $normalizedFilters['search'] . '%';
    }
    if ($normalizedFilters['due_date'] !== '' && finance_is_valid_date($normalizedFilters['due_date'])) {
        $where[] = 'due_date = :due_date';
        $params[':due_date'] = $normalizedFilters['due_date'];
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY created_at DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll() ?: [];
    $rows = array_map('finance_arap_enrich_row', $rows);

    if ($normalizedFilters['status'] !== '') {
        $filterStatus = strtolower($normalizedFilters['status']);
        $rows = array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === $filterStatus));
    }

    return $rows;
}

function getARList(PDO $pdo, array $filters = []): array
{
    return listArAp($pdo, 'AR', $filters);
}

function getAPList(PDO $pdo, array $filters = []): array
{
    return listArAp($pdo, 'AP', $filters);
}

function finance_get_ar_ap_summary(PDO $pdo): array
{
    $arRows = getARList($pdo);
    $apRows = getAPList($pdo);
    $summary = [
        'open_ar' => 0.0,
        'open_ap' => 0.0,
        'overdue_receivables' => 0,
        'due_this_week' => 0,
        'has_ar' => !empty($arRows),
        'has_ap' => !empty($apRows),
    ];

    $todayTs = strtotime(date('Y-m-d')) ?: 0;
    $weekTs = strtotime('+7 days', $todayTs) ?: $todayTs;

    foreach (array_merge($arRows, $apRows) as $row) {
        $entryType = strtoupper((string) ($row['entry_type'] ?? ''));
        $balance = (float) ($row['balance'] ?? 0);
        $dueDate = trim((string) ($row['due_date'] ?? ''));
        $dueTs = $dueDate !== '' ? (strtotime($dueDate) ?: 0) : 0;

        if ($entryType === 'AR' && !($row['is_paid'] ?? false)) {
            $summary['open_ar'] += $balance;
        }
        if ($entryType === 'AP' && !($row['is_paid'] ?? false)) {
            $summary['open_ap'] += $balance;
        }
        if (($row['status'] ?? '') === 'Overdue') {
            $summary['overdue_receivables']++;
        }
        if ($dueTs >= $todayTs && $dueTs <= $weekTs && !($row['is_paid'] ?? false)) {
            $summary['due_this_week']++;
        }
    }

    return $summary;
}

function finance_set_ar_ap_paid_at(PDO $pdo, int $id, ?string $paidAt = null): void
{
    if ($id <= 0 || !finance_table_exists($pdo, 'ar_ap')) {
        return;
    }

    finance_ensure_ar_ap_tracking_columns($pdo);
    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("UPDATE {$schema}ar_ap SET paid_at = :paid_at, updated_at = NOW() WHERE id = :id");
    $stmt->execute([
        ':paid_at' => $paidAt !== null && trim($paidAt) !== '' ? $paidAt : null,
        ':id' => $id,
    ]);
}

function finance_approve_ap_entry(PDO $pdo, int $id): void
{
    $row = getArAp($pdo, $id);
    if (!$row || strtoupper((string) ($row['entry_type'] ?? '')) !== 'AP') {
        throw new RuntimeException('Accounts Payable record not found.');
    }
    if ((float) ($row['balance'] ?? 0) <= 0.00001) {
        throw new RuntimeException('This payable is already paid.');
    }
    if (strcasecmp((string) ($row['status'] ?? ''), 'Approved') === 0) {
        throw new RuntimeException('This payable is already approved.');
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("UPDATE {$schema}ar_ap SET status = 'Approved', updated_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $id]);
    supabase_mirror_safe('ar_ap', ['status' => 'Approved'], 'UPDATE', ['id' => $id]);
}

function finance_record_ar_payment(PDO $pdo, int $id, array $data): int
{
    $row = getArAp($pdo, $id);
    if (!$row || strtoupper((string) ($row['entry_type'] ?? '')) !== 'AR') {
        throw new RuntimeException('Accounts Receivable record not found.');
    }

    $balance = (float) ($row['balance'] ?? 0);
    if ($balance <= 0.00001) {
        throw new RuntimeException('This receivable is already paid.');
    }

    $amount = round((float) ($data['amount'] ?? $balance), 2);
    $paymentDate = trim((string) ($data['payment_date'] ?? date('Y-m-d')));
    $paymentMethod = trim((string) ($data['payment_method'] ?? 'Cash'));
    $remarks = trim((string) ($data['remarks'] ?? ''));

    if ($amount <= 0 || $amount > $balance + 0.00001) {
        throw new RuntimeException('Payment amount must be greater than zero and not exceed the remaining balance.');
    }

    $collectionId = createCollection($pdo, [
        'source_type' => 'AR',
        'source_id' => $id,
        'payer_name' => (string) ($row['party_name'] ?? 'Customer'),
        'amount' => $amount,
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'Cash',
        'payment_date' => $paymentDate,
        'status' => 'Posted',
        'remarks' => $remarks !== '' ? $remarks : ('Payment recorded for ' . (string) ($row['reference_no'] ?? 'AR')),
    ]);

    $updated = getArAp($pdo, $id);
    if ($updated && (float) ($updated['balance'] ?? 0) <= 0.00001) {
        finance_set_ar_ap_paid_at($pdo, $id, $paymentDate . ' 00:00:00');
    }

    return $collectionId;
}

function finance_record_ap_disbursement(PDO $pdo, int $id, array $data): int
{
    $row = getArAp($pdo, $id);
    if (!$row || strtoupper((string) ($row['entry_type'] ?? '')) !== 'AP') {
        throw new RuntimeException('Accounts Payable record not found.');
    }

    $balance = (float) ($row['balance'] ?? 0);
    if ($balance <= 0.00001) {
        throw new RuntimeException('This payable is already paid.');
    }

    $amount = round((float) ($data['amount'] ?? $balance), 2);
    $date = trim((string) ($data['disbursement_date'] ?? date('Y-m-d')));
    $paymentMethod = trim((string) ($data['payment_method'] ?? 'Bank Transfer'));
    $remarks = trim((string) ($data['remarks'] ?? ''));

    if ($amount <= 0 || $amount > $balance + 0.00001) {
        throw new RuntimeException('Disbursement amount must be greater than zero and not exceed the remaining balance.');
    }

    $disbursementId = createDisbursement($pdo, [
        'payee_name' => (string) ($row['party_name'] ?? 'Vendor'),
        'request_source' => 'AP',
        'request_id' => $id,
        'related_ap_id' => $id,
        'amount' => $amount,
        'disbursement_date' => $date,
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'Bank Transfer',
        'status' => 'Released',
        'remarks' => $remarks !== '' ? $remarks : ('Disbursement recorded for ' . (string) ($row['reference_no'] ?? 'AP')),
        'ledger_mode' => 'ap',
    ]);

    $updated = getArAp($pdo, $id);
    if ($updated && (float) ($updated['balance'] ?? 0) <= 0.00001) {
        finance_set_ar_ap_paid_at($pdo, $id, $date . ' 00:00:00');
    }

    return $disbursementId;
}

function updateAPBalance(PDO $pdo, int $apIdOrDisbursementId, float $amount, ?int $disbursementId = null): void
{
    $apId = $apIdOrDisbursementId;

    if ($disbursementId === null) {
        $schema = finance_schema_prefix($pdo);
        $disbursementStmt = $pdo->prepare("
            SELECT id, request_source, request_id
            FROM {$schema}disbursement
            WHERE id = :id
            LIMIT 1
        ");
        $disbursementStmt->execute([':id' => $apIdOrDisbursementId]);
        $disbursement = $disbursementStmt->fetch();

        if (!$disbursement || strtoupper((string) ($disbursement['request_source'] ?? '')) !== 'AP') {
            return;
        }

        $disbursementId = (int) ($disbursement['id'] ?? 0);
        $apId = (int) ($disbursement['request_id'] ?? 0);
    }

    if ($apId <= 0) {
        throw new RuntimeException('AP source record is missing.');
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT id, amount, balance
        FROM {$schema}ar_ap
        WHERE id = :id
          AND entry_type = 'AP'
        LIMIT 1
    ");
    $stmt->execute([':id' => $apId]);
    $ap = $stmt->fetch();

    if (!$ap) {
        throw new RuntimeException('Linked AP record was not found.');
    }

    $currentBalance = (float) $ap['balance'];
    if ($amount > $currentBalance + 0.00001) {
        throw new RuntimeException('Disbursement amount cannot exceed the AP balance.');
    }

    $newBalance = max(0, round($currentBalance - $amount, 2));
    $newStatus = $newBalance <= 0.00001 ? 'Paid' : 'Approved';

    $update = $pdo->prepare("
        UPDATE {$schema}ar_ap
        SET balance = :balance,
            status = :status,
            related_disbursement_id = :related_disbursement_id,
            updated_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':balance' => $newBalance,
        ':status' => $newStatus,
        ':related_disbursement_id' => $disbursementId,
        ':id' => $apId,
    ]);

    finance_set_ar_ap_paid_at($pdo, $apId, $newBalance <= 0.00001 ? date('Y-m-d H:i:s') : null);

    supabase_mirror('ar_ap', [
        'balance' => $newBalance,
        'status' => $newStatus,
        'related_disbursement_id' => $disbursementId
    ], 'UPDATE', ['id' => $apId]);
}

function getArAp(PDO $pdo, int $id): ?array
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("SELECT * FROM {$schema}ar_ap WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function updateArAp(PDO $pdo, int $id, array $data): void
{
    $existing = getArAp($pdo, $id);
    if (!$existing) {
        throw new RuntimeException('AR/AP record not found.');
    }

    $entryType = strtoupper(trim((string) ($data['entry_type'] ?? '')));
    $partyName = trim((string) ($data['party_name'] ?? ''));
    $referenceNo = trim((string) ($data['reference_no'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $balance = round((float) ($data['balance'] ?? $amount), 2);
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    $status = trim((string) ($data['status'] ?? 'Open'));

    if (!in_array($entryType, ['AR', 'AP'], true) || $partyName === '' || $amount <= 0) {
        throw new InvalidArgumentException('Invalid AR/AP data.');
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        UPDATE {$schema}ar_ap
        SET entry_type = :entry_type,
            party_name = :party_name,
            reference_no = :reference_no,
            description = :description,
            amount = :amount,
            balance = :balance,
            due_date = :due_date,
            status = :status,
            related_collection_id = :related_collection_id,
            related_disbursement_id = :related_disbursement_id,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':entry_type' => $entryType,
        ':party_name' => $partyName,
        ':reference_no' => $referenceNo !== '' ? $referenceNo : null,
        ':description' => $description !== '' ? $description : null,
        ':amount' => $amount,
        ':balance' => $balance,
        ':due_date' => finance_is_valid_date($dueDate) ? $dueDate : null,
        ':status' => $status !== '' ? $status : null,
        ':related_collection_id' => !empty($data['related_collection_id']) ? (int) $data['related_collection_id'] : null,
        ':related_disbursement_id' => !empty($data['related_disbursement_id']) ? (int) $data['related_disbursement_id'] : null,
    ]);

    supabase_mirror('ar_ap', array_merge(['id' => $id], $data), 'UPDATE', ['id' => $id]);
}

function deleteArAp(PDO $pdo, int $id): void
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("DELETE FROM {$schema}ar_ap WHERE id = :id");
    $stmt->execute([':id' => $id]);
    supabase_mirror('ar_ap', [], 'DELETE', ['id' => $id]);
}

function updateBudgetFunding(PDO $pdo, int $budgetId): void
{
    if (!finance_table_exists($pdo, 'collection') || !finance_table_exists($pdo, 'budget_management')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM {$schema}collection
        WHERE related_budget_id = :budget_id
          AND status = 'Posted'
    ");
    $stmt->execute([':budget_id' => $budgetId]);
    $fundedAmount = (float) $stmt->fetchColumn();

    $update = $pdo->prepare("
        UPDATE {$schema}budget_management
        SET funded_amount = :funded_amount,
            updated_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':funded_amount' => $fundedAmount,
        ':id' => $budgetId,
    ]);

    supabase_mirror('budget_management', ['funded_amount' => $fundedAmount], 'UPDATE', ['id' => $budgetId]);
}

function updateBudget(PDO $pdo, int|array $budgetIdOrData, ?array $data = null): int
{
    finance_bootstrap($pdo);

    if (is_array($budgetIdOrData)) {
        $data = $budgetIdOrData;
    } else {
        $data = $data ?? [];
        $data['id'] = $budgetIdOrData;
    }

    $budgetId = (int) ($data['id'] ?? 0);
    $budgetName = trim((string) ($data['budget_name'] ?? ''));
    $department = trim((string) ($data['department'] ?? ''));
    $allocatedAmount = round((float) ($data['allocated_amount'] ?? 0), 2);
    $periodStart = trim((string) ($data['period_start'] ?? ''));
    $periodEnd = trim((string) ($data['period_end'] ?? ''));
    $status = trim((string) ($data['status'] ?? 'Active'));
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($budgetName === '') {
        throw new InvalidArgumentException('Budget name is required.');
    }
    if ($department === '') {
        throw new InvalidArgumentException('Department is required.');
    }
    if ($allocatedAmount < 0) {
        throw new InvalidArgumentException('Allocated amount cannot be negative.');
    }
    if ($periodStart !== '' && !finance_is_valid_date($periodStart)) {
        throw new InvalidArgumentException('Period start is invalid.');
    }
    if ($periodEnd !== '' && !finance_is_valid_date($periodEnd)) {
        throw new InvalidArgumentException('Period end is invalid.');
    }
    if ($periodStart !== '' && $periodEnd !== '' && $periodStart > $periodEnd) {
        throw new InvalidArgumentException('Period start must be on or before period end.');
    }

    if ($budgetId > 0) {
        $existing = getBudgetById($pdo, $budgetId);
        if (!$existing) {
            throw new RuntimeException('Budget record not found.');
        }

        $usedAmount = round((float) ($existing['used_amount'] ?? 0), 2);
        $remainingAmount = round($allocatedAmount - $usedAmount, 2);
        $status = normalizeBudgetStatus([
            'allocated_amount' => $allocatedAmount,
            'used_amount' => $usedAmount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => $status,
        ]);

        $schema = finance_schema_prefix($pdo);
        $stmt = $pdo->prepare("
            UPDATE {$schema}budget_management
            SET budget_name = :budget_name,
                department = :department,
                allocated_amount = :allocated_amount,
                used_amount = :used_amount,
                remaining_amount = :remaining_amount,
                period_start = :period_start,
                period_end = :period_end,
                status = :status,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $budgetId,
            ':budget_name' => $budgetName,
            ':department' => $department,
            ':allocated_amount' => $allocatedAmount,
            ':used_amount' => $usedAmount,
            ':remaining_amount' => $remainingAmount,
            ':period_start' => $periodStart !== '' ? $periodStart : null,
            ':period_end' => $periodEnd !== '' ? $periodEnd : null,
            ':status' => $status,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        finance_log_audit($pdo, 'Updated Budget', 'Budget', $budgetId, null, $data);
        supabase_mirror('budget_management', array_merge(['id' => $budgetId], $data, [
            'used_amount' => $usedAmount,
            'remaining_amount' => $remainingAmount,
            'status' => $status,
        ]), 'UPDATE', ['id' => $budgetId]);

        return $budgetId;
    }

    return createBudget($pdo, $data);
}

function deleteBudget(PDO $pdo, int $id): void
{
    if (!finance_table_exists($pdo, 'budget_management')) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("DELETE FROM {$schema}budget_management WHERE id = :id");
    $stmt->execute([':id' => $id]);
    supabase_mirror('budget_management', [], 'DELETE', ['id' => $id]);
}

function getBudgetById(PDO $pdo, int $id): ?array
{
    if (!finance_table_exists($pdo, 'budget_management')) {
        return null;
    }

    $schema = finance_schema_prefix($pdo);
    $stmt = $pdo->prepare("SELECT * FROM {$schema}budget_management WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function getBudget(PDO $pdo, int $id): ?array
{
    return getBudgetById($pdo, $id);
}

function finance_get_budget_usage_totals(PDO $pdo, int $budgetId): array
{
    $usageMap = finance_get_budget_usage_totals_map($pdo, [$budgetId]);

    return $usageMap[$budgetId] ?? [
        'disbursement_total' => 0.0,
        'used_amount' => 0.0,
    ];
}

function finance_get_budget_usage_totals_map(PDO $pdo, array $budgetIds): array
{
    $budgetIds = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $budgetIds), static fn (int $id): bool => $id > 0)));
    $totals = [];

    foreach ($budgetIds as $budgetId) {
        $totals[$budgetId] = [
            'disbursement_total' => 0.0,
            'used_amount' => 0.0,
        ];
    }

    if ($budgetIds === []) {
        return $totals;
    }

    $schema = finance_schema_prefix($pdo);
    $placeholders = [];
    $params = [];
    foreach ($budgetIds as $index => $budgetId) {
        $placeholder = ':budget_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $budgetId;
    }
    $inClause = implode(', ', $placeholders);

    if (finance_table_exists($pdo, 'disbursement')) {
        $budgetExpr = finance_get_disbursement_budget_column_expr($pdo);
        $stmt = $pdo->prepare("
            SELECT {$budgetExpr} AS budget_id, COALESCE(SUM(amount), 0) AS total_amount
            FROM {$schema}disbursement
            WHERE {$budgetExpr} IN ({$inClause})
              AND UPPER(COALESCE(status, '')) IN ('RELEASED', 'POSTED')
            GROUP BY {$budgetExpr}
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $budgetId = (int) ($row['budget_id'] ?? 0);
            if ($budgetId > 0 && isset($totals[$budgetId])) {
                $totals[$budgetId]['disbursement_total'] = round((float) ($row['total_amount'] ?? 0), 2);
            }
        }
    }

    foreach ($totals as &$budgetTotals) {
        $budgetTotals['used_amount'] = $budgetTotals['disbursement_total'];
    }
    unset($budgetTotals);

    return $totals;
}

function recalculateBudget(PDO $pdo, int $budgetId): void
{
    $budget = getBudgetById($pdo, $budgetId);
    if (!$budget) {
        return;
    }

    $schema = finance_schema_prefix($pdo);
    $usage = finance_get_budget_usage_totals($pdo, $budgetId);
    $usedAmount = round((float) ($usage['used_amount'] ?? 0), 2);
    $allocatedAmount = round((float) ($budget['allocated_amount'] ?? 0), 2);
    $remainingAmount = round($allocatedAmount - $usedAmount, 2);
    $status = normalizeBudgetStatus([
        'allocated_amount' => $allocatedAmount,
        'used_amount' => $usedAmount,
        'period_start' => (string) ($budget['period_start'] ?? ''),
        'period_end' => (string) ($budget['period_end'] ?? ''),
        'status' => (string) ($budget['status'] ?? 'Active'),
    ]);

    $update = $pdo->prepare("
        UPDATE {$schema}budget_management
        SET used_amount = :used_amount,
            remaining_amount = :remaining_amount,
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':used_amount' => $usedAmount,
        ':remaining_amount' => $remainingAmount,
        ':status' => $status,
        ':id' => $budgetId,
    ]);

    supabase_mirror('budget_management', [
        'used_amount' => $usedAmount,
        'remaining_amount' => $remainingAmount,
        'status' => $status
    ], 'UPDATE', ['id' => $budgetId]);
}

function canDisburseFromBudget(PDO $pdo, int $budgetId, float $amount): bool
{
    $budget = getBudgetById($pdo, $budgetId);
    if (!$budget) {
        throw new RuntimeException('Budget record not found.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Disbursement amount must be greater than zero.');
    }

    return (float) $budget['remaining_amount'] >= round($amount, 2);
}

function applyBudgetUsage(PDO $pdo, int $budgetId, float $amount): void
{
    finance_assert_budget_disbursement_allowed($pdo, $budgetId, $amount);
    recalculateBudget($pdo, $budgetId);
}

function getBudgetList(PDO $pdo, array $filters = []): array
{
    if (!finance_table_exists($pdo, 'budget_management')) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $sql = "SELECT * FROM {$schema}budget_management WHERE 1 = 1";
    $params = [];
    $periodFilter = trim((string) ($filters['period'] ?? ''));
    $search = trim((string) ($filters['search'] ?? ''));

    if (!empty($filters['department'])) {
        $sql .= ' AND department = :department';
        $params[':department'] = $filters['department'];
    }
    if (!empty($filters['active_period'])) {
        $sql .= ' AND (period_start IS NULL OR period_start <= CURRENT_DATE) AND (period_end IS NULL OR period_end >= CURRENT_DATE)';
    }
    if ($search !== '') {
        $sql .= " AND (budget_name LIKE :search OR department LIKE :search OR COALESCE(notes, '') LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $usageMap = finance_get_budget_usage_totals_map($pdo, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));

    foreach ($rows as &$row) {
        $budgetId = (int) ($row['id'] ?? 0);
        $usage = $usageMap[$budgetId] ?? [
            'used_amount' => (float) ($row['used_amount'] ?? 0),
        ];
        $row['used_amount'] = round((float) ($usage['used_amount'] ?? $row['used_amount'] ?? 0), 2);
        $row['remaining_amount'] = round((float) ($row['allocated_amount'] ?? 0) - (float) $row['used_amount'], 2);
        $row['status'] = normalizeBudgetStatus([
            'allocated_amount' => (float) ($row['allocated_amount'] ?? 0),
            'used_amount' => (float) ($row['used_amount'] ?? 0),
            'period_start' => (string) ($row['period_start'] ?? ''),
            'period_end' => (string) ($row['period_end'] ?? ''),
            'status' => (string) ($row['status'] ?? 'Active'),
        ]);
        $allocated = (float) ($row['allocated_amount'] ?? 0);
        $row['utilization_percentage'] = $allocated > 0 ? round(((float) $row['used_amount'] / $allocated) * 100, 2) : 0.0;
        $row['remaining_percentage'] = $allocated > 0 ? round(((float) $row['remaining_amount'] / $allocated) * 100, 2) : 0.0;
        $periodEnd = trim((string) ($row['period_end'] ?? ''));
        $row['is_expiring_soon'] = $periodEnd !== '' && finance_is_valid_date($periodEnd)
            && $periodEnd >= date('Y-m-d') && $periodEnd <= date('Y-m-d', strtotime('+14 days'));
    }
    unset($row);

    if (!empty($filters['status'])) {
        $statusFilter = strtolower(trim((string) $filters['status']));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === $statusFilter));
    }
    if ($periodFilter !== '') {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+14 days'));
        $rows = array_values(array_filter($rows, static function (array $row) use ($periodFilter, $today, $soon): bool {
            $periodStart = trim((string) ($row['period_start'] ?? ''));
            $periodEnd = trim((string) ($row['period_end'] ?? ''));

            return match ($periodFilter) {
                'current' => ($periodStart === '' || $periodStart <= $today) && ($periodEnd === '' || $periodEnd >= $today),
                'expired' => $periodEnd !== '' && $periodEnd < $today,
                'expiring_soon' => $periodEnd !== '' && $periodEnd >= $today && $periodEnd <= $soon,
                default => true,
            };
        }));
    }

    return $rows;
}

function listBudgets(PDO $pdo): array
{
    return getBudgetList($pdo);
}

function getBudgetDashboardSummary(PDO $pdo): array
{
    return finance_get_budget_dashboard_summary_from_rows(getBudgetList($pdo));
}

function finance_get_budget_dashboard_summary_from_rows(array $rows): array
{
    if (!$rows) {
        return [
            'total_allocated' => 0.0,
            'total_used' => 0.0,
            'total_remaining' => 0.0,
            'near_limit_budgets' => 0,
            'expired_budgets' => 0,
            'over_budget_count' => 0,
        ];
    }

    $summary = [
        'total_allocated' => 0.0,
        'total_used' => 0.0,
        'total_remaining' => 0.0,
        'near_limit_budgets' => 0,
        'expired_budgets' => 0,
        'over_budget_count' => 0,
    ];

    foreach ($rows as $row) {
        $summary['total_allocated'] += (float) ($row['allocated_amount'] ?? 0);
        $summary['total_used'] += (float) ($row['used_amount'] ?? 0);
        $summary['total_remaining'] += (float) ($row['remaining_amount'] ?? 0);
        if (($row['status'] ?? '') === 'Over Budget') {
            $summary['over_budget_count']++;
        }
        if (($row['status'] ?? '') === 'Expired') {
            $summary['expired_budgets']++;
        }
        if ((float) ($row['utilization_percentage'] ?? 0) >= 70 && (float) ($row['utilization_percentage'] ?? 0) <= 100 && !in_array((string) ($row['status'] ?? ''), ['Closed', 'Expired', 'Over Budget'], true)) {
            $summary['near_limit_budgets']++;
        }
    }

    return $summary;
}

function finance_get_budget_usage_history(PDO $pdo, int $budgetId, int $limit = 20): array
{
    if ($budgetId <= 0) {
        return [];
    }

    $schema = finance_schema_prefix($pdo);
    $history = [];

    if (finance_table_exists($pdo, 'disbursement')) {
        $budgetExpr = finance_get_disbursement_budget_column_expr($pdo);
        $stmt = $pdo->prepare("
            SELECT reference_no, amount, disbursement_date AS transaction_date, payee_name AS party_name, remarks, UPPER(COALESCE(request_source, 'DISBURSEMENT')) AS source_type
            FROM {$schema}disbursement
            WHERE {$budgetExpr} = :budget_id
              AND UPPER(COALESCE(status, '')) IN ('RELEASED', 'POSTED')
            ORDER BY disbursement_date DESC, id DESC
        ");
        $stmt->execute([':budget_id' => $budgetId]);
        $history = array_merge($history, $stmt->fetchAll() ?: []);
    }

    usort($history, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['transaction_date'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['transaction_date'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });

    return array_slice($history, 0, max(1, $limit));
}

function finance_get_budget_filtered_summary(array $rows): array
{
    $summary = [
        'allocated' => 0.0,
        'used' => 0.0,
        'remaining' => 0.0,
        'count' => count($rows),
    ];

    foreach ($rows as $row) {
        $summary['allocated'] += (float) ($row['allocated_amount'] ?? 0);
        $summary['used'] += (float) ($row['used_amount'] ?? 0);
        $summary['remaining'] += (float) ($row['remaining_amount'] ?? 0);
    }

    return $summary;
}

function archiveFinancialPeriod(PDO $pdo, string $dateFrom, string $dateTo): void
{
    if (!finance_table_exists($pdo, 'general_ledger')) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE public.general_ledger
        SET is_archived = TRUE,
            updated_at = NOW()
        WHERE transaction_date >= :date_from
          AND transaction_date <= :date_to
          AND is_archived = FALSE
    ");
    $stmt->execute([
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ]);

    finance_log_audit($pdo, 'Archived Financial Period', 'General Ledger', null, ['from' => $dateFrom, 'to' => $dateTo]);
}

function getJournalByReference(PDO $pdo, string $referenceNo): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM public.general_ledger
        WHERE reference_no = :reference_no
        ORDER BY id ASC
    ");
    $stmt->execute([':reference_no' => $referenceNo]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        throw new RuntimeException('Journal entry not found.');
    }

    return $rows;
}

function getLedgerEntries(PDO $pdo, array $filters = []): array
{
    return listGeneralLedger($pdo, $filters);
}

function getTrialBalance(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'general_ledger')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT
            account_name AS account_title,
            COALESCE(SUM(debit), 0) AS total_debit,
            COALESCE(SUM(credit), 0) AS total_credit,
            COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS ending_balance
        FROM public.general_ledger
        GROUP BY account_name
        ORDER BY account_name ASC
    ");

    return $stmt->fetchAll();
}

function getIncomeSummary(PDO $pdo): array
{
    $summary = [
        'total_collections' => 0.0,
        'total_disbursements' => 0.0,
        'net_income' => 0.0,
    ];

    if (finance_table_exists($pdo, 'collection')) {
        $summary['total_collections'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM public.collection
        ")->fetchColumn() ?: 0);
    }

    if (finance_table_exists($pdo, 'disbursement')) {
        $summary['total_disbursements'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM public.disbursement
        ")->fetchColumn() ?: 0);
    }

    $summary['net_income'] = round($summary['total_collections'] - $summary['total_disbursements'], 2);

    return $summary;
}

function getBudgetPerformance(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'budget_management')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT
            id,
            budget_name,
            department,
            allocated_amount,
            used_amount,
            remaining_amount,
            status,
            period_start,
            period_end,
            CASE
                WHEN allocated_amount > 0 THEN ROUND((used_amount / allocated_amount) * 100, 2)
                ELSE 0
            END AS utilization_percentage
        FROM public.budget_management
        ORDER BY department ASC NULLS LAST, budget_name ASC
    ");

    return $stmt->fetchAll();
}

function getAccountsReceivableAging(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT
            reference_no,
            party_name,
            balance,
            due_date,
            GREATEST(CURRENT_DATE - COALESCE(due_date, CURRENT_DATE), 0) AS days_overdue,
            status
        FROM public.ar_ap
        WHERE entry_type = 'AR'
          AND COALESCE(status, '') != 'Paid'
          AND balance > 0
        ORDER BY due_date ASC NULLS LAST, reference_no ASC
    ");

    return $stmt->fetchAll();
}

function getAccountsPayableSummary(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'ar_ap')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT
            reference_no,
            party_name,
            balance,
            due_date,
            status
        FROM public.ar_ap
        WHERE entry_type = 'AP'
          AND balance > 0
        ORDER BY due_date ASC NULLS LAST, reference_no ASC
    ");

    return $stmt->fetchAll();
}

function getFinancialDashboardSummary(PDO $pdo): array
{
    $summary = [
        'collections_month' => 0.0,
        'disbursements_month' => 0.0,
        'open_ar' => 0.0,
        'open_ap' => 0.0,
        'budget_remaining' => 0.0,
    ];

    if (finance_table_exists($pdo, 'collection')) {
        $summary['collections_month'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM public.collection
            WHERE DATE_TRUNC('month', payment_date) = DATE_TRUNC('month', CURRENT_DATE)
        ")->fetchColumn() ?: 0);
    }

    if (finance_table_exists($pdo, 'disbursement')) {
        $summary['disbursements_month'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM public.disbursement
            WHERE DATE_TRUNC('month', disbursement_date) = DATE_TRUNC('month', CURRENT_DATE)
        ")->fetchColumn() ?: 0);
    }

    if (finance_table_exists($pdo, 'ar_ap')) {
        $summary['open_ar'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(balance), 0)
            FROM public.ar_ap
            WHERE entry_type = 'AR'
              AND COALESCE(status, '') != 'Paid'
        ")->fetchColumn() ?: 0);

        $summary['open_ap'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(balance), 0)
            FROM public.ar_ap
            WHERE entry_type = 'AP'
              AND COALESCE(status, '') != 'Paid'
        ")->fetchColumn() ?: 0);
    }

    if (finance_table_exists($pdo, 'budget_management')) {
        $summary['budget_remaining'] = (float) ($pdo->query("
            SELECT COALESCE(SUM(remaining_amount), 0)
            FROM public.budget_management
        ")->fetchColumn() ?: 0);
    }

    return $summary;
}

function getFinancialDashboardWorkspace(PDO $pdo): array
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPg = $driver === 'pgsql';
    $schema = finance_schema_prefix($pdo);
    $todaySql = $isPg ? 'CURRENT_DATE' : 'CURDATE()';
    $monthSql = $isPg
        ? "DATE_TRUNC('month', %s) = DATE_TRUNC('month', CURRENT_DATE)"
        : "DATE_FORMAT(%s, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    $weekSql = $isPg
        ? "%s >= CURRENT_DATE - INTERVAL '7 days'"
        : "%s >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";

    $workspace = [
        'summary' => [
            'open_ar_count' => 0,
            'open_ap_count' => 0,
            'collections_today_count' => 0,
            'disbursements_week_count' => 0,
            'pending_client_requests' => 0,
            'budgets_active' => 0,
        ],
        'quick_actions' => [
            ['label' => 'Record Collection', 'href' => '/FinancialSM/financial/collection.php'],
            ['label' => 'Create Disbursement', 'href' => '/FinancialSM/financial/disbursement.php'],
            ['label' => 'View Pending Requests', 'href' => '/FinancialSM/financial/collection.php'],
            ['label' => 'Open AR / AP', 'href' => '/FinancialSM/financial/ap-ar.php'],
            ['label' => 'View Request Logs', 'href' => '/FinancialSM/financial/request-action-logs.php'],
        ],
        'recent_activity' => [],
        'pending_work' => [],
        'submodules' => [
            'collection' => ['pending' => 0, 'today_amount' => 0.0, 'unposted' => 0],
            'disbursement' => ['pending' => 0, 'week_amount' => 0.0],
            'ar_ap' => ['open_ar_count' => 0, 'open_ap_count' => 0],
            'general_ledger' => ['recent_entries' => 0],
            'budget' => ['active_budgets' => 0, 'remaining_amount' => 0.0],
            'payroll' => ['pending_runs' => 0, 'latest_status' => 'No data yet'],
        ],
    ];

    try {
        if (finance_table_exists($pdo, 'ar_ap')) {
            $workspace['summary']['open_ar_count'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}ar_ap
                WHERE entry_type = 'AR'
                  AND COALESCE(balance, 0) > 0
            ")->fetchColumn() ?: 0);
            $workspace['summary']['open_ap_count'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}ar_ap
                WHERE entry_type = 'AP'
                  AND COALESCE(balance, 0) > 0
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['ar_ap'] = [
                'open_ar_count' => $workspace['summary']['open_ar_count'],
                'open_ap_count' => $workspace['summary']['open_ap_count'],
            ];
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'collection')) {
            $workspace['summary']['collections_today_count'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}collection
                WHERE DATE(payment_date) = {$todaySql}
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['collection']['today_amount'] = (float) ($pdo->query("
                SELECT COALESCE(SUM(amount), 0)
                FROM {$schema}collection
                WHERE DATE(payment_date) = {$todaySql}
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['collection']['unposted'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}collection
                WHERE UPPER(COALESCE(status, 'PENDING')) NOT IN ('POSTED', 'PAID')
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['collection']['pending'] = $workspace['submodules']['collection']['unposted'];
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'disbursement')) {
            $workspace['summary']['disbursements_week_count'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}disbursement
                WHERE " . sprintf($weekSql, 'disbursement_date') . "
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['disbursement']['week_amount'] = (float) ($pdo->query("
                SELECT COALESCE(SUM(amount), 0)
                FROM {$schema}disbursement
                WHERE " . sprintf($weekSql, 'disbursement_date') . "
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['disbursement']['pending'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}disbursement
                WHERE UPPER(COALESCE(status, 'PENDING')) IN ('PENDING', 'FOR APPROVAL', 'DRAFT')
            ")->fetchColumn() ?: 0);
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'client_requests')) {
            $workspace['summary']['pending_client_requests'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}client_requests
                WHERE status IN ('Pending', 'In Review')
            ")->fetchColumn() ?: 0);
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'budget_management')) {
            $workspace['summary']['budgets_active'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}budget_management
                WHERE status = 'Active'
            ")->fetchColumn() ?: 0);
            $workspace['submodules']['budget']['active_budgets'] = $workspace['summary']['budgets_active'];
            $workspace['submodules']['budget']['remaining_amount'] = (float) ($pdo->query("
                SELECT COALESCE(SUM(remaining_amount), 0)
                FROM {$schema}budget_management
            ")->fetchColumn() ?: 0);
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'general_ledger')) {
            $workspace['submodules']['general_ledger']['recent_entries'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}general_ledger
                WHERE " . sprintf($weekSql, 'transaction_date') . "
            ")->fetchColumn() ?: 0);
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'payroll_payment_requests')) {
            $workspace['submodules']['payroll']['pending_runs'] = (int) ($pdo->query("
                SELECT COUNT(*)
                FROM {$schema}payroll_payment_requests
                WHERE status = 'Pending'
            ")->fetchColumn() ?: 0);
        }
        if (finance_table_exists($pdo, 'payroll_runs')) {
            $latestPayroll = $pdo->query("
                SELECT approved_at, created_at
                FROM {$schema}payroll_runs
                ORDER BY id DESC
                LIMIT 1
            ")->fetch();
            if ($latestPayroll) {
                $workspace['submodules']['payroll']['latest_status'] = !empty($latestPayroll['approved_at']) ? 'Approved run' : 'Awaiting approval';
            }
        }
    } catch (Throwable) {
    }

    try {
        $workspace['pending_work'][] = ['label' => 'Pending HR Requests', 'count' => finance_count_hr_review_requests($pdo, ['status' => 'Pending']), 'href' => '/FinancialSM/financial/collection.php'];
        $workspace['pending_work'][] = ['label' => 'Pending Logistics Requests', 'count' => finance_count_logistics_review_requests($pdo, ['status' => 'Pending']), 'href' => '/FinancialSM/financial/collection.php'];
        $workspace['pending_work'][] = ['label' => 'Requests Needing Revision', 'count' => finance_count_hr_review_requests($pdo, ['status' => 'Needs Revision']) + finance_count_logistics_review_requests($pdo, ['status' => 'Needs Revision']), 'href' => '/FinancialSM/financial/collection.php'];
    } catch (Throwable) {
    }

    try {
        $workspace['pending_work'][] = ['label' => 'Pending Core Requests', 'count' => count(finance_get_job_posting_payments($pdo, 'pending')), 'href' => '/FinancialSM/financial/collection.php'];
    } catch (Throwable) {
    }

    $workspace['pending_work'][] = ['label' => 'Unposted Collections', 'count' => (int) ($workspace['submodules']['collection']['unposted'] ?? 0), 'href' => '/FinancialSM/financial/collection.php'];
    $workspace['pending_work'][] = ['label' => 'Pending Disbursements', 'count' => (int) ($workspace['submodules']['disbursement']['pending'] ?? 0), 'href' => '/FinancialSM/financial/disbursement.php'];

    try {
        finance_ensure_request_action_logs_ready($pdo);
        $requestActivities = finance_get_request_action_logs($pdo, 5);
        foreach ($requestActivities as $row) {
            $workspace['recent_activity'][] = [
                'module' => (string) ($row['module'] ?? 'REQUEST'),
                'action' => (string) ($row['action'] ?? 'UPDATE'),
                'description' => (string) ($row['description'] ?? 'Request activity'),
                'amount' => isset($row['amount']) && $row['amount'] !== null ? (float) $row['amount'] : null,
                'date_time' => (string) ($row['approved_at'] ?? ''),
            ];
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'collection')) {
            $collectionRows = $pdo->query("
                SELECT payer_name, amount, payment_date
                FROM {$schema}collection
                ORDER BY payment_date DESC, id DESC
                LIMIT 3
            ")->fetchAll() ?: [];
            foreach ($collectionRows as $row) {
                $workspace['recent_activity'][] = [
                    'module' => 'COLLECTION',
                    'action' => 'RECORDED',
                    'description' => 'New collection recorded for ' . (string) ($row['payer_name'] ?? 'payer'),
                    'amount' => isset($row['amount']) ? (float) $row['amount'] : null,
                    'date_time' => (string) ($row['payment_date'] ?? ''),
                ];
            }
        }
    } catch (Throwable) {
    }

    try {
        if (finance_table_exists($pdo, 'disbursement')) {
            $disbursementRows = $pdo->query("
                SELECT payee_name, amount, disbursement_date
                FROM {$schema}disbursement
                ORDER BY disbursement_date DESC, id DESC
                LIMIT 3
            ")->fetchAll() ?: [];
            foreach ($disbursementRows as $row) {
                $workspace['recent_activity'][] = [
                    'module' => 'DISBURSEMENT',
                    'action' => 'CREATED',
                    'description' => 'Disbursement created for ' . (string) ($row['payee_name'] ?? 'payee'),
                    'amount' => isset($row['amount']) ? (float) $row['amount'] : null,
                    'date_time' => (string) ($row['disbursement_date'] ?? ''),
                ];
            }
        }
    } catch (Throwable) {
    }

    usort($workspace['recent_activity'], static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['date_time'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['date_time'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });
    $workspace['recent_activity'] = array_slice($workspace['recent_activity'], 0, 8);

    return $workspace;
}

function verifyLedgerBalance(PDO $pdo): array
{
    if (!finance_table_exists($pdo, 'general_ledger')) {
        return [
            'is_balanced' => true,
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'difference' => 0.0,
            'warning' => '',
        ];
    }

    $stmt = $pdo->query("
        SELECT
            COALESCE(SUM(debit), 0) AS total_debit,
            COALESCE(SUM(credit), 0) AS total_credit
        FROM public.general_ledger
    ");
    $row = $stmt->fetch() ?: ['total_debit' => 0, 'total_credit' => 0];

    $totalDebit = round((float) ($row['total_debit'] ?? 0), 2);
    $totalCredit = round((float) ($row['total_credit'] ?? 0), 2);
    $difference = round($totalDebit - $totalCredit, 2);
    $isBalanced = abs($difference) < 0.01;

    return [
        'is_balanced' => $isBalanced,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
        'difference' => $difference,
        'warning' => $isBalanced ? '' : 'Ledger imbalance detected',
    ];
}

function getAccountSummary(PDO $pdo, string $accountTitle): array
{
    $stmt = $pdo->prepare("
        SELECT
            account_title,
            COALESCE(SUM(debit), 0) AS total_debit,
            COALESCE(SUM(credit), 0) AS total_credit,
            COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS ending_balance,
            COUNT(*) AS line_count
        FROM public.general_ledger
        WHERE account_title = :account_title
        GROUP BY account_title
    ");
    $stmt->execute([':account_title' => $accountTitle]);
    $row = $stmt->fetch();

    return $row ?: [
        'account_title' => $accountTitle,
        'total_debit' => 0,
        'total_credit' => 0,
        'ending_balance' => 0,
        'line_count' => 0,
    ];
}

function deleteJournalByReference(PDO $pdo, string $referenceNo): void
{
    $stmt = $pdo->prepare('DELETE FROM public.general_ledger WHERE reference_no = :reference_no');
    $stmt->execute([':reference_no' => $referenceNo]);
}

function replaceJournalByReference(PDO $pdo, string $referenceNo, array $header, array $lines): string
{
    $pdo->beginTransaction();
    try {
        deleteJournalByReference($pdo, $referenceNo);
        $header['reference_no'] = $referenceNo;
        $saved = createJournalEntry($pdo, $header, $lines);
        $pdo->commit();
        return $saved;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function convertProcurementToAP(PDO $pdo, int $procurementId): int
{
    if (!finance_table_exists($pdo, 'procurement_requests')) {
        throw new RuntimeException('Procurement module is not available.');
    }

    $stmt = $pdo->prepare("SELECT * FROM public.procurement_requests WHERE id = :id");
    $stmt->execute([':id' => $procurementId]);
    $proc = $stmt->fetch();

    if (!$proc) {
        throw new RuntimeException('Procurement request not found.');
    }

    if ($proc['status'] !== 'Pending') {
        throw new RuntimeException('Procurement request is already processed.');
    }

    $pdo->beginTransaction();
    try {
        $apId = recordARAP($pdo, [
            'entry_type' => 'AP',
            'party_name' => $proc['supplier_name'],
            'reference_no' => $proc['request_no'],
            'description' => $proc['item_description'],
            'amount' => $proc['total_amount'],
            'due_date' => date('Y-m-d', strtotime('+30 days')), // Default 30 days
        ]);

        $update = $pdo->prepare("
            UPDATE public.procurement_requests
            SET status = 'Processed',
                related_ap_id = :ap_id,
                updated_at = NOW()
            WHERE id = :id
        ");
        $update->execute([
            ':id' => $procurementId,
            ':ap_id' => $apId,
        ]);

        finance_log_audit($pdo, 'Converted Procurement to AP', 'Procurement', $procurementId, null, ['ap_id' => $apId]);

        $pdo->commit();
        return $apId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function finance_client_requests_table_exists(PDO $pdo): bool
{
    return finance_table_exists($pdo, 'client_requests');
}

function generateClientRequestNo(PDO $pdo): string
{
    return finance_next_reference($pdo, 'client_requests', 'REQ');
}

function validate_client_request_payload(array $data): array
{
    $payload = [
        'request_no' => trim((string) ($data['request_no'] ?? '')),
        'requester_name' => trim((string) ($data['requester_name'] ?? '')),
        'department' => trim((string) ($data['department'] ?? '')),
        'request_type' => trim((string) ($data['request_type'] ?? '')),
        'description' => trim((string) ($data['description'] ?? '')),
        'amount' => round((float) ($data['amount'] ?? 0), 2),
        'request_date' => trim((string) ($data['request_date'] ?? '')),
        'due_date' => trim((string) ($data['due_date'] ?? '')),
        'status' => trim((string) ($data['status'] ?? 'Pending')),
        'remarks' => trim((string) ($data['remarks'] ?? '')),
        'linked_module' => trim((string) ($data['linked_module'] ?? '')),
        'linked_record_id' => (int) ($data['linked_record_id'] ?? 0),
    ];

    $allowedTypes = ['Collection', 'AR', 'AP', 'Disbursement', 'Budget'];
    $allowedStatuses = ['Pending', 'Approved', 'Rejected', 'Processed'];

    if ($payload['requester_name'] === '') {
        throw new InvalidArgumentException('Requester name is required.');
    }
    if (!in_array($payload['request_type'], $allowedTypes, true)) {
        throw new InvalidArgumentException('Request type is invalid.');
    }
    if ($payload['description'] === '') {
        throw new InvalidArgumentException('Description is required.');
    }
    if ($payload['amount'] < 0) {
        throw new InvalidArgumentException('Amount cannot be negative.');
    }
    if (!finance_is_valid_date($payload['request_date'])) {
        throw new InvalidArgumentException('Request date is invalid.');
    }
    if ($payload['due_date'] !== '' && !finance_is_valid_date($payload['due_date'])) {
        throw new InvalidArgumentException('Due date is invalid.');
    }
    if ($payload['due_date'] !== '' && $payload['due_date'] < $payload['request_date']) {
        throw new InvalidArgumentException('Due date cannot be earlier than request date.');
    }
    if (!in_array($payload['status'], $allowedStatuses, true)) {
        $payload['status'] = 'Pending';
    }
    if ($payload['status'] !== 'Processed') {
        $payload['linked_module'] = '';
        $payload['linked_record_id'] = 0;
    }

    return $payload;
}

function createClientRequest(PDO $pdo, array $data): int
{
    finance_bootstrap($pdo);
    $payload = validate_client_request_payload($data);
    $payload['request_no'] = $payload['request_no'] !== '' ? $payload['request_no'] : generateClientRequestNo($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO public.client_requests (
            request_no,
            requester_name,
            department,
            request_type,
            description,
            amount,
            request_date,
            due_date,
            status,
            remarks,
            linked_module,
            linked_record_id
        ) VALUES (
            :request_no,
            :requester_name,
            :department,
            :request_type,
            :description,
            :amount,
            :request_date,
            :due_date,
            :status,
            :remarks,
            :linked_module,
            :linked_record_id
        )
    ");
    $stmt->execute([
        ':request_no' => $payload['request_no'],
        ':requester_name' => $payload['requester_name'],
        ':department' => $payload['department'] !== '' ? $payload['department'] : null,
        ':request_type' => $payload['request_type'],
        ':description' => $payload['description'],
        ':amount' => $payload['amount'],
        ':request_date' => $payload['request_date'],
        ':due_date' => $payload['due_date'] !== '' ? $payload['due_date'] : null,
        ':status' => $payload['status'],
        ':remarks' => $payload['remarks'] !== '' ? $payload['remarks'] : null,
        ':linked_module' => $payload['linked_module'] !== '' ? $payload['linked_module'] : null,
        ':linked_record_id' => $payload['linked_record_id'] > 0 ? $payload['linked_record_id'] : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function getClientRequests(PDO $pdo, array $filters = []): array
{
    if (!finance_client_requests_table_exists($pdo)) {
        return [];
    }

    $sql = "
        SELECT *
        FROM public.client_requests
        WHERE 1 = 1
    ";
    $params = [];

    if (!empty($filters['status'])) {
        $sql .= ' AND status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['request_type'])) {
        $sql .= ' AND request_type = :request_type';
        $params[':request_type'] = $filters['request_type'];
    }
    if (!empty($filters['department'])) {
        $sql .= ' AND department = :department';
        $params[':department'] = $filters['department'];
    }
    if (!empty($filters['requester_name'])) {
        $sql .= ' AND requester_name ILIKE :requester_name';
        $params[':requester_name'] = '%' . trim((string) $filters['requester_name']) . '%';
    }
    if (!empty($filters['request_date'])) {
        $sql .= ' AND request_date = :request_date';
        $params[':request_date'] = $filters['request_date'];
    }

    $sql .= ' ORDER BY request_date DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function listClientRequests(PDO $pdo): array
{
    return getClientRequests($pdo);
}

function getClientRequestById(PDO $pdo, int $id): ?array
{
    if (!finance_client_requests_table_exists($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM public.client_requests WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function updateClientRequest(PDO $pdo, int $id, array $data): void
{
    $existing = getClientRequestById($pdo, $id);
    if (!$existing) {
        throw new RuntimeException('Client request not found.');
    }

    $payload = validate_client_request_payload($data);
    $payload['request_no'] = $payload['request_no'] !== '' ? $payload['request_no'] : (string) $existing['request_no'];

    $stmt = $pdo->prepare("
        UPDATE public.client_requests
        SET request_no = :request_no,
            requester_name = :requester_name,
            department = :department,
            request_type = :request_type,
            description = :description,
            amount = :amount,
            request_date = :request_date,
            due_date = :due_date,
            status = :status,
            remarks = :remarks,
            linked_module = :linked_module,
            linked_record_id = :linked_record_id,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':request_no' => $payload['request_no'],
        ':requester_name' => $payload['requester_name'],
        ':department' => $payload['department'] !== '' ? $payload['department'] : null,
        ':request_type' => $payload['request_type'],
        ':description' => $payload['description'],
        ':amount' => $payload['amount'],
        ':request_date' => $payload['request_date'],
        ':due_date' => $payload['due_date'] !== '' ? $payload['due_date'] : null,
        ':status' => $payload['status'],
        ':remarks' => $payload['remarks'] !== '' ? $payload['remarks'] : null,
        ':linked_module' => $payload['linked_module'] !== '' ? $payload['linked_module'] : null,
        ':linked_record_id' => $payload['linked_record_id'] > 0 ? $payload['linked_record_id'] : null,
    ]);
}

function approveClientRequest(PDO $pdo, int $id): void
{
    $request = getClientRequestById($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Client request not found.');
    }
    if (strcasecmp((string) ($request['status'] ?? 'Pending'), 'Pending') !== 0) {
        throw new RuntimeException('Only pending client requests can be approved.');
    }

    $stmt = $pdo->prepare("
        UPDATE public.client_requests
        SET status = 'Approved',
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);

    // Mirror to Supabase
    supabase_mirror_safe('client_requests', [
        'id' => $id,
        'status' => 'Approved',
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    // Log approval in audit_logs
    supabase_mirror_safe('audit_logs', [
        'table_name' => 'client_requests',
        'record_id' => $id,
        'action' => 'APPROVE',
        'old_values' => json_encode(['status' => 'Pending']),
        'new_values' => json_encode(['status' => 'Approved']),
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('c'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    if ($request) {
        $resolvedAmount = isset($request['amount']) ? (float) $request['amount'] : null;
        if ($resolvedAmount !== null && $resolvedAmount > 0) {
            finance_create_request_collection_if_missing(
                $pdo,
                'CLIENT',
                $id,
                (string) ($request['client_name'] ?? $request['description'] ?? 'Client Request'),
                $resolvedAmount,
                'Approved client request: ' . (string) ($request['description'] ?? ('Client Request #' . $id)),
                'CLIENT-' . $id
            );
        }

        finance_record_approved_request_log(
            $pdo,
            'APPROVE',
            'CLIENT',
            'client_requests',
            $id,
            (string) ($request['description'] ?? 'Client request approved'),
            $resolvedAmount,
            (string) ($request['remarks'] ?? '')
        );
    }
}

function rejectClientRequest(PDO $pdo, int $id, string $remarks = ''): void
{
    $request = getClientRequestById($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Client request not found.');
    }
    if (strcasecmp((string) ($request['status'] ?? 'Pending'), 'Pending') !== 0) {
        throw new RuntimeException('Only pending client requests can be rejected.');
    }
    $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), $remarks);

    $stmt = $pdo->prepare("
        UPDATE public.client_requests
        SET status = 'Rejected',
            remarks = :remarks,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':remarks' => $mergedRemarks,
    ]);

    // Mirror to Supabase
    supabase_mirror_safe('client_requests', [
        'id' => $id,
        'status' => 'Rejected',
        'remarks' => $mergedRemarks,
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    // Log rejection in audit_logs
    supabase_mirror_safe('audit_logs', [
        'table_name' => 'client_requests',
        'record_id' => $id,
        'action' => 'REJECT',
        'old_values' => json_encode(['status' => 'Pending']),
        'new_values' => json_encode(['status' => 'Rejected', 'remarks' => $mergedRemarks]),
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('c'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    if ($request) {
        finance_record_approved_request_log(
            $pdo,
            'REJECT',
            'CLIENT',
            'client_requests',
            $id,
            (string) ($request['description'] ?? 'Client request rejected'),
            isset($request['amount']) ? (float) $request['amount'] : null,
            $mergedRemarks ?? ''
        );
    }
}

function markClientRequestProcessed(PDO $pdo, int $id, string $linkedModule, int $linkedRecordId): void
{
    $linkedModule = trim($linkedModule);
    if ($linkedModule === '' || $linkedRecordId <= 0) {
        throw new InvalidArgumentException('Linked module and linked record ID are required.');
    }

    $stmt = $pdo->prepare("
        UPDATE public.client_requests
        SET status = 'Processed',
            linked_module = :linked_module,
            linked_record_id = :linked_record_id,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':linked_module' => $linkedModule,
        ':linked_record_id' => $linkedRecordId,
    ]);
}

/**
 * Approve HR Request with Supabase mirroring
 */
function approveHRRequest(PDO $pdo, string $id, ?float $amount = null): void
{
    $id = trim($id);
    $request = finance_get_hr_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('HR request not found.');
    }
    if (finance_hr_status_label((string) ($request['status'] ?? 'Pending')) !== 'Pending') {
        throw new RuntimeException('Only pending HR requests can be approved.');
    }
    if ($request && (($request['source'] ?? '') === 'supabase')) {
        $client = supabase_init();
        if (!$client) {
            throw new RuntimeException('Supabase HR connection is not available.');
        }

        $res = $client->update(
            finance_hr_supabase_table(),
            finance_hr_supabase_update_payload($request, 'approve'),
            'id=eq.' . rawurlencode($id)
        );

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Failed to update Supabase HR request.');
        }

        supabase_mirror_safe('audit_logs', [
            'table_name' => finance_hr_supabase_table(),
            'record_id' => $id,
            'action' => 'APPROVE',
            'old_values' => json_encode(['status' => 'pending']),
            'new_values' => json_encode(['status' => 'approved']),
            'user_id' => $_SESSION['user_id'] ?? null,
            'timestamp' => date('c'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $resolvedAmount = $amount;
        if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
            $resolvedAmount = (float) $request['amount'];
        }

        finance_record_request_history(
            $pdo,
            'HR',
            $id,
            finance_hr_request_code($id, (string) ($request['request_code'] ?? '')),
            'APPROVE',
            'Approved',
            isset($request['remarks']) ? trim((string) $request['remarks']) : null
        );

        finance_record_approved_request_log(
            $pdo,
            'APPROVE',
            'HR',
            finance_hr_supabase_table(),
            $id,
            (string) ($request['request_title'] ?? $request['request_details'] ?? 'HR request approved'),
            $resolvedAmount,
            isset($request['remarks']) ? trim((string) $request['remarks']) : null
        );

        return;
    }

    $schema = finance_schema_prefix($pdo);
    $selectColumns = ['request_details'];
    if (finance_column_exists($pdo, 'hr_requests', 'request_code')) {
        $selectColumns[] = 'request_code';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'title')) {
        $selectColumns[] = 'title';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'description')) {
        $selectColumns[] = 'description';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'amount')) {
        $selectColumns[] = 'amount';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'remarks')) {
        $selectColumns[] = 'remarks';
    }

    $detailsStmt = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . " FROM {$schema}hr_requests WHERE id = :id LIMIT 1");
    $detailsStmt->execute([':id' => $id]);
    $request = $detailsStmt->fetch() ?: null;

    $stmt = $pdo->prepare("
        UPDATE {$schema}hr_requests
        SET status = 'approved',
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);

    // Mirror to Supabase
    supabase_mirror_safe('hr_requests', [
        'id' => $id,
        'status' => 'approved',
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    // Log approval in audit_logs
    supabase_mirror_safe('audit_logs', [
        'table_name' => 'hr_requests',
        'record_id' => $id,
        'action' => 'APPROVE',
        'old_values' => json_encode(['status' => 'pending']),
        'new_values' => json_encode(['status' => 'approved']),
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('c'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $resolvedAmount = $amount;
    if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
        $resolvedAmount = (float) $request['amount'];
    }

    finance_record_request_history(
        $pdo,
        'HR',
        $id,
        finance_hr_request_code($id, (string) ($request['request_code'] ?? '')),
        'APPROVE',
        'Approved',
        isset($request['remarks']) ? trim((string) $request['remarks']) : null
    );

    finance_record_approved_request_log(
        $pdo,
        'APPROVE',
        'HR',
        'hr_requests',
        $id,
        (string) ($request['title'] ?? $request['request_details'] ?? 'HR request approved'),
        $resolvedAmount,
        isset($request['remarks']) ? trim((string) $request['remarks']) : null
    );
}

/**
 * Reject HR Request with Supabase mirroring
 */
function rejectHRRequest(PDO $pdo, string $id, string $remarks = '', ?float $amount = null): void
{
    $id = trim($id);
    $request = finance_get_hr_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('HR request not found.');
    }
    if (finance_hr_status_label((string) ($request['status'] ?? 'Pending')) !== 'Pending') {
        throw new RuntimeException('Only pending HR requests can be rejected.');
    }
    if ($request && (($request['source'] ?? '') === 'supabase')) {
        $client = supabase_init();
        if (!$client) {
            throw new RuntimeException('Supabase HR connection is not available.');
        }

        $res = $client->update(
            finance_hr_supabase_table(),
            finance_hr_supabase_update_payload($request, 'reject', $remarks),
            'id=eq.' . rawurlencode($id)
        );

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Failed to update Supabase HR request.');
        }

        supabase_mirror_safe('audit_logs', [
            'table_name' => finance_hr_supabase_table(),
            'record_id' => $id,
            'action' => 'REJECT',
            'old_values' => json_encode(['status' => 'pending']),
            'new_values' => json_encode(['status' => 'rejected', 'remarks' => trim($remarks)]),
            'user_id' => $_SESSION['user_id'] ?? null,
            'timestamp' => date('c'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $resolvedAmount = $amount;
        if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
            $resolvedAmount = (float) $request['amount'];
        }

        finance_record_request_history(
            $pdo,
            'HR',
            $id,
            finance_hr_request_code($id, (string) ($request['request_code'] ?? '')),
            'REJECT',
            'Rejected',
            trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null)
        );

        finance_record_approved_request_log(
            $pdo,
            'REJECT',
            'HR',
            finance_hr_supabase_table(),
            $id,
            (string) ($request['request_title'] ?? $request['request_details'] ?? 'HR request rejected'),
            $resolvedAmount,
            trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null)
        );

        return;
    }

    $schema = finance_schema_prefix($pdo);
    $selectColumns = ['request_details'];
    if (finance_column_exists($pdo, 'hr_requests', 'request_code')) {
        $selectColumns[] = 'request_code';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'title')) {
        $selectColumns[] = 'title';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'description')) {
        $selectColumns[] = 'description';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'amount')) {
        $selectColumns[] = 'amount';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'remarks')) {
        $selectColumns[] = 'remarks';
    }

    $detailsStmt = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . " FROM {$schema}hr_requests WHERE id = :id LIMIT 1");
    $detailsStmt->execute([':id' => $id]);
    $request = $detailsStmt->fetch() ?: null;

        $hasRemarksColumn = finance_column_exists($pdo, 'hr_requests', 'remarks');
        if ($hasRemarksColumn) {
            $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), $remarks);
            $stmt = $pdo->prepare("
        UPDATE {$schema}hr_requests
        SET status = 'rejected',
            remarks = :remarks,
            updated_at = NOW()
        WHERE id = :id
    ");
            $stmt->execute([
                ':id' => $id,
                ':remarks' => $mergedRemarks,
            ]);
        } else {
        $stmt = $pdo->prepare("
            UPDATE {$schema}hr_requests
            SET status = 'rejected',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    // Mirror to Supabase
    supabase_mirror_safe('hr_requests', [
        'id' => $id,
        'status' => 'rejected',
        'remarks' => isset($mergedRemarks) ? $mergedRemarks : trim($remarks),
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    // Log rejection in audit_logs
    supabase_mirror_safe('audit_logs', [
        'table_name' => 'hr_requests',
        'record_id' => $id,
        'action' => 'REJECT',
        'old_values' => json_encode(['status' => 'pending']),
        'new_values' => json_encode(['status' => 'rejected', 'remarks' => isset($mergedRemarks) ? $mergedRemarks : trim($remarks)]),
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('c'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $resolvedAmount = $amount;
    if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
        $resolvedAmount = (float) $request['amount'];
    }

    finance_record_request_history(
        $pdo,
        'HR',
        $id,
        finance_hr_request_code($id, (string) ($request['request_code'] ?? '')),
        'REJECT',
        'Rejected',
        trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null)
    );

    finance_record_approved_request_log(
        $pdo,
        'REJECT',
        'HR',
        'hr_requests',
        $id,
        (string) ($request['title'] ?? $request['request_details'] ?? 'HR request rejected'),
        $resolvedAmount,
        trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null)
    );
}

function requestHRRevision(PDO $pdo, string $id, string $remarks = ''): void
{
    $id = trim($id);
    $remarks = trim($remarks);
    if ($id === '') {
        throw new InvalidArgumentException('HR request ID is required.');
    }
    if ($remarks === '') {
        throw new InvalidArgumentException('Revision remarks are required.');
    }

    $request = finance_get_hr_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('HR request not found.');
    }
    if (finance_hr_status_label((string) ($request['status'] ?? 'Pending')) !== 'Pending') {
        throw new RuntimeException('Only pending HR requests can be returned for revision.');
    }
    if ($request && (($request['source'] ?? '') === 'supabase')) {
        $client = supabase_init();
        if (!$client) {
            throw new RuntimeException('Supabase HR connection is not available.');
        }

        $res = $client->update(
            finance_hr_supabase_table(),
            finance_hr_supabase_update_payload($request, 'revision', $remarks),
            'id=eq.' . rawurlencode($id)
        );

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Failed to update Supabase HR request.');
        }

        supabase_mirror_safe('audit_logs', [
            'table_name' => finance_hr_supabase_table(),
            'record_id' => $id,
            'action' => 'REVISION',
            'old_values' => json_encode(['status' => (string) ($request['status'] ?? 'pending')]),
            'new_values' => json_encode(['status' => 'needs revision', 'remarks' => $remarks]),
            'user_id' => $_SESSION['user_id'] ?? null,
            'timestamp' => date('c'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        finance_record_request_history(
            $pdo,
            'HR',
            $id,
            finance_hr_request_code($id, (string) ($request['request_code'] ?? '')),
            'REVISION',
            'Needs Revision',
            $remarks
        );

        finance_record_approved_request_log(
            $pdo,
            'REVISION',
            'HR',
            finance_hr_supabase_table(),
            $id,
            (string) ($request['request_title'] ?? $request['request_details'] ?? 'HR request revision requested'),
            isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '' ? (float) $request['amount'] : null,
            $remarks
        );

        return;
    }

    $schema = finance_schema_prefix($pdo);
    $selectColumns = ['request_details'];
    if (finance_column_exists($pdo, 'hr_requests', 'request_code')) {
        $selectColumns[] = 'request_code';
    }
    if (finance_column_exists($pdo, 'hr_requests', 'remarks')) {
        $selectColumns[] = 'remarks';
    }

    $detailsStmt = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . " FROM {$schema}hr_requests WHERE id = :id LIMIT 1");
    $detailsStmt->execute([':id' => $id]);
    $request = $detailsStmt->fetch() ?: null;

    $hasRemarksColumn = finance_column_exists($pdo, 'hr_requests', 'remarks');
    if ($hasRemarksColumn) {
        $existingRemarks = trim((string) ($request['remarks'] ?? ''));
        $combinedRemarks = $existingRemarks !== '' ? $existingRemarks . ' | ' . $remarks : $remarks;
        $stmt = $pdo->prepare("
            UPDATE {$schema}hr_requests
            SET status = 'needs revision',
                remarks = :remarks,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':remarks' => $combinedRemarks,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE {$schema}hr_requests
            SET status = 'needs revision',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    supabase_mirror_safe('hr_requests', [
        'id' => $id,
        'status' => 'needs revision',
        'remarks' => $remarks,
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    supabase_mirror_safe('audit_logs', [
        'table_name' => 'hr_requests',
        'record_id' => $id,
        'action' => 'REVISION',
        'old_values' => json_encode(['status' => (string) ($request['status'] ?? 'pending')]),
        'new_values' => json_encode(['status' => 'needs revision', 'remarks' => $remarks]),
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('c'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    finance_record_request_history(
        $pdo,
        'HR',
        $id,
        finance_hr_request_code($id, (string) ($request['request_code'] ?? '')),
        'REVISION',
        'Needs Revision',
        $remarks
    );

    finance_record_approved_request_log(
        $pdo,
        'REVISION',
        'HR',
        'hr_requests',
        $id,
        (string) ($request['request_details'] ?? 'HR request revision requested'),
        null,
        $remarks
    );
}

/**
 * Approve Logistics Request with Supabase mirroring
 */
function approveLogisticsRequest(PDO $pdo, int $id, ?float $amount = null): void
{
    $request = finance_get_logistics_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Logistics request not found.');
    }
    if (finance_logistics_is_document_tracking_row($request)) {
        throw new RuntimeException('Logistics2 document tracking records are reviewed in General Ledger, not through the Logistics approval queue.');
    }
    if (!finance_logistics_is_actionable($request)) {
        throw new RuntimeException('This logistics request is no longer available for approval.');
    }
    if ($request && (($request['source'] ?? '') === 'supabase')) {
        $client = supabase_init();
        if (!$client) {
            throw new RuntimeException('Supabase logistics connection is not available.');
        }

        $sourceTable = trim((string) ($request['source_table'] ?? ''));
        if ($sourceTable === '') {
            $sourceTable = finance_logistics_supabase_table();
        }

        $res = $client->update(
            $sourceTable,
            finance_logistics_supabase_update_payload($request, 'approve'),
            'id=eq.' . rawurlencode((string) $id)
        );

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Failed to update Supabase logistics request.');
        }

        $linkedInvoiceId = (int) ($request['linked_invoice_id'] ?? 0);
        if ($linkedInvoiceId > 0) {
            $linkedInvoiceRes = $client->update(
                finance_logistics_supabase_invoice_table(),
                finance_logistics_supabase_update_payload([
                    'source_table' => finance_logistics_supabase_invoice_table(),
                    '_raw' => isset($request['_linked_invoice']) && is_array($request['_linked_invoice']) ? $request['_linked_invoice'] : [],
                ], 'approve'),
                'id=eq.' . rawurlencode((string) $linkedInvoiceId)
            );
            if (($linkedInvoiceRes['status'] ?? 0) < 200 || ($linkedInvoiceRes['status'] ?? 0) >= 300) {
                throw new RuntimeException('Failed to update linked Supabase logistics invoice.');
            }
        }

        $resolvedAmount = $amount;
        if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
            $resolvedAmount = (float) $request['amount'];
        }

        finance_record_request_history(
            $pdo,
            'LOGISTICS',
            (string) $id,
            finance_logistics_request_code($id, (string) ($request['request_code'] ?? '')),
            'APPROVE',
            'Approved',
            isset($request['remarks']) ? trim((string) $request['remarks']) : null
        );

        finance_record_approved_request_log(
            $pdo,
            'APPROVE',
            'LOGISTICS',
            $sourceTable,
            (string) $id,
            (string) ($request['request_description'] ?? $request['item_name'] ?? 'Logistics request approved'),
            $resolvedAmount,
            isset($request['remarks']) ? trim((string) $request['remarks']) : null
        );

        return;
    }

    $schema = finance_schema_prefix($pdo);
    $selectColumns = ['item_name'];
    if (finance_column_exists($pdo, 'logistic_requests', 'request_code')) {
        $selectColumns[] = 'request_code';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'title')) {
        $selectColumns[] = 'title';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'description')) {
        $selectColumns[] = 'description';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'amount')) {
        $selectColumns[] = 'amount';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'remarks')) {
        $selectColumns[] = 'remarks';
    }

    $detailsStmt = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . " FROM {$schema}logistic_requests WHERE id = :id LIMIT 1");
    $detailsStmt->execute([':id' => $id]);
    $request = $detailsStmt->fetch() ?: null;

    $stmt = $pdo->prepare("
        UPDATE {$schema}logistic_requests
        SET status = 'approved',
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);

    // Mirror to Supabase
    supabase_mirror_safe('logistic_requests', [
        'id' => $id,
        'status' => 'approved',
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    $resolvedAmount = $amount;
    if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
        $resolvedAmount = (float) $request['amount'];
    }

    finance_record_request_history(
        $pdo,
        'LOGISTICS',
        $id,
        finance_logistics_request_code($id, (string) ($request['request_code'] ?? '')),
        'APPROVE',
        'Approved',
        isset($request['remarks']) ? trim((string) $request['remarks']) : null
    );

    finance_record_approved_request_log(
        $pdo,
        'APPROVE',
        'LOGISTICS',
        'logistic_requests',
        $id,
        (string) ($request['item_name'] ?? 'Logistics request approved'),
        $resolvedAmount,
        isset($request['remarks']) ? trim((string) $request['remarks']) : null
    );
}

/**
 * Reject Logistics Request with Supabase mirroring
 */
function rejectLogisticsRequest(PDO $pdo, int $id, string $remarks = '', ?float $amount = null): void
{
    $request = finance_get_logistics_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Logistics request not found.');
    }
    if (finance_logistics_is_document_tracking_row($request)) {
        throw new RuntimeException('Logistics2 document tracking records are reviewed in General Ledger, not through the Logistics approval queue.');
    }
    if (!finance_logistics_is_actionable($request)) {
        throw new RuntimeException('This logistics request is no longer available for rejection.');
    }
    if ($request && (($request['source'] ?? '') === 'supabase')) {
        $client = supabase_init();
        if (!$client) {
            throw new RuntimeException('Supabase logistics connection is not available.');
        }

        $sourceTable = trim((string) ($request['source_table'] ?? ''));
        if ($sourceTable === '') {
            $sourceTable = finance_logistics_supabase_table();
        }

        $res = $client->update(
            $sourceTable,
            finance_logistics_supabase_update_payload($request, 'reject', $remarks),
            'id=eq.' . rawurlencode((string) $id)
        );

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Failed to update Supabase logistics request.');
        }

        $linkedInvoiceId = (int) ($request['linked_invoice_id'] ?? 0);
        if ($linkedInvoiceId > 0) {
            $linkedInvoiceRes = $client->update(
                finance_logistics_supabase_invoice_table(),
                finance_logistics_supabase_update_payload([
                    'source_table' => finance_logistics_supabase_invoice_table(),
                    '_raw' => isset($request['_linked_invoice']) && is_array($request['_linked_invoice']) ? $request['_linked_invoice'] : [],
                ], 'reject', $remarks),
                'id=eq.' . rawurlencode((string) $linkedInvoiceId)
            );
            if (($linkedInvoiceRes['status'] ?? 0) < 200 || ($linkedInvoiceRes['status'] ?? 0) >= 300) {
                throw new RuntimeException('Failed to update linked Supabase logistics invoice.');
            }
        }

        $resolvedAmount = $amount;
        if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
            $resolvedAmount = (float) $request['amount'];
        }

        finance_record_request_history(
            $pdo,
            'LOGISTICS',
            (string) $id,
            finance_logistics_request_code($id, (string) ($request['request_code'] ?? '')),
            'REJECT',
            'Rejected',
            trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null)
        );

        finance_record_approved_request_log(
            $pdo,
            'REJECT',
            'LOGISTICS',
            $sourceTable,
            (string) $id,
            (string) ($request['request_description'] ?? $request['item_name'] ?? 'Logistics request rejected'),
            $resolvedAmount,
            trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null)
        );

        return;
    }

    $schema = finance_schema_prefix($pdo);
    $selectColumns = ['item_name'];
    if (finance_column_exists($pdo, 'logistic_requests', 'request_code')) {
        $selectColumns[] = 'request_code';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'title')) {
        $selectColumns[] = 'title';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'description')) {
        $selectColumns[] = 'description';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'amount')) {
        $selectColumns[] = 'amount';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'remarks')) {
        $selectColumns[] = 'remarks';
    }

    $detailsStmt = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . " FROM {$schema}logistic_requests WHERE id = :id LIMIT 1");
    $detailsStmt->execute([':id' => $id]);
    $request = $detailsStmt->fetch() ?: null;

    $hasRemarksColumn = finance_column_exists($pdo, 'logistic_requests', 'remarks');
    if ($hasRemarksColumn) {
        $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), $remarks);
        $stmt = $pdo->prepare("
        UPDATE {$schema}logistic_requests
        SET status = 'rejected',
            remarks = :remarks,
            updated_at = NOW()
        WHERE id = :id
    ");
        $stmt->execute([
            ':id' => $id,
            ':remarks' => $mergedRemarks,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE {$schema}logistic_requests
            SET status = 'rejected',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    // Mirror to Supabase
    supabase_mirror_safe('logistic_requests', [
        'id' => $id,
        'status' => 'rejected',
        'remarks' => isset($mergedRemarks) ? $mergedRemarks : trim($remarks),
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    $resolvedAmount = $amount;
    if ($resolvedAmount === null && isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '') {
        $resolvedAmount = (float) $request['amount'];
    }

    finance_record_request_history(
        $pdo,
        'LOGISTICS',
        $id,
        finance_logistics_request_code($id, (string) ($request['request_code'] ?? '')),
        'REJECT',
        'Rejected',
        isset($mergedRemarks) ? $mergedRemarks : (trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null))
    );

    finance_record_approved_request_log(
        $pdo,
        'REJECT',
        'LOGISTICS',
        'logistic_requests',
        $id,
        (string) ($request['item_name'] ?? 'Logistics request rejected'),
        $resolvedAmount,
        isset($mergedRemarks) ? $mergedRemarks : (trim($remarks) !== '' ? trim($remarks) : (isset($request['remarks']) ? trim((string) $request['remarks']) : null))
    );
}

function requestLogisticsRevision(PDO $pdo, int $id, string $remarks = '', ?float $amount = null): void
{
    $remarks = trim($remarks);
    if ($id <= 0) {
        throw new InvalidArgumentException('Logistics request ID is required.');
    }
    if ($remarks === '') {
        throw new InvalidArgumentException('Revision remarks are required.');
    }

    $request = finance_get_logistics_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Logistics request not found.');
    }
    if (!finance_logistics_is_actionable($request)) {
        throw new RuntimeException('This logistics request is no longer available for revision.');
    }
    if ($request && (($request['source'] ?? '') === 'supabase')) {
        $client = supabase_init();
        if (!$client) {
            throw new RuntimeException('Supabase logistics connection is not available.');
        }

        $sourceTable = trim((string) ($request['source_table'] ?? ''));
        if ($sourceTable === '') {
            $sourceTable = finance_logistics_supabase_table();
        }

        $res = $client->update(
            $sourceTable,
            finance_logistics_supabase_update_payload($request, 'revision', $remarks),
            'id=eq.' . rawurlencode((string) $id)
        );

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Failed to update Supabase logistics request.');
        }

        $linkedInvoiceId = (int) ($request['linked_invoice_id'] ?? 0);
        if ($linkedInvoiceId > 0) {
            $linkedInvoiceRes = $client->update(
                finance_logistics_supabase_invoice_table(),
                finance_logistics_supabase_update_payload([
                    'source_table' => finance_logistics_supabase_invoice_table(),
                    '_raw' => isset($request['_linked_invoice']) && is_array($request['_linked_invoice']) ? $request['_linked_invoice'] : [],
                ], 'revision', $remarks),
                'id=eq.' . rawurlencode((string) $linkedInvoiceId)
            );
            if (($linkedInvoiceRes['status'] ?? 0) < 200 || ($linkedInvoiceRes['status'] ?? 0) >= 300) {
                throw new RuntimeException('Failed to update linked Supabase logistics invoice.');
            }
        }

        finance_record_request_history(
            $pdo,
            'LOGISTICS',
            (string) $id,
            finance_logistics_request_code($id, (string) ($request['request_code'] ?? '')),
            'REVISION',
            'Needs Revision',
            $remarks
        );

        finance_record_approved_request_log(
            $pdo,
            'REVISION',
            'LOGISTICS',
            $sourceTable,
            (string) $id,
            (string) ($request['request_description'] ?? $request['item_name'] ?? 'Logistics request revision requested'),
            isset($request['amount']) && $request['amount'] !== null && $request['amount'] !== '' ? (float) $request['amount'] : null,
            $remarks
        );

        return;
    }

    $schema = finance_schema_prefix($pdo);
    $selectColumns = ['item_name'];
    if (finance_column_exists($pdo, 'logistic_requests', 'request_code')) {
        $selectColumns[] = 'request_code';
    }
    if (finance_column_exists($pdo, 'logistic_requests', 'remarks')) {
        $selectColumns[] = 'remarks';
    }

    $detailsStmt = $pdo->prepare("SELECT " . implode(', ', $selectColumns) . " FROM {$schema}logistic_requests WHERE id = :id LIMIT 1");
    $detailsStmt->execute([':id' => $id]);
    $request = $detailsStmt->fetch() ?: null;

    $hasRemarksColumn = finance_column_exists($pdo, 'logistic_requests', 'remarks');
    if ($hasRemarksColumn) {
        $existingRemarks = trim((string) ($request['remarks'] ?? ''));
        $combinedRemarks = $existingRemarks !== '' ? $existingRemarks . ' | ' . $remarks : $remarks;
        $stmt = $pdo->prepare("
            UPDATE {$schema}logistic_requests
            SET status = 'needs revision',
                remarks = :remarks,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':remarks' => $combinedRemarks,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE {$schema}logistic_requests
            SET status = 'needs revision',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    supabase_mirror_safe('logistic_requests', [
        'id' => $id,
        'status' => 'needs revision',
        'remarks' => $remarks,
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    finance_record_request_history(
        $pdo,
        'LOGISTICS',
        (string) $id,
        finance_logistics_request_code($id, (string) ($request['request_code'] ?? '')),
        'REVISION',
        'Needs Revision',
        $remarks
    );

    finance_record_approved_request_log(
        $pdo,
        'REVISION',
        'LOGISTICS',
        'logistic_requests',
        (string) $id,
        (string) ($request['item_name'] ?? 'Logistics request revision requested'),
        $amount,
        $remarks
    );
}

/**
 * Approve Service Request (Job Posting Payment) with Supabase mirroring and collection creation
 */
function approveServiceRequest(PDO $pdo, int $id): void
{
    finance_ensure_core_request_tracking_columns($pdo);
    $schema = finance_schema_prefix($pdo);
    $request = finance_get_job_posting_payment($pdo, $id);
    if (!$request) {
        throw new RuntimeException('CORE request not found.');
    }
    if (finance_core_status_label((string) ($request['status'] ?? 'Pending')) !== 'Pending') {
        throw new RuntimeException('Only pending CORE requests can be approved.');
    }

    if (finance_table_exists($pdo, 'job_posting_payments')) {
        $stmt = $pdo->prepare("
            UPDATE {$schema}job_posting_payments
            SET status = 'approved',
                related_ar_ap_id = NULL,
                related_disbursement_id = NULL,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    if ($request) {
        finance_create_request_collection_if_missing(
            $pdo,
            'CORE',
            $id,
            (string) ($request['company_name'] ?? 'CORE'),
            (float) ($request['amount'] ?? 0),
            'Approved Job Posting: ' . (string) ($request['job_title'] ?? ('CORE Request #' . $id)),
            'CORE-' . $id
        );

        finance_record_approved_request_log(
            $pdo,
            'APPROVE',
            'CORE',
            'job_posting_payments',
            $id,
            (string) ($request['job_title'] ?? 'Service request approved'),
            isset($request['amount']) ? (float) $request['amount'] : null,
            (string) ($request['company_name'] ?? '')
        );
    }

    // Mirror to Supabase
    supabase_mirror_safe('job_posting_payments', [
        'id' => $id,
        'status' => 'approved',
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    finance_record_request_history(
        $pdo,
        'CORE',
        (string) $id,
        finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'APPROVE',
        'Approved',
        'Approved by Finance.'
    );
}

/**
 * Reject Service Request (Job Posting Payment) with Supabase mirroring
 */
function rejectServiceRequest(PDO $pdo, int $id, string $remarks = ''): void
{
    finance_ensure_core_request_tracking_columns($pdo);
    $schema = finance_schema_prefix($pdo);
    $request = finance_get_job_posting_payment($pdo, $id);
    if (!$request) {
        throw new RuntimeException('CORE request not found.');
    }
    if (finance_core_status_label((string) ($request['status'] ?? 'Pending')) !== 'Pending') {
        throw new RuntimeException('Only pending CORE requests can be rejected.');
    }

    if (finance_table_exists($pdo, 'job_posting_payments')) {
        $hasRemarksColumn = finance_column_exists($pdo, 'job_posting_payments', 'remarks');
        if ($hasRemarksColumn) {
            $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), $remarks);
            $stmt = $pdo->prepare("
                UPDATE {$schema}job_posting_payments
                SET status = 'rejected',
                    remarks = :remarks,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':remarks' => $mergedRemarks,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE {$schema}job_posting_payments
                SET status = 'rejected',
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
        }
    }

    // Mirror to Supabase
    supabase_mirror_safe('job_posting_payments', [
        'id' => $id,
        'status' => 'rejected',
        'remarks' => isset($mergedRemarks) ? $mergedRemarks : trim($remarks),
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    finance_record_approved_request_log(
        $pdo,
        'REJECT',
        'CORE',
        'job_posting_payments',
        $id,
        (string) ($request['job_title'] ?? 'Service request rejected'),
        isset($request['amount']) ? (float) $request['amount'] : null,
        isset($mergedRemarks) ? $mergedRemarks : (trim($remarks) !== '' ? trim($remarks) : (string) ($request['company_name'] ?? ''))
    );

    finance_record_request_history(
        $pdo,
        'CORE',
        (string) $id,
        finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'REJECT',
        'Rejected',
        isset($mergedRemarks) ? $mergedRemarks : trim($remarks)
    );
}

function requestCoreRevision(PDO $pdo, int $id, string $remarks): void
{
    finance_ensure_core_request_tracking_columns($pdo);
    $request = finance_get_job_posting_payment($pdo, $id);
    if (!$request) {
        throw new RuntimeException('CORE request not found.');
    }
    if (finance_core_status_label((string) ($request['status'] ?? 'Pending')) !== 'Pending') {
        throw new RuntimeException('Only pending CORE requests can be returned for revision.');
    }

    $schema = finance_schema_prefix($pdo);
    $hasRemarksColumn = finance_table_exists($pdo, 'job_posting_payments') && finance_column_exists($pdo, 'job_posting_payments', 'remarks');
    $mergedRemarks = trim($remarks);

    if ($hasRemarksColumn) {
        $existingRemarks = trim((string) ($request['remarks'] ?? ''));
        $mergedRemarks = $existingRemarks !== '' ? $existingRemarks . ' | ' . $mergedRemarks : $mergedRemarks;
        $stmt = $pdo->prepare("
            UPDATE {$schema}job_posting_payments
            SET status = 'needs revision',
                remarks = :remarks,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':remarks' => $mergedRemarks,
        ]);
    } elseif (finance_table_exists($pdo, 'job_posting_payments')) {
        $stmt = $pdo->prepare("
            UPDATE {$schema}job_posting_payments
            SET status = 'needs revision',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    supabase_mirror_safe('job_posting_payments', [
        'id' => $id,
        'status' => 'needs revision',
        'remarks' => $mergedRemarks,
        'updated_at' => date('c')
    ], 'UPDATE', ['id' => $id]);

    finance_record_approved_request_log(
        $pdo,
        'REVISION',
        'CORE',
        'job_posting_payments',
        $id,
        (string) ($request['job_title'] ?? 'CORE request returned for revision'),
        isset($request['amount']) ? (float) $request['amount'] : null,
        $mergedRemarks
    );

    finance_record_request_history(
        $pdo,
        'CORE',
        (string) $id,
        finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'REVISION',
        'Revision Requested',
        $mergedRemarks
    );
}

function finance_link_core_request_to_disbursement(PDO $pdo, int $id, string $remarks = ''): void
{
    finance_ensure_core_request_tracking_columns($pdo);
    $request = finance_get_job_posting_payment($pdo, $id);
    if (!$request) {
        throw new RuntimeException('CORE request not found.');
    }
    if (finance_core_status_label((string) ($request['status'] ?? 'Pending')) !== 'Approved') {
        throw new RuntimeException('Only approved CORE requests can be linked to disbursement.');
    }
    if ((int) ($request['related_disbursement_id'] ?? 0) > 0) {
        throw new RuntimeException('This CORE request is already linked to disbursement.');
    }
    if ((int) ($request['related_ar_ap_id'] ?? 0) > 0 || (int) ($request['related_budget_id'] ?? 0) > 0) {
        throw new RuntimeException('This CORE request is already linked to another financial record.');
    }

    $schema = finance_schema_prefix($pdo);
    $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), $remarks);
    $stmt = $pdo->prepare("
        UPDATE {$schema}job_posting_payments
        SET status = 'Ready for Disbursement',
            remarks = :remarks,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':remarks' => $mergedRemarks,
    ]);

    supabase_mirror_safe('job_posting_payments', [
        'id' => $id,
        'status' => 'Ready for Disbursement',
        'remarks' => $mergedRemarks,
        'updated_at' => date('c'),
    ], 'UPDATE', ['id' => $id]);

    finance_record_request_history(
        $pdo,
        'CORE',
        (string) $id,
        finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'LINK_DISBURSEMENT',
        'Ready for Disbursement',
        $mergedRemarks ?? 'Linked to Disbursement.'
    );
}

function finance_link_core_request_to_arap(PDO $pdo, int $id, string $entryType, ?string $dueDate = null, string $remarks = ''): int
{
    finance_ensure_core_request_tracking_columns($pdo);
    finance_ensure_ar_ap_link_columns($pdo);

    $request = finance_get_job_posting_payment($pdo, $id);
    if (!$request) {
        throw new RuntimeException('CORE request not found.');
    }
    if (finance_core_status_label((string) ($request['status'] ?? 'Pending')) !== 'Approved') {
        throw new RuntimeException('Only approved CORE requests can be linked to AR/AP.');
    }
    if ((int) ($request['related_ar_ap_id'] ?? 0) > 0) {
        throw new RuntimeException('This CORE request is already linked to AR/AP.');
    }
    if ((int) ($request['related_disbursement_id'] ?? 0) > 0 || (int) ($request['related_budget_id'] ?? 0) > 0) {
        throw new RuntimeException('This CORE request is already linked to another financial record.');
    }

    $entryType = strtoupper(trim($entryType));
    if (!in_array($entryType, ['AR', 'AP'], true)) {
        throw new InvalidArgumentException('Select AR or AP.');
    }

    $payload = [
        'reference_no' => finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'description' => (string) ($request['job_title'] ?? 'CORE request'),
        'amount' => (float) ($request['amount'] ?? 0),
        'due_date' => $dueDate,
        'source_module' => 'CORE',
        'source_request_id' => $id,
    ];
    if ($entryType === 'AR') {
        $payload['client_name'] = (string) ($request['company_name'] ?? 'CORE Client');
        $arApId = createAR($pdo, $payload);
    } else {
        $payload['supplier_name'] = (string) ($request['company_name'] ?? 'CORE Vendor');
        $arApId = createAP($pdo, $payload);
    }

    $schema = finance_schema_prefix($pdo);
    $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), $remarks);
    $stmt = $pdo->prepare("
        UPDATE {$schema}job_posting_payments
        SET status = 'Linked to AR/AP',
            related_ar_ap_id = :related_ar_ap_id,
            remarks = :remarks,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':related_ar_ap_id' => $arApId,
        ':remarks' => $mergedRemarks,
    ]);

    supabase_mirror_safe('job_posting_payments', [
        'id' => $id,
        'status' => 'Linked to AR/AP',
        'related_ar_ap_id' => $arApId,
        'remarks' => $mergedRemarks,
        'updated_at' => date('c'),
    ], 'UPDATE', ['id' => $id]);

    finance_record_request_history(
        $pdo,
        'CORE',
        (string) $id,
        finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'LINK_' . $entryType,
        'Linked to AR/AP',
        trim($entryType . ' record created. ' . $remarks)
    );

    return $arApId;
}

function finance_link_core_request_to_budget(PDO $pdo, int $id, int $budgetId, string $remarks = ''): array
{
    finance_ensure_core_request_tracking_columns($pdo);
    $request = finance_get_job_posting_payment($pdo, $id);
    if (!$request) {
        throw new RuntimeException('CORE request not found.');
    }
    if (finance_core_status_label((string) ($request['status'] ?? 'Pending')) !== 'Approved') {
        throw new RuntimeException('Only approved CORE requests can be linked to a budget.');
    }
    if ((int) ($request['related_budget_id'] ?? 0) > 0) {
        throw new RuntimeException('This CORE request is already linked to a budget.');
    }
    if ((int) ($request['related_ar_ap_id'] ?? 0) > 0 || (int) ($request['related_disbursement_id'] ?? 0) > 0) {
        throw new RuntimeException('This CORE request is already linked to another financial record.');
    }

    $budget = getBudgetById($pdo, $budgetId);
    if (!$budget) {
        throw new RuntimeException('Selected budget was not found.');
    }

    $usage = finance_get_budget_usage_totals($pdo, $budgetId);
    $remainingAmount = round((float) ($budget['allocated_amount'] ?? 0) - (float) ($usage['used_amount'] ?? 0), 2);
    $requestAmount = (float) ($request['amount'] ?? 0);
    $overBudget = $requestAmount > $remainingAmount + 0.00001;

    $schema = finance_schema_prefix($pdo);
    $warning = $overBudget ? 'Over budget warning: request amount exceeds remaining allocation.' : 'Linked to budget.';
    $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), trim($warning . ' ' . $remarks));
    $stmt = $pdo->prepare("
        UPDATE {$schema}job_posting_payments
        SET status = 'Linked to Budget',
            related_budget_id = :related_budget_id,
            remarks = :remarks,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':related_budget_id' => $budgetId,
        ':remarks' => $mergedRemarks,
    ]);

    supabase_mirror_safe('job_posting_payments', [
        'id' => $id,
        'status' => 'Linked to Budget',
        'related_budget_id' => $budgetId,
        'remarks' => $mergedRemarks,
        'updated_at' => date('c'),
    ], 'UPDATE', ['id' => $id]);

    finance_record_request_history(
        $pdo,
        'CORE',
        (string) $id,
        finance_core_request_code($id, (string) ($request['request_code'] ?? '')),
        'LINK_BUDGET',
        'Linked to Budget',
        trim('Budget #' . $budgetId . '. ' . $warning . ' ' . $remarks)
    );

    return [
        'budget_id' => $budgetId,
        'over_budget' => $overBudget,
        'remaining_amount' => $remainingAmount,
    ];
}

function finance_link_logistics_request_to_ap(PDO $pdo, int $id, string $vendorName, ?string $dueDate = null, string $remarks = ''): int
{
    finance_ensure_logistics_request_tracking_columns($pdo);
    finance_ensure_ar_ap_link_columns($pdo);

    $request = finance_get_logistics_request_source_row($pdo, $id);
    if (!$request) {
        throw new RuntimeException('Logistics request not found.');
    }

    $status = finance_logistics_status_label((string) ($request['status'] ?? 'Pending'));
    if (!in_array($status, ['Approved', 'Ready for Disbursement'], true)) {
        throw new RuntimeException('Only approved logistics requests can create a payable.');
    }

    $linkedRecords = finance_get_logistics_linked_records($pdo, $id);
    if ($linkedRecords['ap'] !== null) {
        throw new RuntimeException('This logistics request already has a linked payable.');
    }
    if ($linkedRecords['disbursement'] !== null) {
        throw new RuntimeException('This logistics request has already been released through disbursement.');
    }

    $vendorName = trim($vendorName);
    if ($vendorName === '') {
        throw new InvalidArgumentException('Vendor / payee is required.');
    }

    $amount = finance_numeric_from_mixed($request['amount'] ?? null) ?? 0.0;
    if ($amount <= 0) {
        throw new RuntimeException('Logistics request amount must be greater than zero before creating a payable.');
    }

    $payload = [
        'reference_no' => (string) ($request['request_code'] ?? finance_logistics_request_code($id)),
        'supplier_name' => $vendorName,
        'description' => (string) ($request['request_description'] ?? $request['request_title'] ?? 'Logistics payable'),
        'amount' => $amount,
        'due_date' => $dueDate,
        'source_module' => 'LOGISTICS',
        'source_request_id' => $id,
    ];

    $arApId = createAP($pdo, $payload);
    $mergedRemarks = finance_merge_remarks((string) ($request['remarks'] ?? ''), trim('AP created for vendor settlement. ' . $remarks));

    if (($request['source'] ?? '') !== 'supabase') {
        $schema = finance_schema_prefix($pdo);
        $stmt = $pdo->prepare("
            UPDATE {$schema}logistic_requests
            SET related_ar_ap_id = :related_ar_ap_id,
                remarks = :remarks,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':related_ar_ap_id' => $arApId,
            ':remarks' => $mergedRemarks,
            ':id' => $id,
        ]);
    }

    finance_record_request_history(
        $pdo,
        'LOGISTICS',
        (string) $id,
        (string) ($request['request_code'] ?? finance_logistics_request_code($id)),
        'CREATE_AP',
        'Approved',
        trim('AP #' . $arApId . ' created. ' . $remarks)
    );

    return $arApId;
}
