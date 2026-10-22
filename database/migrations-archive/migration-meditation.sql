-- ============================================================
-- Migration: ध्यान व सत्संग (Meditation & Satsang) Module
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE satsang_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  event_date DATE NOT NULL,
  organizing_department_id INT NULL,
  theme VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (organizing_department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE satsang_duties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  satsang_event_id INT NOT NULL,
  assigned_to INT NOT NULL,
  duty_description VARCHAR(255) NOT NULL,
  status ENUM('pending','completed') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (satsang_event_id) REFERENCES satsang_events(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE meditation_quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  quote_text TEXT NOT NULL,
  author VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
