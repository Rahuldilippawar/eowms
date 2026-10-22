-- ============================================================
-- Migration: Teaching vs Non-Teaching Staff — संपूर्ण System
-- टीप: `users.teaching_mode` (Teaching/Non Teaching/Other) आधीच
-- अस्तित्वात आहे (पण कुठेच वापरलेलं नव्हतं) — तेच वापरतो, नवीन Column
-- नाही.
-- ============================================================

-- 2. Personal Teaching Timetable (शिक्षकाचं स्वतःचं — Virtual Classroom
-- शी Independent, फक्त एक Class/Section Setup असणं गरजेचं नाही)
CREATE TABLE teacher_timetable (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  teacher_id INT NOT NULL,
  day_of_week TINYINT NOT NULL COMMENT '1=Sunday..7=Saturday',
  period_no TINYINT NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  subject_name VARCHAR(150) NOT NULL,
  class_section VARCHAR(100) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Substitute/Cover-Class व्यवस्थापन
CREATE TABLE substitute_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  original_timetable_id INT NULL COMMENT 'teacher_timetable.id — कुठला Period होता',
  original_teacher_id INT NOT NULL,
  substitute_teacher_id INT NOT NULL,
  substitute_date DATE NOT NULL,
  period_no TINYINT NOT NULL,
  subject_name VARCHAR(150) NOT NULL,
  class_section VARCHAR(100) NOT NULL,
  reason VARCHAR(255) NULL,
  assigned_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (original_timetable_id) REFERENCES teacher_timetable(id) ON DELETE SET NULL,
  FOREIGN KEY (original_teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (substitute_teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Syllabus Completion Tracker
CREATE TABLE syllabus_trackers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  teacher_id INT NOT NULL,
  subject_name VARCHAR(150) NOT NULL,
  class_section VARCHAR(100) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  total_chapters INT NOT NULL DEFAULT 0,
  chapters_completed INT NOT NULL DEFAULT 0,
  last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_syllabus (teacher_id, subject_name, class_section, academic_year)
) ENGINE=InnoDB;

-- 5. Academic Qualifications Master (NAAC Documentation साठीही उपयोगी)
CREATE TABLE teacher_qualifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  qualification_type VARCHAR(100) NOT NULL COMMENT 'उदा. PhD, NET, SET, M.Ed',
  specialization VARCHAR(150) NULL,
  institution VARCHAR(255) NULL,
  year_completed YEAR NULL,
  net_set_status ENUM('NET','SET','Both','None') DEFAULT 'None',
  research_papers_count INT DEFAULT 0,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Class Teacher / Coordinator Assignment
CREATE TABLE class_teacher_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  class_section VARCHAR(100) NOT NULL,
  teacher_id INT NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_class_teacher (campus_id, class_section, academic_year)
) ENGINE=InnoDB;

-- 7. Exam Duty / Invigilation Allocation
CREATE TABLE exam_duty_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  exam_name VARCHAR(255) NOT NULL,
  duty_date DATE NOT NULL,
  shift ENUM('morning','afternoon','full_day') DEFAULT 'morning',
  teacher_id INT NOT NULL,
  room_hall VARCHAR(100) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. Student/Parent Feedback (ऐच्छिक — Simple Rating)
CREATE TABLE teacher_feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  rating TINYINT NOT NULL COMMENT '1-5',
  comment TEXT NULL,
  submitted_by_type ENUM('student','parent','self') DEFAULT 'student',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- नवीन Permissions
INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_teaching_academics', 'Manage teaching staff timetable, substitutes, syllabus, exam duty', 'शिक्षक Timetable, Substitute, Syllabus, Exam Duty व्यवस्थापन', 36);
