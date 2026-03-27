ALTER TABLE budgets
  ADD COLUMN department VARCHAR(120) NULL AFTER id,
  ADD COLUMN allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER budget_name;

CREATE TABLE IF NOT EXISTS general_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  journal_entry_id INT NULL,
  account_id INT NULL,
  transaction_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  debit DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0,
  category VARCHAR(120) NULL,
  department VARCHAR(120) NULL,
  reference_type VARCHAR(80) NULL,
  reference_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gl_transaction_date (transaction_date),
  INDEX idx_gl_account (account_id),
  INDEX idx_gl_category (category),
  INDEX idx_gl_department (department),
  INDEX idx_gl_reference (reference_type, reference_id),
  CONSTRAINT fk_general_ledger_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_general_ledger_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS budget_usage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  budget_id INT NOT NULL,
  gl_transaction_id INT NOT NULL,
  amount_used DECIMAL(12,2) NOT NULL DEFAULT 0,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_budget_usage_link (budget_id, gl_transaction_id),
  INDEX idx_budget_usage_budget (budget_id),
  INDEX idx_budget_usage_gl (gl_transaction_id),
  CONSTRAINT fk_budget_usage_budget FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_budget_usage_gl FOREIGN KEY (gl_transaction_id) REFERENCES general_ledger(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT
  b.id,
  b.budget_name,
  COALESCE(SUM(a.allocated_amount), 0) AS allocated_amount,
  COALESCE(SUM(u.amount_used), 0) AS actual_spent,
  (COALESCE(SUM(a.allocated_amount), 0) - COALESCE(SUM(u.amount_used), 0)) AS remaining_budget
FROM budgets b
LEFT JOIN budget_allocations a
  ON a.budget_id = b.id
LEFT JOIN budget_usage u
  ON u.budget_id = b.id
GROUP BY b.id, b.budget_name;
