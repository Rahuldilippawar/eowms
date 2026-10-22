-- ============================================================
-- Migration: SOP Compliance Tracking (Auto-Detect सहित)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE sops (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_by INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE sop_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sop_id INT NOT NULL,
  step_order INT DEFAULT 0,
  step_description VARCHAR(500) NOT NULL,
  frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
  detection_type ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  auto_source_module VARCHAR(50) NULL COMMENT 'daily_work / hostel_attendance / vehicle_trip / maintenance_log',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sop_id) REFERENCES sops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sop_department_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sop_id INT NOT NULL,
  department_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sop_id) REFERENCES sops(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_sop_dept (sop_id, department_id)
) ENGINE=InnoDB;

CREATE TABLE sop_compliance_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sop_step_id INT NOT NULL,
  department_id INT NOT NULL,
  log_date DATE NOT NULL,
  status ENUM('done','not_done') NOT NULL DEFAULT 'not_done',
  completed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sop_step_id) REFERENCES sop_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_step_dept_date (sop_step_id, department_id, log_date)
) ENGINE=InnoDB;
