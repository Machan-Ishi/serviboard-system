-- Rebuilt from original uploaded SQL: kept first occurrence of each CREATE TABLE block, removed duplicates, added RLS enables.
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE SCHEMA IF NOT EXISTS hr1;
CREATE SCHEMA IF NOT EXISTS service;

CREATE TABLE IF NOT EXISTS public.users (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  name TEXT NOT NULL,
  role_slug TEXT NOT NULL, -- 'staff', 'legal_officer', 'facility_manager', 'admin'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.profiles (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  user_id UUID REFERENCES public.users(id) ON DELETE CASCADE,
  full_name TEXT,
  avatar_url TEXT,
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.legal_cases (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  title TEXT NOT NULL,
  assigned_to TEXT,
  status TEXT DEFAULT 'Open', -- 'Open', 'In Progress', 'Closed'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.new_hires (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  full_name TEXT NOT NULL,
  email TEXT UNIQUE NOT NULL,
  position TEXT,
  photo_url TEXT,
  onboarding_status TEXT DEFAULT 'pending', -- 'pending', 'review', 'completed'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.documents (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  title TEXT NOT NULL,
  category TEXT, -- Also used for 'folder' in organize-files
  folder TEXT,   -- Explicit folder column
  status TEXT DEFAULT 'pending', -- 'pending', 'approved'
  employee_id UUID REFERENCES public.new_hires(id) ON DELETE SET NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.contracts (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  name TEXT NOT NULL,
  party TEXT,
  start_date DATE,
  end_date DATE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.incidents (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  description TEXT NOT NULL,
  date DATE,
  status TEXT DEFAULT 'Open', -- 'Open', 'Investigating', 'Resolved'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.facilities_management (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  facility_name TEXT NOT NULL,
  schedule TEXT,
  is_available BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.assets (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  name TEXT NOT NULL,
  tag TEXT,
  location TEXT,
  usage_status TEXT DEFAULT 'Operational',
  maintenance_status TEXT DEFAULT 'Good',
  facility_id UUID REFERENCES public.facilities_management(id) ON DELETE SET NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.room_bookings (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  room TEXT NOT NULL, -- Maps to facility_name
  date DATE,
  time TIME,
  purpose TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.maintenance_requests (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  item TEXT NOT NULL, -- Maps to item_name
  description TEXT,
  priority TEXT DEFAULT 'Medium', -- 'Low', 'Medium', 'High'
  status TEXT DEFAULT 'Open',
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.visitors (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  name TEXT NOT NULL,
  company TEXT,
  purpose TEXT,
  status TEXT DEFAULT 'Checked In', -- 'Checked In', 'Checked Out'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.facility_tasks (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  task TEXT NOT NULL,
  status TEXT DEFAULT 'Open',
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.client_agreements (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  client_name TEXT NOT NULL,
  agreement_type TEXT,
  legal_feedback TEXT,
  status TEXT DEFAULT 'pending', -- 'pending', 'under_review', 'approved'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.document_tracking (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  doc_title TEXT NOT NULL,
  tracking_number TEXT,
  status TEXT DEFAULT 'request', -- 'request', 'updated'
  feedback TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.warehousing (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  facility_name TEXT,
  item_name TEXT,
  requested_date DATE,
  available_schedules TEXT,
  status TEXT DEFAULT 'request', -- 'request', 'schedules'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.mro_execution (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  item_name TEXT,
  execution_details TEXT,
  admin_feedback TEXT,
  status TEXT DEFAULT 'request', -- 'request', 'execution'
  created_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.activity_log (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  role_slug TEXT,
  module_slug TEXT,
  message TEXT,
  ts TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

CREATE TABLE IF NOT EXISTS public.generic_module_data (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  slug TEXT NOT NULL,
  data TEXT,
  user_name TEXT,
  ts TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now())
);

create table if not exists public.categories (
  id bigint generated by default as identity primary key,
  name text not null
);

create table if not exists public.competency_categories (
  id bigint generated by default as identity primary key,
  name text not null,
  description text,
  status text not null default 'Active' check (status in ('Active', 'Archived')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.jobs (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  title text not null,
  department text,
  location text,
  type text,
  description text,
  status text default 'open',
  analytics_logged boolean default false
);

create table if not exists public.critical_roles (
  id bigint generated by default as identity primary key,
  job_title text not null,
  department text,
  description text,
  created_at timestamptz not null default now()
);

create table if not exists public.job_roles (
  id bigint generated by default as identity primary key,
  code text not null unique,
  title text not null,
  department text,
  is_critical boolean default false,
  status text not null default 'Active' check (status in ('Active', 'Archived'))
);

create table if not exists public.hr_items (
  id bigint generated by default as identity primary key,
  name text,
  status text
);

create table if not exists public.employees (
  id bigint generated by default as identity primary key,
  name text not null,
  email text,
  job_id uuid references public.jobs(id) on delete set null,
  role text default 'employee',
  username text unique,
  password text,
  created_at timestamptz not null default now()
);

create table if not exists public.competencies (
  id bigint generated by default as identity primary key,
  name text not null,
  description text,
  category_id bigint not null references public.competency_categories(id) on delete restrict,
  evaluation_method text default 'manager' check (evaluation_method in ('self', 'manager', 'system')),
  status text default 'Active' check (status in ('Active', 'Archived')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.proficiency_levels (
  id bigint generated by default as identity primary key,
  competency_id bigint not null references public.competencies(id) on delete cascade,
  level smallint not null,
  behavioral_indicators text,
  short_description text,
  constraint uq_proficiency_levels unique (competency_id, level)
);

create table if not exists public.employee_competencies (
  id bigint generated by default as identity primary key,
  employee_id bigint not null references public.employees(id) on delete cascade,
  competency_id bigint not null references public.competencies(id) on delete cascade,
  current_level smallint default 0,
  target_level smallint default 0,
  status text default 'Active' check (status in ('Active', 'Archived')),
  updated_at timestamptz not null default now(),
  rating integer,
  feedback text
);

create table if not exists public.competency_assessments (
  id bigint generated by default as identity primary key,
  employee_competency_id bigint not null references public.employee_competencies(id) on delete cascade,
  assessed_level smallint not null,
  evaluator_role text default 'manager' check (evaluator_role in ('self', 'manager', 'hr', 'system')),
  evaluator_id bigint,
  assessment_date date,
  approved_by_manager boolean default false,
  notes text,
  created_at timestamptz not null default now()
);

create table if not exists public.employee_assessments (
  id bigint generated by default as identity primary key,
  employee_id bigint references public.employees(id) on delete set null,
  competency_id bigint references public.competencies(id) on delete set null,
  self_assessed_level integer,
  assessment_date timestamptz not null default now()
);

create table if not exists public.skill_gap_records (
  id bigint generated by default as identity primary key,
  employee_competency_id bigint not null unique references public.employee_competencies(id) on delete cascade,
  gap integer not null,
  classification text not null check (classification in ('No Gap', 'Minor Gap', 'Moderate Gap', 'Critical Gap')),
  calculated_at timestamptz not null default now(),
  created_at timestamptz not null default now()
);

create table if not exists public.job_competency_requirement (
  id bigint generated by default as identity primary key,
  job_id uuid not null references public.jobs(id) on delete cascade,
  competency_id bigint not null references public.competencies(id) on delete cascade,
  required_level integer not null,
  created_at timestamptz not null default now()
);

create table if not exists public.role_competencies (
  id bigint generated by default as identity primary key,
  role_id bigint not null references public.critical_roles(id) on delete cascade,
  competency_id bigint not null references public.competencies(id) on delete cascade,
  required_level integer not null
);

create table if not exists public.successor_candidates (
  id bigint generated by default as identity primary key,
  role_id bigint not null references public.critical_roles(id) on delete cascade,
  employee_id bigint not null references public.employees(id) on delete cascade,
  readiness_score numeric(5,2) default 0.00,
  readiness_status text,
  created_at timestamptz not null default now()
);

create table if not exists public.trainings (
  id bigint generated by default as identity primary key,
  training_name text,
  description text,
  competency_id bigint references public.competencies(id) on delete set null,
  duration_hours numeric(5,2) default 0.00,
  delivery_method text default 'Blended' check (delivery_method in ('Online', 'In-person', 'Blended')),
  status text default 'Active' check (status in ('Active', 'Inactive', 'Archived')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.courses (
  id bigint generated by default as identity primary key,
  title text not null,
  description text,
  category text,
  duration_hours text,
  content_url text,
  status text default 'Active' check (status in ('Active', 'Inactive')),
  created_at timestamptz not null default now(),
  competency_id bigint references public.competencies(id) on delete set null,
  delivery_method text not null default 'online' check (delivery_method in ('online', 'in-person', 'blended'))
);

create table if not exists public.employee_trainings (
  id bigint generated by default as identity primary key,
  employee_id bigint references public.employees(id) on delete cascade,
  training_id bigint references public.trainings(id) on delete cascade,
  status text,
  assigned_date date,
  completion_date date
);

create table if not exists public.learning_assignments (
  id bigint generated by default as identity primary key,
  employee_id bigint not null references public.employees(id) on delete cascade,
  competency_id bigint not null references public.competencies(id) on delete cascade,
  training_id bigint references public.trainings(id) on delete set null,
  progress_percentage integer default 0,
  status text default 'Pending' check (status in ('Pending', 'In Progress', 'Completed')),
  date_assigned timestamptz not null default now(),
  completion_date timestamptz
);

create table if not exists public.assessment_questions (
  id bigint generated by default as identity primary key,
  training_id bigint references public.trainings(id) on delete cascade,
  question_text text,
  option_a text,
  option_b text,
  option_c text,
  option_d text,
  correct_answer char(1)
);

create table if not exists public.assessment_attempts (
  id bigint generated by default as identity primary key,
  employee_id bigint references public.employees(id) on delete set null,
  training_id bigint references public.trainings(id) on delete set null,
  score_percentage numeric(5,2),
  is_passed boolean,
  attempt_date timestamptz not null default now()
);

create table if not exists public.development_plans (
  id bigint generated by default as identity primary key,
  employee_id bigint not null references public.employees(id) on delete cascade,
  name text,
  created_by bigint references public.employees(id) on delete set null,
  status text default 'Draft' check (status in ('Draft', 'Active', 'Completed', 'Archived')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  competency_id bigint references public.competencies(id) on delete set null,
  training_id bigint references public.trainings(id) on delete set null,
  course_id bigint references public.courses(id) on delete set null
);

create table if not exists public.development_plan_items (
  id bigint generated by default as identity primary key,
  development_plan_id bigint not null references public.development_plans(id) on delete cascade,
  competency_id bigint not null references public.competencies(id) on delete restrict,
  current_level smallint,
  target_level smallint,
  action_plan text
);

create table if not exists public.learning_recommendations (
  id bigint generated by default as identity primary key,
  development_plan_id bigint references public.development_plans(id) on delete set null,
  competency_id bigint references public.competencies(id) on delete set null,
  recommended_course_code text,
  source text default 'System' check (source in ('LMS', 'Training', 'Manual', 'System')),
  confidence numeric(3,2) default 0.75,
  created_at timestamptz not null default now()
);

create table if not exists public.applicants (
  id uuid primary key default gen_random_uuid(),
  full_name text not null,
  email text unique not null,
  training_status text default 'pending',
  training_schedule jsonb default '{}'::jsonb,
  hired_at timestamptz,
  created_at timestamptz default now()
);

create table if not exists public.employee_shifts (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete cascade,
  shift_date date not null,
  start_time time not null,
  end_time time not null,
  shift_type text,
  status text default 'scheduled',
  adjustment_reason text,
  created_at timestamptz default now()
);

create table if not exists public.shift_swaps (
  id uuid primary key default gen_random_uuid(),
  requester_id uuid references public.profiles(id) on delete cascade,
  requested_with_id uuid references public.profiles(id) on delete set null,
  shift_id uuid references public.employee_shifts(id) on delete cascade,
  reason text,
  status text default 'pending',
  created_at timestamptz default now()
);

create table if not exists public.attendance_records (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete cascade,
  shift_id uuid references public.employee_shifts(id) on delete set null,
  clock_in timestamptz,
  clock_out timestamptz,
  total_hours numeric(8,2) default 0,
  status text default 'present',
  created_at timestamptz default now()
);

create table if not exists public.time_sheets (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete cascade,
  week_start date not null,
  week_end date not null,
  total_hours numeric(8,2) default 0,
  overtime_hours numeric(8,2) default 0,
  approval_status text default 'pending',
  created_at timestamptz default now()
);

create table if not exists public.leave_requests (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete cascade,
  leave_type text,
  start_date date not null,
  end_date date not null,
  reason text,
  status text default 'pending',
  created_at timestamptz default now()
);

create table if not exists public.leave_balances (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete cascade,
  leave_type text not null,
  balance_days numeric(8,2) default 0,
  updated_at timestamptz default now(),
  constraint uq_leave_balance unique (user_id, leave_type)
);

create table if not exists public.claims_reimbursement (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete set null,
  claim_type text,
  amount numeric(12,2) default 0,
  receipt_url text,
  status text default 'pending',
  created_at timestamptz default now()
);

create table if not exists public.ar_ap_records (
  id uuid primary key default gen_random_uuid(),
  record_type text,
  reference_no text,
  party_name text,
  amount numeric(15,2) default 0,
  due_date date,
  payment_status text default 'pending',
  created_at timestamptz default now()
);

create table if not exists public.general_ledger (
  id uuid primary key default gen_random_uuid(),
  entry_date date default current_date,
  account_name text,
  description text,
  debit numeric(15,2) default 0,
  credit numeric(15,2) default 0,
  category text,
  created_at timestamptz default now()
);

create table if not exists public.audit_logs (
  id uuid primary key default gen_random_uuid(),
  user_id uuid,
  user_name text,
  action text not null,
  module text,
  details jsonb,
  ip_address text,
  created_at timestamptz default now()
);

create table if not exists service.users (
  id integer generated by default as identity primary key,
  username text unique not null,
  password text not null,
  role text default 'client',
  full_name text,
  created_at timestamptz default now()
);

create table if not exists service.vendors (
  id integer generated by default as identity primary key,
  vendor_name text not null,
  contact_person text,
  email text,
  phone text,
  compliance_docs_url text,
  is_approved boolean default false,
  created_at timestamptz default now()
);

create table if not exists service.procurement (
  id integer generated by default as identity primary key,
  item text not null,
  quantity integer not null,
  status text default 'Pending',
  vendor_id integer references service.vendors(id) on delete set null,
  financial_report_url text,
  payment_status text default 'Unpaid',
  created_at timestamptz default now()
);

create table if not exists service.assets (
  id integer generated by default as identity primary key,
  asset_name text not null,
  asset_tag text unique,
  status text default 'Active',
  location text,
  assigned_to text,
  availability_status text default 'Available',
  maintenance_status text default 'Good',
  created_at timestamptz default now()
);

create table if not exists service.purchase_requests (
  id integer generated by default as identity primary key,
  asset_name text,
  details text,
  requester_id integer references service.users(id) on delete set null,
  status text default 'Pending',
  invoice_url text,
  created_at timestamptz default now()
);

create table if not exists service.warehouse (
  id integer generated by default as identity primary key,
  item_name text not null,
  quantity integer default 0,
  location text,
  phase text,
  pick_list_url text,
  proof_of_delivery_url text,
  quality_assurance_status text,
  created_at timestamptz default now()
);

create table if not exists service.supply_requests (
  id integer generated by default as identity primary key,
  asset_id integer references service.assets(id) on delete set null,
  item_name text,
  quantity integer,
  status text default 'Requested',
  fulfillment_date timestamptz,
  created_at timestamptz default now()
);

create table if not exists service.maintenance_work_orders (
  id integer generated by default as identity primary key,
  asset_id integer references service.assets(id) on delete set null,
  issue_desc text,
  priority text,
  scheduled_date date,
  report_url text,
  completion_date timestamptz,
  status text default 'Open',
  created_at timestamptz default now()
);

create table if not exists service.projects (
  id integer generated by default as identity primary key,
  project_name text not null,
  project_manager text,
  start_date date,
  end_date date,
  phase text,
  status text,
  created_at timestamptz default now()
);

create table if not exists service.asset_assignments (
  id integer generated by default as identity primary key,
  project_id integer references service.projects(id) on delete set null,
  asset_id integer references service.assets(id) on delete set null,
  assignment_date timestamptz default now(),
  return_date timestamptz,
  created_at timestamptz default now()
);

create table if not exists service.fleet (
  id integer generated by default as identity primary key,
  vehicle_name text not null,
  plate_number text unique,
  maintenance_request_status text,
  created_at timestamptz default now()
);

create table if not exists service.facilities (
  id integer generated by default as identity primary key,
  facility_name text not null,
  availability_status text,
  schedule_details text,
  monitoring_data text,
  created_at timestamptz default now()
);

create table if not exists service.vehicle_reservations (
  id integer generated by default as identity primary key,
  vehicle_id integer references service.fleet(id) on delete set null,
  user_id integer references service.users(id) on delete set null,
  usage_report text,
  reservation_date date,
  created_at timestamptz default now()
);

create table if not exists service.audit_logs (
  id integer generated by default as identity primary key,
  user_id integer,
  user_name text,
  action text,
  module text,
  details text,
  ip_address text,
  created_at timestamptz default now()
);

create table if not exists hr1.applications (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  job_id uuid references public.jobs(id) on delete set null,
  applicant_name text not null,
  applicant_email text not null,
  phone text,
  experience text,
  cover_note text,
  status text default 'pending',
  assessment_score integer,
  mentor_id uuid,
  performance_report_sent boolean default false
);

create table if not exists hr1.user_management (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null,
  email text unique not null,
  role text default 'employee',
  department text,
  status text default 'active',
  preferences jsonb default '{}'::jsonb
);

create table if not exists hr1.training_catalog (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  course_name text not null,
  description text,
  resource_url text,
  category text,
  is_onboarding_required boolean default false
);

create table if not exists hr1.onboarding_tasks (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  application_id uuid references hr1.applications(id) on delete cascade,
  task_title text not null,
  assignee_id uuid references hr1.user_management(id) on delete set null,
  category text,
  due_date date,
  status text default 'pending',
  approval_status text default 'pending',
  training_id uuid references hr1.training_catalog(id) on delete set null
);

create table if not exists hr1.training_enrollments (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  user_id uuid references hr1.user_management(id) on delete cascade,
  course_id uuid references hr1.training_catalog(id) on delete cascade,
  mentor_id uuid references hr1.user_management(id) on delete set null,
  status text default 'enrolled',
  progress integer default 0,
  evaluation_score integer
);

create table if not exists hr1.recognitions (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  from_user_id uuid references hr1.user_management(id) on delete set null,
  to_user_id uuid references hr1.user_management(id) on delete set null,
  type text,
  message text,
  points integer default 0,
  visibility text default 'public',
  metadata jsonb default '{}'::jsonb
);

create table if not exists hr1.evaluations (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  employee_id uuid references hr1.user_management(id) on delete cascade,
  evaluator_id uuid references hr1.user_management(id) on delete set null,
  period text,
  scores jsonb default '{}'::jsonb,
  comments text,
  status text default 'pending',
  is_visible_to_employee boolean default true
);

create table if not exists hr1.requisitions (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  job_id uuid references public.jobs(id) on delete cascade,
  department text,
  priority text,
  status text default 'pending',
  requested_by uuid references hr1.user_management(id) on delete set null
);

create table if not exists hr1.document_management (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  user_id uuid references hr1.user_management(id) on delete cascade,
  doc_name text not null,
  doc_type text,
  file_path text,
  version text default '1.0',
  tags text[],
  is_public_in_dept boolean default false
);

create table if not exists hr1.contact_submissions (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text,
  email text,
  message text,
  ip text
);

CREATE TABLE IF NOT EXISTS jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT NOT NULL,
    department TEXT NOT NULL DEFAULT '',
    location TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL DEFAULT '',
    deadline DATE,
    description TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed', 'draft')),
    posted_by TEXT NOT NULL DEFAULT '',
    analytics_logged BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS applications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    applicant_name TEXT NOT NULL,
    applicant_email TEXT NOT NULL,
    phone TEXT,
    job_id UUID REFERENCES jobs(id) ON DELETE SET NULL,
    job_title TEXT NOT NULL DEFAULT '',
    experience TEXT,
    cover_note TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'shortlisted', 'interview', 'hired', 'rejected', 'offered')),
    assessment_score INTEGER,
    decision_note TEXT,
    performance_report_sent BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS user_management (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    role TEXT NOT NULL CHECK (role IN ('hr_admin', 'hr_manager', 'applicant', 'employee')),
    email TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    department TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    preferences JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS requisitions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT NOT NULL,
    department TEXT NOT NULL DEFAULT '',
    headcount SMALLINT NOT NULL DEFAULT 1,
    reason TEXT,
    requested_by TEXT NOT NULL DEFAULT 'HR Manager',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending', 'approved', 'rejected', 'posted_analytics')),
    decision_note TEXT,
    decided_by TEXT,
    decided_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS evaluations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    employee_name TEXT NOT NULL,
    department TEXT NOT NULL DEFAULT '',
    period TEXT NOT NULL DEFAULT '',
    scores JSONB DEFAULT '{}'::jsonb,
    average DECIMAL(4,2) NOT NULL DEFAULT 0,
    comments TEXT,
    evaluated_by TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS goals (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    employee TEXT NOT NULL,
    department TEXT NOT NULL DEFAULT '',
    goal TEXT NOT NULL,
    kpi TEXT NOT NULL DEFAULT '',
    deadline DATE,
    priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('high', 'medium', 'low')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'achieved', 'missed', 'cancelled')),
    set_by TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS onboarding_tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id UUID REFERENCES applications(id) ON DELETE CASCADE,
    task_title TEXT NOT NULL,
    assignee TEXT NOT NULL DEFAULT '',
    category TEXT, -- Training, Administrative, HR4 Core, Recruitment
    due_date DATE,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'in_progress', 'done')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS training_catalog (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    course_name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT '',
    duration TEXT NOT NULL DEFAULT '',
    description TEXT,
    resource_url TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS training_enrollments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    employee_name TEXT NOT NULL,
    course_name TEXT NOT NULL DEFAULT '',
    mentor_name TEXT,
    status TEXT NOT NULL DEFAULT 'enrolled' CHECK (status IN ('enrolled', 'in_progress', 'completed')),
    progress INTEGER NOT NULL DEFAULT 0,
    completed_at DATE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS recognitions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    from_name TEXT NOT NULL,
    to_name TEXT NOT NULL,
    type TEXT, -- Training Completion, Performance Star, Offer, Peer Praise
    message TEXT,
    visibility TEXT NOT NULL DEFAULT 'public' CHECK (visibility IN ('public', 'private')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS document_management (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_name TEXT NOT NULL,
    doc_type TEXT, -- Contract, Performance, Training, ID
    file_path TEXT,
    version TEXT NOT NULL DEFAULT '1.0',
    tags TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS contact_submissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT,
    email TEXT,
    message TEXT,
    ip TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    role TEXT DEFAULT 'client', -- admin, vendor, finance, operator, technician
    full_name TEXT,
    permissions JSONB, -- User Management → Vendor Portal: Credentials and Access Permissions
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vendors (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id), -- Connection to User Management
    vendor_name TEXT NOT NULL,
    contact_person TEXT,
    email TEXT,
    phone TEXT,
    is_approved BOOLEAN DEFAULT FALSE, -- Procurement → Vendor Portal: Send Approved Vendor List
    compliance_docs_url TEXT, -- Vendor Portal → Procurement: Submit Compliance Docs
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS procurement (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    item TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    status TEXT DEFAULT 'Pending',
    vendor_id UUID REFERENCES vendors(id),
    financial_report_url TEXT, -- Procurement → Account Payable & Receivable: Financial Report
    payment_status TEXT DEFAULT 'Unpaid', -- Account Payable & Receivable → Procurement: Payment Process
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS assets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_name TEXT NOT NULL,
    asset_tag TEXT UNIQUE,
    status TEXT DEFAULT 'Active',
    location TEXT,
    assigned_to TEXT,
    is_available BOOLEAN DEFAULT TRUE, -- Asset Management → Vehicle Reservation: Update Asset Availability
    availability_status TEXT DEFAULT 'Available', -- Asset Management → Project Management: Availability Report
    maintenance_status TEXT DEFAULT 'Good',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS fleet (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id UUID REFERENCES assets(id),
    vehicle_name TEXT NOT NULL,
    plate_number TEXT UNIQUE,
    parking_space_id TEXT, -- Facilities Management → Fleet Management: Assign Parking Space
    maintenance_request_status TEXT, -- Fleet Management → MRO: Issue Maintenance Request
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicle_reservations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    vehicle_id UUID REFERENCES fleet(id),
    user_id UUID REFERENCES users(id),
    vendor_id UUID REFERENCES vendors(id), -- Vendor Portal → Vehicle Reservation: Request Vehicle
    usage_report TEXT, -- Vehicle Reservation → Asset Management: Report Asset Usage
    usage_logs TEXT, -- Budget Management → Vehicle Reservation: Usage Reporting Logs
    reservation_date DATE,
    status TEXT DEFAULT 'Requested',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS warehouse (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    item_name TEXT NOT NULL,
    quantity INTEGER DEFAULT 0,
    location TEXT,
    phase TEXT, -- inbound, storage, picking, outbound
    pick_list_url TEXT, -- Warehousing → Document Tracking: Generate Pick-list
    shipping_data JSONB, -- Warehousing → Document Tracking: Verify Shipping & Storage Data
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS document_tracking (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    doc_type TEXT, -- Service Record, POD, Maintenance Log, Collection Record
    file_url TEXT, -- Vendor Portal → Document Tracking: Store Documents
    source_module TEXT, -- Warehousing, Fleet, Financial, etc.
    reference_id UUID, -- Link to specific record
    is_verified BOOLEAN DEFAULT FALSE, -- Document Tracking → Warehousing: Verify Signed POD
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS supply_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id UUID REFERENCES assets(id),
    item_name TEXT,
    quantity INTEGER,
    status TEXT DEFAULT 'Requested', -- Asset Management → Warehousing: Supply Request
    fulfillment_date TIMESTAMP WITH TIME ZONE, -- Warehousing → Asset Management: Acquired Supply
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS maintenance_work_orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id UUID REFERENCES assets(id),
    fleet_id UUID REFERENCES fleet(id),
    facility_id UUID,
    issue_desc TEXT,
    priority TEXT,
    scheduled_date DATE, -- Asset Management → MRO: Maintenance Schedule
    execution_data JSONB, -- MRO → Facilities Management: Maintenance Execution & Monitoring
    report_url TEXT, -- MRO → Asset Management: Maintenance Report
    completion_date TIMESTAMP WITH TIME ZONE, -- MRO → Fleet Management: Confirm Repair Completion
    status TEXT DEFAULT 'Open',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS projects (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_name TEXT NOT NULL,
    project_manager TEXT,
    start_date DATE,
    end_date DATE,
    phase TEXT, -- initiation, planning, execution, monitoring, closing
    status TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS facilities (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    facility_name TEXT NOT NULL,
    availability_status TEXT, -- Facilities Management → Fleet Management: Facility Availability
    schedule_details TEXT, -- Fleet Management → Facilities Management: Facility Schedule
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_management (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    audit_type TEXT, -- Reservation Log, Maintenance Audit
    findings TEXT, -- Audit Management → Fleet Management: Review Audit Findings
    authorization_token TEXT, -- Vehicle Reservation → Audit Management: Audit Authorization
    reference_id UUID,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS general_ledger (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    transaction_history JSONB, -- General Ledger → Audit Management: Financial Transaction History
    compliance_report TEXT, -- General Ledger → Audit Management: Compliance Audit Report
    collection_records JSONB, -- Collection → Document Tracking: Financial Collection Records
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id),
    user_name TEXT,
    action TEXT,
    module TEXT,
    details TEXT,
    ip_address TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS profiles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    full_name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    role VARCHAR(50) DEFAULT 'client',
    permissions JSONB DEFAULT '{}',
    phone VARCHAR(20),
    address TEXT,
    bio TEXT,
    profile_pic VARCHAR(255),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    doc_number VARCHAR(50) UNIQUE,
    title VARCHAR(255),
    category VARCHAR(100),
    type VARCHAR(100),
    sender_name VARCHAR(100),
    file_url VARCHAR(255),
    received_date DATE,
    status VARCHAR(50) DEFAULT 'Received',
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS legal_management (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    document_id UUID REFERENCES documents(id) ON DELETE CASCADE,
    contract_type VARCHAR(100),
    review_status VARCHAR(50) DEFAULT 'under_review',
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS facilities_management (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    facility_name VARCHAR(255) NOT NULL,
    schedule VARCHAR(100),
    is_available BOOLEAN DEFAULT TRUE,
    availability_notes TEXT,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS warehousing (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    facility_id UUID REFERENCES facilities_management(id) ON DELETE SET NULL,
    facility_name VARCHAR(255),
    item_name VARCHAR(255),
    quantity INTEGER DEFAULT 0,
    requested_date DATE DEFAULT CURRENT_DATE,
    status VARCHAR(50) DEFAULT 'pending'
);

CREATE TABLE IF NOT EXISTS warehouse_picklists (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    warehouse_request_id UUID,
    items JSONB DEFAULT '[]',
    generated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50) DEFAULT 'generated'
);

CREATE TABLE IF NOT EXISTS asset_purchase_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id UUID REFERENCES assets(id) ON DELETE SET NULL,
    item_name VARCHAR(255),
    quantity INTEGER,
    specifications TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    requested_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS asset_invoice_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id UUID REFERENCES assets(id) ON DELETE SET NULL,
    vendor_id UUID REFERENCES vendors(id),
    amount DECIMAL(15, 2),
    description TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    requested_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS maintenance_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    facility_id UUID REFERENCES facilities_management(id) ON DELETE SET NULL,
    item VARCHAR(255),
    description TEXT,
    priority VARCHAR(50) DEFAULT 'Medium',
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mro_execution (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    request_id UUID REFERENCES maintenance_requests(id) ON DELETE CASCADE,
    item_name VARCHAR(255),
    execution_details TEXT,
    status VARCHAR(50) DEFAULT 'in_progress',
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS maintenance_schedules (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_id UUID REFERENCES assets(id) ON DELETE CASCADE,
    maintenance_type VARCHAR(100),
    scheduled_date DATE,
    frequency VARCHAR(50),
    status VARCHAR(50) DEFAULT 'scheduled',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS maintenance_reports (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    maintenance_id UUID REFERENCES maintenance_schedules(id),
    report_type VARCHAR(100),
    details TEXT,
    cost DECIMAL(15, 2) DEFAULT 0,
    status VARCHAR(50) DEFAULT 'completed',
    reported_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
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
    source_type VARCHAR(50), -- MANUAL, AR
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

CREATE TABLE IF NOT EXISTS public.compliance_notices (
    id BIGSERIAL PRIMARY KEY,
    notice_type VARCHAR(50) NOT NULL,
    related_module VARCHAR(50) NOT NULL,
    related_record_id BIGINT NOT NULL,
    description TEXT NOT NULL,
    adjustment_required BOOLEAN NOT NULL DEFAULT FALSE,
    adjustment_amount NUMERIC(14,2) DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.payroll_runs (
    id BIGSERIAL PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_gross NUMERIC(14,2) DEFAULT 0,
    total_net NUMERIC(14,2) DEFAULT 0,
    approval_status VARCHAR(20) DEFAULT 'Pending',
    approved_by UUID REFERENCES public.users(id), -- FIXED to UUID
    approved_at TIMESTAMPTZ,
    payment_request_id BIGINT,
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
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.legal_cases ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.new_hires ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.contracts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.incidents ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.facilities_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.assets ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.room_bookings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.maintenance_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.visitors ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.facility_tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.client_agreements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.document_tracking ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.warehousing ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.mro_execution ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.activity_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.generic_module_data ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.competency_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.critical_roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.job_roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.hr_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.employees ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.competencies ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.proficiency_levels ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.employee_competencies ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.competency_assessments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.employee_assessments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.skill_gap_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.job_competency_requirement ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.role_competencies ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.successor_candidates ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.trainings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.courses ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.employee_trainings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.learning_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.assessment_questions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.assessment_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.development_plans ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.development_plan_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.learning_recommendations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.applicants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.employee_shifts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.shift_swaps ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.attendance_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.time_sheets ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.leave_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.leave_balances ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.claims_reimbursement ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ar_ap_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.general_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.vendors ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.procurement ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.assets ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.purchase_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.warehouse ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.supply_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.maintenance_work_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.asset_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.fleet ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.facilities ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.vehicle_reservations ENABLE ROW LEVEL SECURITY;
ALTER TABLE service.audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.applications ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.user_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.training_catalog ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.onboarding_tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.training_enrollments ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.recognitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.evaluations ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.requisitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.document_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE hr1.contact_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE applications ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE requisitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE evaluations ENABLE ROW LEVEL SECURITY;
ALTER TABLE goals ENABLE ROW LEVEL SECURITY;
ALTER TABLE onboarding_tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE training_catalog ENABLE ROW LEVEL SECURITY;
ALTER TABLE training_enrollments ENABLE ROW LEVEL SECURITY;
ALTER TABLE recognitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE contact_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE vendors ENABLE ROW LEVEL SECURITY;
ALTER TABLE procurement ENABLE ROW LEVEL SECURITY;
ALTER TABLE assets ENABLE ROW LEVEL SECURITY;
ALTER TABLE fleet ENABLE ROW LEVEL SECURITY;
ALTER TABLE vehicle_reservations ENABLE ROW LEVEL SECURITY;
ALTER TABLE warehouse ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_tracking ENABLE ROW LEVEL SECURITY;
ALTER TABLE supply_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE maintenance_work_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE facilities ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE general_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE legal_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE facilities_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE warehousing ENABLE ROW LEVEL SECURITY;
ALTER TABLE warehouse_picklists ENABLE ROW LEVEL SECURITY;
ALTER TABLE asset_purchase_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE asset_invoice_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE maintenance_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE mro_execution ENABLE ROW LEVEL SECURITY;
ALTER TABLE maintenance_schedules ENABLE ROW LEVEL SECURITY;
ALTER TABLE maintenance_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ar_ap ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.budget_management ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.collection ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.disbursement ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.client_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.procurement_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.compliance_notices ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payroll_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payroll_run_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payroll_payment_requests ENABLE ROW LEVEL SECURITY;
