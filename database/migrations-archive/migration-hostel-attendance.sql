-- ============================================================
-- Migration: वसतिगृह विद्यार्थी हजेरी (3 वेळा दैनिक)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE hostel_student_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  student_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  session ENUM('morning','afternoon','evening') NOT NULL,
  status ENUM('present','absent') NOT NULL DEFAULT 'present',
  remark VARCHAR(255) NULL,
  marked_by INT NULL,
  marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_student_date_session (student_id, attendance_date, session),
  INDEX idx_hostel_date (hostel_id, attendance_date)
) ENGINE=InnoDB;
