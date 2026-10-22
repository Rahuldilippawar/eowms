-- ============================================================
-- Migration: Hostel-wise Student & Staff Allocation
-- Lets Super Admin add multiple physical Hostels under the
-- "Hostel" department, allocate staff to each, and track
-- students per hostel (school, class, district).
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE hostels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  capacity INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hostel_staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  user_id INT NOT NULL,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_hostel_staff (hostel_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE hostel_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  school_name VARCHAR(255) NULL,
  class_grade VARCHAR(50) NULL,
  district VARCHAR(100) NULL,
  gender ENUM('मुलगा','मुलगी') NULL,
  admission_date DATE NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  INDEX idx_hostel (hostel_id)
) ENGINE=InnoDB;
