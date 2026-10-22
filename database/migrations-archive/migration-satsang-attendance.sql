-- ============================================================
-- Migration: सत्संग हजेरी (Satsang Attendance)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE satsang_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  satsang_event_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('present','absent') NOT NULL DEFAULT 'absent',
  remark VARCHAR(255) NULL,
  marked_by INT NULL,
  marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (satsang_event_id) REFERENCES satsang_events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_event_user (satsang_event_id, user_id)
) ENGINE=InnoDB;
