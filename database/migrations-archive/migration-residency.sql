-- ============================================================
-- Migration: रहिवासी विभाग — Room-निहाय वास्तव्य नोंद
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- 1) नवीन विभाग जोडा (आपल्या organization_id / campus_id नुसार बदला)
-- उदा. संस्था id=1, कॅम्पस id=1 असेल तर:
INSERT INTO departments (organization_id, campus_id, name, name_local, code, icon, has_issue_register)
VALUES (1, 1, 'Residency', 'रहिवासी विभाग', 'RES', 'fa-solid fa-house-chimney-user', 0);

-- 2) खोलीत कोण राहतं — प्रत्येक व्यक्ती (staff किंवा त्यांचे कुटुंबीय) साठी एक नोंद
CREATE TABLE residency_occupants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  occupant_type ENUM('staff','family') NOT NULL DEFAULT 'staff',
  user_id INT NULL,
  name VARCHAR(255) NULL,
  relation VARCHAR(100) NULL,
  age INT NULL,
  moved_in_date DATE NULL,
  status ENUM('active','vacated') NOT NULL DEFAULT 'active',
  vacated_date DATE NULL,
  vacated_remark TEXT NULL,
  vacated_by INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (vacated_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_location_status (location_id, status)
) ENGINE=InnoDB;

-- 3) खोलीतलं साहित्य — संस्थेचं की वैयक्तिक हे checkbox ने ठरतं
CREATE TABLE residency_room_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  ownership ENUM('संस्थेचे','वैयक्तिक') NOT NULL DEFAULT 'संस्थेचे',
  quantity INT NOT NULL DEFAULT 1,
  remarks TEXT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
