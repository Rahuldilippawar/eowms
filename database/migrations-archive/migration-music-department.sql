-- ============================================================
-- Migration: Music Department — संपूर्ण Module
-- (वाद्य Inventory, सराव-खोली Booking, विद्यार्थी+हजेरी, परीक्षा,
-- कार्यक्रम, Sheet Music Library)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE music_instruments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  instrument_name VARCHAR(255) NOT NULL,
  instrument_type VARCHAR(100) NULL COMMENT 'उदा. तालवाद्य, तंतुवाद्य, सुषिरवाद्य',
  brand_model VARCHAR(255) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(10,2) NULL,
  condition_status ENUM('excellent','good','needs_tuning','under_repair','damaged') DEFAULT 'good',
  last_tuned_date DATE NULL,
  current_holder_id INT NULL COMMENT 'सध्या कोणाकडे आहे (Staff/Student)',
  remarks TEXT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (current_holder_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE music_instrument_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instrument_id INT NOT NULL,
  action_type ENUM('issued','returned','tuned','repaired') NOT NULL,
  holder_name VARCHAR(255) NULL,
  remarks TEXT NULL,
  logged_by INT NULL,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (instrument_id) REFERENCES music_instruments(id) ON DELETE CASCADE,
  FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE music_room_bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL COMMENT 'maintenance_locations कडे Reference',
  booked_by INT NOT NULL,
  booking_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  purpose VARCHAR(255) NULL,
  status ENUM('confirmed','cancelled') DEFAULT 'confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (booked_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE music_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  contact VARCHAR(50) NULL,
  guardian_name VARCHAR(255) NULL,
  admission_date DATE NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE music_enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject VARCHAR(150) NOT NULL COMMENT 'उदा. गायन, तबला, हार्मोनियम, गिटार',
  teacher_id INT NULL,
  start_date DATE NOT NULL,
  status ENUM('active','completed','discontinued') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES music_students(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE music_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent') NOT NULL,
  marked_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (enrollment_id) REFERENCES music_enrollments(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_enroll_date (enrollment_id, attendance_date)
) ENGINE=InnoDB;

CREATE TABLE music_exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  exam_board VARCHAR(150) NOT NULL COMMENT 'उदा. गांधर्व महाविद्यालय, Trinity',
  grade_level VARCHAR(100) NULL,
  subject VARCHAR(150) NULL,
  exam_date DATE NOT NULL,
  result ENUM('pending','pass','distinction','fail') DEFAULT 'pending',
  marks VARCHAR(50) NULL,
  certificate_path VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES music_students(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE music_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  event_type ENUM('concert','competition','annual_function','other') DEFAULT 'other',
  event_date DATE NOT NULL,
  venue VARCHAR(255) NULL,
  description TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE music_event_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  student_id INT NOT NULL,
  performance_item VARCHAR(255) NULL,
  result VARCHAR(150) NULL,
  FOREIGN KEY (event_id) REFERENCES music_events(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES music_students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE music_sheet_library (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  raga_or_type VARCHAR(150) NULL,
  instrument VARCHAR(100) NULL,
  composer VARCHAR(255) NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
