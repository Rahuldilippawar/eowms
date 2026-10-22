-- ============================================================
-- Migration: पत्र व्यवहार (File/Letter Movement Workflow)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE letter_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  name_local VARCHAR(150) NULL,
  is_active TINYINT(1) DEFAULT 1,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE workflow_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  category_id INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES letter_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE workflow_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workflow_template_id INT NOT NULL,
  step_order INT NOT NULL,
  step_name VARCHAR(150) NOT NULL,
  approver_type ENUM('department_hod','specific_user','letter_dept_hod') NOT NULL,
  department_id INT NULL,
  user_id INT NULL,
  FOREIGN KEY (workflow_template_id) REFERENCES workflow_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE letters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  file_number VARCHAR(60) NOT NULL UNIQUE,
  subject VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category_id INT NULL,
  department_id INT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status ENUM('draft','in_progress','returned','completed') DEFAULT 'draft',
  workflow_template_id INT NULL,
  current_step_order INT DEFAULT 0,
  current_holder_id INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES letter_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (workflow_template_id) REFERENCES workflow_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (current_holder_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_holder (current_holder_id),
  INDEX idx_org_status (organization_id, status)
) ENGINE=InnoDB;

CREATE TABLE letter_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  action_type ENUM('created','submitted','forwarded','returned','remark_added','completed') NOT NULL,
  from_user_id INT NULL,
  to_user_id INT NULL,
  remark TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE letter_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
