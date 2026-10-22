-- ============================================================
-- Migration: Virtual Classroom — Advance Features
-- (Announcements, Notifications, Attendance, Marks, Timetable, Q&A)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE classroom_announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  created_by INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- विद्यार्थ्यांसाठी स्वतंत्र Notification System (users.notifications शी
-- मिसळत नाही — students वेगळ्या Table मध्ये असल्याने)
CREATE TABLE student_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  message VARCHAR(500) NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE classroom_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  student_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  subject_name VARCHAR(150) NULL,
  status ENUM('present','absent') DEFAULT 'present',
  marked_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_student_date_subject (student_id, attendance_date, subject_name)
) ENGINE=InnoDB;

CREATE TABLE classroom_exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  name VARCHAR(150) NOT NULL COMMENT 'उदा. Unit Test 1',
  subject_name VARCHAR(150) NOT NULL,
  max_marks DECIMAL(6,2) NOT NULL,
  exam_date DATE NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE classroom_exam_marks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  marks_obtained DECIMAL(6,2) NULL,
  remarks VARCHAR(255) NULL,
  entered_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (exam_id) REFERENCES classroom_exams(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_exam_student (exam_id, student_id)
) ENGINE=InnoDB;

CREATE TABLE classroom_timetable (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  day_of_week TINYINT NOT NULL COMMENT '0=रविवार ... 6=शनिवार',
  period_no TINYINT NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  subject_name VARCHAR(150) NOT NULL,
  teacher_id INT NULL,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_slot (classroom_id, day_of_week, period_no)
) ENGINE=InnoDB;

CREATE TABLE classroom_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  student_id INT NOT NULL,
  subject_name VARCHAR(150) NULL,
  question_text TEXT NOT NULL,
  status ENUM('open','answered') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE classroom_question_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  replied_by INT NOT NULL,
  reply_text TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (question_id) REFERENCES classroom_questions(id) ON DELETE CASCADE,
  FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
