-- ============================================================
-- Migration: Virtual Classroom System (College)
-- Student Master + Classroom + Allocation + Teacher Assignment +
-- Study Material + Assignments — वेगळा Student Login Portal सह
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- विद्यार्थी Master — College च्या सगळ्या विद्यार्थ्यांचं मूळ Database
CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  roll_no VARCHAR(30) NOT NULL,
  admission_no VARCHAR(50) NOT NULL COMMENT 'Login साठी वापरतो — संपूर्ण संस्थेत Unique',
  name VARCHAR(255) NOT NULL,
  year_of_study TINYINT NOT NULL COMMENT '1,2,3,4',
  academic_year VARCHAR(20) NOT NULL COMMENT 'उदा. 2026-27',
  mobile VARCHAR(15) NULL,
  parent_mobile VARCHAR(15) NULL,
  email VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) DEFAULT 1,
  failed_login_attempts INT DEFAULT 0,
  locked_until DATETIME NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_roll (campus_id, department_id, academic_year, roll_no),
  UNIQUE KEY uniq_admission_no (organization_id, admission_no)
) ENGINE=InnoDB;

CREATE TABLE virtual_classrooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  name VARCHAR(255) NOT NULL COMMENT 'उदा. FY B.Com - A',
  year_of_study TINYINT NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE classroom_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  student_id INT NOT NULL,
  allocated_by INT NULL,
  allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_classroom_student (classroom_id, student_id)
) ENGINE=InnoDB;

CREATE TABLE classroom_staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  user_id INT NOT NULL,
  subject_name VARCHAR(150) NOT NULL,
  assigned_by INT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE classroom_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  uploaded_by INT NOT NULL,
  subject_name VARCHAR(150) NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE classroom_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  created_by INT NOT NULL,
  subject_name VARCHAR(150) NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  attachment_path VARCHAR(255) NULL,
  due_date DATE NOT NULL,
  max_marks DECIMAL(6,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE classroom_assignment_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  assignment_id INT NOT NULL,
  student_id INT NOT NULL,
  file_path VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL,
  submission_note TEXT NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  marks_obtained DECIMAL(6,2) NULL,
  feedback VARCHAR(500) NULL,
  graded_by INT NULL,
  graded_at TIMESTAMP NULL,
  FOREIGN KEY (assignment_id) REFERENCES classroom_assignments(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_assignment_student (assignment_id, student_id)
) ENGINE=InnoDB;
