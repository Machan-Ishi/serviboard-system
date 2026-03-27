-- Simple capstone-friendly MySQL schema for HR requests reviewed by Finance

CREATE TABLE IF NOT EXISTS departments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150),
    department VARCHAR(120),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(40) UNIQUE,
    request_date DATE DEFAULT CURRENT_DATE,
    request_type VARCHAR(80) NOT NULL,
    employee_id BIGINT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    department_id BIGINT,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    amount NUMERIC(14,2),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS request_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL,
    request_id BIGINT NOT NULL,
    request_code VARCHAR(40),
    action VARCHAR(20) NOT NULL,
    status VARCHAR(30) NOT NULL,
    remarks TEXT,
    acted_by VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional foreign keys if your project already uses these tables
-- ALTER TABLE hr_requests
--     ADD CONSTRAINT fk_hr_requested_employee
--     FOREIGN KEY (employee_id) REFERENCES employees(id);
--
-- ALTER TABLE hr_requests
--     ADD CONSTRAINT fk_hr_department
--     FOREIGN KEY (department_id) REFERENCES departments(id);
