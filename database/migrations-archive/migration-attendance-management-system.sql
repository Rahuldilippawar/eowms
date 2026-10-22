-- ============================================================
-- Migration: संपूर्ण Attendance Management System
-- (Shift-Timing, Holiday Master, Manual Entry, Regularization,
-- Late/Early Detection, Auto-Absent Marking)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- प्रत्येक Staff ची Shift-वेळ + आठवड्याची सुट्टी — यावरूनच Late/Early
-- आणि Absent आपोआप ठरवलं जातं
CREATE TABLE staff_shift_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  shift_start TIME NOT NULL DEFAULT '09:30:00',
  shift_end TIME NOT NULL DEFAULT '17:30:00',
  grace_minutes INT NOT NULL DEFAULT 15 COMMENT 'इतक्या मिनिटांपर्यंत उशीर चालेल, Late मानलं जाणार नाही',
  half_day_hours DECIMAL(4,2) NOT NULL DEFAULT 4.00 COMMENT 'इतक्या तासांपेक्षा कमी असेल तर Half-Day',
  weekly_off_1 TINYINT NULL COMMENT '0=रविवार ... 6=शनिवार',
  weekly_off_2 TINYINT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- सुट्ट्यांचं Master — या दिवशी कोणालाही Absent म्हणून धरलं जाणार नाही
CREATE TABLE holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL COMMENT 'NULL = सगळ्या Campus साठी लागू',
  holiday_date DATE NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_holiday (organization_id, campus_id, holiday_date)
) ENGINE=InnoDB;

-- Biometric चुकलं/Punch राहिला तर — Staff स्वतः दुरुस्तीची विनंती करू
-- शकतो, HOD Approve/Reject करतो
CREATE TABLE attendance_regularization_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  requested_status ENUM('present','half_day') NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- staff_attendance मध्ये Late/Early-Leaving + Holiday चिन्हांकित करण्यासाठी
-- नवीन Columns
ALTER TABLE staff_attendance MODIFY COLUMN status ENUM('present','absent','half_day','holiday','on_leave') DEFAULT 'present';
ALTER TABLE staff_attendance ADD COLUMN is_late TINYINT(1) DEFAULT 0 AFTER status;
ALTER TABLE staff_attendance ADD COLUMN is_early_leave TINYINT(1) DEFAULT 0 AFTER is_late;
ALTER TABLE staff_attendance ADD COLUMN remarks VARCHAR(255) NULL AFTER source;
