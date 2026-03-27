-- Consolidated SQL for Supabase (PostgreSQL) Setup
-- This script creates all necessary tables for the Financial, Payroll, and Request modules.

-- 1. Enable pgcrypto for UUID generation
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- 2. Create helper function for updated_at triggers
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
   NEW.updated_at = NOW();
   RETURN NEW;
END;
$$ language 'plpgsql';

-- 3. Core Tables
CREATE TABLE IF NOT EXISTS public.users (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  name TEXT NOT NULL,
  role_slug TEXT NOT NULL,
  email TEXT UNIQUE,
  password_hash TEXT,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.accounts (
    id BIGSERIAL PRIMARY KEY,
    account_code VARCHAR(20),
    account_title VARCHAR(120) NOT NULL,
    account_type VARCHAR(50),
    description TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.jobs (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  title TEXT NOT NULL,
  department TEXT,
  location TEXT,
  type TEXT,
  description TEXT,
  status TEXT DEFAULT 'open',
  analytics_logged BOOLEAN DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS public.employees (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL,
  email TEXT,
  job_id UUID REFERENCES public.jobs(id) ON DELETE SET NULL,
  role TEXT DEFAULT 'employee',
  username TEXT UNIQUE,
  password TEXT,
  employee_code VARCHAR(30),
  department VARCHAR(100),
  position VARCHAR(100),
  pay_type VARCHAR(20) DEFAULT 'monthly',
  basic_salary NUMERIC(14,2) NOT NULL DEFAULT 0,
  allowance NUMERIC(14,2) NOT NULL DEFAULT 0,
  deduction_default NUMERIC(14,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(50) DEFAULT 'Bank Transfer',
  status VARCHAR(20) DEFAULT 'Active',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 4. Financial Module Tables
CREATE TABLE IF NOT EXISTS public.ar_ap (
    id BIGSERIAL PRIMARY KEY,
    entry_type VARCHAR(10) NOT NULL, -- AR or AP
    party_name VARCHAR(150) NOT NULL,
    reference_no VARCHAR(60),
    description TEXT,
    amount NUMERIC(14,2) NOT NULL DEFAULT 0,
    balance NUMERIC(14,2) NOT NULL DEFAULT 0,
    due_date DATE,
    status VARCHAR(30),
    related_collection_id BIGINT,
    related_disbursement_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.budget_management (
    id BIGSERIAL PRIMARY KEY,
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
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.collection (
    id BIGSERIAL PRIMARY KEY,
    reference_no VARCHAR(60),
    source_type VARCHAR(50), -- MANUAL, AR, CORE
    source_id BIGINT,
    payer_name VARCHAR(150) NOT NULL,
    amount NUMERIC(14,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(40),
    payment_date DATE NOT NULL,
    status VARCHAR(30),
    remarks TEXT,
    related_budget_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.disbursement (
    id BIGSERIAL PRIMARY KEY,
    reference_no VARCHAR(60),
    payee_name VARCHAR(150) NOT NULL,
    request_source VARCHAR(50), -- MANUAL, AP, BUDGET, PAYROLL
    request_id BIGINT,
    amount NUMERIC(14,2) NOT NULL DEFAULT 0,
    disbursement_date DATE NOT NULL,
    payment_method VARCHAR(40),
    status VARCHAR(30),
    remarks TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.general_ledger (
    id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    entry_date DATE DEFAULT CURRENT_DATE,
    account_name TEXT,
    description TEXT,
    debit NUMERIC(15,2) DEFAULT 0,
    credit NUMERIC(15,2) DEFAULT 0,
    category VARCHAR(100),
    reference_no VARCHAR(100),
    source_module VARCHAR(50),
    source_id BIGINT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 5. Request Tables (Incoming from CORE, HR, Logistics)
CREATE TABLE IF NOT EXISTS public.job_posting_payments (
  id SERIAL PRIMARY KEY,
  job_title VARCHAR(255) NOT NULL,
  company_name VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.hr_requests (
  id SERIAL PRIMARY KEY,
  request_details VARCHAR(255) NOT NULL,
  department VARCHAR(100) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.logistic_requests (
  id SERIAL PRIMARY KEY,
  item_name VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  destination VARCHAR(255) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- 6. Payroll Tables
CREATE TABLE IF NOT EXISTS public.payroll_runs (
    id BIGSERIAL PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_gross NUMERIC(14,2) DEFAULT 0,
    total_net NUMERIC(14,2) DEFAULT 0,
    approval_status VARCHAR(20) DEFAULT 'Pending',
    approved_by UUID REFERENCES public.users(id),
    approved_at TIMESTAMPTZ,
    payment_request_id BIGINT,
    budget_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.payroll_run_items (
    id BIGSERIAL PRIMARY KEY,
    payroll_run_id BIGINT NOT NULL REFERENCES public.payroll_runs(id) ON DELETE CASCADE,
    employee_id BIGINT NOT NULL REFERENCES public.employees(id),
    basic_salary NUMERIC(14,2) NOT NULL,
    overtime_pay NUMERIC(14,2) DEFAULT 0,
    allowances NUMERIC(14,2) DEFAULT 0,
    deductions NUMERIC(14,2) DEFAULT 0,
    absence_deduction NUMERIC(14,2) DEFAULT 0,
    late_deduction NUMERIC(14,2) DEFAULT 0,
    gross_pay NUMERIC(14,2) NOT NULL,
    net_pay NUMERIC(14,2) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.payroll_payment_requests (
    id BIGSERIAL PRIMARY KEY,
    payroll_run_id BIGINT NOT NULL REFERENCES public.payroll_runs(id),
    request_no VARCHAR(60) UNIQUE NOT NULL,
    total_amount NUMERIC(14,2) NOT NULL,
    request_date DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'Pending',
    related_budget_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 7. Client & Procurement Requests
CREATE TABLE IF NOT EXISTS public.client_requests (
    id BIGSERIAL PRIMARY KEY,
    request_no VARCHAR(60) NOT NULL UNIQUE,
    requester_name VARCHAR(150) NOT NULL,
    department VARCHAR(120),
    request_type VARCHAR(30) NOT NULL,
    description TEXT NOT NULL,
    amount NUMERIC(14,2) NOT NULL DEFAULT 0,
    request_date DATE NOT NULL,
    due_date DATE,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    remarks TEXT,
    linked_module VARCHAR(50),
    linked_record_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.procurement_requests (
    id BIGSERIAL PRIMARY KEY,
    request_no VARCHAR(60) NOT NULL UNIQUE,
    supplier_name VARCHAR(150) NOT NULL,
    item_description TEXT NOT NULL,
    total_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
    request_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    related_ap_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 8. Triggers for updated_at
DO $$
DECLARE
    t text;
BEGIN
    FOR t IN 
        SELECT table_name 
        FROM information_schema.columns 
        WHERE column_name = 'updated_at' 
        AND table_schema = 'public'
    LOOP
        EXECUTE format('CREATE OR REPLACE TRIGGER update_%I_updated_at BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()', t, t);
    END LOOP;
END;
$$;

-- 9. Initial Sample Data (Optional)
INSERT INTO public.accounts (account_code, account_title, account_type) VALUES
('1001', 'Cash', 'Asset'),
('1002', 'Accounts Receivable', 'Asset'),
('2001', 'Accounts Payable', 'Liability'),
('3001', 'Equity', 'Equity'),
('4001', 'Service Revenue', 'Revenue'),
('5001', 'Salaries Expense', 'Expense')
ON CONFLICT DO NOTHING;

INSERT INTO public.job_posting_payments (job_title, company_name, amount) VALUES
('Senior Software Engineer', 'Tech Solutions Inc.', 15000.00),
('Marketing Manager', 'Creative Agency', 2500.50)
ON CONFLICT DO NOTHING;
