-- Final SQL for Supabase (PostgreSQL) Setup
-- This script creates the necessary tables for handling requests from CORE, HR, and Logistics.

-- Optional: Uncomment the line below to reset the schema during development.
-- DROP TABLE IF EXISTS service_requests, hr_requests, logistic_requests CASCADE;

-- This function will be triggered on any update to a row and set the `updated_at` column to the current time.
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
   NEW.updated_at = NOW();
   RETURN NEW;
END;
$$ language 'plpgsql';


-- 1. Create service_requests table for CORE/Financial
CREATE TABLE IF NOT EXISTS service_requests (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Trigger to automatically update `updated_at` on row change
CREATE OR REPLACE TRIGGER update_service_requests_updated_at
BEFORE UPDATE ON service_requests
FOR EACH ROW
EXECUTE FUNCTION update_updated_at_column();


-- 2. Create hr_requests table for HR
CREATE TABLE IF NOT EXISTS hr_requests (
  id SERIAL PRIMARY KEY,
  request_details VARCHAR(255) NOT NULL,
  department VARCHAR(100) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Trigger for hr_requests
CREATE OR REPLACE TRIGGER update_hr_requests_updated_at
BEFORE UPDATE ON hr_requests
FOR EACH ROW
EXECUTE FUNCTION update_updated_at_column();


-- 3. Create logistic_requests table for Logistics
CREATE TABLE IF NOT EXISTS logistic_requests (
  id SERIAL PRIMARY KEY,
  item_name VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  destination VARCHAR(255) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Trigger for logistic_requests
CREATE OR REPLACE TRIGGER update_logistic_requests_updated_at
BEFORE UPDATE ON logistic_requests
FOR EACH ROW
EXECUTE FUNCTION update_updated_at_column();


-- 4. Clear existing data and insert fresh sample data
TRUNCATE TABLE service_requests, hr_requests, logistic_requests RESTART IDENTITY;

INSERT INTO service_requests (name, amount) VALUES
('System Installation - Client A', 15000.00),
('Monthly Maintenance - Corp B', 2500.50);

INSERT INTO hr_requests (request_details, department) VALUES
('Onboard New Employee', 'Sales');

INSERT INTO logistic_requests (item_name, quantity, destination) VALUES
('Vehicle for Site Visit', 1, 'Makati Office');

