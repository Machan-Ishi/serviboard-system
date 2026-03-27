-- SQL FIX FOR SUPABASE INTEGRATION
-- Run this in your Supabase SQL Editor to fix the "can't see it" issue.

-- 1. Ensure the schema is accessible to the API
GRANT USAGE ON SCHEMA public TO anon, authenticated;
GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO anon, authenticated;

-- 2. Fix the employees table to include all necessary columns
CREATE TABLE IF NOT EXISTS public.employees (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    employee_code VARCHAR(30),
    email TEXT,
    department VARCHAR(100),
    position VARCHAR(100),
    pay_type VARCHAR(20) DEFAULT 'monthly',
    basic_salary NUMERIC(14,2) DEFAULT 0,
    allowance NUMERIC(14,2) DEFAULT 0,
    deduction_default NUMERIC(14,2) DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT 'Bank Transfer',
    status VARCHAR(20) DEFAULT 'Active',
    job_id UUID,
    role TEXT DEFAULT 'employee',
    username TEXT,
    password TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Ensure columns exist if the table was already created differently
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS employee_code VARCHAR(30);
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS department VARCHAR(100);
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS position VARCHAR(100);
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS pay_type VARCHAR(20) DEFAULT 'monthly';
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS basic_salary NUMERIC(14,2) DEFAULT 0;
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS allowance NUMERIC(14,2) DEFAULT 0;
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS deduction_default NUMERIC(14,2) DEFAULT 0;
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT 'Bank Transfer';
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'Active';
ALTER TABLE public.employees ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT NOW();

-- 3. Fix other core tables
CREATE TABLE IF NOT EXISTS public.collection (
    id BIGSERIAL PRIMARY KEY,
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
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 4. DISABLE RLS (Row Level Security) so the API can see and write data
-- This is the most common reason why data doesn't show up in the dashboard API
ALTER TABLE public.employees DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.collection DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.accounts DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.ar_ap DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.general_ledger DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.client_requests DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.users DISABLE ROW LEVEL SECURITY;

-- 4.1 Grant permissions specifically to the 'anon' role used by the API
GRANT ALL ON TABLE public.employees TO anon;
GRANT ALL ON TABLE public.collection TO anon;
GRANT ALL ON TABLE public.accounts TO anon;
GRANT ALL ON TABLE public.ar_ap TO anon;
GRANT ALL ON TABLE public.general_ledger TO anon;
GRANT ALL ON TABLE public.client_requests TO anon;
GRANT ALL ON TABLE public.users TO anon;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO anon;

-- 6. Connect Budget and Payroll
ALTER TABLE public.payroll_runs ADD COLUMN IF NOT EXISTS budget_id BIGINT;
ALTER TABLE public.payroll_payment_requests ADD COLUMN IF NOT EXISTS related_budget_id BIGINT;
ALTER TABLE public.budget_management ADD COLUMN IF NOT EXISTS funded_amount NUMERIC(14,2) DEFAULT 0;

-- Refresh schema cache
NOTIFY pgrst, 'reload schema';
