-- database.sql
CREATE DATABASE IF NOT EXISTS dashboard_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dashboard_db;

-- basic tables to hold counts and items for widgets
CREATE TABLE IF NOT EXISTS hr_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  status ENUM('open','in-progress','closed') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS core_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  status ENUM('open','assigned','closed') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS logistic_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  status ENUM('requested','dispatched','delivered') DEFAULT 'requested',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS financial_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  amount DECIMAL(10,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- sample seed data
INSERT INTO hr_items (name, status) VALUES
('HR1 - Onboard A', 'open'),
('HR2 - Training B', 'in-progress'),
('HR3 - Payroll', 'closed'),
('HR4 - Leave Request', 'open');

INSERT INTO core_items (name, status) VALUES
('CORE1 - Case A', 'open'),
('CORE2 - Case B', 'assigned'),
('CORE3 - Case C', 'closed');

INSERT INTO logistic_items (name, status) VALUES
('LOGISTIC1 - Vehicle Request', 'requested'),
('LOGISTIC2 - Spare Parts', 'dispatched'),
('LOGISTIC3 - Delivery', 'delivered');

INSERT INTO financial_items (name, amount) VALUES
('Invoice 001', 12000.50),
('Invoice 002', 4500.00);

INSERT INTO admin_reports (title) VALUES
('Monthly Staffing Report'),
('Incident Analysis Q3');

-- CORE2 Sub-modules Tables
-- Analytics & Reporting
CREATE TABLE IF NOT EXISTS talent_analytics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  metric_name VARCHAR(100),
  value DECIMAL(10,2),
  category VARCHAR(50),
  period VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recruitment_matrix (
  id INT AUTO_INCREMENT PRIMARY KEY,
  position VARCHAR(100),
  department VARCHAR(100),
  status ENUM('open','in-progress','filled','closed') DEFAULT 'open',
  applicants_count INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS monthly_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_title VARCHAR(200),
  report_type VARCHAR(50),
  month_year VARCHAR(20),
  content TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS realtime_dashboard_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  metric_type VARCHAR(50),
  metric_value INT,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Recruitment
CREATE TABLE IF NOT EXISTS job_postings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_title VARCHAR(200),
  department VARCHAR(100),
  location VARCHAR(100),
  status ENUM('draft','published','closed') DEFAULT 'draft',
  description TEXT,
  requirements TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_name VARCHAR(200),
  email VARCHAR(200),
  phone VARCHAR(50),
  position_applied VARCHAR(200),
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT,
  interviewer_name VARCHAR(200),
  interview_date DATETIME,
  interview_type VARCHAR(50),
  status ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
  notes TEXT,
  rating INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS offer_management (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT,
  position VARCHAR(200),
  offer_amount DECIMAL(10,2),
  status ENUM('pending','accepted','rejected','withdrawn') DEFAULT 'pending',
  offer_date DATE,
  response_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Applicant Management
CREATE TABLE IF NOT EXISTS application_tracking (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_name VARCHAR(200),
  position VARCHAR(200),
  status ENUM('applied','screening','interview','offer','hired','rejected') DEFAULT 'applied',
  current_stage VARCHAR(100),
  applied_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS communication_feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT,
  communication_type VARCHAR(50),
  subject VARCHAR(200),
  message TEXT,
  feedback TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidate_evaluations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id INT,
  evaluator_name VARCHAR(200),
  evaluation_date DATE,
  skills_rating INT,
  experience_rating INT,
  cultural_fit_rating INT,
  overall_rating INT,
  comments TEXT,
  recommendation ENUM('hire','maybe','no-hire') DEFAULT 'maybe',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employee Management
CREATE TABLE IF NOT EXISTS evaluation_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT,
  employee_name VARCHAR(200),
  evaluation_period VARCHAR(50),
  evaluator_name VARCHAR(200),
  performance_score DECIMAL(5,2),
  evaluation_date DATE,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS performance_management (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT,
  employee_name VARCHAR(200),
  goal_title VARCHAR(200),
  goal_description TEXT,
  target_date DATE,
  status ENUM('not-started','in-progress','completed','on-hold') DEFAULT 'not-started',
  progress_percentage INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS training_development (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT,
  employee_name VARCHAR(200),
  training_program VARCHAR(200),
  training_type VARCHAR(100),
  status ENUM('scheduled','in-progress','completed','cancelled') DEFAULT 'scheduled',
  start_date DATE,
  end_date DATE,
  completion_percentage INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- New Hired on Board
CREATE TABLE IF NOT EXISTS list_of_hired (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_name VARCHAR(200),
  position VARCHAR(200),
  department VARCHAR(100),
  hire_date DATE,
  status ENUM('pending-onboarding','onboarding','completed') DEFAULT 'pending-onboarding',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orientation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT,
  employee_name VARCHAR(200),
  orientation_date DATE,
  status ENUM('scheduled','in-progress','completed') DEFAULT 'scheduled',
  orientation_type VARCHAR(100),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS task_overflow (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT,
  employee_name VARCHAR(200),
  task_title VARCHAR(200),
  task_description TEXT,
  assigned_date DATE,
  due_date DATE,
  status ENUM('pending','in-progress','completed','overdue') DEFAULT 'pending',
  priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS training_mentorship (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT,
  employee_name VARCHAR(200),
  mentor_name VARCHAR(200),
  program_type VARCHAR(100),
  status ENUM('assigned','active','completed') DEFAULT 'assigned',
  start_date DATE,
  end_date DATE,
  progress_notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);