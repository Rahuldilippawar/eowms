-- ============================================================
-- Migration: विभाग-निहाय Budget व्यवस्थापन (Financial Year आधारित)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE department_budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  financial_year INT NOT NULL COMMENT 'FY सुरु होणारं वर्ष — उदा. 2026 म्हणजे एप्रिल 2026 ते मार्च 2027',
  allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  allocated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_dept_fy (department_id, financial_year)
) ENGINE=InnoDB;

CREATE TABLE budget_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  financial_year INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('नवीन खरेदी','देखभाल','इंधन','इतर') NOT NULL,
  source_module VARCHAR(50) NULL COMMENT 'dead_stock / maintenance / vehicle_fuel / vehicle_maintenance / manual',
  reference_id INT NULL,
  description VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_fy (department_id, financial_year)
) ENGINE=InnoDB;
