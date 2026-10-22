-- ============================================================
-- Migration: रजा व्यवस्थापन (Leave Management System)
-- Attendance System शी थेट जोडलेलं — रजा मंजूर झाली की आपोआप
-- staff_attendance मध्ये "on_leave" म्हणून दिसेल.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(100) NOT NULL COMMENT 'उदा. Casual Leave, Sick Leave, Earned Leave',
  name_local VARCHAR(100) NOT NULL COMMENT 'उदा. प्रासंगिक रजा, आजारपणाची रजा',
  annual_quota DECIMAL(5,1) NOT NULL DEFAULT 12 COMMENT 'वर्षाला किती दिवस मिळतात',
  is_paid TINYINT(1) DEFAULT 1,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- प्रत्येक Staff चा, प्रत्येक Leave-प्रकारासाठी, प्रत्येक वर्षीचा कोटा +
-- वापरलेले दिवस
CREATE TABLE leave_balances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  leave_type_id INT NOT NULL,
  year INT NOT NULL,
  allocated_days DECIMAL(5,1) NOT NULL,
  used_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_type_year (user_id, leave_type_id, year)
) ENGINE=InnoDB;

CREATE TABLE leave_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  leave_type_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_days DECIMAL(5,1) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
