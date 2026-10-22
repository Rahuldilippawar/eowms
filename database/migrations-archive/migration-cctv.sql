-- ============================================================
-- Migration: CCTV विभाग — DVR/Camera Master, Checkup, Reports
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE cctv_dvrs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  dvr_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  channel_capacity INT NULL COMMENT 'DVR किती Cameras support करतो',
  storage_capacity_tb DECIMAL(6,2) NULL COMMENT 'एकूण Storage क्षमता (TB मध्ये)',
  installed_date DATE NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE cctv_cameras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  dvr_id INT NOT NULL,
  camera_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL COMMENT 'उदा. मुख्य प्रवेशद्वार, वर्ग क्र. 5',
  camera_type ENUM('dome','bullet','ptz','other') DEFAULT 'dome',
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (dvr_id) REFERENCES cctv_dvrs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cctv_checkups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  dvr_id INT NOT NULL,
  checkup_date DATE NOT NULL,
  storage_used_percent DECIMAL(5,2) NULL,
  footage_days_available INT NULL COMMENT 'सध्याचं Footage किती दिवसांचं मागे उपलब्ध आहे',
  backup_done TINYINT(1) DEFAULT 0,
  backup_location VARCHAR(255) NULL COMMENT 'उदा. External HDD / Cloud',
  remarks TEXT NULL,
  checked_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (dvr_id) REFERENCES cctv_dvrs(id) ON DELETE CASCADE,
  FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_dvr_date (dvr_id, checkup_date)
) ENGINE=InnoDB;

CREATE TABLE cctv_camera_status (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkup_id INT NOT NULL,
  camera_id INT NOT NULL,
  status ENUM('working','not_working','needs_attention') DEFAULT 'working',
  remarks VARCHAR(255) NULL,
  FOREIGN KEY (checkup_id) REFERENCES cctv_checkups(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cctv_cameras(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_checkup_camera (checkup_id, camera_id)
) ENGINE=InnoDB;
