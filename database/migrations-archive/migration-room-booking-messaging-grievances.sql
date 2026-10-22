-- ============================================================
-- Migration: Room Booking, Internal Messaging, Grievance System
-- (मूळ "6 Modules" पैकी Document-Expiry, Custom-Forms, AMC —
-- वापरकर्त्याच्या विनंतीनुसार काढले गेले)
-- ============================================================

-- 1. Room/Hall Booking System
CREATE TABLE bookable_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  room_type VARCHAR(100) NULL COMMENT 'उदा. Meeting Room, Auditorium, Lab',
  capacity INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  booked_by INT NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  start_time DATETIME NOT NULL,
  end_time DATETIME NOT NULL,
  status ENUM('confirmed','cancelled') DEFAULT 'confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (room_id) REFERENCES bookable_rooms(id) ON DELETE CASCADE,
  FOREIGN KEY (booked_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Internal Staff Messaging (Simple 1-to-1)
CREATE TABLE staff_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  recipient_id INT NOT NULL,
  message_text TEXT NOT NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_conversation (sender_id, recipient_id, created_at)
) ENGINE=InnoDB;

-- 3. Grievance / औपचारिक तक्रार निवारण
CREATE TABLE grievances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  submitted_by INT NOT NULL,
  category VARCHAR(100) NULL,
  description TEXT NOT NULL,
  is_anonymous TINYINT(1) DEFAULT 0,
  status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
  assigned_to INT NULL,
  resolution TEXT NULL,
  sla_deadline DATE NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- नवीन Permissions
INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_room_booking', 'Manage bookable rooms', 'Room/Hall Booking व्यवस्थापन', 40),
('manage_grievances', 'View and resolve grievances', 'तक्रार निवारण व्यवस्थापन', 42);
