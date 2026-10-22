-- ============================================================
-- Migration: Dynamic Daily-Work Custom Fields
-- Lets a Super Admin add organization-specific fields to the
-- Daily Work entry form (since every organization's rules differ).
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE custom_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  field_label VARCHAR(255) NOT NULL,
  field_type ENUM('text','textarea','number','date','select','checkbox') DEFAULT 'text',
  field_options TEXT NULL,
  is_required TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_org_key (organization_id, field_key)
) ENGINE=InnoDB;

CREATE TABLE custom_field_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  field_value TEXT NULL,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  INDEX idx_work_record (work_record_id)
) ENGINE=InnoDB;
