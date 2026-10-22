-- ============================================================
-- Migration: Asset Checkout/Borrowing System
-- (Projector, चावी, इतर वस्तू — कोणी घेतली, कधी परत करणार)
-- ============================================================

CREATE TABLE checkout_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL COMMENT 'उदा. Projector, चावी, Camera',
  default_location VARCHAR(150) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE checkout_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  borrowed_by INT NOT NULL,
  issued_by INT NOT NULL,
  purpose VARCHAR(255) NULL,
  checkout_at DATETIME NOT NULL,
  expected_return_at DATETIME NULL,
  actual_return_at DATETIME NULL,
  returned_to INT NULL,
  remarks VARCHAR(255) NULL,
  FOREIGN KEY (item_id) REFERENCES checkout_items(id) ON DELETE CASCADE,
  FOREIGN KEY (borrowed_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (returned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_checkout', 'Manage asset checkout/borrowing (issue, receive return)', 'Asset Checkout/Borrowing व्यवस्थापन', 37);
