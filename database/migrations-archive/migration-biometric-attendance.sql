-- ============================================================
-- Migration: Biometric Attendance Machine Integration (eSSL/
-- ZKTeco-Style ADMS Push Protocol)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Staff ला Device वरचा PIN Number Assign करण्यासाठी (Device Configuration
-- मध्ये प्रत्येक व्यक्तीला एक Numeric PIN दिलेला असतो — तोच इथे जुळवतो)
ALTER TABLE users ADD COLUMN biometric_pin VARCHAR(20) NULL AFTER employee_code;
ALTER TABLE users ADD UNIQUE KEY uniq_biometric_pin (organization_id, biometric_pin);

CREATE TABLE biometric_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  serial_number VARCHAR(50) NOT NULL,
  device_name VARCHAR(150) NULL COMMENT 'उदा. मुख्य प्रवेशद्वार, Trust Office',
  last_seen_at TIMESTAMP NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_serial (serial_number)
) ENGINE=InnoDB;

-- Device वरून येणाऱ्या प्रत्येक Punch ची Raw नोंद (Processing च्या आधीची)
CREATE TABLE biometric_punch_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_serial VARCHAR(50) NOT NULL,
  biometric_pin VARCHAR(20) NOT NULL,
  punch_time DATETIME NOT NULL,
  verify_mode VARCHAR(10) NULL,
  processed TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pin_time (biometric_pin, punch_time),
  INDEX idx_processed (processed)
) ENGINE=InnoDB;

-- रोजची, Processed Attendance (प्रत्येक Staff साठी, प्रत्येक दिवशी एक नोंद)
CREATE TABLE staff_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  first_punch_time TIME NULL,
  last_punch_time TIME NULL,
  total_punches INT DEFAULT 0,
  status ENUM('present','absent','half_day') DEFAULT 'present',
  source ENUM('biometric','manual') DEFAULT 'biometric',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_date (user_id, attendance_date)
) ENGINE=InnoDB;
