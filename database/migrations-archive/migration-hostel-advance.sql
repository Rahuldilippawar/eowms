-- ============================================================
-- Migration: Hostel Module — संपूर्ण Advance Features
-- (Fee, Room/Bed वाटप, आरोग्य, सुट्टी-नोंद, माजी-विद्यार्थी)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE hostel_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  room_number VARCHAR(50) NOT NULL,
  capacity INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_hostel_room (hostel_id, room_number)
) ENGINE=InnoDB;

CREATE TABLE hostel_room_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  student_id INT NOT NULL,
  bed_label VARCHAR(20) NULL COMMENT 'उदा. Bed 1, Bed 2',
  allocated_date DATE NOT NULL,
  vacated_date DATE NULL,
  status ENUM('active','vacated') DEFAULT 'active',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hostel_fees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  fee_period VARCHAR(50) NOT NULL COMMENT 'उदा. जून 2026, Term 1',
  amount_due DECIMAL(10,2) NOT NULL,
  due_date DATE NULL,
  status ENUM('pending','partial','paid') DEFAULT 'pending',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hostel_fee_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fee_id INT NOT NULL,
  amount_paid DECIMAL(10,2) NOT NULL,
  payment_mode ENUM('cash','online','cheque','other') DEFAULT 'cash',
  paid_date DATE NOT NULL,
  received_by INT NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (fee_id) REFERENCES hostel_fees(id) ON DELETE CASCADE,
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hostel_student_health (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL UNIQUE,
  blood_group VARCHAR(10) NULL,
  allergies TEXT NULL,
  regular_medication TEXT NULL,
  emergency_contact_name VARCHAR(255) NULL,
  emergency_contact_phone VARCHAR(50) NULL,
  medical_notes TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hostel_health_incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  incident_date DATE NOT NULL,
  description TEXT NOT NULL,
  action_taken TEXT NULL,
  reported_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hostel_leave_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  leave_date DATE NOT NULL,
  expected_return_date DATE NULL,
  actual_return_date DATE NULL,
  reason VARCHAR(255) NULL,
  approved_by INT NULL,
  status ENUM('out','returned') DEFAULT 'out',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hostel_alumni (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL UNIQUE,
  departure_date DATE NOT NULL,
  departure_reason VARCHAR(255) NULL,
  current_status VARCHAR(255) NULL COMMENT 'उदा. महाविद्यालयात, नोकरीत',
  contact_number VARCHAR(50) NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
