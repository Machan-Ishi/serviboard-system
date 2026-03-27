<?php
require_once __DIR__ . '/../config/db.php';

/**
 * CSRF Protection
 */
function csrf_token(): string {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): bool {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
    return false;
  }
  return hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

/**
 * XSS Protection helper
 */
if (!function_exists('finance_h')) {
  function finance_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

// 2) CSS file path (edit later when you have a real css file)
function css_url(): string {
  // Example if you have /assets/style.css:
  // return "/FinancialSM/assets/style.css";
  return "";
}

// 3) Dashboard counts used by your pages
function fetch_counts(PDO $pdo): array {
  $out = [
    'fin_sum'        => 0.00,
    'fin_total'      => 0,
    'hr_total'       => 0,
    'hr_open'        => 0,
    'core_total'     => 0,
    'core_assigned'  => 0,
    'log_total'      => 0,
    'admin_total'    => 0,
  ];

  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $table = ($driver === 'pgsql') ? 'public.collection' : 'collection';

  try {
    // Financial totals from collection table
    $stmt = $pdo->query("
      SELECT
        COUNT(*) AS total,
        COALESCE(SUM(amount), 0) AS sum
      FROM $table
    ");
    $row = $stmt->fetch();
    if ($row) {
      $out['fin_total'] = (int)$row['total'];
      $out['fin_sum']   = (float)$row['sum'];
    }
  } catch (Exception $e) {}

  return $out;
}

// Finance helpers
function getInvoiceSummary(PDO $pdo): array {
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $table = ($driver === 'pgsql') ? 'public.ar_ap' : 'ar_ap';
  $now = ($driver === 'pgsql') ? 'CURRENT_DATE' : 'CURDATE()';

  try {
    $stmt = $pdo->prepare(
      "SELECT
          id,
          party_name AS client_name,
          reference_no AS invoice_no,
          created_at AS invoice_date,
          due_date,
          amount AS total_amount,
          balance,
          CASE
            WHEN balance <= 0 THEN 'PAID'
            WHEN balance < amount THEN 'PARTIAL'
            WHEN due_date < $now THEN 'OVERDUE'
            ELSE 'UNPAID'
          END AS status
       FROM $table
       WHERE entry_type = 'AR'
       ORDER BY id DESC"
    );
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function getAROutstanding(PDO $pdo): float {
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $table = ($driver === 'pgsql') ? 'public.ar_ap' : 'ar_ap';

  try {
    $stmt = $pdo->prepare(
      "SELECT COALESCE(SUM(balance), 0) AS total_outstanding
       FROM $table
       WHERE entry_type = 'AR' AND balance > 0"
    );
    $stmt->execute();
    return (float)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0.0;
  }
}

function getAPOutstanding(PDO $pdo): float {
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $table = ($driver === 'pgsql') ? 'public.ar_ap' : 'ar_ap';

  try {
    $stmt = $pdo->prepare(
      "SELECT COALESCE(SUM(balance), 0) AS total_outstanding
       FROM $table
       WHERE entry_type = 'AP' AND balance > 0"
    );
    $stmt->execute();
    return (float)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0.0;
  }
}

/**
 * @deprecated Use migrate.php script for schema updates
 */
function ensure_financial_support_schema(PDO $pdo): void {
  // Logic moved to scripts/migrate.php
}

function receivable_status(float $total, float $paid, ?string $dueDate, bool $isVoid = false): string {
  if ($isVoid) {
    return 'VOID';
  }
  $balance = max(0.0, $total - $paid);
  if ($balance <= 0.00001) {
    return 'PAID';
  }
  if ($dueDate !== null && $dueDate !== '' && $dueDate < date('Y-m-d')) {
    return $paid > 0.00001 ? 'PARTIAL' : 'OVERDUE';
  }
  return $paid > 0.00001 ? 'PARTIAL' : 'UNPAID';
}

function get_financial_dashboard_metrics(PDO $pdo): array {
  $metrics = [
    'open_ar' => 0.0,
    'open_ap' => 0.0,
    'collections_month' => 0.0,
    'disbursements_month' => 0.0,
    'budget_count' => 0,
    'request_count' => 0,
  ];

  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $isPg = ($driver === 'pgsql');
  $schema = $isPg ? 'public.' : '';

  try {
    $metrics['open_ar'] = (float)($pdo->query("SELECT COALESCE(SUM(balance), 0) FROM {$schema}ar_ap WHERE entry_type = 'AR'")->fetchColumn() ?: 0);
  } catch (Throwable $e) {}
  try {
    $metrics['open_ap'] = (float)($pdo->query("SELECT COALESCE(SUM(balance), 0) FROM {$schema}ar_ap WHERE entry_type = 'AP'")->fetchColumn() ?: 0);
  } catch (Throwable $e) {}
  
  try {
    $sql = $isPg 
      ? "SELECT COALESCE(SUM(amount), 0) FROM public.collection WHERE TO_CHAR(payment_date, 'YYYY-MM') = TO_CHAR(CURRENT_DATE, 'YYYY-MM')"
      : "SELECT COALESCE(SUM(amount), 0) FROM collection WHERE DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    $stmt = $pdo->query($sql);
    $metrics['collections_month'] = (float)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {}

  try {
    $sql = $isPg
      ? "SELECT COALESCE(SUM(amount), 0) FROM public.disbursement WHERE TO_CHAR(disbursement_date, 'YYYY-MM') = TO_CHAR(CURRENT_DATE, 'YYYY-MM')"
      : "SELECT COALESCE(SUM(amount), 0) FROM disbursement WHERE DATE_FORMAT(disbursement_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    $stmt = $pdo->query($sql);
    $metrics['disbursements_month'] = (float)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {}

  try { $metrics['budget_count'] = (int)($pdo->query("SELECT COUNT(*) FROM {$schema}budget_management")->fetchColumn() ?: 0); } catch (Throwable $e) {}
  try { $metrics['request_count'] = (int)($pdo->query("SELECT COUNT(*) FROM {$schema}client_requests WHERE status IN ('Pending', 'In Review')")->fetchColumn() ?: 0); } catch (Throwable $e) {}

  return $metrics;
}

function getBudgetRemaining(PDO $pdo, int $budgetId): float {
  try {
    $stmt = $pdo->prepare(
      "SELECT remaining_amount FROM public.budget_management WHERE id = :budget_id"
    );
    $stmt->execute([':budget_id' => $budgetId]);
    return (float)($stmt->fetchColumn() ?: 0.0);
  } catch (Throwable $e) {
    return 0.0;
  }
}

function table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  $key = strtolower($table);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $isPg = ($driver === 'pgsql');

  try {
    if ($isPg) {
      $stmt = $pdo->prepare("
          SELECT 1 
          FROM information_schema.tables 
          WHERE table_schema = 'public' 
            AND table_name = :table 
          LIMIT 1
      ");
    } else {
      $stmt = $pdo->prepare("
          SELECT 1 
          FROM information_schema.tables 
          WHERE table_schema = DATABASE() 
            AND table_name = :table 
          LIMIT 1
      ");
    }
    $stmt->execute([':table' => strtolower($table)]);
    $exists = (bool)$stmt->fetchColumn();
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

function column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = strtolower($table . '.' . $column);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $isPg = ($driver === 'pgsql');

  try {
    if ($isPg) {
      $stmt = $pdo->prepare("
          SELECT 1 
          FROM information_schema.columns 
          WHERE table_schema = 'public' 
            AND table_name = :table 
            AND column_name = :column 
          LIMIT 1
      ");
    } else {
      $stmt = $pdo->prepare("
          SELECT 1 
          FROM information_schema.columns 
          WHERE table_schema = DATABASE() 
            AND table_name = :table 
            AND column_name = :column 
          LIMIT 1
      ");
    }
    $stmt->execute([':table' => $table, ':column' => $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $cache[$key] = false;
  }
  return $cache[$key];
}

function ensure_request_tables(PDO $pdo): void {
  // Ensure helper tables used by the Financial -> Collection workflow exist.
  // This helps avoid "relation does not exist" errors on fresh setups.
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $isPg = ($driver === 'pgsql');
  $schema = $isPg ? "public." : "";

  if (!table_exists($pdo, 'job_posting_payments')) {
    if ($isPg) {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$schema}job_posting_payments (
           id SERIAL PRIMARY KEY,
           job_title VARCHAR(255) NOT NULL,
           company_name VARCHAR(255) NOT NULL,
           amount NUMERIC(10,2) NOT NULL,
           status VARCHAR(20) DEFAULT 'pending',
           created_at TIMESTAMPTZ DEFAULT NOW(),
           updated_at TIMESTAMPTZ DEFAULT NOW()
         )"
      );
    } else {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS job_posting_payments (
           id INT AUTO_INCREMENT PRIMARY KEY,
           job_title VARCHAR(255) NOT NULL,
           company_name VARCHAR(255) NOT NULL,
           amount DECIMAL(10,2) NOT NULL,
           status ENUM('pending','approved','rejected') DEFAULT 'pending',
           created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
           updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
      );
    }
  }

  if (!table_exists($pdo, 'hr_requests')) {
    if ($isPg) {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$schema}hr_requests (
           id SERIAL PRIMARY KEY,
           request_details VARCHAR(255) NOT NULL,
           department VARCHAR(100) NOT NULL,
           status VARCHAR(20) DEFAULT 'pending',
           created_at TIMESTAMPTZ DEFAULT NOW(),
           updated_at TIMESTAMPTZ DEFAULT NOW()
         )"
      );
    } else {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hr_requests (
           id INT AUTO_INCREMENT PRIMARY KEY,
           request_details VARCHAR(255) NOT NULL,
           department VARCHAR(100) NOT NULL,
           status ENUM('pending','approved','rejected') DEFAULT 'pending',
           created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
           updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
      );
    }
  }

  if (!table_exists($pdo, 'logistic_requests')) {
    if ($isPg) {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$schema}logistic_requests (
           id SERIAL PRIMARY KEY,
           item_name VARCHAR(255) NOT NULL,
           quantity INT NOT NULL,
           destination VARCHAR(255) NOT NULL,
           status VARCHAR(20) DEFAULT 'pending',
           created_at TIMESTAMPTZ DEFAULT NOW(),
           updated_at TIMESTAMPTZ DEFAULT NOW()
         )"
      );
    } else {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS logistic_requests (
           id INT AUTO_INCREMENT PRIMARY KEY,
           item_name VARCHAR(255) NOT NULL,
           quantity INT NOT NULL,
           destination VARCHAR(255) NOT NULL,
           status ENUM('pending','approved','rejected') DEFAULT 'pending',
           created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
           updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
      );
    }
  }
}

/**
 * @deprecated Use scripts/migrate.php
 */
function ensure_budget_ledger_bridge(PDO $pdo): void {
  // Logic moved to scripts/migrate.php
}

function account_meta(PDO $pdo, int $accountId): ?array {
  static $cache = [];
  if ($accountId <= 0) {
    return null;
  }
  if (array_key_exists($accountId, $cache)) {
    return $cache[$accountId];
  }
  try {
    $stmt = $pdo->prepare("SELECT id, account_code, account_title as account_name, account_type FROM public.accounts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $accountId]);
    $cache[$accountId] = $stmt->fetch() ?: null;
  } catch (Throwable $e) {
    $cache[$accountId] = null;
  }
  return $cache[$accountId];
}

function resolve_budget_for_ledger(PDO $pdo, array $entry): int {
  // Simplification for Supabase schema
  return (int)($entry['budget_id'] ?? 0);
}

function link_gl_to_budget(PDO $pdo, int $glTransactionId, array $entry): int {
  return 0; // Not used in new schema
}

function record_general_ledger_entry(PDO $pdo, array $entry): int {
  $transactionDate = (string)($entry['transaction_date'] ?? date('Y-m-d'));
  $description = trim((string)($entry['description'] ?? ''));
  $debit = (float)($entry['debit'] ?? 0);
  $credit = (float)($entry['credit'] ?? 0);
  $accountTitle = trim((string)($entry['account_title'] ?? ''));
  $referenceNo = trim((string)($entry['reference_no'] ?? ''));
  $sourceModule = trim((string)($entry['source_module'] ?? 'manual'));
  $sourceId = (int)($entry['source_id'] ?? 0);

  if ($description === '') {
    $description = 'General ledger transaction';
  }

  $stmt = $pdo->prepare(
    "INSERT INTO public.general_ledger (
        transaction_date, reference_no, account_title, entry_type, description, 
        debit, credit, source_module, source_id
     ) VALUES (
        :transaction_date, :reference_no, :account_title, :entry_type, :description, 
        :debit, :credit, :source_module, :source_id
     )"
  );
  
  $entryType = $debit > 0 ? 'Debit' : 'Credit';
  
  $stmt->execute([
    ':transaction_date' => $transactionDate,
    ':reference_no' => $referenceNo,
    ':account_title' => $accountTitle,
    ':entry_type' => $entryType,
    ':description' => $description,
    ':debit' => $debit,
    ':credit' => $credit,
    ':source_module' => $sourceModule !== '' ? $sourceModule : null,
    ':source_id' => $sourceId > 0 ? $sourceId : null,
  ]);

  return (int)$pdo->lastInsertId();
}

function record_general_ledger_lines(PDO $pdo, int $sourceId, string $journalDate, ?string $referenceNo, string $description, array $lines, array $context = []): void {
  foreach ($lines as $line) {
    $payload = [
      'transaction_date' => $journalDate,
      'reference_no' => $referenceNo,
      'account_title' => trim((string)($line['account_title'] ?? '')),
      'description' => (string)($line['description'] ?? $description),
      'debit' => (float)($line['debit'] ?? 0),
      'credit' => (float)($line['credit'] ?? 0),
      'source_module' => trim((string)($context['source_module'] ?? 'manual')),
      'source_id' => $sourceId,
    ];

    record_general_ledger_entry($pdo, $payload);
  }
}

function get_budget_dashboard_rows(PDO $pdo, int $budgetId = 0, string $department = ''): array {
  try {
    $sql = "SELECT
              id AS budget_id,
              budget_name,
              department,
              allocated_amount,
              used_amount AS actual_spent,
              remaining_amount AS remaining_budget
            FROM public.budget_management
            WHERE (:budget_id = 0 OR id = :budget_id)
              AND (:department = '' OR LOWER(department) = LOWER(:department))
            ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':budget_id' => $budgetId,
      ':department' => $department,
    ]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
      $allocated = (float)$row['allocated_amount'];
      $spent = (float)$row['actual_spent'];
      $rows[] = [
        'budget_id' => (int)$row['budget_id'],
        'budget_name' => (string)$row['budget_name'],
        'department' => (string)$row['department'],
        'account' => '-',
        'allocated' => $allocated,
        'actual_spent' => $spent,
        'remaining_budget' => (float)$row['remaining_budget'],
        'utilization_pct' => $allocated > 0 ? ($spent / $allocated) * 100 : 0.0,
      ];
    }
    return $rows;
  } catch (Throwable $e) {
    return [];
  }
}

function get_budget_usage_entries(PDO $pdo, int $budgetId): array {
  try {
    $stmt = $pdo->prepare(
      "SELECT
          transaction_date,
          account_title,
          description,
          debit,
          credit,
          source_module,
          source_id
       FROM public.general_ledger
       WHERE source_module = 'budget' AND source_id = :budget_id
       ORDER BY transaction_date DESC, id DESC"
    );
    $stmt->execute([':budget_id' => $budgetId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
      $rows[] = [
        'transaction_date' => (string)$row['transaction_date'],
        'account' => (string)$row['account_title'],
        'amount_used' => (float)($row['debit'] > 0 ? $row['debit'] : $row['credit']),
        'description' => (string)$row['description'],
        'reference' => (string)$row['source_module'] . ' #' . (string)$row['source_id'],
      ];
    }
    return $rows;
  } catch (Throwable $e) {
    return [];
  }
}

// ---------- General Ledger helpers usable across modules ----------
function gl_normal_balance(string $type): string {
  return in_array($type, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit';
}

/**
 * @deprecated Use scripts/migrate.php
 */
function ensure_gl_core(PDO $pdo): void {
  // Logic moved to scripts/migrate.php
}

function ensure_account(PDO $pdo, string $code, string $name, string $type): int {
  $stmt = $pdo->prepare("SELECT id FROM public.accounts WHERE account_code = :code LIMIT 1");
  $stmt->execute([':code' => $code]);
  $id = $stmt->fetchColumn();
  if ($id) return (int)$id;
  $ins = $pdo->prepare("INSERT INTO public.accounts (account_code, account_title, account_type) VALUES (:code,:name,:type)");
  $ins->execute([':code' => $code, ':name' => $name, ':type' => $type]);
  return (int)$pdo->lastInsertId();
}

/**
 * Insert a balanced journal entry.
 * $lines is an array of ['account_title' => string, 'debit' => float, 'credit' => float]
 */
function post_journal(PDO $pdo, string $journalDate, ?string $reference, string $description, array $lines, array $context = []): string {
  $totalDebit = 0.0; $totalCredit = 0.0; $valid = [];
  foreach ($lines as $line) {
    $acc = trim((string)($line['account_title'] ?? ''));
    $debit = (float)($line['debit'] ?? 0);
    $credit = (float)($line['credit'] ?? 0);
    if ($acc === '') continue;
    if ($debit < 0 || $credit < 0) continue;
    if (($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) continue;
    $line['account_title'] = $acc;
    $line['debit'] = $debit;
    $line['credit'] = $credit;
    $valid[] = $line;
    $totalDebit += $debit;
    $totalCredit += $credit;
  }
  if (count($valid) < 2) {
    throw new RuntimeException('Journal entry requires at least two valid lines.');
  }
  if (abs($totalDebit - $totalCredit) > 0.00001) {
    throw new RuntimeException('Journal entry must balance debits and credits.');
  }

  $referenceNo = $reference ?: 'GL-' . date('YmdHis');
  
  foreach ($valid as $line) {
    record_general_ledger_entry($pdo, [
      'transaction_date' => $journalDate,
      'reference_no' => $referenceNo,
      'account_title' => $line['account_title'],
      'description' => $description,
      'debit' => $line['debit'],
      'credit' => $line['credit'],
      'source_module' => $context['source_module'] ?? 'manual',
      'source_id' => $context['source_id'] ?? 0
    ]);
  }
  
  return $referenceNo;
}
