-- ================================================================
-- EOWMS — सर्व Migrations एकत्र (Master Update File)
-- ================================================================
-- वापर: phpMyAdmin -> तुमचा Database -> SQL टॅब -> हा संपूर्ण मजकूर
-- पेस्ट करा -> Go दाबा.
-- ================================================================


-- ============================================================
-- FROM: migration-dynamic-roles.sql
-- ============================================================
-- ============================================================
-- Migration: Dynamic Roles / Designations
-- Run this in phpMyAdmin -> eowms database -> SQL tab
-- (safe even if you already have users/departments data — it
--  only adds a new table and widens the `role` column)
-- ============================================================

USE eowms;

-- 1. New table: every possible role/designation in the system, each tagged
--    with an access category so permissions still work no matter what the
--    role is actually CALLED (Director, Trustee, Secretary, etc.)
CREATE TABLE IF NOT EXISTS role_definitions (
  role_key VARCHAR(50) PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  label_local VARCHAR(100) NULL,
  category ENUM('super_admin','management','hod','staff') NOT NULL DEFAULT 'staff',
  is_system TINYINT(1) DEFAULT 0,   -- system roles can't be deleted (super_admin, hod, staff)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Seed the roles you already had, PLUS a broad set of common organizational
--    designations — all pre-mapped to the right access category. Add more
--    anytime from Super Admin -> "पदे व्यवस्थापन" (Roles Management) screen.
INSERT IGNORE INTO role_definitions (role_key, label, label_local, category, is_system) VALUES
('super_admin', 'Super Admin', 'सुपर अ‍ॅडमिन', 'super_admin', 1),
('president', 'President', 'अध्यक्ष', 'management', 0),
('executive_president', 'Executive President', 'कार्यकारी अध्यक्ष', 'management', 0),
('general_manager', 'General Manager', 'जनरल मॅनेजर', 'management', 0),
('vice_president', 'Vice President', 'उपाध्यक्ष', 'management', 0),
('director', 'Director', 'संचालक', 'management', 0),
('secretary', 'Secretary', 'सचिव', 'management', 0),
('joint_secretary', 'Joint Secretary', 'सहसचिव', 'management', 0),
('treasurer', 'Treasurer', 'कोषाध्यक्ष', 'management', 0),
('trustee', 'Trustee', 'विश्वस्त', 'management', 0),
('principal', 'Principal', 'प्राचार्य', 'management', 0),
('deputy_general_manager', 'Deputy General Manager', 'उप जनरल मॅनेजर', 'management', 0),
('assistant_general_manager', 'Assistant General Manager', 'सहाय्यक जनरल मॅनेजर', 'management', 0),
('administrative_officer', 'Administrative Officer', 'प्रशासकीय अधिकारी', 'management', 0),
('manager', 'Manager', 'व्यवस्थापक', 'management', 0),
('hod', 'HOD', 'विभागप्रमुख', 'hod', 1),
('supervisor', 'Supervisor', 'पर्यवेक्षक', 'hod', 0),
('coordinator', 'Coordinator', 'समन्वयक', 'hod', 0),
('staff', 'Staff', 'कर्मचारी', 'staff', 1);

-- 3. Widen users.role from a fixed ENUM to a flexible VARCHAR so ANY
--    role_key above (or any new one you add later) can be assigned.
ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff';

-- ============================================================
-- FROM: migration-add-logo.sql
-- ============================================================
-- ============================================================
-- Migration: Add organization logo support
-- Run this in phpMyAdmin (SQL tab) ONLY IF your `eowms` database
-- was created BEFORE this update. Fresh installs using the main
-- schema.sql already include this column — skip this file then.
-- ============================================================

ALTER TABLE organizations ADD COLUMN logo_path VARCHAR(255) NULL AFTER type;

-- ============================================================
-- FROM: migration-add-requested-by.sql
-- ============================================================
-- ============================================================
-- Migration: Add "requested_by" to work_records
-- This is the person/department who ASKED for the work to be
-- done (e.g. "Principal Office", "Hostel Warden", a teacher's name)
-- — separate from the employee who actually performed the work.
-- Run this ONLY if your database was created before this update.
-- ============================================================

USE eowms;

ALTER TABLE work_records ADD COLUMN requested_by VARCHAR(255) NULL AFTER department_id;

-- ============================================================
-- FROM: migration-custom-fields.sql
-- ============================================================
-- ============================================================
-- Migration: Dynamic Daily-Work Custom Fields
-- Lets a Super Admin add organization-specific fields to the
-- Daily Work entry form (since every organization's rules differ).
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE custom_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  field_label VARCHAR(255) NOT NULL,
  field_type ENUM('text','textarea','number','date','select','checkbox') DEFAULT 'text',
  field_options TEXT NULL,
  is_required TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_org_key (organization_id, field_key)
) ENGINE=InnoDB;

CREATE TABLE custom_field_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  field_value TEXT NULL,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  INDEX idx_work_record (work_record_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-hostels.sql
-- ============================================================
-- ============================================================
-- Migration: Hostel-wise Student & Staff Allocation
-- Lets Super Admin add multiple physical Hostels under the
-- "Hostel" department, allocate staff to each, and track
-- students per hostel (school, class, district).
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE hostels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  capacity INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hostel_staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  user_id INT NOT NULL,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_hostel_staff (hostel_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE hostel_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  school_name VARCHAR(255) NULL,
  class_grade VARCHAR(50) NULL,
  district VARCHAR(100) NULL,
  gender ENUM('मुलगा','मुलगी') NULL,
  admission_date DATE NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  INDEX idx_hostel (hostel_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-work-requests.sql
-- ============================================================
-- ============================================================
-- Migration: Public Work Requests (no-login submission)
-- Anyone can submit a work request to any department from the
-- landing page — it then shows up for that department's HOD/staff.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(50) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  requester_name VARCHAR(255) NOT NULL,
  requester_contact VARCHAR(100) NULL,
  work_description TEXT NOT NULL,
  status ENUM('pending','in_progress','completed') DEFAULT 'pending',
  completed_by INT NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_status (department_id, status)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-permissions.sql
-- ============================================================
-- ============================================================
-- Migration: Granular Permissions per Role
-- Lets Super Admin decide EXACTLY which features each role/
-- designation can access — not just a fixed category.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE permissions (
  permission_key VARCHAR(60) PRIMARY KEY,
  label VARCHAR(150) NOT NULL,
  label_local VARCHAR(150) NULL,
  sort_order INT DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(50) NOT NULL,
  permission_key VARCHAR(60) NOT NULL,
  UNIQUE KEY uniq_role_perm (role_key, permission_key)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('assign_tasks', 'Assign Tasks to any staff', 'कोणालाही टास्क असाइन करणे', 1),
('view_task_tracker', 'View organization-wide Task Tracker', 'संपूर्ण संस्थेचा टास्क ट्रॅकर पाहणे', 2),
('view_staff_directory', 'View organization-wide Staff Directory', 'संपूर्ण कर्मचारी सूची पाहणे', 3),
('view_org_wide_dashboards', 'View any department\'s dashboard', 'कोणत्याही विभागाचा डॅशबोर्ड पाहणे', 4),
('view_org_wide_reports', 'Generate organization-wide reports', 'संपूर्ण संस्था-स्तरीय अहवाल तयार करणे', 5),
('manage_requests_org_wide', 'View/manage requests for all departments', 'सर्व विभागांच्या विनंत्या पाहणे/व्यवस्थापणे', 6),
('manage_campuses', 'Add/edit campuses', 'कॅम्पस जोडणे/संपादित करणे', 7),
('manage_departments', 'Add/edit departments', 'विभाग जोडणे/संपादित करणे', 8),
('manage_hostels', 'Add hostels, allocate hostel staff', 'वसतिगृह जोडणे, स्टाफ नियुक्ती', 9),
('manage_hostel_students', 'Add/edit hostel students', 'वसतिगृह विद्यार्थी जोडणे/संपादित करणे', 10);

-- Default permissions matching current behavior — Super Admin can change
-- any of this anytime from "पदे व्यवस्थापन" -> role -> Permissions.
INSERT INTO role_permissions (role_key, permission_key) VALUES
('president', 'assign_tasks'), ('president', 'view_task_tracker'), ('president', 'view_staff_directory'),
('president', 'view_org_wide_dashboards'), ('president', 'view_org_wide_reports'), ('president', 'manage_requests_org_wide'),
('executive_president', 'assign_tasks'), ('executive_president', 'view_task_tracker'), ('executive_president', 'view_staff_directory'),
('executive_president', 'view_org_wide_dashboards'), ('executive_president', 'view_org_wide_reports'), ('executive_president', 'manage_requests_org_wide'),
('general_manager', 'assign_tasks'), ('general_manager', 'view_task_tracker'), ('general_manager', 'view_staff_directory'),
('general_manager', 'view_org_wide_dashboards'), ('general_manager', 'view_org_wide_reports'), ('general_manager', 'manage_requests_org_wide'),
('general_manager', 'manage_campuses'), ('general_manager', 'manage_departments'),
('hod', 'view_staff_directory');

-- ============================================================
-- FROM: migration-notifications-log.sql
-- ============================================================
-- ============================================================
-- Migration: Notifications + Activity Log
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NULL,
  user_id INT NULL,
  actor_name VARCHAR(255) NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_org_created (organization_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-work-remarks.sql
-- ============================================================
-- ============================================================
-- Migration: Add "remarks" to work_records
-- Used when staff updates a pending/in-progress work item —
-- either marking it complete, or explaining why it's still pending.
-- Run this ONLY if your database was created before this update.
-- ============================================================

ALTER TABLE work_records ADD COLUMN remarks TEXT NULL AFTER status;

-- ============================================================
-- FROM: migration-meditation.sql
-- ============================================================
-- ============================================================
-- Migration: ध्यान व सत्संग (Meditation & Satsang) Module
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE satsang_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  event_date DATE NOT NULL,
  organizing_department_id INT NULL,
  theme VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (organizing_department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE satsang_duties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  satsang_event_id INT NOT NULL,
  assigned_to INT NOT NULL,
  duty_description VARCHAR(255) NOT NULL,
  status ENUM('pending','completed') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (satsang_event_id) REFERENCES satsang_events(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE meditation_quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  quote_text TEXT NOT NULL,
  author VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-satsang-attendance.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-hostel-attendance.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-vehicles.sql
-- ============================================================
-- ============================================================
-- Migration: वाहन व्यवस्थापन (Vehicle / Transport Management)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  department_id INT NULL,
  vehicle_number VARCHAR(50) NOT NULL,
  vehicle_type VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  driver_id INT NULL,
  fuel_type ENUM('petrol','diesel','cng','electric','other') DEFAULT 'diesel',
  current_odometer DECIMAL(10,1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  trip_date DATE NOT NULL,
  from_location VARCHAR(255) NOT NULL,
  to_location VARCHAR(255) NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  start_odometer DECIMAL(10,1) NOT NULL,
  end_odometer DECIMAL(10,1) NOT NULL,
  distance_km DECIMAL(10,1) NOT NULL,
  purpose VARCHAR(255) NULL,
  driver_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_fuel_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  fill_date DATE NOT NULL,
  odometer_reading DECIMAL(10,1) NOT NULL,
  liters DECIMAL(8,2) NOT NULL,
  cost DECIMAL(10,2) NULL,
  fuel_station VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  maintenance_date DATE NOT NULL,
  type VARCHAR(100) NOT NULL,
  description TEXT NULL,
  cost DECIMAL(10,2) NULL,
  odometer_reading DECIMAL(10,1) NULL,
  next_due_date DATE NULL,
  vendor VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-manage-vehicles-permission.sql
-- ============================================================
-- ============================================================
-- Migration: Add "manage_vehicles" permission
-- Run this ONLY if you already ran migration-permissions.sql earlier.
-- ============================================================

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_vehicles', 'Add/edit vehicles, log trips & fuel', 'वाहन जोडणे, ट्रिप व इंधन नोंदवणे', 11);

-- Give it to general_manager by default (matches existing manage_campuses/manage_departments defaults)
INSERT INTO role_permissions (role_key, permission_key) VALUES
('general_manager', 'manage_vehicles');

-- ============================================================
-- FROM: migration-vehicle-corrections.sql
-- ============================================================
-- ============================================================
-- Migration: वाहन नोंद सुधारणा विनंती (Vehicle Entry Correction Requests)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE vehicle_correction_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  entry_type ENUM('trip','fuel','maintenance') NOT NULL,
  entry_id INT NOT NULL,
  request_type ENUM('edit','delete') NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  requested_by INT NOT NULL,
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-letters.sql
-- ============================================================
-- ============================================================
-- Migration: पत्र व्यवहार (File/Letter Movement Workflow)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE letter_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  name_local VARCHAR(150) NULL,
  is_active TINYINT(1) DEFAULT 1,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE workflow_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  category_id INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES letter_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE workflow_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workflow_template_id INT NOT NULL,
  step_order INT NOT NULL,
  step_name VARCHAR(150) NOT NULL,
  approver_type ENUM('department_hod','specific_user','letter_dept_hod') NOT NULL,
  department_id INT NULL,
  user_id INT NULL,
  FOREIGN KEY (workflow_template_id) REFERENCES workflow_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE letters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  file_number VARCHAR(60) NOT NULL UNIQUE,
  subject VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category_id INT NULL,
  department_id INT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status ENUM('draft','in_progress','returned','completed') DEFAULT 'draft',
  workflow_template_id INT NULL,
  current_step_order INT DEFAULT 0,
  current_holder_id INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES letter_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (workflow_template_id) REFERENCES workflow_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (current_holder_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_holder (current_holder_id),
  INDEX idx_org_status (organization_id, status)
) ENGINE=InnoDB;

CREATE TABLE letter_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  action_type ENUM('created','submitted','forwarded','returned','remark_added','completed') NOT NULL,
  from_user_id INT NULL,
  to_user_id INT NULL,
  remark TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE letter_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-letters-features.sql
-- ============================================================
-- ============================================================
-- Migration: पत्र व्यवहार — 5 नवीन Features
-- (Overdue tracking, CC/Watchers, Read Status, Templates)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Read-status: reset to NULL whenever current_holder_id changes; set when opened
ALTER TABLE letters ADD COLUMN current_holder_seen_at TIMESTAMP NULL AFTER current_holder_id;

-- CC / Watchers — can view + get notified, but cannot act

-- Letter Templates — quick-start pre-filled drafts

-- ============================================================
-- FROM: migration-force-password-change.sql
-- ============================================================
-- ============================================================
-- Migration: पहिल्या Login ला जबरदस्ती नवीन पासवर्ड
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER password;

-- ============================================================
-- FROM: migration-password-reset.sql
-- ============================================================
-- ============================================================
-- Migration: पासवर्ड रिसेट Verification Code
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE password_reset_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  code VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_code (user_id, code)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-request-tracking.sql
-- ============================================================
-- ============================================================
-- Migration: कामाची विनंती — Tracking, Remarks History, Seen-by
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_request_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  action_type ENUM('created','status_changed') NOT NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NULL,
  remark TEXT NULL,
  changed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES work_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE work_request_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  user_id INT NOT NULL,
  viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES work_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_request_user (request_id, user_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-login-lockout.sql
-- ============================================================
-- ============================================================
-- Migration: Failed Login Lockout (Brute-force संरक्षण)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0 AFTER password;
ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts;

-- ============================================================
-- FROM: migration-salary-joining-leave.sql
-- ============================================================
-- ============================================================
-- Migration: कर्मचारी पगार + Joining/Leave Date Tracking
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN salary DECIMAL(10,2) NULL AFTER designation;
ALTER TABLE users ADD COLUMN joining_date DATE NULL AFTER salary;
ALTER TABLE users ADD COLUMN left_date DATE NULL AFTER joining_date;

-- Existing employees: default their joining_date to when their record was created
UPDATE users SET joining_date = DATE(created_at) WHERE joining_date IS NULL;

-- ============================================================
-- FROM: migration-work-team.sql
-- ============================================================
-- ============================================================
-- Migration: टीम-निहाय काम नोंद (एकापेक्षा जास्त कर्मचारी मिळून काम)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_record_team (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  user_id INT NOT NULL,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_record_user (work_record_id, user_id)
) ENGINE=InnoDB;

-- Existing records: backfill so every past record's single employee_id
-- also appears in the new team table (keeps everything consistent).
INSERT IGNORE INTO work_record_team (work_record_id, user_id)
SELECT id, employee_id FROM work_records;

-- ============================================================
-- FROM: migration-birthday-celebration.sql
-- ============================================================
-- ============================================================
-- Migration: वाढदिवस साजरा (Birthday Celebration) — Photo + DOB
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Staff: date_of_birth already exists; add photo
ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER email;

-- Hostel Students: add both date_of_birth and photo
ALTER TABLE hostel_students ADD COLUMN date_of_birth DATE NULL AFTER gender;
ALTER TABLE hostel_students ADD COLUMN photo_path VARCHAR(255) NULL AFTER date_of_birth;

-- ============================================================
-- FROM: migration-dead-stock.sql
-- ============================================================
-- ============================================================
-- Migration: विभाग-निहाय Dead Stock Register (टिकाऊ मालमत्ता नोंदवही)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE dead_stock_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  item_code VARCHAR(50) NOT NULL UNIQUE,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit VARCHAR(50) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  `condition` ENUM('चांगली','दुरुस्तीयोग्य','खराब','भंगार') NOT NULL DEFAULT 'चांगली',
  location VARCHAR(255) NULL,
  remarks TEXT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept (department_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-dead-stock-item-master.sql
-- ============================================================
-- ============================================================
-- Migration: Dead Stock Item Master (केंद्रीकृत वस्तू यादी)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE dead_stock_item_master (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  default_unit VARCHAR(50) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_org_item (organization_id, item_name)
) ENGINE=InnoDB;

-- (ऐच्छिक) आधीच्या मुक्त-टायपिंग नोंदींमधून वेगळी नावं आपोआप master list मध्ये टाका:
INSERT IGNORE INTO dead_stock_item_master (organization_id, item_name, category)
SELECT DISTINCT organization_id, item_name, category FROM dead_stock_items;

-- dead_stock_items मध्ये आता कुठला master-item निवडला हे जोडण्यासाठी:
ALTER TABLE dead_stock_items ADD COLUMN item_master_id INT NULL AFTER department_id;
ALTER TABLE dead_stock_items ADD FOREIGN KEY (item_master_id) REFERENCES dead_stock_item_master(id) ON DELETE SET NULL;

-- ============================================================
-- FROM: migration-maintenance-log.sql
-- ============================================================
-- ============================================================
-- Migration: देखभाल नोंदवही — जागा-निहाय इतिहास (Maintenance Log)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE maintenance_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  location_code VARCHAR(50) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_org_location_code (organization_id, location_code)
) ENGINE=InnoDB;

CREATE TABLE maintenance_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  department_id INT NOT NULL,
  item_description VARCHAR(255) NOT NULL,
  work_type ENUM('बसवले','दुरुस्त केले','बदलले','तपासणी') NOT NULL DEFAULT 'दुरुस्त केले',
  work_date DATE NOT NULL,
  cost DECIMAL(10,2) NULL,
  remarks TEXT NULL,
  performed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_location (location_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-issue-register-buildings.sql
-- ============================================================
-- ============================================================
-- Migration: Multi-Department Issue Register + Building-wise Rooms
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Super Admin decides which departments get the "देखभाल नोंदवही (Issue Register)" tab
ALTER TABLE departments ADD COLUMN has_issue_register TINYINT(1) DEFAULT 0 AFTER hod_id;

-- Turn it on for Maintenance by default (matches how it already worked)
UPDATE departments SET has_issue_register = 1 WHERE code = 'MTN';

-- Buildings — Super Admin sets these up; rooms belong to a building
CREATE TABLE buildings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Link existing rooms/locations to a building (nullable at first for backward compatibility)
ALTER TABLE maintenance_locations ADD COLUMN building_id INT NULL AFTER campus_id;
ALTER TABLE maintenance_locations ADD FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE SET NULL;

-- ============================================================
-- FROM: migration-residency.sql
-- ============================================================
-- ============================================================
-- Migration: रहिवासी विभाग — Room-निहाय वास्तव्य नोंद
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- 1) नवीन विभाग जोडा (आपल्या organization_id / campus_id नुसार बदला)
-- उदा. संस्था id=1, कॅम्पस id=1 असेल तर:
INSERT INTO departments (organization_id, campus_id, name, name_local, code, icon, has_issue_register)
VALUES (1, 1, 'Residency', 'रहिवासी विभाग', 'RES', 'fa-solid fa-house-chimney-user', 0);

-- 2) खोलीत कोण राहतं — प्रत्येक व्यक्ती (staff किंवा त्यांचे कुटुंबीय) साठी एक नोंद
CREATE TABLE residency_occupants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  occupant_type ENUM('staff','family') NOT NULL DEFAULT 'staff',
  user_id INT NULL,
  name VARCHAR(255) NULL,
  relation VARCHAR(100) NULL,
  age INT NULL,
  moved_in_date DATE NULL,
  status ENUM('active','vacated') NOT NULL DEFAULT 'active',
  vacated_date DATE NULL,
  vacated_remark TEXT NULL,
  vacated_by INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (vacated_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_location_status (location_id, status)
) ENGINE=InnoDB;

-- 3) खोलीतलं साहित्य — संस्थेचं की वैयक्तिक हे checkbox ने ठरतं
CREATE TABLE residency_room_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  ownership ENUM('संस्थेचे','वैयक्तिक') NOT NULL DEFAULT 'संस्थेचे',
  quantity INT NOT NULL DEFAULT 1,
  remarks TEXT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-letter-inward-outward.sql
-- ============================================================
-- ============================================================
-- Migration: विभाग-निहाय जावक (Outward) / आवक (Inward) क्रमांक + संदर्भ क्रमांक
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- फाईल तयार करणाऱ्या विभागाला मिळणारा जावक क्रमांक (उदा. MTN/2026/0001)
ALTER TABLE letters ADD COLUMN outward_no VARCHAR(50) NULL AFTER file_number;

-- संदर्भ क्रमांक — दुसऱ्या जुन्या पत्राचा हवाला देण्यासाठी (विषयाच्या खाली दिसतो)
ALTER TABLE letters ADD COLUMN reference_no VARCHAR(100) NULL AFTER outward_no;

-- प्रत्येक विभागाला, जेव्हा फाईल पहिल्यांदा त्यांच्याकडे येते, तेव्हा त्यांचा
-- स्वतःचा आवक क्रमांक मिळतो — एका फाईलला अनेक विभागांचे अनेक आवक क्रमांक असू शकतात
CREATE TABLE letter_inward_numbers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  department_id INT NOT NULL,
  inward_no VARCHAR(50) NOT NULL,
  year INT NOT NULL,
  sequence_no INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_letter_dept (letter_id, department_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-clerk-gatekeeper.sql
-- ============================================================
-- ============================================================
-- Migration: Clerk/Gatekeeper Pattern — Executive कडे जाणारी पत्रं
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- कोणत्या अधिकाऱ्यासाठी कोणता Clerk काम बघतो

-- फाईल तांत्रिकदृष्ट्या Clerk कडे असली, तरी ती खरं कोणासाठी आहे हे दाखवण्यासाठी

-- Executive ने "कुठे पुढे पाठवायचं" ठरवलं, पण अजून Clerk ने Approve केलेलं नाही

-- फाईल सध्या clerk-प्रक्रियेच्या कोणत्या टप्प्यावर आहे

-- ============================================================
-- FROM: migration-sister-organizations.sql
-- ============================================================
-- ============================================================
-- Migration: Parent-Child संस्था रचना — शाखांमधला (Sister Org) पत्रव्यवहार
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- कोणती संस्था कोणत्या मुख्य Trust ची उप-शाखा आहे
ALTER TABLE organizations ADD COLUMN parent_organization_id INT NULL AFTER type;
ALTER TABLE organizations ADD FOREIGN KEY (parent_organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

-- उदाहरण: संस्था id=2 ही संस्था id=1 (मुख्य Trust) ची उप-शाखा आहे असं जोडायचं असल्यास:
-- UPDATE organizations SET parent_organization_id = 1 WHERE id = 2;

-- ============================================================
-- FROM: migration-department-budget.sql
-- ============================================================
-- ============================================================
-- Migration: विभाग-निहाय Budget व्यवस्थापन (Financial Year आधारित)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE department_budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  financial_year INT NOT NULL COMMENT 'FY सुरु होणारं वर्ष — उदा. 2026 म्हणजे एप्रिल 2026 ते मार्च 2027',
  allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  allocated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_dept_fy (department_id, financial_year)
) ENGINE=InnoDB;

CREATE TABLE budget_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  financial_year INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category ENUM('नवीन खरेदी','देखभाल','इंधन','इतर') NOT NULL,
  source_module VARCHAR(50) NULL COMMENT 'dead_stock / maintenance / vehicle_fuel / vehicle_maintenance / manual',
  reference_id INT NULL,
  description VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_fy (department_id, financial_year)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-deadstock-new-vs-existing.sql
-- ============================================================
-- ============================================================
-- Migration: Dead Stock — नवीन खरेदी vs आधीपासून अस्तित्वात असलेली वस्तू
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- 1 = नवीन खरेदी (Budget मधून वजा होते), 0 = आधीपासून अस्तित्वात (वजा होत नाही)
ALTER TABLE dead_stock_items ADD COLUMN is_new_purchase TINYINT(1) DEFAULT 1 AFTER purchase_cost;

-- ============================================================
-- FROM: migration-letter-budget-link.sql
-- ============================================================
-- ============================================================
-- Migration: पत्र-मंजुरी → आपोआप Budget मधून वजा (Letter-Budget Link)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- ही फाईल खर्च/खरेदी/देखभाल-विनंतीसाठी आहे का, ती मूळ मागणी रक्कम,
-- कोणत्या Budget-श्रेणीत मोजायची, प्रत्यक्ष मंजूर झालेली रक्कम, आणि
-- Budget मधून वजा झालं आहे का (दुबार वजा होऊ नये यासाठी)
ALTER TABLE letters ADD COLUMN is_budget_expense TINYINT(1) DEFAULT 0 AFTER reference_no;
ALTER TABLE letters ADD COLUMN budget_category ENUM('नवीन खरेदी','देखभाल','इंधन','इतर') NULL AFTER is_budget_expense;
ALTER TABLE letters ADD COLUMN requested_amount DECIMAL(12,2) NULL AFTER budget_category;
ALTER TABLE letters ADD COLUMN approved_amount DECIMAL(12,2) NULL AFTER requested_amount;
ALTER TABLE letters ADD COLUMN budget_deducted TINYINT(1) DEFAULT 0 AFTER approved_amount;

-- ============================================================
-- FROM: migration-about-page.sql
-- ============================================================
-- ============================================================
-- Migration: About Page — देव/सद्गुरू/मार्गदर्शक विभाग + योगदानकर्ते
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE about_sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  section_type ENUM('god','sadguru','guide') NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  photo_path VARCHAR(255) NULL,
  display_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE about_contributors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  contribution VARCHAR(255) NULL COMMENT 'उदा. Concept व Requirement, Testing, इ.',
  bio TEXT NULL,
  photo_path VARCHAR(255) NULL,
  display_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-webauthn-fingerprint.sql
-- ============================================================
-- ============================================================
-- Migration: Fingerprint/Face ID Login (WebAuthn)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE webauthn_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  credential_id TEXT NOT NULL COMMENT 'base64url encoded credential ID from authenticator',
  public_key_pem TEXT NOT NULL COMMENT 'PEM-encoded EC P-256 public key, used to verify future logins',
  sign_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'replay-attack protection counter',
  device_label VARCHAR(255) NULL COMMENT 'e.g. "Chrome on Windows", user-editable',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-sop-compliance.sql
-- ============================================================
-- ============================================================
-- Migration: SOP Compliance Tracking (Auto-Detect सहित)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE sops (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_by INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE sop_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sop_id INT NOT NULL,
  step_order INT DEFAULT 0,
  step_description VARCHAR(500) NOT NULL,
  frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
  detection_type ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  auto_source_module VARCHAR(50) NULL COMMENT 'daily_work / hostel_attendance / vehicle_trip / maintenance_log',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sop_id) REFERENCES sops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sop_department_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sop_id INT NOT NULL,
  department_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sop_id) REFERENCES sops(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_sop_dept (sop_id, department_id)
) ENGINE=InnoDB;

CREATE TABLE sop_compliance_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sop_step_id INT NOT NULL,
  department_id INT NOT NULL,
  log_date DATE NOT NULL,
  status ENUM('done','not_done') NOT NULL DEFAULT 'not_done',
  completed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sop_step_id) REFERENCES sop_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_step_dept_date (sop_step_id, department_id, log_date)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-scheduled-backup.sql
-- ============================================================
-- ============================================================
-- Migration: Automated Scheduled Backup — नोंद ठेवण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE scheduled_backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  file_size_kb INT NULL,
  status ENUM('success','failed') NOT NULL DEFAULT 'success',
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-location-floor.sql
-- ============================================================
-- ============================================================
-- Migration: खोल्यांना मजला (Floor) नोंदवणे — Building Layout साठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE maintenance_locations ADD COLUMN floor INT NOT NULL DEFAULT 0 AFTER building_id;
-- 0 = तळमजला (Ground Floor), 1 = पहिला मजला, 2 = दुसरा मजला, इ.

-- ============================================================
-- FROM: migration-new-module-permissions.sql
-- ============================================================
-- ============================================================
-- Migration: नवीन Modules साठी Granular Permissions जोडणे
-- (पत्र व्यवहार, Budget, SOP, Dead Stock, देखभाल, रहिवासी विभाग)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_letters_org_wide', 'View/manage all letters across departments', 'सर्व विभागांचे पत्र व्यवहार पाहणे/व्यवस्थापणे', 12),
('manage_budget', 'Set and view department budgets', 'विभाग Budget ठरवणे/पाहणे', 13),
('manage_sop', 'Create SOPs and assign to departments', 'SOP तयार करणे/विभागांना नेमणे', 14),
('manage_dead_stock_master', 'Manage Dead Stock item master, edit/delete entries', 'Dead Stock Item Master + Edit/Delete अधिकार', 15),
('manage_maintenance_settings', 'Enable/disable Maintenance Log per department', 'कोणत्या विभागांना देखभाल नोंदवही उपलब्ध ते ठरवणे', 16),
('manage_residency_all_depts', 'View/manage Residency across all departments', 'रहिवासी विभाग सर्व विभागांसाठी पाहणे/व्यवस्थापणे', 17);

-- सध्या "Management आणि वरचे" (category = 'management') असलेल्या सगळ्या
-- Roles ना हे नवीन Permissions आपोआप द्या — जेणेकरून Migration नंतर त्यांचा
-- आधीचा access अचानक बंद होणार नाही. Super Admin नंतर हवं तसं बदलू शकतो.
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_letters_org_wide' FROM role_definitions WHERE category = 'management';
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_budget' FROM role_definitions WHERE category = 'management';
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_sop' FROM role_definitions WHERE category = 'management';
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_dead_stock_master' FROM role_definitions WHERE category = 'management';
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_maintenance_settings' FROM role_definitions WHERE category = 'management';
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_residency_all_depts' FROM role_definitions WHERE category = 'management';

-- ============================================================
-- FROM: migration-letter-signatures.sql
-- ============================================================
-- ============================================================
-- Migration: Sign & Approve — Digital Signature (Fingerprint/Password)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE letter_signatures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  signed_by INT NULL,
  signer_name VARCHAR(255) NOT NULL COMMENT 'नाव जसंच्या तसं साठवलेलं — नंतर User Edit/Delete झाला तरी इतिहास अचूक राहतो',
  signer_designation VARCHAR(255) NULL,
  action_type VARCHAR(50) NOT NULL COMMENT 'उदा. complete, forward',
  verification_method ENUM('fingerprint','password') NOT NULL,
  verification_code VARCHAR(20) NOT NULL UNIQUE COMMENT 'सार्वजनिक पडताळणीसाठी — QR मध्ये वापरला जातो',
  signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (signed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-welcome-music.sql
-- ============================================================
-- ============================================================
-- Migration: Welcome Music — Super Admin Panel वरून Upload करण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE organizations ADD COLUMN welcome_audio_path VARCHAR(255) NULL AFTER logo_path;

-- ============================================================
-- FROM: migration-vehicle-full-details.sql
-- ============================================================
-- ============================================================
-- Migration: वाहनाचे संपूर्ण तपशील (RTO/Compliance) + नूतनीकरण-जोडणी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE vehicles
  ADD COLUMN date_of_registration DATE NULL AFTER model,
  ADD COLUMN chassis_no VARCHAR(100) NULL AFTER date_of_registration,
  ADD COLUMN engine_no VARCHAR(100) NULL AFTER chassis_no,
  ADD COLUMN vehicle_class VARCHAR(100) NULL AFTER engine_no,
  ADD COLUMN passenger_capacity INT NULL AFTER vehicle_class,
  ADD COLUMN permit_validity DATE NULL AFTER passenger_capacity,
  ADD COLUMN puc_validity DATE NULL AFTER permit_validity,
  ADD COLUMN insurance_validity DATE NULL AFTER puc_validity,
  ADD COLUMN fitness_validity DATE NULL AFTER insurance_validity,
  ADD COLUMN road_tax_validity DATE NULL AFTER fitness_validity,
  ADD COLUMN green_tax_validity DATE NULL AFTER road_tax_validity,
  ADD COLUMN mv_tax_validity DATE NULL AFTER green_tax_validity;

-- वाहनाच्या या 7 नूतनीकरण-तारखा — "नूतनीकरण व अनुपालन" पानावर आपोआप
-- दिसण्यासाठी — renewal_items शी जोडणारे columns (vehicle_id + कोणत्या
-- प्रकारचं नूतनीकरण आहे ते ओळखणारा renewal_field).
ALTER TABLE renewal_items
  ADD COLUMN vehicle_id INT NULL AFTER department_id,
  ADD COLUMN renewal_field VARCHAR(50) NULL AFTER vehicle_id,
  ADD FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE;

-- ============================================================
-- FROM: migration-cctv.sql
-- ============================================================
-- ============================================================
-- Migration: CCTV विभाग — DVR/Camera Master, Checkup, Reports
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE cctv_dvrs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  dvr_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  channel_capacity INT NULL COMMENT 'DVR किती Cameras support करतो',
  storage_capacity_tb DECIMAL(6,2) NULL COMMENT 'एकूण Storage क्षमता (TB मध्ये)',
  installed_date DATE NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE cctv_cameras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  dvr_id INT NOT NULL,
  camera_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL COMMENT 'उदा. मुख्य प्रवेशद्वार, वर्ग क्र. 5',
  camera_type ENUM('dome','bullet','ptz','other') DEFAULT 'dome',
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (dvr_id) REFERENCES cctv_dvrs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cctv_checkups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  dvr_id INT NOT NULL,
  checkup_date DATE NOT NULL,
  storage_used_percent DECIMAL(5,2) NULL,
  footage_days_available INT NULL COMMENT 'सध्याचं Footage किती दिवसांचं मागे उपलब्ध आहे',
  backup_done TINYINT(1) DEFAULT 0,
  backup_location VARCHAR(255) NULL COMMENT 'उदा. External HDD / Cloud',
  remarks TEXT NULL,
  checked_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (dvr_id) REFERENCES cctv_dvrs(id) ON DELETE CASCADE,
  FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_dvr_date (dvr_id, checkup_date)
) ENGINE=InnoDB;

CREATE TABLE cctv_camera_status (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checkup_id INT NOT NULL,
  camera_id INT NOT NULL,
  status ENUM('working','not_working','needs_attention') DEFAULT 'working',
  remarks VARCHAR(255) NULL,
  FOREIGN KEY (checkup_id) REFERENCES cctv_checkups(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cctv_cameras(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_checkup_camera (checkup_id, camera_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-cctv-permission.sql
-- ============================================================
-- ============================================================
-- Migration: CCTV विभाग साठी Granular Permission
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_cctv', 'Access the CCTV department module', 'CCTV विभाग पाहणे/व्यवस्थापणे', 20);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_cctv' FROM role_definitions WHERE category = 'management';

-- ============================================================
-- FROM: migration-it-department.sql
-- ============================================================
-- ============================================================
-- Migration: IT विभाग — Labs, Assets, Repair Tickets
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE it_labs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  lab_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  capacity INT NULL,
  assistant_id INT NULL COMMENT 'या Lab ला नेमलेला Assistant — तोच Data भरेल',
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (assistant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE it_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  lab_id INT NOT NULL,
  asset_tag VARCHAR(50) NOT NULL UNIQUE COMMENT 'QR/Barcode साठी अद्वितीय क्रमांक',
  asset_type ENUM('computer','monitor','keyboard','mouse','cpu','ups','smartboard','projector','printer','other') NOT NULL,
  brand VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  serial_no VARCHAR(150) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  warranty_expiry DATE NULL,
  os_license VARCHAR(150) NULL COMMENT 'फक्त Computer साठी — कोणतं OS/Office License',
  license_expiry DATE NULL,
  status ENUM('working','faulty','under_repair','scrapped') DEFAULT 'working',
  remarks VARCHAR(255) NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE it_asset_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  from_lab_id INT NULL,
  to_lab_id INT NOT NULL,
  transferred_by INT NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id) REFERENCES it_assets(id) ON DELETE CASCADE,
  FOREIGN KEY (from_lab_id) REFERENCES it_labs(id) ON DELETE SET NULL,
  FOREIGN KEY (to_lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE it_repair_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  asset_id INT NOT NULL,
  lab_id INT NOT NULL,
  issue_description TEXT NOT NULL,
  status ENUM('pending','in_progress','resolved') DEFAULT 'pending',
  resolution_notes TEXT NULL,
  reported_by INT NOT NULL,
  resolved_by INT NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES it_assets(id) ON DELETE CASCADE,
  FOREIGN KEY (lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_asset_status (asset_id, status)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_it', 'Access the IT department module', 'IT विभाग पाहणे/व्यवस्थापणे', 21);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_it' FROM role_definitions WHERE category = 'management';

-- ============================================================
-- FROM: migration-hod-assign-tasks.sql
-- ============================================================
-- ============================================================
-- Migration: विभाग प्रमुख (HOD) ला Task Assign करण्याची परवानगी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'assign_tasks' FROM role_definitions WHERE category = 'hod';

-- ============================================================
-- FROM: migration-task-org-wide-permission.sql
-- ============================================================
-- ============================================================
-- Migration: Task Assignment — Org-Wide वि. Department-Scoped
-- Super Admin ला आता "Roles व्यवस्थापन" मधूनच ठरवता येईल — कोणत्या Role
-- ला संपूर्ण Organization चे Tasks दिसावेत, कोणाला फक्त स्वतःच्या विभागाचे.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('assign_tasks_org_wide', 'Assign/view tasks across all departments (not just own)', 'सर्व विभागांचे Task Assign/पाहणे (फक्त स्वतःचा विभाग नाही)', 2);

-- सध्या "Management आणि वरचे" (category = 'management') असलेल्या सगळ्या
-- Roles ना हे आपोआप द्या — जेणेकरून त्यांचा आधीचा Org-Wide Access तसाच राहील.
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'assign_tasks_org_wide' FROM role_definitions WHERE category = 'management';

-- ============================================================
-- FROM: migration-it-workstation-grouping.sql
-- ============================================================
-- ============================================================
-- Migration: IT Assets — PC Setup Grouping (workstation_no)
-- एका PC Setup (CPU+Monitor+Keyboard+Mouse+इतर) चे सगळे भाग एकत्र
-- ओळखता यावेत म्हणून — Run this in phpMyAdmin -> SQL tab
-- ============================================================

ALTER TABLE it_assets ADD COLUMN workstation_no VARCHAR(50) NULL AFTER lab_id;

-- ============================================================
-- FROM: migration-internal-work-requests.sql
-- ============================================================
-- ============================================================
-- Migration: आंतर-विभागीय कामाची विनंती (Inter-Department Work Request)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE work_requests
  ADD COLUMN requested_by_user_id INT NULL AFTER requester_contact,
  ADD COLUMN requesting_department_id INT NULL AFTER requested_by_user_id,
  ADD FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD FOREIGN KEY (requesting_department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- ============================================================
-- FROM: migration-college-fields.sql
-- ============================================================
-- ============================================================
-- Migration: Campus/College — Advance Fields (Principal, Affiliation,
-- NAAC, Established Year, इ.)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses
  ADD COLUMN college_type ENUM('Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL AFTER code,
  ADD COLUMN principal_name VARCHAR(255) NULL AFTER college_type,
  ADD COLUMN affiliated_university VARCHAR(255) NULL AFTER principal_name,
  ADD COLUMN ugc_recognition VARCHAR(150) NULL AFTER affiliated_university,
  ADD COLUMN naac_grade VARCHAR(20) NULL AFTER ugc_recognition,
  ADD COLUMN established_year YEAR NULL AFTER naac_grade;

-- ============================================================
-- FROM: migration-college-renewal-letterhead.sql
-- ============================================================
-- ============================================================
-- Migration: College — NAAC/UGC Renewal Dates + College-wise Letterhead
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses
  ADD COLUMN naac_valid_until DATE NULL AFTER naac_grade,
  ADD COLUMN ugc_valid_until DATE NULL AFTER naac_valid_until,
  ADD COLUMN letterhead_logo_path VARCHAR(255) NULL AFTER established_year;

-- ============================================================
-- FROM: migration-college-type-schools.sql
-- ============================================================
-- ============================================================
-- Migration: College Type मध्ये "School" प्रकार जोडणे
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses MODIFY COLUMN college_type
  ENUM('Primary School','Secondary School','Junior College','Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL;

-- ============================================================
-- FROM: migration-trust-level-departments.sql
-- ============================================================
-- ============================================================
-- Migration: Trust-स्तरीय विभाग (कुठल्याही College/School खाली नसलेले)
-- उदा. Trust Office, केंद्रीय हिशोब विभाग, विधी विभाग, इ.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE departments MODIFY COLUMN campus_id INT NULL;

-- ============================================================
-- FROM: migration-trust-hq-type.sql
-- ============================================================
-- ============================================================
-- Migration: Campus/College Type मध्ये "Trust/मुख्य कार्यालय" जोडणे
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses MODIFY COLUMN college_type
  ENUM('Trust HQ','Primary School','Secondary School','Junior College','Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL;

-- ============================================================
-- FROM: migration-work-request-person.sql
-- ============================================================
-- ============================================================
-- Migration: कामाची विनंती — विशिष्ट व्यक्तीला थेट नेमता येण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE work_requests
  ADD COLUMN assigned_to_user_id INT NULL AFTER requesting_department_id,
  ADD FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================================
-- FROM: migration-renewal-advance.sql
-- ============================================================
-- ============================================================
-- Migration: नूतनीकरण Module — Advance Features
-- (Escalating Reminder, History, Vendor Contact)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items
  ADD COLUMN responsible_user_id INT NULL AFTER department_id,
  ADD COLUMN vendor_contact VARCHAR(100) NULL AFTER vendor_name,
  ADD FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE renewal_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  renewal_item_id INT NOT NULL,
  old_expiry_date DATE NOT NULL,
  old_issued_date DATE NULL,
  old_renewal_cost DECIMAL(12,2) NULL,
  old_vendor_name VARCHAR(255) NULL,
  old_reference_no VARCHAR(100) NULL,
  renewed_by INT NULL,
  renewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE,
  FOREIGN KEY (renewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-renewal-regulatory-body.sql
-- ============================================================
-- ============================================================
-- Migration: नूतनीकरण — Government Regulatory Body Tracking
-- (CBSE, State Board, AICTE, DTE, MSBTE, University, UGC, NAAC, इ.)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items
  ADD COLUMN regulatory_body ENUM('CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','Other Government','Not Applicable') NULL AFTER item_type;

-- ============================================================
-- FROM: migration-rto-regulatory-body.sql
-- ============================================================
-- ============================================================
-- Migration: RTO/Transport ला Regulatory Body म्हणून जोडणे
-- (Vehicle Insurance/PUC/Permit/Fitness/Road Tax हे सुद्धा RTO या
-- शासकीय संस्थेचंच Compliance आहे — आता तेही स्पष्ट दिसेल)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items MODIFY COLUMN regulatory_body
  ENUM('CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','RTO/Transport','Other Government','Not Applicable') NULL;

-- आधीच अस्तित्वात असलेल्या वाहन-नूतनीकरण नोंदींना (item_type = 'वाहन नूतनीकरण')
-- आपोआप "RTO/Transport" असं चिन्हांकित करतो.
UPDATE renewal_items SET regulatory_body = 'RTO/Transport' WHERE item_type = 'वाहन नूतनीकरण' AND regulatory_body IS NULL;

-- ============================================================
-- FROM: migration-residency-advance.sql
-- ============================================================
-- ============================================================
-- Migration: रहिवासी विभाग — Advance Features
-- (Billing/Sharing, Allotment Workflow, Inspection, Transfer
-- History, Occupancy Analytics, Guest Registration)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- मासिक भाडं/वीज-बिल — प्रत्येक Room साठी
CREATE TABLE residency_bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  billing_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM स्वरूपात',
  room_rent DECIMAL(10,2) DEFAULT 0,
  electricity_bill DECIMAL(10,2) DEFAULT 0,
  water_bill DECIMAL(10,2) DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL,
  billing_unit_count INT NOT NULL DEFAULT 1 COMMENT 'या Room मधले किती Staff-Occupant — यांच्यात बिल विभागलं',
  per_person_amount DECIMAL(10,2) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_location_month (location_id, billing_month)
) ENGINE=InnoDB;

-- प्रत्येक Bill मधला — कोणत्या Occupant (Staff) चा किती वाटा, भरला की नाही
CREATE TABLE residency_bill_shares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  occupant_id INT NOT NULL,
  amount_due DECIMAL(10,2) NOT NULL,
  status ENUM('pending','paid') DEFAULT 'pending',
  paid_date DATE NULL,
  FOREIGN KEY (bill_id) REFERENCES residency_bills(id) ON DELETE CASCADE,
  FOREIGN KEY (occupant_id) REFERENCES residency_occupants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- औपचारिक खोली-वाटप विनंती (Allotment Request) + प्रतीक्षा-यादी
CREATE TABLE residency_allotment_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  requested_by INT NOT NULL,
  preferred_building_id INT NULL,
  family_size INT DEFAULT 1,
  reason TEXT NULL,
  status ENUM('pending','approved','rejected','waitlisted') DEFAULT 'pending',
  allotted_location_id INT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (preferred_building_id) REFERENCES buildings(id) ON DELETE SET NULL,
  FOREIGN KEY (allotted_location_id) REFERENCES maintenance_locations(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- खोली-तपासणी अहवाल (Room Inspection)
CREATE TABLE residency_inspections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  inspection_date DATE NOT NULL,
  condition_rating ENUM('excellent','good','fair','poor') NOT NULL,
  remarks TEXT NULL,
  inspected_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (inspected_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- खोली-बदल इतिहास (Room Transfer History)
CREATE TABLE residency_room_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  occupant_id INT NOT NULL,
  from_location_id INT NULL,
  to_location_id INT NOT NULL,
  transfer_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  transferred_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (occupant_id) REFERENCES residency_occupants(id) ON DELETE CASCADE,
  FOREIGN KEY (from_location_id) REFERENCES maintenance_locations(id) ON DELETE SET NULL,
  FOREIGN KEY (to_location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- पाहुणे-नोंदणी (Guest Registration)
CREATE TABLE residency_guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  host_occupant_id INT NULL,
  guest_name VARCHAR(255) NOT NULL,
  guest_contact VARCHAR(50) NULL,
  relation VARCHAR(100) NULL,
  purpose VARCHAR(255) NULL,
  arrival_date DATE NOT NULL,
  expected_departure_date DATE NULL,
  actual_departure_date DATE NULL,
  status ENUM('checked_in','checked_out') DEFAULT 'checked_in',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (host_occupant_id) REFERENCES residency_occupants(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-residency-import-advance.sql
-- ============================================================
-- ============================================================
-- Migration: रहिवासी Advance Import — Batch Tracking
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE residency_import_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  filename VARCHAR(255) NULL,
  buildings_created INT DEFAULT 0,
  rooms_created INT DEFAULT 0,
  occupants_created INT DEFAULT 0,
  imported_by INT NULL,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('completed','rolled_back') DEFAULT 'completed',
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE buildings ADD COLUMN import_batch_id INT NULL AFTER campus_id;
ALTER TABLE maintenance_locations ADD COLUMN import_batch_id INT NULL AFTER building_id;
ALTER TABLE residency_occupants ADD COLUMN import_batch_id INT NULL AFTER location_id;

-- ============================================================
-- FROM: migration-residency-paid-datetime.sql
-- ============================================================
-- ============================================================
-- Migration: निवास-बिल भरण्याची — तारीख + वेळ दोन्ही नोंदवण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE residency_bill_shares CHANGE COLUMN paid_date paid_at DATETIME NULL;

-- ============================================================
-- FROM: migration-task-request-cost.sql
-- ============================================================
-- ============================================================
-- Migration: Task/कामाची विनंती — खर्च Tracking + Advance Report
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE tasks ADD COLUMN cost_incurred DECIMAL(10,2) NULL AFTER completion_remarks;
ALTER TABLE work_requests ADD COLUMN cost_incurred DECIMAL(10,2) NULL AFTER work_description;
ALTER TABLE work_requests ADD COLUMN completion_remarks TEXT NULL AFTER cost_incurred;

-- ============================================================
-- FROM: migration-work-record-materials.sql
-- ============================================================
-- ============================================================
-- Migration: दैनंदिन कामात — Store-साहित्य वापर Tracking
-- (Vidyut/Plumbing/Swachata इ. विभागांनी कामासाठी Store मधून घेतलेलं
-- साहित्य — त्या-त्या कामाच्या नोंदीशी Link करण्यासाठी)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_record_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity_used DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES store_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-hostel-permissions-fix.sql
-- ============================================================
-- ============================================================
-- Migration: वसतिगृह Permissions — Management ला Default मध्ये
-- कधीच दिलेले नव्हते, ती चूक दुरुस्त करतो.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_hostels' FROM role_definitions WHERE category = 'management';

INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_hostel_students' FROM role_definitions WHERE category = 'management';

-- ============================================================
-- FROM: migration-extra-dept-access.sql
-- ============================================================
-- ============================================================
-- Migration: लवचिक Department-Visibility — Super Admin एखाद्या
-- विशिष्ट व्यक्तीला, त्याच्या स्वतःच्या विभागाव्यतिरिक्त — निवडक इतर
-- विभागही (किंवा हवं तर संपूर्ण संस्था) बघण्याची परवानगी देऊ शकतो.
-- (Task, नूतनीकरण, कामाची विनंती, Tracker — या सर्वांसाठी लागू)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE user_extra_department_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  department_id INT NOT NULL,
  granted_by INT NULL,
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_dept (user_id, department_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-announcements-documents-emergency.sql
-- ============================================================
-- ============================================================
-- Migration: Circular/Announcement Board, Document Repository,
-- Emergency/SOS Alert
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  priority ENUM('normal','important','urgent') DEFAULT 'normal',
  target_scope ENUM('all','specific') DEFAULT 'all',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE announcement_departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  department_id INT NOT NULL,
  FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE announcement_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_announcement_user (announcement_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category VARCHAR(100) NULL,
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NULL,
  department_id INT NULL,
  uploaded_by INT NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE emergency_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  triggered_by INT NOT NULL,
  alert_type ENUM('fire','medical','security','other') NOT NULL,
  location VARCHAR(255) NULL,
  message TEXT NULL,
  status ENUM('active','resolved') DEFAULT 'active',
  resolved_by INT NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-web-push.sql
-- ============================================================
-- ============================================================
-- Migration: खरं Push Notification (App बंद असतानाही सूचना)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE organizations
  ADD COLUMN vapid_public_key TEXT NULL,
  ADD COLUMN vapid_private_key TEXT NULL;

CREATE TABLE push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  endpoint VARCHAR(512) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_endpoint (endpoint(255))
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-music-department.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-it-general-requests.sql
-- ============================================================
-- ============================================================
-- Migration: सामान्य IT/Internet Request प्रणाली
-- (Internet/Network, Software, नवीन Hardware, Account/Access, इतर)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE it_general_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  request_code VARCHAR(20) NOT NULL UNIQUE,
  category ENUM('internet_network','software','new_hardware','account_access','printer_scanner','other') NOT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(255) NULL COMMENT 'उदा. प्रयोगशाळा 2, दुसरा मजला',
  status ENUM('open','assigned','in_progress','resolved','closed') DEFAULT 'open',
  assigned_to INT NULL,
  resolution_notes TEXT NULL,
  requested_by INT NOT NULL,
  resolved_by INT NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_status_priority (status, priority)
) ENGINE=InnoDB;

CREATE TABLE it_general_request_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  comment TEXT NOT NULL,
  commented_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES it_general_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (commented_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-wishes-wall.sql
-- ============================================================
-- ============================================================
-- Migration: शुभेच्छा-भिंत (Wishes Wall) — वाढदिवस/वर्धापनदिनी
-- सहकाऱ्यांनी टाकलेल्या छोट्या शुभेच्छा
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE birthday_wishes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  target_user_id INT NOT NULL,
  wish_date DATE NOT NULL,
  wish_type ENUM('birthday','anniversary') NOT NULL,
  wish_text VARCHAR(255) NOT NULL,
  posted_by INT NOT NULL,
  posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_wish (target_user_id, wish_date, posted_by)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-hostel-advance.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-software-suggestions.sql
-- ============================================================
-- ============================================================
-- Migration: Software Suggestions/Feature Request Box —
-- Staff/HOD/Management यांना System मध्ये सुधारणा/नवीन कल्पना
-- सुचवता येण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE software_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  category ENUM('new_feature','improvement','bug','other') DEFAULT 'improvement',
  module_name VARCHAR(150) NULL COMMENT 'कुठला Module संबंधित आहे',
  status ENUM('submitted','under_review','planned','implemented','rejected') DEFAULT 'submitted',
  admin_response TEXT NULL,
  responded_by INT NULL,
  responded_at TIMESTAMP NULL,
  submitted_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE software_suggestion_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  suggestion_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (suggestion_id) REFERENCES software_suggestions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_vote (suggestion_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE software_suggestion_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  suggestion_id INT NOT NULL,
  comment TEXT NOT NULL,
  commented_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (suggestion_id) REFERENCES software_suggestions(id) ON DELETE CASCADE,
  FOREIGN KEY (commented_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-daily-work-edit-access.sql
-- ============================================================
-- ============================================================
-- Migration: दैनंदिन कामाची नोंद — Staff ला स्वतःची नोंद Edit
-- करण्याचा Access (Super Admin प्रत्येक व्यक्तीसाठी वेगळा ठरवतो)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN can_edit_daily_work TINYINT(1) DEFAULT 0 AFTER department_id;

-- ============================================================
-- FROM: migration-residency-capacity.sql
-- ============================================================
-- ============================================================
-- Migration: रहिवासी विभाग — Room Capacity + Family Member
-- Employee-Link सुविधा
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE maintenance_locations ADD COLUMN capacity INT NULL AFTER location_code;

-- ============================================================
-- FROM: migration-letters-advance.sql
-- ============================================================
-- ============================================================
-- Migration: पत्रव्यवहार — Advance Features (Thread-Link, Bulk Send)
-- (Attachments आणि Digital Signature System आधीच अस्तित्वात होतं —
-- फक्त हे 2 नवीन Column लागतात.)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================


-- ============================================================
-- FROM: migration-biometric-attendance.sql
-- ============================================================
-- ============================================================
-- Migration: Biometric Attendance Machine Integration (eSSL/
-- ZKTeco-Style ADMS Push Protocol)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Staff ला Device वरचा PIN Number Assign करण्यासाठी (Device Configuration
-- मध्ये प्रत्येक व्यक्तीला एक Numeric PIN दिलेला असतो — तोच इथे जुळवतो)
ALTER TABLE users ADD COLUMN biometric_pin VARCHAR(20) NULL AFTER employee_code;
ALTER TABLE users ADD UNIQUE KEY uniq_biometric_pin (organization_id, biometric_pin);

CREATE TABLE biometric_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  serial_number VARCHAR(50) NOT NULL,
  device_name VARCHAR(150) NULL COMMENT 'उदा. मुख्य प्रवेशद्वार, Trust Office',
  last_seen_at TIMESTAMP NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_serial (serial_number)
) ENGINE=InnoDB;

-- Device वरून येणाऱ्या प्रत्येक Punch ची Raw नोंद (Processing च्या आधीची)
CREATE TABLE biometric_punch_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_serial VARCHAR(50) NOT NULL,
  biometric_pin VARCHAR(20) NOT NULL,
  punch_time DATETIME NOT NULL,
  verify_mode VARCHAR(10) NULL,
  processed TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pin_time (biometric_pin, punch_time),
  INDEX idx_processed (processed)
) ENGINE=InnoDB;

-- रोजची, Processed Attendance (प्रत्येक Staff साठी, प्रत्येक दिवशी एक नोंद)
CREATE TABLE staff_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  first_punch_time TIME NULL,
  last_punch_time TIME NULL,
  total_punches INT DEFAULT 0,
  status ENUM('present','absent','half_day') DEFAULT 'present',
  source ENUM('biometric','manual') DEFAULT 'biometric',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_date (user_id, attendance_date)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-attendance-management-system.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-leave-management.sql
-- ============================================================
-- ============================================================
-- Migration: रजा व्यवस्थापन (Leave Management System)
-- Attendance System शी थेट जोडलेलं — रजा मंजूर झाली की आपोआप
-- staff_attendance मध्ये "on_leave" म्हणून दिसेल.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(100) NOT NULL COMMENT 'उदा. Casual Leave, Sick Leave, Earned Leave',
  name_local VARCHAR(100) NOT NULL COMMENT 'उदा. प्रासंगिक रजा, आजारपणाची रजा',
  annual_quota DECIMAL(5,1) NOT NULL DEFAULT 12 COMMENT 'वर्षाला किती दिवस मिळतात',
  is_paid TINYINT(1) DEFAULT 1,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- प्रत्येक Staff चा, प्रत्येक Leave-प्रकारासाठी, प्रत्येक वर्षीचा कोटा +
-- वापरलेले दिवस
CREATE TABLE leave_balances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  leave_type_id INT NOT NULL,
  year INT NOT NULL,
  allocated_days DECIMAL(5,1) NOT NULL,
  used_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_type_year (user_id, leave_type_id, year)
) ENGINE=InnoDB;

CREATE TABLE leave_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  leave_type_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_days DECIMAL(5,1) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-seed-leave-types.sql
-- ============================================================
-- ============================================================
-- Migration: मानक 4 रजा-प्रकार Seed करणे — CL, CO, Medical, Vacation
-- सगळ्या Organizations साठी आपोआप (आधीच असेल तर वगळलं जाईल)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'CL (Casual Leave)', 'प्रासंगिक रजा (CL)', 12, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'CL%');

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'CO (Compensatory Off)', 'भरपाई सुट्टी (CO)', 6, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'CO%');

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'Medical Leave', 'आजारपणाची रजा (Medical)', 12, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'Medical%');

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'Vacation Leave', 'नियमित सुट्टी (Vacation)', 15, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'Vacation%');

-- ============================================================
-- FROM: migration-manage-attendance-permission.sql
-- ============================================================
-- ============================================================
-- Migration: "कर्मचारी हजेरी व्यवस्थापन" साठी नवीन, स्वतंत्र Permission
-- (manage_attendance) — जाणूनबुजून कोणालाही Auto-Grant केलेलं नाही,
-- Super Admin ने "Roles & Permissions" मधून हवं त्याला (उदा. HR) द्यावं.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_attendance', 'Manage staff attendance (mark manually, edit monthly grid, generate certificates) — typically HR', 'कर्मचारी हजेरी व्यवस्थापन (Manually नोंदवणे, Monthly Grid, प्रमाणपत्र) — साधारणतः HR साठी', 22);

-- ============================================================
-- FROM: migration-store-department-link.sql
-- ============================================================
-- ============================================================
-- Migration: Store Items ला Department-Link जोडणे
-- (भोजनालय/इतर कुठल्याही विभागासाठी — स्वतंत्र Live Stock Dashboard)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE store_items ADD COLUMN department_id INT NULL AFTER category;
ALTER TABLE store_items ADD CONSTRAINT fk_store_items_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- ============================================================
-- FROM: migration-virtual-classroom.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-virtual-classroom-advance.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-fix-activity-log-timezone.sql
-- ============================================================
-- ============================================================
-- Migration: Activity Log — वेळ 5.5 तास चुकीची दिसण्याची समस्या
-- कायमची दुरुस्त करणे
-- ============================================================
-- कारण: created_at column आधी TIMESTAMP प्रकाराचा होता — MySQL असे
-- Columns आतून नेहमी UTC मध्ये साठवतो आणि वाचताना Session-Timezone
-- नुसार परत रूपांतरित करतो. Shared-Hosting वर हे Session-Setting कधीकधी
-- सुसंगतपणे लागू होत नाही, त्यामुळे वेळ 5.5 तास पुढे/मागे दिसत होती.
--
-- उपाय: Column ला DATETIME मध्ये बदलतो — हा प्रकार कधीच Timezone-Convert
-- करत नाही, जो दिलेला वेळ जसाच्या तसा साठवतो/दाखवतो — त्यामुळे ही समस्या
-- कायमची जाईल (PHP कडून आता आधीच बरोबर IST वेळ पाठवली जाते).
-- ============================================================

ALTER TABLE activity_logs MODIFY COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- FROM: migration-module-permissions.sql
-- ============================================================
-- ============================================================
-- Migration: 3 नवीन Module Permissions — Virtual Classroom, संगीत
-- विभाग (आधी पूर्णपणे Unprotected होता!), ध्यान व सत्संग
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_academics', 'Manage Virtual Classroom (students, classrooms, materials, assignments) for any department — beyond own HOD scope', 'Virtual Classroom व्यवस्थापन (कोणत्याही विभागासाठी — स्वतःच्या HOD-मर्यादेपलीकडे)', 24),
('manage_music', 'Manage Music Department (instruments, students, exams, events, bookings)', 'संगीत विभाग व्यवस्थापन (वाद्य, विद्यार्थी, परीक्षा, कार्यक्रम, Booking)', 25),
('manage_meditation', 'Manage Meditation & Satsang (duty chart, quotes, events) — beyond top Management', 'ध्यान व सत्संग व्यवस्थापन (Duty Chart, सुविचार, कार्यक्रम) — फक्त वरिष्ठ Management शिवायही', 26);

-- Super Admin ला हे Permission आपोआप देतो — बाकी कोणालाही Auto-Grant
-- केलेलं नाही (जाणूनबुजून — Super Admin नंतर Roles & Permissions मधून
-- हवं त्याला निवडून देऊ शकतो).

-- ============================================================
-- FROM: migration-content-permissions.sql
-- ============================================================
-- ============================================================
-- Migration: आणखी 3 Permissions — Announcements, Documents,
-- Suggestions (आधी चुकीने "Dashboard पाहण्याचं" Permission वापरत होते)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_announcements', 'Post organization-wide announcements/circulars', 'संस्था-व्यापी परिपत्रकं/सूचना पोस्ट करणे', 27),
('manage_documents', 'Upload documents to the shared document repository', 'सामायिक Document Repository मध्ये File Upload करणे', 28),
('manage_suggestions', 'Respond to and update status of staff suggestions', 'Staff च्या सुविचार/सूचनांना उत्तर देणे, स्थिती बदलणे', 29),
('view_emergency_alerts', 'View and resolve Emergency/SOS alerts triggered by staff', 'Staff कडून आलेले Emergency/SOS Alerts पाहणे व सोडवणे', 30);

-- सध्या ज्यांच्याकडे 'view_org_wide_dashboards' Permission आहे — त्यांना
-- हे नवीन 4 Permissions आपोआप देतो (आधीचा Access तसाच राहावा म्हणून —
-- Existing Users साठी Backward-Compatible).
INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_announcements' FROM role_permissions WHERE permission_key = 'view_org_wide_dashboards';
INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_documents' FROM role_permissions WHERE permission_key = 'view_org_wide_dashboards';
INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_suggestions' FROM role_permissions WHERE permission_key = 'view_org_wide_dashboards';
INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'view_emergency_alerts' FROM role_permissions WHERE permission_key = 'view_org_wide_dashboards';

-- ============================================================
-- FROM: migration-remove-po-from-pending-register.sql
-- ============================================================
-- ============================================================
-- Migration: खरेदी बाकी नोंदवही — PO क्र./दिनांक Columns काढणे
-- (ही Register पूर्णपणे Standalone राहणार आहे — Formal PO System
-- शी कुठलाही संबंध नको, म्हणून हे 2 Columns काढतो.)
-- ============================================================

ALTER TABLE purchase_pending_register DROP COLUMN po_no;
ALTER TABLE purchase_pending_register DROP COLUMN po_date;

-- ============================================================
-- FROM: migration-maintenance-mode.sql
-- ============================================================
-- ============================================================
-- Migration: Site Maintenance Mode
-- Super Admin एका Click वर संपूर्ण Site "Under Maintenance" करू शकेल —
-- Super Admin सोडून सगळ्यांना एक सूचना-पान दिसेल (आवाजासह).
-- ============================================================

ALTER TABLE organizations ADD COLUMN maintenance_mode TINYINT(1) DEFAULT 0 AFTER is_active;
ALTER TABLE organizations ADD COLUMN maintenance_message TEXT NULL AFTER maintenance_mode;
ALTER TABLE organizations ADD COLUMN maintenance_started_at TIMESTAMP NULL AFTER maintenance_message;

-- ============================================================
-- FROM: migration-staff-track-tiered-access.sql
-- ============================================================
-- ============================================================
-- Migration: Staff Profile/Track-Record — Tiered Visibility
-- (स्वतःचा विभाग → अनेक विभाग → एक कॉलेज → अनेक कॉलेज → संपूर्ण संस्था)
-- ============================================================

-- "एक कॉलेज" (स्वतःच्या Campus मधले सगळे विभाग) आणि "संपूर्ण संस्था"
-- साठी नवीन Permissions
INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('view_staff_track_own_campus', 'View staff profile/track record for ALL departments within own campus', 'स्वतःच्या Campus मधल्या सगळ्या विभागांचे Staff Profile/Track Record पाहणे', 32),
('view_staff_track_org_wide', 'View staff profile/track record across the ENTIRE organization', 'संपूर्ण संस्थेतल्या सगळ्यांचे Staff Profile/Track Record पाहणे', 33);

-- "अनेक कॉलेज" — विशिष्ट व्यक्तीला, विशिष्ट Extra Campuses चा Access
-- देण्यासाठी (user_extra_department_access च्याच Pattern प्रमाणे)
CREATE TABLE user_extra_campus_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  campus_id INT NOT NULL,
  granted_by INT NULL,
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_campus (user_id, campus_id)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-pending-register-nullable-dept.sql
-- ============================================================
-- ============================================================
-- Migration: खरेदी बाकी नोंदवही — Bulk Import मध्ये विभाग जुळला
-- नाही तरीही Row Skip न होता Import व्हावी
-- ============================================================

ALTER TABLE purchase_pending_register MODIFY COLUMN department_id INT NULL;
ALTER TABLE purchase_pending_register ADD COLUMN unmatched_department_text VARCHAR(255) NULL COMMENT 'Import करताना टाइप केलेलं विभागाचं नाव — System मधल्या कुठल्याही विभागाशी जुळलं नाही तर, ते इथे जपून ठेवतो' AFTER department_id;

-- ============================================================
-- FROM: migration-events-guests.sql
-- ============================================================
-- ============================================================
-- Migration: Event Management + Guest Management + Residency-Staff
-- Link Integrity — सगळं Central `users`/`departments` Table शीच Link,
-- कुठेही Duplicate Staff Entity तयार होणार नाही.
-- ============================================================

-- 🎉 कार्यक्रम व्यवस्थापन
CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  event_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  venue VARCHAR(255) NULL,
  responsible_user_id INT NULL COMMENT 'users.id — जबाबदार व्यक्ती, Duplicate Entity नाही',
  status ENUM('planned','ongoing','completed','cancelled') DEFAULT 'planned',
  budget_estimated DECIMAL(12,2) NULL,
  budget_actual DECIMAL(12,2) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  assigned_to INT NULL COMMENT 'users.id',
  due_date DATE NULL,
  status ENUM('pending','in_progress','done') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE event_budget_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  item_description VARCHAR(255) NOT NULL,
  estimated_amount DECIMAL(12,2) NULL,
  actual_amount DECIMAL(12,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(255) NULL,
  uploaded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 🎫 पाहुणे व्यवस्थापन (Trust-Level Guests — Residency च्या
-- residency_guests पेक्षा वेगळं, ते फक्त वसतिगृह/निवासस्थानाच्या पाहुण्यांसाठी
-- होतं; हे संपूर्ण संस्थेत येणाऱ्या सगळ्या पाहुण्यांसाठी)
CREATE TABLE guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  mobile VARCHAR(15) NULL,
  guest_organization VARCHAR(255) NULL,
  purpose VARCHAR(255) NULL,
  meeting_with INT NULL COMMENT 'users.id — कोणाला भेटायला आले, Duplicate Entity नाही',
  check_in_time DATETIME NOT NULL,
  check_out_time DATETIME NULL,
  pass_code VARCHAR(20) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (meeting_with) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- नवीन Permissions
INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_events', 'Create and manage events', 'कार्यक्रम व्यवस्थापन', 34),
('manage_guests', 'Log and manage visitor/guest entries', 'पाहुणे नोंदणी व्यवस्थापन', 35);

-- ============================================================
-- FROM: migration-event-guest-advance.sql
-- ============================================================
-- ============================================================
-- Migration: Event/Guest Management — Advance Features
-- (Recurring Events, Attendance, Certificate, Guest Photo,
-- Pre-Registration, वारंवार येणारे पाहुणे)
-- ============================================================

ALTER TABLE events ADD COLUMN is_recurring TINYINT(1) DEFAULT 0 AFTER status;
ALTER TABLE events ADD COLUMN recurrence_pattern ENUM('yearly','monthly') NULL AFTER is_recurring;
ALTER TABLE events ADD COLUMN parent_event_id INT NULL COMMENT 'मूळ Recurring Event — पुढच्या वर्षीच्या Auto-Created Event साठी' AFTER recurrence_pattern;
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

-- ============================================================
-- FROM: migration-it-power-status.sql
-- ============================================================
-- ============================================================
-- Migration: IT Assets — Live Power ON/OFF Status
-- (Condition-Status 'working/faulty' पासून वेगळं — हे "आत्ता चालू आहे
-- का बंद आहे" हे रोजचं, Quick Status आहे)
-- ============================================================

ALTER TABLE it_assets ADD COLUMN power_status ENUM('on','off') DEFAULT 'off' AFTER status;
ALTER TABLE it_assets ADD COLUMN power_status_updated_at TIMESTAMP NULL AFTER power_status;
ALTER TABLE it_assets ADD COLUMN power_status_updated_by INT NULL AFTER power_status_updated_at;
ALTER TABLE it_assets ADD CONSTRAINT fk_it_assets_power_updater FOREIGN KEY (power_status_updated_by) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================================
-- FROM: migration-restricted-access-mode.sql
-- ============================================================
-- ============================================================
-- Migration: Single-Purpose Access Mode (Module-Restricted Roles)
-- एखादा Role फक्त 1-2 Specific Modules शीच मर्यादित ठेवण्यासाठी —
-- अशा Role च्या व्यक्तीला Login केल्यावर संपूर्ण System ऐवजी फक्त
-- त्यांचाच Module दिसेल.
-- ============================================================

ALTER TABLE role_definitions ADD COLUMN restricted_mode TINYINT(1) DEFAULT 0 AFTER is_system;
ALTER TABLE role_definitions ADD COLUMN restricted_modules VARCHAR(255) NULL COMMENT 'Comma-separated Module Keys — उदा. it,store' AFTER restricted_mode;

-- ============================================================
-- FROM: migration-onboarding-tracking.sql
-- ============================================================
-- ============================================================
-- Migration: Onboarding Tour Tracking
-- ============================================================
ALTER TABLE users ADD COLUMN onboarding_seen TINYINT(1) DEFAULT 0 AFTER must_change_password;

-- 10. Student/Parent Feedback (ऐच्छिक — Simple Rating)
-- ============================================================
-- FROM: migration-teaching-staff.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-profile-advance.sql
-- ============================================================
-- ============================================================
-- Migration: Personal Profile — Advance Features
-- (Emergency Contact, Document Vault, Personal Achievements)
-- ============================================================

ALTER TABLE users ADD COLUMN emergency_contact_name VARCHAR(255) NULL AFTER mobile;
ALTER TABLE users ADD COLUMN emergency_contact_relation VARCHAR(100) NULL AFTER emergency_contact_name;
ALTER TABLE users ADD COLUMN emergency_contact_mobile VARCHAR(20) NULL AFTER emergency_contact_relation;

CREATE TABLE staff_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  document_type VARCHAR(100) NOT NULL COMMENT 'उदा. Aadhar, PAN, Degree Certificate',
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE staff_achievements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  achievement_date DATE NOT NULL,
  added_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-help-center.sql
-- ============================================================
-- ============================================================
-- Migration: Help Center / User Manual (System च्या आतच)
-- ============================================================

CREATE TABLE help_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  icon VARCHAR(60) DEFAULT 'fa-solid fa-circle-question',
  sort_order INT DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE help_articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  content MEDIUMTEXT NOT NULL COMMENT 'HTML — Step-by-Step सूचना',
  keywords VARCHAR(500) NULL COMMENT 'Search साठी — Comma-Separated',
  sort_order INT DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES help_categories(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO help_categories (name, icon, sort_order) VALUES
('सुरुवात कशी करावी', 'fa-solid fa-play', 1),
('दैनंदिन कामकाज', 'fa-solid fa-pen', 2),
('उपस्थिती व रजा', 'fa-solid fa-calendar-check', 3),
('पत्रव्यवहार', 'fa-solid fa-envelope', 4),
('Task व कामाची विनंती', 'fa-solid fa-list-check', 5),
('खरेदी व भांडार', 'fa-solid fa-cart-shopping', 6),
('कर्मचारी व्यवस्थापन', 'fa-solid fa-users', 7),
('Virtual Classroom (Teaching)', 'fa-solid fa-chalkboard-user', 8),
('Backup व Settings', 'fa-solid fa-gears', 9);

INSERT INTO help_articles (category_id, title, content, keywords, sort_order) VALUES
(1, 'Login कसं करायचं',
'<p>प्रत्येक कर्मचाऱ्याला Email आणि Password दिलेला असतो — तो HR/Super Admin कडून मिळतो.</p>
<ol>
<li>Website उघडा — तुमच्या संस्थेचा Link</li>
<li>वरच्या "Login" Button वर Click करा</li>
<li>Email आणि Password टाका</li>
<li>पहिल्यांदा Login करताना — नवीन Password सेट करायला सांगेल, तो लक्षात ठेवा</li>
</ol>
<p><b>Password विसरलात?</b> Login Page वर "Password विसरलात?" Link वर Click करा — Email वर Reset-Link येईल.</p>',
'login, पासवर्ड, प्रवेश', 1),

(1, 'Command Center — मुख्य पान समजून घ्या',
'<p>Login केल्यावर तुम्हाला "Command Center" दिसतं — हे तुमचं मुख्य Dashboard आहे.</p>
<ul>
<li><b>डावीकडे Sidebar</b> — तुम्हाला दिलेले सगळे Modules इथे दिसतात</li>
<li><b>वरती Search Bar</b> — कुठेही, काहीही पटकन शोधण्यासाठी (Ctrl+K दाबूनही उघडता येतं)</li>
<li><b>मधोमध Numbers/Charts</b> — तुमच्या कामाचा आजचा सारांश</li>
</ul>',
'dashboard, command center, मुख्य पान', 2),

(2, 'रोजची काम-नोंद कशी करायची',
'<ol>
<li>Sidebar मध्ये "दैनंदिन काम-नोंद" वर Click करा</li>
<li>"+ नवीन नोंद" Button दाबा</li>
<li>कामाचं वर्णन, दिनांक, आणि आवश्यक असल्यास Photo टाका</li>
<li>"जतन करा" दाबा</li>
</ol>
<p>तुमचा HOD/Management ही नोंद बघू शकतील आणि आवश्यक असल्यास Feedback देऊ शकतील.</p>',
'daily work, काम नोंद, रोजचं काम', 1),

(3, 'Attendance कशी मार्क होते',
'<p>बहुतेक ठिकाणी Attendance Biometric Machine वरून आपोआप येते. पण Manual नोंदही करता येते (HOD/Management साठी).</p>
<ol>
<li>Sidebar → "उपस्थिती" वर जा</li>
<li>Calendar वर तुमची महिन्याची Attendance दिसेल</li>
<li>कुठे चूक वाटली, तर "Regularization Request" पाठवू शकता — HOD Approve करेल</li>
</ol>',
'attendance, हजेरी, उपस्थिती', 1),

(3, 'रजेसाठी अर्ज कसा करायचा',
'<ol>
<li>Sidebar → "रजा अर्ज करा" वर जा</li>
<li>कोणती रजा (CL/Medical/Vacation), किती दिवस, कारण — भरा</li>
<li>"पाठवा" दाबा — तुमचा HOD ला Notification जाईल</li>
<li>Approve झाल्यावर — ती आपोआप तुमच्या Attendance मध्ये "रजेवर" म्हणून नोंदवली जाईल</li>
</ol>
<p>तुमची शिल्लक रजा — "माझी रजा" Page वर कायम दिसते.</p>',
'leave, रजा, सुट्टी', 2),

(4, 'नवीन पत्र कसं तयार करायचं',
'<ol>
<li>Sidebar → "पत्रव्यवहार" → "नवीन पत्र"</li>
<li>कोणत्या विभागाला/College ला पत्र आहे ते निवडा</li>
<li>विषय आणि तपशील लिहा</li>
<li>गरज असल्यास Attachment जोडा</li>
<li>"पाठवा" दाबा — Workflow-नुसार योग्य व्यक्तीकडे Approval साठी जाईल</li>
</ol>',
'letter, पत्र, correspondence', 1),

(5, 'Task कसं Complete करायचं',
'<ol>
<li>Sidebar → "Task" वर जा — तुम्हाला दिलेली सगळी Tasks दिसतील</li>
<li>ज्या Task वर काम केलंय, त्यावर Click करा</li>
<li>Status "पूर्ण झालं" करा, आवश्यक असल्यास टिप्पणी लिहा</li>
</ol>',
'task, काम, complete', 1),

(5, 'कामाची विनंती (Work Request) कशी पाठवायची',
'<p>हे Feature — कोणत्याही विभागाला, दुरुस्ती/मदत हवी असल्यास वापरतात.</p>
<ol>
<li>Sidebar → "कामाची विनंती" → "नवीन विनंती"</li>
<li>कोणत्या विभागाला विनंती आहे, आणि तपशील लिहा</li>
<li>पाठवल्यावर — त्या विभागाला Notification जाईल, ते Status Update करत राहतील</li>
</ol>',
'work request, विनंती, दुरुस्ती', 2),

(6, 'खरेदीची Requisition कशी करायची',
'<ol>
<li>Sidebar → "खरेदी व भांडार" → "नवीन Requisition"</li>
<li>कोणती वस्तू, किती Quantity, कशासाठी हवी — भरा</li>
<li>पाठवल्यावर — Approval-Chain नुसार पुढे जाईल (HOD → Management → Purchase Dept)</li>
</ol>',
'purchase, खरेदी, requisition', 1),

(7, 'नवीन कर्मचारी कसा जोडायचा (Super Admin/HR साठी)',
'<ol>
<li>Sidebar → "कर्मचारी" → "+ नवीन कर्मचारी जोडा"</li>
<li>नाव, Email, Mobile, विभाग, पद — भरा</li>
<li>Role निवडा (Staff/HOD/Management)</li>
<li>"जतन करा" — आपोआप Login Details असलेला Email त्यांना जाईल</li>
</ol>',
'staff, कर्मचारी, नवीन, add employee', 1),

(8, 'शिक्षकांसाठी — Teaching Module काय आहे',
'<p>जर तुम्ही "Teaching" Staff असाल, तर Sidebar मध्ये "Teaching Module" दिसेल — इथे:</p>
<ul>
<li><b>माझं Timetable</b> — तुमचं आठवड्याचं Lecture-Schedule</li>
<li><b>Syllabus Tracker</b> — किती Chapters पूर्ण झाले याची नोंद</li>
<li><b>Substitute</b> — तुम्ही रजेवर असाल तर कोण Class घेणार</li>
<li><b>Exam Duty</b> — तुमची Invigilation Duty कधी आहे</li>
</ul>',
'teaching, शिक्षक, lecture, timetable', 1),

(9, 'Backup कसा घ्यायचा (Super Admin साठी)',
'<ol>
<li>Sidebar → "System Update" → "Backup"</li>
<li>"Data Download करा" — साधा Backup (जलद)</li>
<li>"Full Backup (Files सकट)" — सगळे Photo/Document सुद्धा हवे असतील तर</li>
</ol>
<p>ही File सुरक्षित ठिकाणी (Google Drive, Pendrive) ठेवा — गरज पडल्यास "Restore" Page वरून परत आणता येईल.</p>',
'backup, बॅकअप, restore', 1);

-- ============================================================
-- FROM: migration-checkout-system.sql
-- ============================================================
-- ============================================================
-- Migration: Asset Checkout/Borrowing System
-- (Projector, चावी, इतर वस्तू — कोणी घेतली, कधी परत करणार)
-- ============================================================

CREATE TABLE checkout_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL COMMENT 'उदा. Projector, चावी, Camera',
  default_location VARCHAR(150) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE checkout_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  borrowed_by INT NOT NULL,
  issued_by INT NOT NULL,
  purpose VARCHAR(255) NULL,
  checkout_at DATETIME NOT NULL,
  expected_return_at DATETIME NULL,
  actual_return_at DATETIME NULL,
  returned_to INT NULL,
  remarks VARCHAR(255) NULL,
  FOREIGN KEY (item_id) REFERENCES checkout_items(id) ON DELETE CASCADE,
  FOREIGN KEY (borrowed_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (returned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_checkout', 'Manage asset checkout/borrowing (issue, receive return)', 'Asset Checkout/Borrowing व्यवस्थापन', 37);

-- ============================================================
-- FROM: migration-asset-allocations.sql
-- ============================================================
-- ============================================================
-- Migration: Asset Allocation Registry
-- (कोणाला काय Allot केलं — Xerox, PC, इ. — Super Admin ठरवेल Fields)
-- Current + Full History दोन्ही — unallocated_date NULL असेल तर
-- "अजूनही त्याच्याकडे आहे", असेल तर "आधी होतं, आता नाही".
-- ============================================================

CREATE TABLE asset_allocation_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL COMMENT 'उदा. Xerox Machine, Computer/PC',
  icon VARCHAR(60) DEFAULT 'fa-solid fa-box',
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE asset_allocation_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_id INT NOT NULL,
  field_label VARCHAR(150) NOT NULL COMMENT 'उदा. Model No., RAM/Storage',
  field_key VARCHAR(100) NOT NULL COMMENT 'JSON-Key साठी, Auto-Generate होतं',
  field_type ENUM('text','number','dropdown','date','yesno') DEFAULT 'text',
  field_options TEXT NULL COMMENT 'Dropdown असेल तर — Comma-Separated Options',
  is_required TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE asset_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  type_id INT NOT NULL,
  campus_id INT NOT NULL,
  user_id INT NOT NULL COMMENT 'कोणाला Allot केलं',
  asset_label VARCHAR(150) NULL COMMENT 'उदा. "Xerox #2", "PC-Workstation 5" — Quick Identify साठी',
  custom_data TEXT NULL COMMENT 'Super Admin ने ठरवलेल्या Fields चा Data — JSON-Encoded String',
  allocated_date DATE NOT NULL,
  unallocated_date DATE NULL COMMENT 'NULL = अजूनही याच्याकडे आहे',
  unallocated_reason VARCHAR(255) NULL,
  allocated_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_asset_allocations', 'Manage asset allocation types and records', 'Asset Allocation Registry व्यवस्थापन', 38);

-- ============================================================
-- FROM: migration-letters-dispatch.sql
-- ============================================================
-- ============================================================
-- Migration: पत्रव्यवहार — Dispatch Mode Tracking
-- ============================================================
ALTER TABLE letters ADD COLUMN dispatch_mode ENUM('Post','Courier','Speed Post','Email','Hand Delivery','Other') NULL AFTER outward_no;
ALTER TABLE letters ADD COLUMN dispatch_tracking_no VARCHAR(100) NULL AFTER dispatch_mode;
ALTER TABLE letters ADD COLUMN dispatch_date DATE NULL AFTER dispatch_tracking_no;

-- ============================================================
-- FROM: migration-attachment-remarks.sql
-- ============================================================
-- ============================================================
-- Migration: पत्रव्यवहार — Attachment-Level Remarks
-- ============================================================

-- ============================================================
-- FROM: migration-attachment-remark-position.sql
-- ============================================================
-- ============================================================
-- Migration: Attachment-Remarks — Image वर थेट Point करून Comment
-- ============================================================

-- ============================================================
-- FROM: migration-room-booking-messaging-grievances.sql
-- ============================================================
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

-- ============================================================
-- FROM: migration-gaushala.sql
-- ============================================================
-- ============================================================
-- Migration: गौशाळा (Gaushala) Module
-- ============================================================

-- 1. गाय/जनावर Registry (मुख्य)
CREATE TABLE gaushala_cattle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  tag_number VARCHAR(50) NOT NULL,
  name VARCHAR(150) NULL,
  breed VARCHAR(100) NULL,
  gender ENUM('Male','Female') NOT NULL,
  color VARCHAR(100) NULL,
  date_of_birth DATE NULL,
  acquisition_type ENUM('Birth','Donation','Purchase','Other') DEFAULT 'Other',
  acquisition_date DATE NULL,
  source_details VARCHAR(255) NULL COMMENT 'कोणाकडून आलं — दानदाता/विक्रेता',
  mother_cattle_id INT NULL,
  caretaker_id INT NULL,
  photo_path VARCHAR(255) NULL,
  status ENUM('active','sold','deceased','donated_out') DEFAULT 'active',
  status_date DATE NULL,
  status_remarks VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (mother_cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (caretaker_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_tag (organization_id, tag_number)
) ENGINE=InnoDB;

-- 2. आरोग्य नोंदी
CREATE TABLE gaushala_health_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL,
  record_type ENUM('Vaccination','Illness','Treatment','Checkup','Deworming') DEFAULT 'Checkup',
  description TEXT NOT NULL,
  vet_name VARCHAR(150) NULL,
  record_date DATE NOT NULL,
  next_due_date DATE NULL,
  cost DECIMAL(10,2) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. दूध उत्पादन
CREATE TABLE gaushala_milk_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL,
  record_date DATE NOT NULL,
  session ENUM('Morning','Evening') NOT NULL,
  quantity_liters DECIMAL(5,2) NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_milk_entry (cattle_id, record_date, session)
) ENGINE=InnoDB;

-- 4. चारा व्यवस्थापन (Fodder)
CREATE TABLE gaushala_fodder_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(30) DEFAULT 'Kg',
  current_stock DECIMAL(10,2) DEFAULT 0,
  reorder_level DECIMAL(10,2) DEFAULT 0,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gaushala_fodder_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fodder_type_id INT NOT NULL,
  transaction_type ENUM('in','out') NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  transaction_date DATE NOT NULL,
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (fodder_type_id) REFERENCES gaushala_fodder_types(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. प्रजनन नोंदी (Breeding)
CREATE TABLE gaushala_breeding_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL COMMENT 'मादी (Female) — गाभण गाय',
  breeding_date DATE NOT NULL,
  breeding_method ENUM('Natural','Artificial Insemination') DEFAULT 'Natural',
  expected_calving_date DATE NULL,
  actual_calving_date DATE NULL,
  calf_cattle_id INT NULL COMMENT 'जन्मलेलं वासरू — नवीन Registry-Entry शी Link',
  status ENUM('pregnant','delivered','miscarriage') DEFAULT 'pregnant',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (calf_cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. दान/Sponsorship
CREATE TABLE gaushala_sponsorships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NULL COMMENT 'NULL असेल तर — संपूर्ण गौशाळेसाठी सर्वसाधारण देणगी',
  organization_id INT NOT NULL,
  donor_name VARCHAR(255) NOT NULL,
  donor_contact VARCHAR(50) NULL,
  amount DECIMAL(10,2) NOT NULL,
  sponsorship_period ENUM('One-Time','Monthly','Yearly') DEFAULT 'One-Time',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('active','completed','cancelled') DEFAULT 'active',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_gaushala', 'Manage Gaushala — cattle registry, health, milk, fodder, breeding, sponsorship', 'गौशाळा व्यवस्थापन', 44);

-- ============================================================
-- FROM: migration-gaushala-optional-tag.sql
-- ============================================================
-- ============================================================
-- Migration: गौशाळा — Tag/Batch-Number ऐच्छिक + Animal Category
-- (Batch-Number नसलेली गुरं — वासरू, गोऱ्हा — सुद्धा जोडता यावीत)
-- ============================================================
ALTER TABLE gaushala_cattle MODIFY COLUMN tag_number VARCHAR(50) NULL;
ALTER TABLE gaushala_cattle ADD COLUMN animal_category ENUM('Cow','Calf','Young Bull','Bull/Ox') DEFAULT 'Cow' AFTER gender;

-- ============================================================
-- FROM: migration-gaushala-approx-age.sql
-- ============================================================
-- ============================================================
-- Migration: गौशाळा — अंदाजे वय (जन्म-दिनांक माहीत नसलेल्या जनावरांसाठी)
-- ============================================================
ALTER TABLE gaushala_cattle ADD COLUMN approx_age_months INT NULL AFTER date_of_birth;

-- ============================================================
-- FROM: migration-dept-finance.sql
-- ============================================================
-- ============================================================
-- Migration: विभाग-निहाय Material Purchase + Shared Payment Ledger
-- + Recurring Monthly Bill (Internet सारखे मासिक Payment-Contracts)
-- ============================================================

-- 1. Material Purchase — HOD थेट नोंदवतो
CREATE TABLE dept_material_purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  material_name VARCHAR(255) NOT NULL,
  vendor_name VARCHAR(255) NOT NULL,
  vendor_contact VARCHAR(100) NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit VARCHAR(50) NULL,
  rate DECIMAL(12,2) NOT NULL,
  base_amount DECIMAL(12,2) NOT NULL COMMENT 'Quantity x Rate',
  transport_cost DECIMAL(12,2) DEFAULT 0,
  gst_percent DECIMAL(5,2) DEFAULT 0,
  gst_amount DECIMAL(12,2) DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL COMMENT 'base + transport + gst',
  purchase_date DATE NOT NULL,
  remarks VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. सामायिक Payment Ledger — Material Purchase, Renewal, Maintenance
-- तिन्हींना जोडतो (Polymorphic — source_type + source_id, DB-Level FK
-- नाही कारण ते 3 वेगवेगळ्या Tables कडे जातं; Application-Level तपासतो)
CREATE TABLE dept_payment_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  source_type ENUM('material_purchase','renewal','maintenance') NOT NULL,
  source_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_type ENUM('Advance','Partial','Final') DEFAULT 'Partial',
  payment_mode VARCHAR(50) NULL COMMENT 'उदा. Cash, Cheque, Online',
  for_month DATE NULL COMMENT 'Recurring-Bill असेल तर — कोणत्या महिन्याचं Payment (महिन्याची 1 तारीख साठवतो)',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_source (source_type, source_id)
) ENGINE=InnoDB;

-- 3. Renewal-Items ला Recurring-Monthly-Bill Support जोडतो (Internet,
-- Monthly Subscription सारख्या — वार्षिक Contract, पण Payment दर महिन्याला)
ALTER TABLE renewal_items ADD COLUMN is_recurring_monthly TINYINT(1) DEFAULT 0 AFTER renewal_cost;
ALTER TABLE renewal_items ADD COLUMN monthly_amount DECIMAL(10,2) NULL AFTER is_recurring_monthly;
ALTER TABLE renewal_items ADD COLUMN recurring_start_month DATE NULL COMMENT 'कोणत्या महिन्यापासून सुरू — महिन्याची 1 तारीख साठवतो' AFTER monthly_amount;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_dept_finance', 'Record department material purchases and track payments', 'विभाग Material Purchase व Payment Tracking', 45);

-- ============================================================
-- FROM: migration-store-material-master.sql
-- ============================================================
-- ============================================================
-- Migration: भांडार Material-Master (Daily-Work च्या "वापरलेलं
-- साहित्य" Feature साठी आवश्यक — Purchase/Store चं Formal
-- Workflow काढलं, फक्त ही Simple Item-List ठेवली)
-- ============================================================

CREATE TABLE store_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  unit VARCHAR(50) NOT NULL,
  reorder_level DECIMAL(10,2) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_store', 'Manage the Material-Master list used by Daily-Work', 'भांडार Material-यादी व्यवस्थापणे', 19);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_store' FROM role_definitions WHERE category = 'management';


-- ============================================================
-- FROM: migration-android-app.sql
-- ============================================================
-- Android App (FCM push) — device tokens
CREATE TABLE IF NOT EXISTS device_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(255) NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  app_version VARCHAR(20) NULL,
  last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_token (token),
  INDEX idx_user (user_id)
) ENGINE=InnoDB;


-- ============================================================
-- FROM: migration-letters-simplify.sql
-- ============================================================
-- ============================================================
-- पत्र व्यवहार सोपा केला — Clerk-Gatekeeper पद्धत काढली.
-- Clerk च्या टेबलावर अडकलेली पत्रं थेट संबंधित अधिकाऱ्याकडे हलवतो.
-- (कितीही वेळा चालवलं तरी सुरक्षित)
-- ============================================================
UPDATE letters SET current_holder_id = intended_recipient_id, clerk_stage = 'none', intended_recipient_id = NULL, current_holder_seen_at = NULL
  WHERE clerk_stage = 'awaiting_inward' AND intended_recipient_id IS NOT NULL;
UPDATE letters SET current_holder_id = pending_forward_to, clerk_stage = 'none', pending_forward_to = NULL, current_holder_seen_at = NULL
  WHERE clerk_stage = 'awaiting_forward_approval' AND pending_forward_to IS NOT NULL;
UPDATE users SET gatekeeper_clerk_id = NULL WHERE gatekeeper_clerk_id IS NOT NULL;

-- ============================================================
-- FROM: migration-letters-drop-unused.sql
-- ============================================================
-- पत्र व्यवहारातून काढलेल्या सुविधांचे Tables/Columns पूर्णपणे काढतो
-- (Clerk-Gatekeeper, CC, Bulk परिपत्रक, Templates, Reply-thread, Pin-remarks).
-- वरचे UPDATE आधी Clerk कडे अडकलेली पत्रं अधिकाऱ्याकडे हलवतात — मगच हे.
ALTER TABLE letters DROP COLUMN clerk_stage;
ALTER TABLE letters DROP COLUMN intended_recipient_id;
ALTER TABLE letters DROP COLUMN pending_forward_to;
ALTER TABLE letters DROP COLUMN parent_letter_id;
ALTER TABLE letters DROP COLUMN batch_ref;
ALTER TABLE users DROP COLUMN gatekeeper_clerk_id;
DROP TABLE IF EXISTS letter_watchers;
DROP TABLE IF EXISTS letter_templates;
DROP TABLE IF EXISTS letter_attachment_remarks;

-- ============================================================
-- FROM: migration-security-fixes-w1.sql
-- ============================================================
-- Biometric: अनोळखी Machine आपोआप "मंजूर" होऊ नये — Super Admin ने मंजूर केल्यावरच तिचे Punches स्वीकारायचे
-- (आधीपासून असलेली Devices मंजूरच राहतात — DEFAULT 1; नवीन, आपोआप नोंदलेली Devices iclock/cdata.php मधून 0 ने येतात)
ALTER TABLE biometric_devices ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

-- Biometric: Machine ने तेच Punches पुन्हा पाठवले तरी दुप्पट नोंद होऊ नये
DELETE p1 FROM biometric_punch_logs p1 JOIN biometric_punch_logs p2 ON p1.device_serial = p2.device_serial AND p1.biometric_pin = p2.biometric_pin AND p1.punch_time = p2.punch_time AND p1.id > p2.id;
ALTER TABLE biometric_punch_logs ADD UNIQUE KEY uniq_device_pin_time (device_serial, biometric_pin, punch_time);

-- Public फॉर्म (request.php, guests/pre-register.php) साठी साधी Rate-Limit नोंद
CREATE TABLE IF NOT EXISTS public_form_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  form_key VARCHAR(40) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_form_ip_time (form_key, ip_address, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-renewals-upgrade.sql
-- ============================================================
-- ============================================================
-- Migration: नूतनीकरण Module सुधारणा
--  1) स्थिती (Active/लवकरच/मुदत संपली) आता expiry_date वरून काढली जाते —
--     'renewed' कायमची स्थिती काढली; 'inactive' = Soft-Delete (निष्क्रिय)
--  2) Reminder Dedupe Table (item + milestone + expiry_date)
--  3) नूतनीकरण-Cycle नुसार प्रमाणपत्र/दस्तऐवज Attachments
--  4) Cron Jobs — शेवटचं कधी चाललं (Last-Run) + दैनंदिन Digest Dedupe
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items MODIFY status ENUM('active','due_soon','expired','renewed','inactive') DEFAULT 'active';

-- जुन्या 'renewed'/'due_soon'/'expired' नोंदी पुन्हा सामान्य 'active' — इतिहास renewal_history मध्ये तसाच राहतो
UPDATE renewal_items SET status = 'active' WHERE status IN ('renewed','due_soon','expired');

CREATE TABLE renewal_reminder_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  renewal_item_id INT NOT NULL,
  milestone VARCHAR(20) NOT NULL,
  expiry_date DATE NOT NULL,
  recipients INT DEFAULT 0,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_milestone_expiry (renewal_item_id, milestone, expiry_date),
  FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE renewal_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  renewal_item_id INT NOT NULL,
  organization_id INT NOT NULL,
  cycle_expiry_date DATE NULL,
  title VARCHAR(255) NULL,
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NULL,
  file_size INT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_item_cycle (renewal_item_id, cycle_expiry_date)
) ENGINE=InnoDB;

CREATE TABLE cron_job_runs (
  job_key VARCHAR(100) NOT NULL PRIMARY KEY,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_status ENUM('running','success','failed') DEFAULT 'running',
  last_duration_sec INT NULL,
  last_output TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE cron_digest_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cron_key VARCHAR(50) NOT NULL,
  user_id INT NOT NULL,
  digest_date DATE NOT NULL,
  item_count INT DEFAULT 0,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cron_user_day (cron_key, user_id, digest_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- FROM: migration-remove-teaching-module.sql
-- ============================================================
-- ============================================================
-- Migration: Teaching Module पूर्णपणे काढलं — Virtual Classroom
-- (academics/) हेच Class/Timetable साठी एकमेव ठिकाण.
-- टीप: या Tables चा Data कायमचा जातो (मालकाची मंजुरी आहे).
-- teacher_feedback ठेवली आहे — Student Portal (give-feedback) तिथे लिहितो.
-- ============================================================

DROP TABLE IF EXISTS substitute_assignments;
DROP TABLE IF EXISTS teacher_timetable;
DROP TABLE IF EXISTS syllabus_trackers;
DROP TABLE IF EXISTS teacher_qualifications;
DROP TABLE IF EXISTS class_teacher_assignments;
DROP TABLE IF EXISTS exam_duty_allocations;

-- Teaching Module ची Permission (फक्त त्याच Module मध्ये वापरली जात होती)
DELETE FROM role_permissions WHERE permission_key = 'manage_teaching_academics';
DELETE FROM permissions WHERE permission_key = 'manage_teaching_academics';

-- Help Center मधला "Teaching Module" लेख
DELETE FROM help_articles WHERE title LIKE '%Teaching Module%';

-- Student Feedback — एक विद्यार्थी एका शिक्षकाला महिन्यातून एकदाच (कोणी दिलं ते Anonymous च दाखवतो)
ALTER TABLE teacher_feedback ADD COLUMN student_id INT NULL AFTER teacher_id;
ALTER TABLE teacher_feedback ADD FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE;
ALTER TABLE teacher_feedback ADD INDEX idx_fb_student_teacher (student_id, teacher_id, submitted_at);

-- ============================================================
-- FROM: migration-residency-music-events-fixes.sql
-- ============================================================
-- ============================================================
-- Migration: रहिवासी विभाग — निवासी खोली Flag; संगीत/कार्यक्रम/इतर दुरुस्त्या
-- ============================================================

-- maintenance_locations मध्ये Office/Kitchen सुद्धा आहेत — फक्त "निवासी" जागाच रहिवासी विभागात मोजायच्या
ALTER TABLE maintenance_locations ADD COLUMN is_residential TINYINT(1) NOT NULL DEFAULT 0 AFTER capacity;

-- आधीपासून रहिवासी Data (रहिवासी/बिल/साहित्य/तपासणी/पाहुणे/खोली-बदल/वाटप/Import) असलेल्या जागा = निवासी,
-- जेणेकरून चालू Setup तसंच चालेल. (ज्या संस्थेत आधीच एखादी जागा निवासी चिन्हांकित आहे, तिथे पुन्हा चालवत नाही)
UPDATE maintenance_locations SET is_residential = 1 WHERE (id IN (SELECT location_id FROM residency_occupants) OR id IN (SELECT location_id FROM residency_bills) OR id IN (SELECT location_id FROM residency_room_items) OR id IN (SELECT location_id FROM residency_inspections) OR id IN (SELECT location_id FROM residency_guests) OR id IN (SELECT to_location_id FROM residency_room_transfers) OR id IN (SELECT allotted_location_id FROM residency_allotment_requests WHERE allotted_location_id IS NOT NULL) OR import_batch_id IS NOT NULL) AND organization_id NOT IN (SELECT organization_id FROM (SELECT DISTINCT organization_id FROM maintenance_locations WHERE is_residential = 1) AS already_flagged);

-- संगीत: वाद्य कोणाकडे आहे — Staff असेल तर current_holder_id, इतर (विद्यार्थी इ.) नाव इथे; आधी हे remarks मध्ये लिहिलं जायचं
ALTER TABLE music_instruments ADD COLUMN current_holder_name VARCHAR(255) NULL AFTER current_holder_id;
UPDATE music_instruments SET current_holder_name = TRIM(REPLACE(REPLACE(remarks, 'सध्या: ', ''), ' कडे', '')), remarks = NULL WHERE remarks LIKE 'सध्या: % कडे' AND current_holder_name IS NULL;

-- कार्यक्रम उपस्थिती — एकाच कर्मचाऱ्याची/विद्यार्थ्याची दुहेरी नोंद नको (Guest साठी user_id/student_id NULL — लागू होत नाही)
ALTER TABLE event_attendance ADD UNIQUE KEY uniq_event_staff (event_id, user_id);
ALTER TABLE event_attendance ADD UNIQUE KEY uniq_event_student (event_id, student_id);

-- ============================================================
-- FROM: migration-task-verification.sql
-- ============================================================
-- टास्क — Assign करणाऱ्याकडून रद्द करणे (cancelled) आणि पूर्ण टास्कची पडताळणी/पुन्हा उघडणे
ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending';
ALTER TABLE tasks ADD COLUMN verified_at DATETIME NULL AFTER cost_incurred;
ALTER TABLE tasks ADD COLUMN verified_by INT NULL AFTER verified_at;
ALTER TABLE tasks ADD COLUMN verification_remarks TEXT NULL AFTER verified_by;

-- ============================================================
-- FROM: migration-module-merge.sql
-- ============================================================
-- ============================================================
-- Migration: Duplicate Modules एकत्र करणे
--  1. Asset Checkout → Asset Allocation ("Issue / Return Register")
--  2. विभाग Budget + Material Purchase → एकच "विभाग Finance" (एकच खर्च-Ledger)
--  3. Restricted-Mode Roles मधले जुने Module-Keys नवीन Keys ने बदलणे
-- कितीही वेळा चालवली तरी सुरक्षित — प्रत्येक Data-INSERT "NOT EXISTS" ने तपासलेला आहे.
-- जुन्या checkout_items / checkout_logs Tables काढलेल्या नाहीत (फक्त वापर बंद).
-- ============================================================

-- ---------- 1. Allocation: Loan (उसनं) Types + वस्तू-Master + Return नोंद ----------
ALTER TABLE asset_allocation_types
  ADD COLUMN is_loan TINYINT(1) DEFAULT 0,
  ADD COLUMN legacy_checkout_category VARCHAR(150) NULL;

CREATE TABLE asset_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  type_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  default_location VARCHAR(150) NULL,
  is_active TINYINT(1) DEFAULT 1,
  legacy_checkout_item_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_legacy_checkout_item (legacy_checkout_item_id),
  INDEX idx_org_type (organization_id, type_id)
) ENGINE=InnoDB;

ALTER TABLE asset_allocations
  MODIFY campus_id INT NULL,
  ADD COLUMN item_id INT NULL,
  ADD COLUMN purpose VARCHAR(255) NULL,
  ADD COLUMN allocated_at DATETIME NULL,
  ADD COLUMN due_at DATETIME NULL,
  ADD COLUMN returned_at DATETIME NULL,
  ADD COLUMN returned_to INT NULL,
  ADD COLUMN legacy_checkout_log_id INT NULL,
  ADD UNIQUE KEY uniq_legacy_checkout_log (legacy_checkout_log_id),
  ADD INDEX idx_item_active (item_id, unallocated_date),
  ADD FOREIGN KEY (item_id) REFERENCES asset_items(id) ON DELETE SET NULL,
  ADD FOREIGN KEY (returned_to) REFERENCES users(id) ON DELETE SET NULL;

-- आधीच्या Allocations मधल्या "वस्तूचं नाव" (asset_label) वरून वस्तू-Master तयार करतो
INSERT INTO asset_items (organization_id, type_id, campus_id, name, is_active, created_by)
SELECT aa.organization_id, aa.type_id, MIN(aa.campus_id), aa.asset_label, 1, MIN(aa.allocated_by)
FROM asset_allocations aa
WHERE aa.asset_label IS NOT NULL AND aa.asset_label <> '' AND aa.item_id IS NULL AND aa.legacy_checkout_log_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM asset_items ai WHERE ai.organization_id = aa.organization_id AND ai.type_id = aa.type_id AND ai.name COLLATE utf8mb4_unicode_ci = aa.asset_label COLLATE utf8mb4_unicode_ci)
GROUP BY aa.organization_id, aa.type_id, aa.asset_label;

UPDATE asset_allocations aa
JOIN asset_items ai ON ai.organization_id = aa.organization_id AND ai.type_id = aa.type_id AND ai.name COLLATE utf8mb4_unicode_ci = aa.asset_label COLLATE utf8mb4_unicode_ci
SET aa.item_id = ai.id
WHERE aa.item_id IS NULL AND aa.asset_label IS NOT NULL AND aa.asset_label <> '';

-- Checkout वस्तूंच्या प्रत्येक "प्रकार" (category) साठी एक Loan-Type
INSERT INTO asset_allocation_types (organization_id, name, icon, is_active, is_loan, legacy_checkout_category, created_by)
SELECT ci.organization_id, COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू'), 'fa-solid fa-key', 1, 1, COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू'), MIN(ci.created_by)
FROM checkout_items ci
WHERE NOT EXISTS (
  SELECT 1 FROM asset_allocation_types t
  WHERE t.organization_id = ci.organization_id AND t.legacy_checkout_category = COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू')
)
GROUP BY ci.organization_id, COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू');

-- Checkout वस्तू → वस्तू-Master
INSERT INTO asset_items (organization_id, type_id, campus_id, name, default_location, is_active, legacy_checkout_item_id, created_by, created_at)
SELECT ci.organization_id, t.id, ci.campus_id, ci.name, ci.default_location, ci.is_active, ci.id, ci.created_by, ci.created_at
FROM checkout_items ci
JOIN asset_allocation_types t ON t.organization_id = ci.organization_id AND t.legacy_checkout_category = COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू')
WHERE NOT EXISTS (SELECT 1 FROM asset_items ai WHERE ai.legacy_checkout_item_id = ci.id);

-- Checkout Log → Allocation History (कोणी घेतलं, कधी, मुदत, कधी परत आलं, कोणी परत घेतलं)
INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, item_id, asset_label, purpose, allocated_date, allocated_at, due_at, unallocated_date, returned_at, returned_to, unallocated_reason, allocated_by, legacy_checkout_log_id, created_at)
SELECT ai.organization_id, ai.type_id, ai.campus_id, cl.borrowed_by, ai.id, ai.name, cl.purpose, DATE(cl.checkout_at), cl.checkout_at, cl.expected_return_at, DATE(cl.actual_return_at), cl.actual_return_at, cl.returned_to, cl.remarks, cl.issued_by, cl.id, cl.checkout_at
FROM checkout_logs cl
JOIN asset_items ai ON ai.legacy_checkout_item_id = cl.item_id
WHERE NOT EXISTS (SELECT 1 FROM asset_allocations aa WHERE aa.legacy_checkout_log_id = cl.id);

-- ---------- 2. एकच खर्च-Ledger (budget_transactions) ----------
ALTER TABLE budget_transactions
  MODIFY category ENUM('नवीन खरेदी','देखभाल','इंधन','नूतनीकरण','इतर') NOT NULL,
  ADD COLUMN spend_date DATE NULL,
  ADD INDEX idx_source_ref (source_module, reference_id);

-- जुन्या नोंदींसाठी — खर्चाचा खरा दिनांक भरतो
UPDATE budget_transactions bt JOIN dead_stock_items ds ON ds.id = bt.reference_id
SET bt.spend_date = COALESCE(ds.purchase_date, DATE(ds.created_at))
WHERE bt.source_module = 'dead_stock' AND bt.spend_date IS NULL;

UPDATE budget_transactions bt JOIN maintenance_logs ml ON ml.id = bt.reference_id
SET bt.spend_date = ml.work_date
WHERE bt.source_module = 'maintenance' AND bt.spend_date IS NULL;

-- वाहन इंधन/देखभाल: आधी reference_id मध्ये वाहनाचा ID जात होता — योग्य Log-ID शोधून दुरुस्त करतो
UPDATE budget_transactions bt
JOIN vehicle_fuel_log f ON f.vehicle_id = bt.reference_id AND f.cost = bt.amount AND ABS(TIMESTAMPDIFF(SECOND, f.created_at, bt.created_at)) <= 300
SET bt.reference_id = f.id, bt.source_module = 'vehicle_fuel_log', bt.spend_date = f.fill_date
WHERE bt.source_module = 'vehicle_fuel';

UPDATE budget_transactions bt
JOIN vehicle_maintenance vm ON vm.vehicle_id = bt.reference_id AND vm.cost = bt.amount AND ABS(TIMESTAMPDIFF(SECOND, vm.created_at, bt.created_at)) <= 300
SET bt.reference_id = vm.id, bt.source_module = 'vehicle_maintenance_log', bt.spend_date = vm.maintenance_date
WHERE bt.source_module = 'vehicle_maintenance';

UPDATE budget_transactions SET spend_date = DATE(created_at) WHERE spend_date IS NULL;

-- आर्थिक वर्ष "आज" वरून नाही — खर्चाच्या दिनांकावरून
UPDATE budget_transactions SET financial_year = IF(MONTH(spend_date) >= 4, YEAR(spend_date), YEAR(spend_date) - 1) WHERE spend_date IS NOT NULL;

-- Material Purchase — आत्तापर्यंत फक्त Payment-Ledger मध्ये होत्या; Budget-Ledger मध्ये एकदाच नोंदवतो
INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by, spend_date, created_at)
SELECT p.organization_id, p.department_id, IF(MONTH(p.purchase_date) >= 4, YEAR(p.purchase_date), YEAR(p.purchase_date) - 1), p.total_amount, 'नवीन खरेदी', 'material_purchase', p.id, LEFT(CONCAT(p.material_name, ' — ', p.vendor_name), 255), p.created_by, p.purchase_date, p.created_at
FROM dept_material_purchases p
WHERE p.total_amount > 0
  AND NOT EXISTS (SELECT 1 FROM budget_transactions bt WHERE bt.source_module = 'material_purchase' AND bt.reference_id = p.id);

-- Renewal Payments — प्रत्येक Payment एकदाच खर्च म्हणून
INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by, spend_date, created_at)
SELECT l.organization_id, l.department_id, IF(MONTH(l.payment_date) >= 4, YEAR(l.payment_date), YEAR(l.payment_date) - 1), l.amount, 'नूतनीकरण', 'renewal_payment', l.id, LEFT(CONCAT('नूतनीकरण Payment — ', COALESCE(ri.item_name, '')), 255), l.recorded_by, l.payment_date, l.created_at
FROM dept_payment_ledger l
LEFT JOIN renewal_items ri ON ri.id = l.source_id
WHERE l.source_type = 'renewal' AND l.amount > 0
  AND NOT EXISTS (SELECT 1 FROM budget_transactions bt WHERE bt.source_module = 'renewal_payment' AND bt.reference_id = l.id);

-- ---------- 3. Restricted-Mode Roles — काढलेले Module-Keys ----------
UPDATE role_definitions SET restricted_modules = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', restricted_modules, ','), ',checkout,', ',allocations,')) WHERE restricted_modules LIKE '%checkout%';
UPDATE role_definitions SET restricted_modules = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', restricted_modules, ','), ',budget,', ',dept-finance,')) WHERE restricted_modules LIKE '%budget%';

UPDATE permissions SET label_local = 'Asset Issue/Return (उसनं) व्यवस्थापन' WHERE permission_key = 'manage_checkout';
UPDATE permissions SET label_local = 'विभाग Finance — Material Purchase व Payment' WHERE permission_key = 'manage_dept_finance';

-- ============================================================
-- FROM: migration-notifications-tickets-sos.sql
-- ============================================================
-- ============================================================
-- Migration: सूचना Push-Queue, एकत्रित विनंती (Ticket) प्रणाली + SLA,
-- तक्रार निवारण सुधारणा, आणीबाणी (SOS) प्रतिसाद, परिपत्रक Edit/Pin/मुदत
-- कितीही वेळा चालवली तरी सुरक्षित (Runner: dbmRunMigrationFile)
-- ============================================================

-- ---------------- 1. Push Queue ----------------
CREATE TABLE notification_push_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  notification_id INT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  link VARCHAR(255) NULL,
  is_urgent TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_queue_status (status, is_urgent, id),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE notifications
  ADD INDEX idx_read_created (is_read, created_at);

-- ---------------- 2. एकत्रित विनंती (work_requests = एकमेव Ticket Table) ----------------
ALTER TABLE work_requests
  MODIFY department_id INT NULL,
  MODIFY status ENUM('pending','in_progress','completed','closed','cancelled') DEFAULT 'pending',
  ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'general' AFTER department_id,
  ADD COLUMN sub_category VARCHAR(50) NULL AFTER category,
  ADD COLUMN title VARCHAR(255) NULL AFTER sub_category,
  ADD COLUMN location VARCHAR(255) NULL AFTER title,
  ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER location,
  ADD COLUMN due_at DATETIME NULL AFTER priority,
  ADD COLUMN escalation_level TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN rating TINYINT NULL,
  ADD COLUMN feedback TEXT NULL,
  ADD COLUMN closed_at DATETIME NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN reopened_count INT NOT NULL DEFAULT 0,
  ADD COLUMN legacy_it_request_id INT NULL COMMENT 'जुन्या it_general_requests.id — स्थलांतर शोधण्यासाठी',
  ADD UNIQUE KEY uniq_legacy_it (legacy_it_request_id),
  ADD INDEX idx_org_category_status (organization_id, category, status),
  ADD INDEX idx_status_due (status, due_at);

ALTER TABLE work_request_actions
  MODIFY action_type ENUM('created','status_changed','assigned','comment','reopened','closed','cancelled','escalated') NOT NULL;

CREATE TABLE work_request_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  user_id INT NULL,
  comment TEXT NOT NULL,
  legacy_it_comment_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_legacy_it_comment (legacy_it_comment_id),
  INDEX idx_request (request_id),
  FOREIGN KEY (request_id) REFERENCES work_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- जुन्या IT Requests → work_requests (category = 'it'); मूळ Table जशीच्या तशी (Archive) राहते
INSERT INTO work_requests (request_code, organization_id, department_id, category, sub_category, title, location, priority, requester_name, requester_contact, requested_by_user_id, requesting_department_id, assigned_to_user_id, work_description, completion_remarks, status, completed_by, completed_at, closed_at, created_at, legacy_it_request_id)
SELECT
  IF(EXISTS(SELECT 1 FROM work_requests w2 WHERE w2.request_code = r.request_code), CONCAT(r.request_code, '-L', r.id), r.request_code),
  r.organization_id,
  (SELECT d.id FROM departments d WHERE d.organization_id = r.organization_id AND d.code = 'IT' ORDER BY d.id LIMIT 1),
  'it', r.category, r.title, r.location, r.priority,
  COALESCE(u.name, '—'), u.mobile, r.requested_by, r.department_id, r.assigned_to, r.description, r.resolution_notes,
  CASE r.status WHEN 'in_progress' THEN 'in_progress' WHEN 'resolved' THEN 'completed' WHEN 'closed' THEN 'closed' ELSE 'pending' END,
  r.resolved_by, r.resolved_at, IF(r.status = 'closed', r.resolved_at, NULL), r.created_at, r.id
FROM it_general_requests r LEFT JOIN users u ON u.id = r.requested_by
WHERE NOT EXISTS (SELECT 1 FROM work_requests w WHERE w.legacy_it_request_id = r.id);

INSERT INTO work_request_comments (request_id, user_id, comment, legacy_it_comment_id, created_at)
SELECT w.id, c.commented_by, c.comment, c.id, c.created_at
FROM it_general_request_comments c JOIN work_requests w ON w.legacy_it_request_id = c.request_id
WHERE NOT EXISTS (SELECT 1 FROM work_request_comments x WHERE x.legacy_it_comment_id = c.id);

INSERT INTO work_request_actions (request_id, action_type, new_status, remark, changed_by, created_at)
SELECT w.id, 'created', w.status, 'जुन्या IT Request मधून स्थलांतरित', w.requested_by_user_id, w.created_at
FROM work_requests w
WHERE w.legacy_it_request_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM work_request_actions a WHERE a.request_id = w.id);

-- SLA मुदत — जुन्या नोंदींसाठी (urgent 8 तास, high 1 दिवस, normal 3, low 7)
UPDATE work_requests SET due_at = DATE_ADD(created_at, INTERVAL (CASE priority WHEN 'urgent' THEN 8 WHEN 'high' THEN 24 WHEN 'low' THEN 168 ELSE 72 END) HOUR) WHERE due_at IS NULL;

-- ---------------- 3. तक्रार निवारण ----------------
ALTER TABLE grievances
  MODIFY submitted_by INT NULL,
  ADD COLUMN submitter_hash CHAR(64) NULL COMMENT 'गुप्त तक्रारीसाठी — व्यक्तीचा ID न ठेवता HMAC',
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN reopened_count INT NOT NULL DEFAULT 0,
  ADD INDEX idx_submitter_hash (submitter_hash);

CREATE TABLE grievance_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grievance_id INT NOT NULL,
  user_id INT NULL COMMENT 'गुप्त तक्रारदाराची टिप्पणी असेल तर NULL',
  author_role ENUM('submitter','handler') NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grievance (grievance_id),
  FOREIGN KEY (grievance_id) REFERENCES grievances(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- 4. आणीबाणी (SOS) ----------------
ALTER TABLE emergency_alerts
  ADD COLUMN campus_id INT NULL AFTER triggered_by,
  ADD COLUMN latitude DECIMAL(10,7) NULL,
  ADD COLUMN longitude DECIMAL(10,7) NULL,
  ADD COLUMN first_ack_at DATETIME NULL,
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN resolution_remark TEXT NULL,
  ADD INDEX idx_user_created (triggered_by, created_at);

CREATE TABLE emergency_alert_recipients (
  alert_id INT NOT NULL,
  user_id INT NOT NULL,
  notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (alert_id, user_id),
  FOREIGN KEY (alert_id) REFERENCES emergency_alerts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE emergency_alert_responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alert_id INT NOT NULL,
  user_id INT NOT NULL,
  responded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alert_user (alert_id, user_id),
  FOREIGN KEY (alert_id) REFERENCES emergency_alerts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- 5. परिपत्रक ----------------
ALTER TABLE announcements
  ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN expires_at DATE NULL,
  ADD COLUMN withdrawn_at DATETIME NULL,
  ADD COLUMN withdrawn_by INT NULL,
  ADD COLUMN updated_at DATETIME NULL;
