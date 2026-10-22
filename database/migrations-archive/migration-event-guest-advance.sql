-- ============================================================
-- Migration: Event/Guest Management — Advance Features
-- (Recurring Events, Attendance, Certificate, Guest Photo,
-- Pre-Registration, वारंवार येणारे पाहुणे)
-- ============================================================

ALTER TABLE events ADD COLUMN is_recurring TINYINT(1) DEFAULT 0 AFTER status;
ALTER TABLE events ADD COLUMN recurrence_pattern ENUM('yearly','monthly') NULL AFTER is_recurring;
ALTER TABLE events ADD COLUMN parent_event_id INT NULL AFTER recurrence_pattern COMMENT 'मूळ Recurring Event — पुढच्या वर्षीच्या Auto-Created Event साठी';
ALTER TABLE events ADD CONSTRAINT fk_events_parent FOREIGN KEY (parent_event_id) REFERENCES events(id) ON DELETE SET NULL;

CREATE TABLE event_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  attendee_type ENUM('staff','student','guest') NOT NULL,
  user_id INT NULL COMMENT 'Staff असेल तर — users.id',
  student_id INT NULL COMMENT 'Student असेल तर — students.id',
  guest_name VARCHAR(255) NULL COMMENT 'बाहेरचा पाहुणा असेल, Staff/Student नसेल तर — फक्त नाव',
  marked_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE guests ADD COLUMN photo_path VARCHAR(255) NULL AFTER pass_code;
ALTER TABLE guests ADD COLUMN pre_registration_id INT NULL AFTER photo_path;

CREATE TABLE guest_pre_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  guest_organization VARCHAR(255) NULL,
  purpose VARCHAR(255) NULL,
  meeting_with INT NULL,
  expected_date DATE NOT NULL,
  registration_code VARCHAR(20) NOT NULL,
  status ENUM('pending','arrived','no_show') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (meeting_with) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_reg_code (registration_code)
) ENGINE=InnoDB;

ALTER TABLE guests ADD CONSTRAINT fk_guests_prereg FOREIGN KEY (pre_registration_id) REFERENCES guest_pre_registrations(id) ON DELETE SET NULL;
