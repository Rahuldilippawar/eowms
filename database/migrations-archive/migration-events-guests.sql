-- ============================================================
-- Migration: Event Management + Guest Management + Residency-Staff
-- Link Integrity — सगळं Central `users`/`departments` Table शीच Link,
-- कुठेही Duplicate Staff Entity तयार होणार नाही.
-- ============================================================

-- 🎉 कार्यक्रम व्यवस्थापन
CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  event_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  venue VARCHAR(255) NULL,
  responsible_user_id INT NULL COMMENT 'users.id — जबाबदार व्यक्ती, Duplicate Entity नाही',
  status ENUM('planned','ongoing','completed','cancelled') DEFAULT 'planned',
  budget_estimated DECIMAL(12,2) NULL,
  budget_actual DECIMAL(12,2) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  assigned_to INT NULL COMMENT 'users.id',
  due_date DATE NULL,
  status ENUM('pending','in_progress','done') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE event_budget_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  item_description VARCHAR(255) NOT NULL,
  estimated_amount DECIMAL(12,2) NULL,
  actual_amount DECIMAL(12,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(255) NULL,
  uploaded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 🎫 पाहुणे व्यवस्थापन (Trust-Level Guests — Residency च्या
-- residency_guests पेक्षा वेगळं, ते फक्त वसतिगृह/निवासस्थानाच्या पाहुण्यांसाठी
-- होतं; हे संपूर्ण संस्थेत येणाऱ्या सगळ्या पाहुण्यांसाठी)
CREATE TABLE guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  mobile VARCHAR(15) NULL,
  guest_organization VARCHAR(255) NULL,
  purpose VARCHAR(255) NULL,
  meeting_with INT NULL COMMENT 'users.id — कोणाला भेटायला आले, Duplicate Entity नाही',
  check_in_time DATETIME NOT NULL,
  check_out_time DATETIME NULL,
  pass_code VARCHAR(20) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (meeting_with) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- नवीन Permissions
INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_events', 'Create and manage events', 'कार्यक्रम व्यवस्थापन', 34),
('manage_guests', 'Log and manage visitor/guest entries', 'पाहुणे नोंदणी व्यवस्थापन', 35);
