-- ============================================================
-- Migration: गौशाळा (Gaushala) Module
-- ============================================================

-- 1. गाय/जनावर Registry (मुख्य)
CREATE TABLE gaushala_cattle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  tag_number VARCHAR(50) NOT NULL,
  name VARCHAR(150) NULL,
  breed VARCHAR(100) NULL,
  gender ENUM('Male','Female') NOT NULL,
  color VARCHAR(100) NULL,
  date_of_birth DATE NULL,
  acquisition_type ENUM('Birth','Donation','Purchase','Other') DEFAULT 'Other',
  acquisition_date DATE NULL,
  source_details VARCHAR(255) NULL COMMENT 'कोणाकडून आलं — दानदाता/विक्रेता',
  mother_cattle_id INT NULL,
  caretaker_id INT NULL,
  photo_path VARCHAR(255) NULL,
  status ENUM('active','sold','deceased','donated_out') DEFAULT 'active',
  status_date DATE NULL,
  status_remarks VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (mother_cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (caretaker_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_tag (organization_id, tag_number)
) ENGINE=InnoDB;

-- 2. आरोग्य नोंदी
CREATE TABLE gaushala_health_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL,
  record_type ENUM('Vaccination','Illness','Treatment','Checkup','Deworming') DEFAULT 'Checkup',
  description TEXT NOT NULL,
  vet_name VARCHAR(150) NULL,
  record_date DATE NOT NULL,
  next_due_date DATE NULL,
  cost DECIMAL(10,2) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. दूध उत्पादन
CREATE TABLE gaushala_milk_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL,
  record_date DATE NOT NULL,
  session ENUM('Morning','Evening') NOT NULL,
  quantity_liters DECIMAL(5,2) NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_milk_entry (cattle_id, record_date, session)
) ENGINE=InnoDB;

-- 4. चारा व्यवस्थापन (Fodder)
CREATE TABLE gaushala_fodder_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(30) DEFAULT 'Kg',
  current_stock DECIMAL(10,2) DEFAULT 0,
  reorder_level DECIMAL(10,2) DEFAULT 0,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gaushala_fodder_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fodder_type_id INT NOT NULL,
  transaction_type ENUM('in','out') NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  transaction_date DATE NOT NULL,
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (fodder_type_id) REFERENCES gaushala_fodder_types(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. प्रजनन नोंदी (Breeding)
CREATE TABLE gaushala_breeding_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL COMMENT 'मादी (Female) — गाभण गाय',
  breeding_date DATE NOT NULL,
  breeding_method ENUM('Natural','Artificial Insemination') DEFAULT 'Natural',
  expected_calving_date DATE NULL,
  actual_calving_date DATE NULL,
  calf_cattle_id INT NULL COMMENT 'जन्मलेलं वासरू — नवीन Registry-Entry शी Link',
  status ENUM('pregnant','delivered','miscarriage') DEFAULT 'pregnant',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (calf_cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. दान/Sponsorship
CREATE TABLE gaushala_sponsorships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NULL COMMENT 'NULL असेल तर — संपूर्ण गौशाळेसाठी सर्वसाधारण देणगी',
  organization_id INT NOT NULL,
  donor_name VARCHAR(255) NOT NULL,
  donor_contact VARCHAR(50) NULL,
  amount DECIMAL(10,2) NOT NULL,
  sponsorship_period ENUM('One-Time','Monthly','Yearly') DEFAULT 'One-Time',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('active','completed','cancelled') DEFAULT 'active',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_gaushala', 'Manage Gaushala — cattle registry, health, milk, fodder, breeding, sponsorship', 'गौशाळा व्यवस्थापन', 44);
