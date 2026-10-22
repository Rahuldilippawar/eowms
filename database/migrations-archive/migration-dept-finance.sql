-- ============================================================
-- Migration: विभाग-निहाय Material Purchase + Shared Payment Ledger
-- + Recurring Monthly Bill (Internet सारखे मासिक Payment-Contracts)
-- ============================================================

-- 1. Material Purchase — HOD थेट नोंदवतो
CREATE TABLE dept_material_purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  material_name VARCHAR(255) NOT NULL,
  vendor_name VARCHAR(255) NOT NULL,
  vendor_contact VARCHAR(100) NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit VARCHAR(50) NULL,
  rate DECIMAL(12,2) NOT NULL,
  base_amount DECIMAL(12,2) NOT NULL COMMENT 'Quantity x Rate',
  transport_cost DECIMAL(12,2) DEFAULT 0,
  gst_percent DECIMAL(5,2) DEFAULT 0,
  gst_amount DECIMAL(12,2) DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL COMMENT 'base + transport + gst',
  purchase_date DATE NOT NULL,
  remarks VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. सामायिक Payment Ledger — Material Purchase, Renewal, Maintenance
-- तिन्हींना जोडतो (Polymorphic — source_type + source_id, DB-Level FK
-- नाही कारण ते 3 वेगवेगळ्या Tables कडे जातं; Application-Level तपासतो)
CREATE TABLE dept_payment_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  source_type ENUM('material_purchase','renewal','maintenance') NOT NULL,
  source_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_type ENUM('Advance','Partial','Final') DEFAULT 'Partial',
  payment_mode VARCHAR(50) NULL COMMENT 'उदा. Cash, Cheque, Online',
  for_month DATE NULL COMMENT 'Recurring-Bill असेल तर — कोणत्या महिन्याचं Payment (महिन्याची 1 तारीख साठवतो)',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_source (source_type, source_id)
) ENGINE=InnoDB;

-- 3. Renewal-Items ला Recurring-Monthly-Bill Support जोडतो (Internet,
-- Monthly Subscription सारख्या — वार्षिक Contract, पण Payment दर महिन्याला)
ALTER TABLE renewal_items ADD COLUMN is_recurring_monthly TINYINT(1) DEFAULT 0 AFTER renewal_cost;
ALTER TABLE renewal_items ADD COLUMN monthly_amount DECIMAL(10,2) NULL AFTER is_recurring_monthly;
ALTER TABLE renewal_items ADD COLUMN recurring_start_month DATE NULL COMMENT 'कोणत्या महिन्यापासून सुरू — महिन्याची 1 तारीख साठवतो' AFTER monthly_amount;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_dept_finance', 'Record department material purchases and track payments', 'विभाग Material Purchase व Payment Tracking', 45);
