-- ============================================================
-- Migration: Public Work Requests (no-login submission)
-- Anyone can submit a work request to any department from the
-- landing page — it then shows up for that department's HOD/staff.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(50) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  requester_name VARCHAR(255) NOT NULL,
  requester_contact VARCHAR(100) NULL,
  work_description TEXT NOT NULL,
  status ENUM('pending','in_progress','completed') DEFAULT 'pending',
  completed_by INT NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_status (department_id, status)
) ENGINE=InnoDB;
