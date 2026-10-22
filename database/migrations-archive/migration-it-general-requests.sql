-- ============================================================
-- Migration: सामान्य IT/Internet Request प्रणाली
-- (Internet/Network, Software, नवीन Hardware, Account/Access, इतर)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE it_general_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  request_code VARCHAR(20) NOT NULL UNIQUE,
  category ENUM('internet_network','software','new_hardware','account_access','printer_scanner','other') NOT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(255) NULL COMMENT 'उदा. प्रयोगशाळा 2, दुसरा मजला',
  status ENUM('open','assigned','in_progress','resolved','closed') DEFAULT 'open',
  assigned_to INT NULL,
  resolution_notes TEXT NULL,
  requested_by INT NOT NULL,
  resolved_by INT NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_status_priority (status, priority)
) ENGINE=InnoDB;

CREATE TABLE it_general_request_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  comment TEXT NOT NULL,
  commented_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES it_general_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (commented_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
