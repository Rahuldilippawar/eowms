-- ============================================================
-- EOWMS — Plain PHP Edition — Database Schema + Demo Data
-- Import this file directly via phpMyAdmin (XAMPP) into a
-- database named `eowms`.
-- ============================================================

CREATE DATABASE IF NOT EXISTS eowms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eowms;

-- ---------------- Organizations ----------------
CREATE TABLE organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  type ENUM('trust','school','hospital','corporate') DEFAULT 'trust',
  parent_organization_id INT NULL,
  logo_path VARCHAR(255) NULL,
  welcome_audio_path VARCHAR(255) NULL,
  contact_email VARCHAR(255) NULL,
  contact_phone VARCHAR(20) NULL,
  vapid_public_key TEXT NULL,
  vapid_private_key TEXT NULL,
  is_active TINYINT(1) DEFAULT 1,
  maintenance_mode TINYINT(1) DEFAULT 0,
  maintenance_message TEXT NULL,
  maintenance_started_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- Campuses ----------------
CREATE TABLE campuses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  college_type ENUM('Trust HQ','Primary School','Secondary School','Junior College','Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL,
  principal_name VARCHAR(255) NULL,
  affiliated_university VARCHAR(255) NULL,
  ugc_recognition VARCHAR(150) NULL,
  naac_grade VARCHAR(20) NULL,
  naac_valid_until DATE NULL,
  ugc_valid_until DATE NULL,
  established_year YEAR NULL,
  letterhead_logo_path VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- Departments (fully dynamic — 13 seeded below, but any number supported) ----------------
CREATE TABLE departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  name_local VARCHAR(255) NULL,
  code VARCHAR(20) NOT NULL,
  icon VARCHAR(100) DEFAULT 'fa-solid fa-building',
  hod_id INT NULL,
  has_issue_register TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_campus_code (campus_id, code)
) ENGINE=InnoDB;

-- ---------------- Users (role stored directly — no separate permission tables, kept simple) ----------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NULL,
  can_edit_daily_work TINYINT(1) DEFAULT 0,
  employee_code VARCHAR(50) NULL UNIQUE,
  biometric_pin VARCHAR(20) NULL,
  name VARCHAR(255) NOT NULL,
  father_name VARCHAR(255) NULL,
  mother_name VARCHAR(255) NULL,
  gender ENUM('Male','Female') NULL,
  date_of_birth DATE NULL,
  group_type ENUM('School','Trust','Other') DEFAULT 'Trust',
  teaching_mode ENUM('Teaching','Non Teaching','Other') DEFAULT 'Non Teaching',
  local_address TEXT NULL,
  permanent_address TEXT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  photo_path VARCHAR(255) NULL,
  mobile VARCHAR(20) NULL,
  emergency_contact_name VARCHAR(255) NULL,
  emergency_contact_relation VARCHAR(100) NULL,
  emergency_contact_mobile VARCHAR(20) NULL,
  designation VARCHAR(255) NULL,
  employment_type ENUM('Salaried','Non Salaried','Contractor') DEFAULT 'Salaried',
  designation_2 VARCHAR(255) NULL,
  esic_mode VARCHAR(50) NULL,
  esic_no VARCHAR(50) NULL,
  pf_mode VARCHAR(50) NULL,
  uan_no VARCHAR(50) NULL,
  pt_mode VARCHAR(50) NULL,
  aadhar_number VARCHAR(20) NULL,
  ration_card TINYINT(1) DEFAULT 0,
  bank_account_no VARCHAR(50) NULL,
  ifsc_code VARCHAR(20) NULL,
  bank_name VARCHAR(150) NULL,
  branch_name VARCHAR(150) NULL,
  whatsapp_number VARCHAR(20) NULL,
  ashram_resident TINYINT(1) DEFAULT 0,
  marital_status ENUM('Single','Married') DEFAULT 'Single',
  religion_caste VARCHAR(100) NULL,
  caste_validity_no VARCHAR(50) NULL,
  is_handicap TINYINT(1) DEFAULT 0,
  naam_dharak TINYINT(1) DEFAULT 0,
  food_preference ENUM('Veg','Non Veg') DEFAULT 'Veg',
  health_scheme_no VARCHAR(50) NULL,
  family_health_scheme_no VARCHAR(50) NULL,
  interested_sports_club TINYINT(1) DEFAULT 0,
  interested_home_scheme TINYINT(1) DEFAULT 0,
  home_scheme_type VARCHAR(50) NULL,
  family_interested_working TINYINT(1) DEFAULT 0,
  family_currently_working TINYINT(1) DEFAULT 0,
  salary DECIMAL(10,2) NULL,
  joining_date DATE NULL,
  left_date DATE NULL,
  pf_applicable TINYINT(1) DEFAULT 0,
  pt_applicable TINYINT(1) DEFAULT 0,
  esic_applicable TINYINT(1) DEFAULT 0,
  password VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) DEFAULT 0,
  onboarding_seen TINYINT(1) DEFAULT 0,
  failed_login_attempts INT DEFAULT 0,
  locked_until DATETIME NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) DEFAULT 1,
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_biometric_pin (organization_id, biometric_pin)
) ENGINE=InnoDB;

ALTER TABLE departments ADD FOREIGN KEY (hod_id) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------- Dynamic Roles / Designations ----------------
-- Every possible role/designation, each tagged with an access category so
-- permissions work no matter what the role is actually CALLED (Director,
-- Trustee, Secretary, etc.). Manage from Super Admin -> "पदे व्यवस्थापन".
CREATE TABLE role_definitions (
  role_key VARCHAR(50) PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  label_local VARCHAR(100) NULL,
  category ENUM('super_admin','management','hod','staff') NOT NULL DEFAULT 'staff',
  is_system TINYINT(1) DEFAULT 0,
  restricted_mode TINYINT(1) DEFAULT 0,
  restricted_modules VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO role_definitions (role_key, label, label_local, category, is_system) VALUES
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

-- ---------------- Granular Permissions per Role ----------------
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

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('assign_tasks', 'Assign Tasks to any staff', 'कोणालाही टास्क असाइन करणे', 1),
('assign_tasks_org_wide', 'Assign/view tasks across all departments (not just own)', 'सर्व विभागांचे Task Assign/पाहणे (फक्त स्वतःचा विभाग नाही)', 2),
('view_task_tracker', 'View organization-wide Task Tracker', 'संपूर्ण संस्थेचा टास्क ट्रॅकर पाहणे', 2),
('view_staff_directory', 'View organization-wide Staff Directory', 'संपूर्ण कर्मचारी सूची पाहणे', 3),
('view_org_wide_dashboards', 'View any department\'s dashboard', 'कोणत्याही विभागाचा डॅशबोर्ड पाहणे', 4),
('view_org_wide_reports', 'Generate organization-wide reports', 'संपूर्ण संस्था-स्तरीय अहवाल तयार करणे', 5),
('manage_requests_org_wide', 'View/manage requests for all departments', 'सर्व विभागांच्या विनंत्या पाहणे/व्यवस्थापणे', 6),
('manage_campuses', 'Add/edit campuses', 'कॅम्पस जोडणे/संपादित करणे', 7),
('manage_departments', 'Add/edit departments', 'विभाग जोडणे/संपादित करणे', 8),
('manage_hostels', 'Add hostels, allocate hostel staff', 'वसतिगृह जोडणे, स्टाफ नियुक्ती', 9),
('manage_hostel_students', 'Add/edit hostel students', 'वसतिगृह विद्यार्थी जोडणे/संपादित करणे', 10),
('manage_vehicles', 'Add/edit vehicles, log trips & fuel', 'वाहन जोडणे, ट्रिप व इंधन नोंदवणे', 11);

INSERT INTO role_permissions (role_key, permission_key) VALUES
('president', 'assign_tasks'), ('president', 'view_task_tracker'), ('president', 'view_staff_directory'),
('president', 'view_org_wide_dashboards'), ('president', 'view_org_wide_reports'), ('president', 'manage_requests_org_wide'),
('executive_president', 'assign_tasks'), ('executive_president', 'view_task_tracker'), ('executive_president', 'view_staff_directory'),
('executive_president', 'view_org_wide_dashboards'), ('executive_president', 'view_org_wide_reports'), ('executive_president', 'manage_requests_org_wide'),
('general_manager', 'assign_tasks'), ('general_manager', 'view_task_tracker'), ('general_manager', 'view_staff_directory'),
('general_manager', 'view_org_wide_dashboards'), ('general_manager', 'view_org_wide_reports'), ('general_manager', 'manage_requests_org_wide'),
('general_manager', 'manage_campuses'), ('general_manager', 'manage_departments'), ('general_manager', 'manage_vehicles'),
('president', 'manage_announcements'), ('president', 'manage_documents'), ('president', 'manage_suggestions'), ('president', 'view_emergency_alerts'),
('executive_president', 'manage_announcements'), ('executive_president', 'manage_documents'), ('executive_president', 'manage_suggestions'), ('executive_president', 'view_emergency_alerts'),
('general_manager', 'manage_announcements'), ('general_manager', 'manage_documents'), ('general_manager', 'manage_suggestions'), ('general_manager', 'view_emergency_alerts'),
('hod', 'view_staff_directory');

-- विभाग प्रमुख (HOD) — स्वतःच्या विभागातल्या कर्मचाऱ्यांना Task Assign करू शकतात
-- (हा Access फक्त त्यांच्याच विभागापुरता मर्यादित राहतो — tasks/save.php
-- मध्ये त्याची स्वतंत्रपणे, सर्व्हरवर पडताळणी होते.)
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'assign_tasks' FROM role_definitions WHERE category = 'hod';

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_letters_org_wide', 'View/manage all letters across departments', 'सर्व विभागांचे पत्र व्यवहार पाहणे/व्यवस्थापणे', 12),
('manage_budget', 'Set and view department budgets', 'विभाग Budget ठरवणे/पाहणे', 13),
('manage_sop', 'Create SOPs and assign to departments', 'SOP तयार करणे/विभागांना नेमणे', 14),
('manage_dead_stock_master', 'Manage Dead Stock item master, edit/delete entries', 'Dead Stock Item Master + Edit/Delete अधिकार', 15),
('manage_maintenance_settings', 'Enable/disable Maintenance Log per department', 'कोणत्या विभागांना देखभाल नोंदवही उपलब्ध ते ठरवणे', 16),
('manage_residency_all_depts', 'View/manage Residency across all departments', 'रहिवासी विभाग सर्व विभागांसाठी पाहणे/व्यवस्थापणे', 17),
('manage_store', 'Manage the Material-Master list used by Daily-Work', 'भांडार Material-यादी व्यवस्थापणे', 19),
('manage_cctv', 'Access the CCTV department module', 'CCTV विभाग पाहणे/व्यवस्थापणे', 20),
('manage_it', 'Access the IT department module', 'IT विभाग पाहणे/व्यवस्थापणे', 21),
('manage_attendance', 'Manage staff attendance (mark manually, edit monthly grid, generate certificates) — typically HR', 'कर्मचारी हजेरी व्यवस्थापन (Manually नोंदवणे, Monthly Grid, प्रमाणपत्र) — साधारणतः HR साठी', 22),
('manage_academics', 'Manage Virtual Classroom (students, classrooms, materials, assignments) for any department — beyond own HOD scope', 'Virtual Classroom व्यवस्थापन (कोणत्याही विभागासाठी — स्वतःच्या HOD-मर्यादेपलीकडे)', 24),
('manage_music', 'Manage Music Department (instruments, students, exams, events, bookings)', 'संगीत विभाग व्यवस्थापन (वाद्य, विद्यार्थी, परीक्षा, कार्यक्रम, Booking)', 25),
('manage_meditation', 'Manage Meditation & Satsang (duty chart, quotes, events) — beyond top Management', 'ध्यान व सत्संग व्यवस्थापन (Duty Chart, सुविचार, कार्यक्रम) — फक्त वरिष्ठ Management शिवायही', 26),
('manage_announcements', 'Post organization-wide announcements/circulars', 'संस्था-व्यापी परिपत्रकं/सूचना पोस्ट करणे', 27),
('manage_documents', 'Upload documents to the shared document repository', 'सामायिक Document Repository मध्ये File Upload करणे', 28),
('manage_suggestions', 'Respond to and update status of staff suggestions', 'Staff च्या सुविचार/सूचनांना उत्तर देणे, स्थिती बदलणे', 29),
('view_emergency_alerts', 'View and resolve Emergency/SOS alerts triggered by staff', 'Staff कडून आलेले Emergency/SOS Alerts पाहणे व सोडवणे', 30),
('manage_events', 'Create and manage events', 'कार्यक्रम व्यवस्थापन', 34),
('manage_guests', 'Log and manage visitor/guest entries', 'पाहुणे नोंदणी व्यवस्थापन', 35),
('view_staff_track_own_campus', 'View staff profile/track record for ALL departments within own campus', 'स्वतःच्या Campus मधल्या सगळ्या विभागांचे Staff Profile/Track Record पाहणे', 32),
('view_staff_track_org_wide', 'View staff profile/track record across the ENTIRE organization', 'संपूर्ण संस्थेतल्या सगळ्यांचे Staff Profile/Track Record पाहणे', 33),
('manage_teaching_academics', 'Manage teaching staff timetable, substitutes, syllabus, exam duty', 'शिक्षक Timetable, Substitute, Syllabus, Exam Duty व्यवस्थापन', 36),
('manage_checkout', 'Manage asset checkout/borrowing (issue, receive return)', 'Asset Checkout/Borrowing व्यवस्थापन', 37),
('manage_asset_allocations', 'Manage asset allocation types and records', 'Asset Allocation Registry व्यवस्थापन', 38),
('manage_room_booking', 'Manage bookable rooms', 'Room/Hall Booking व्यवस्थापन', 40),
('manage_grievances', 'View and resolve grievances', 'तक्रार निवारण व्यवस्थापन', 42),
('manage_dept_finance', 'Record department material purchases and track payments', 'विभाग Material Purchase व Payment Tracking', 45),
('manage_gaushala', 'Manage Gaushala — cattle registry, health, milk, fodder, breeding, sponsorship', 'गौशाळा व्यवस्थापन', 44);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_it' FROM role_definitions WHERE category = 'management';

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'assign_tasks_org_wide' FROM role_definitions WHERE category = 'management';

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_cctv' FROM role_definitions WHERE category = 'management';

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_hostels' FROM role_definitions WHERE category = 'management';

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_hostel_students' FROM role_definitions WHERE category = 'management';

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
INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_store' FROM role_definitions WHERE category = 'management';

-- ---------------- Daily Work Records ----------------
CREATE TABLE work_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_code VARCHAR(50) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  requested_by VARCHAR(255) NULL,
  employee_id INT NOT NULL,
  hod_id INT NULL,
  entered_by_role ENUM('staff','hod') DEFAULT 'staff',
  entered_by_user_id INT NOT NULL,
  work_date DATE NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status ENUM('pending','in_progress','completed') DEFAULT 'pending',
  remarks TEXT NULL,
  start_date DATE NULL,
  due_date DATE NULL,
  completion_date DATE NULL,
  estimated_cost DECIMAL(12,2) NULL,
  actual_cost DECIMAL(12,2) NULL,
  before_photo_path VARCHAR(255) NULL,
  after_photo_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_dept_date (department_id, work_date),
  INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE work_record_team (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  user_id INT NOT NULL,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_record_user (work_record_id, user_id)
) ENGINE=InnoDB;

-- ---------------- Renewal & Compliance (one generic table for every department) ----------------
CREATE TABLE renewal_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  responsible_user_id INT NULL,
  vehicle_id INT NULL,
  renewal_field VARCHAR(50) NULL,
  item_type VARCHAR(100) NOT NULL,
  regulatory_body ENUM('CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','RTO/Transport','Other Government','Not Applicable') NULL,
  item_name VARCHAR(255) NOT NULL,
  reference_no VARCHAR(100) NULL,
  issued_date DATE NULL,
  expiry_date DATE NOT NULL,
  renewal_cost DECIMAL(12,2) NULL,
  is_recurring_monthly TINYINT(1) DEFAULT 0,
  monthly_amount DECIMAL(10,2) NULL,
  recurring_start_month DATE NULL,
  vendor_name VARCHAR(255) NULL,
  vendor_contact VARCHAR(100) NULL,
  status ENUM('active','due_soon','expired','renewed') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_expiry (department_id, expiry_date)
) ENGINE=InnoDB;

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

-- ---------------- Task Assignment ----------------
CREATE TABLE tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_code VARCHAR(50) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  assigned_by INT NOT NULL,
  assigned_to INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  target_date DATE NOT NULL,
  status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  completed_at TIMESTAMP NULL,
  completion_remarks TEXT NULL,
  cost_incurred DECIMAL(10,2) NULL,
  verified_at DATETIME NULL,
  verified_by INT NULL,
  verification_remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_assignee_status (assigned_to, status)
) ENGINE=InnoDB;

-- ---------------- Dynamic Daily-Work Custom Fields (organization-specific) ----------------
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

-- ---------------- Hostel-wise Student & Staff Allocation ----------------
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
  date_of_birth DATE NULL,
  photo_path VARCHAR(255) NULL,
  admission_date DATE NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  INDEX idx_hostel (hostel_id)
) ENGINE=InnoDB;

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

-- ---------------- वाहन व्यवस्थापन (Vehicle / Transport Management) ----------------
CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  department_id INT NULL,
  vehicle_number VARCHAR(50) NOT NULL,
  vehicle_type VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  date_of_registration DATE NULL,
  chassis_no VARCHAR(100) NULL,
  engine_no VARCHAR(100) NULL,
  vehicle_class VARCHAR(100) NULL,
  passenger_capacity INT NULL,
  permit_validity DATE NULL,
  puc_validity DATE NULL,
  insurance_validity DATE NULL,
  fitness_validity DATE NULL,
  road_tax_validity DATE NULL,
  green_tax_validity DATE NULL,
  mv_tax_validity DATE NULL,
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

ALTER TABLE renewal_items ADD FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE;

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

-- ---------------- पत्र व्यवहार (File/Letter Movement Workflow) ----------------
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
  outward_no VARCHAR(50) NULL,
  dispatch_mode ENUM('Post','Courier','Speed Post','Email','Hand Delivery','Other') NULL,
  dispatch_tracking_no VARCHAR(100) NULL,
  dispatch_date DATE NULL,
  reference_no VARCHAR(100) NULL,
  is_budget_expense TINYINT(1) DEFAULT 0,
  budget_category ENUM('नवीन खरेदी','देखभाल','इंधन','इतर') NULL,
  requested_amount DECIMAL(12,2) NULL,
  approved_amount DECIMAL(12,2) NULL,
  budget_deducted TINYINT(1) DEFAULT 0,
  subject VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category_id INT NULL,
  department_id INT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status ENUM('draft','in_progress','returned','completed') DEFAULT 'draft',
  workflow_template_id INT NULL,
  current_step_order INT DEFAULT 0,
  current_holder_id INT NULL,
  current_holder_seen_at TIMESTAMP NULL,
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

-- ---------------- Public Work Requests (no-login submission) ----------------
CREATE TABLE work_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(50) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  requester_name VARCHAR(255) NOT NULL,
  requester_contact VARCHAR(100) NULL,
  requested_by_user_id INT NULL,
  requesting_department_id INT NULL,
  assigned_to_user_id INT NULL,
  work_description TEXT NOT NULL,
  cost_incurred DECIMAL(10,2) NULL,
  completion_remarks TEXT NULL,
  status ENUM('pending','in_progress','completed') DEFAULT 'pending',
  completed_by INT NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (requesting_department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_status (department_id, status)
) ENGINE=InnoDB;

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

-- ---------------- Notifications + Activity Log ----------------
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
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_org_created (organization_id, created_at)
) ENGINE=InnoDB;

-- ---------------- ध्यान व सत्संग (Meditation & Satsang) ----------------
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

CREATE TABLE employee_family_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  relation VARCHAR(100) NULL,
  resides_in_ashram TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE dead_stock_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  item_master_id INT NULL,
  item_code VARCHAR(50) NOT NULL UNIQUE,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit VARCHAR(50) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  is_new_purchase TINYINT(1) DEFAULT 1,
  `condition` ENUM('चांगली','दुरुस्तीयोग्य','खराब','भंगार') NOT NULL DEFAULT 'चांगली',
  location VARCHAR(255) NULL,
  remarks TEXT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (item_master_id) REFERENCES dead_stock_item_master(id) ON DELETE SET NULL,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept (department_id)
) ENGINE=InnoDB;

CREATE TABLE buildings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  import_batch_id INT NULL,
  name VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE maintenance_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  building_id INT NULL,
  import_batch_id INT NULL,
  floor INT NOT NULL DEFAULT 0,
  name VARCHAR(255) NOT NULL,
  location_code VARCHAR(50) NOT NULL,
  capacity INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE SET NULL,
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

CREATE TABLE residency_occupants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  import_batch_id INT NULL,
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

CREATE TABLE residency_bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  billing_month VARCHAR(7) NOT NULL,
  room_rent DECIMAL(10,2) DEFAULT 0,
  electricity_bill DECIMAL(10,2) DEFAULT 0,
  water_bill DECIMAL(10,2) DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL,
  billing_unit_count INT NOT NULL DEFAULT 1,
  per_person_amount DECIMAL(10,2) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_location_month (location_id, billing_month)
) ENGINE=InnoDB;

CREATE TABLE residency_bill_shares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  occupant_id INT NOT NULL,
  amount_due DECIMAL(10,2) NOT NULL,
  status ENUM('pending','paid') DEFAULT 'pending',
  paid_at DATETIME NULL,
  FOREIGN KEY (bill_id) REFERENCES residency_bills(id) ON DELETE CASCADE,
  FOREIGN KEY (occupant_id) REFERENCES residency_occupants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE department_budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  financial_year INT NOT NULL,
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
  source_module VARCHAR(50) NULL,
  reference_id INT NULL,
  description VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept_fy (department_id, financial_year)
) ENGINE=InnoDB;

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
  contribution VARCHAR(255) NULL,
  bio TEXT NULL,
  photo_path VARCHAR(255) NULL,
  display_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE webauthn_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  credential_id TEXT NOT NULL,
  public_key_pem TEXT NOT NULL,
  sign_count INT UNSIGNED NOT NULL DEFAULT 0,
  device_label VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
  auto_source_module VARCHAR(50) NULL,
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

CREATE TABLE letter_signatures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  signed_by INT NULL,
  signer_name VARCHAR(255) NOT NULL,
  signer_designation VARCHAR(255) NULL,
  action_type VARCHAR(50) NOT NULL,
  verification_method ENUM('fingerprint','password') NOT NULL,
  verification_code VARCHAR(20) NOT NULL UNIQUE,
  signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (signed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE store_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  department_id INT NULL,
  unit VARCHAR(50) NOT NULL,
  reorder_level DECIMAL(10,2) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE work_record_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity_used DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES store_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cctv_dvrs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  dvr_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  channel_capacity INT NULL,
  storage_capacity_tb DECIMAL(6,2) NULL,
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
  location VARCHAR(255) NULL,
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
  footage_days_available INT NULL,
  backup_done TINYINT(1) DEFAULT 0,
  backup_location VARCHAR(255) NULL,
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

CREATE TABLE it_labs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  lab_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  capacity INT NULL,
  assistant_id INT NULL,
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
  workstation_no VARCHAR(50) NULL,
  asset_tag VARCHAR(50) NOT NULL UNIQUE,
  asset_type ENUM('computer','monitor','keyboard','mouse','cpu','ups','smartboard','projector','printer','other') NOT NULL,
  brand VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  serial_no VARCHAR(150) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  warranty_expiry DATE NULL,
  os_license VARCHAR(150) NULL,
  license_expiry DATE NULL,
  status ENUM('working','faulty','under_repair','scrapped') DEFAULT 'working',
  power_status ENUM('on','off') DEFAULT 'off',
  power_status_updated_at TIMESTAMP NULL,
  power_status_updated_by INT NULL,
  remarks VARCHAR(255) NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (power_status_updated_by) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE device_tokens (
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

CREATE TABLE music_instruments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  instrument_name VARCHAR(255) NOT NULL,
  instrument_type VARCHAR(100) NULL,
  brand_model VARCHAR(255) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(10,2) NULL,
  condition_status ENUM('excellent','good','needs_tuning','under_repair','damaged') DEFAULT 'good',
  last_tuned_date DATE NULL,
  current_holder_id INT NULL,
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
  location_id INT NOT NULL,
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
  subject VARCHAR(150) NOT NULL,
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
  exam_board VARCHAR(150) NOT NULL,
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

CREATE TABLE it_general_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  request_code VARCHAR(20) NOT NULL UNIQUE,
  category ENUM('internet_network','software','new_hardware','account_access','printer_scanner','other') NOT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(255) NULL,
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
  bed_label VARCHAR(20) NULL,
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
  fee_period VARCHAR(50) NOT NULL,
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
  current_status VARCHAR(255) NULL,
  contact_number VARCHAR(50) NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES hostel_students(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE software_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  category ENUM('new_feature','improvement','bug','other') DEFAULT 'improvement',
  module_name VARCHAR(150) NULL,
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

CREATE TABLE biometric_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  serial_number VARCHAR(50) NOT NULL,
  device_name VARCHAR(150) NULL,
  last_seen_at TIMESTAMP NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_serial (serial_number)
) ENGINE=InnoDB;

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

CREATE TABLE staff_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  first_punch_time TIME NULL,
  last_punch_time TIME NULL,
  total_punches INT DEFAULT 0,
  status ENUM('present','absent','half_day','holiday','on_leave') DEFAULT 'present',
  is_late TINYINT(1) DEFAULT 0,
  is_early_leave TINYINT(1) DEFAULT 0,
  source ENUM('biometric','manual') DEFAULT 'biometric',
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_date (user_id, attendance_date)
) ENGINE=InnoDB;

CREATE TABLE staff_shift_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  shift_start TIME NOT NULL DEFAULT '09:30:00',
  shift_end TIME NOT NULL DEFAULT '17:30:00',
  grace_minutes INT NOT NULL DEFAULT 15,
  half_day_hours DECIMAL(4,2) NOT NULL DEFAULT 4.00,
  weekly_off_1 TINYINT NULL,
  weekly_off_2 TINYINT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  holiday_date DATE NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_holiday (organization_id, campus_id, holiday_date)
) ENGINE=InnoDB;

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

CREATE TABLE leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  name_local VARCHAR(100) NOT NULL,
  annual_quota DECIMAL(5,1) NOT NULL DEFAULT 12,
  is_paid TINYINT(1) DEFAULT 1,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  department_id INT NOT NULL,
  roll_no VARCHAR(30) NOT NULL,
  admission_no VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  year_of_study TINYINT NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
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
  name VARCHAR(255) NOT NULL,
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
  name VARCHAR(150) NOT NULL,
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
  day_of_week TINYINT NOT NULL,
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
  responsible_user_id INT NULL,
  status ENUM('planned','ongoing','completed','cancelled') DEFAULT 'planned',
  is_recurring TINYINT(1) DEFAULT 0,
  recurrence_pattern ENUM('yearly','monthly') NULL,
  parent_event_id INT NULL,
  budget_estimated DECIMAL(12,2) NULL,
  budget_actual DECIMAL(12,2) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (parent_event_id) REFERENCES events(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  assigned_to INT NULL,
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

CREATE TABLE guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  mobile VARCHAR(15) NULL,
  guest_organization VARCHAR(255) NULL,
  purpose VARCHAR(255) NULL,
  meeting_with INT NULL,
  check_in_time DATETIME NOT NULL,
  check_out_time DATETIME NULL,
  pass_code VARCHAR(20) NULL,
  photo_path VARCHAR(255) NULL,
  pre_registration_id INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (meeting_with) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (pre_registration_id) REFERENCES guest_pre_registrations(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  attendee_type ENUM('staff','student','guest') NOT NULL,
  user_id INT NULL,
  student_id INT NULL,
  guest_name VARCHAR(255) NULL,
  marked_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE teacher_timetable (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  teacher_id INT NOT NULL,
  day_of_week TINYINT NOT NULL,
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

CREATE TABLE substitute_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  original_timetable_id INT NULL,
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

CREATE TABLE teacher_qualifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  qualification_type VARCHAR(100) NOT NULL,
  specialization VARCHAR(150) NULL,
  institution VARCHAR(255) NULL,
  year_completed YEAR NULL,
  net_set_status ENUM('NET','SET','Both','None') DEFAULT 'None',
  research_papers_count INT DEFAULT 0,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE teacher_feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT NULL,
  submitted_by_type ENUM('student','parent','self') DEFAULT 'student',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE staff_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  document_type VARCHAR(100) NOT NULL,
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
  content MEDIUMTEXT NOT NULL,
  keywords VARCHAR(500) NULL,
  sort_order INT DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES help_categories(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE checkout_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
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

CREATE TABLE asset_allocation_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
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
  field_label VARCHAR(150) NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  field_type ENUM('text','number','dropdown','date','yesno') DEFAULT 'text',
  field_options TEXT NULL,
  is_required TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE asset_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  type_id INT NOT NULL,
  campus_id INT NOT NULL,
  user_id INT NOT NULL,
  asset_label VARCHAR(150) NULL,
  custom_data TEXT NULL,
  allocated_date DATE NOT NULL,
  unallocated_date DATE NULL,
  unallocated_reason VARCHAR(255) NULL,
  allocated_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bookable_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  room_type VARCHAR(100) NULL,
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


CREATE TABLE gaushala_cattle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NOT NULL,
  tag_number VARCHAR(50) NULL,
  name VARCHAR(150) NULL,
  breed VARCHAR(100) NULL,
  gender ENUM('Male','Female') NOT NULL,
  animal_category ENUM('Cow','Calf','Young Bull','Bull/Ox') DEFAULT 'Cow',
  color VARCHAR(100) NULL,
  date_of_birth DATE NULL,
  approx_age_months INT NULL,
  acquisition_type ENUM('Birth','Donation','Purchase','Other') DEFAULT 'Other',
  acquisition_date DATE NULL,
  source_details VARCHAR(255) NULL,
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

CREATE TABLE gaushala_breeding_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NOT NULL,
  breeding_date DATE NOT NULL,
  breeding_method ENUM('Natural','Artificial Insemination') DEFAULT 'Natural',
  expected_calving_date DATE NULL,
  actual_calving_date DATE NULL,
  calf_cattle_id INT NULL,
  status ENUM('pregnant','delivered','miscarriage') DEFAULT 'pregnant',
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cattle_id) REFERENCES gaushala_cattle(id) ON DELETE CASCADE,
  FOREIGN KEY (calf_cattle_id) REFERENCES gaushala_cattle(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gaushala_sponsorships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cattle_id INT NULL,
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
  base_amount DECIMAL(12,2) NOT NULL,
  transport_cost DECIMAL(12,2) DEFAULT 0,
  gst_percent DECIMAL(5,2) DEFAULT 0,
  gst_amount DECIMAL(12,2) DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL,
  purchase_date DATE NOT NULL,
  remarks VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dept_payment_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  source_type ENUM('material_purchase','renewal','maintenance') NOT NULL,
  source_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_type ENUM('Advance','Partial','Final') DEFAULT 'Partial',
  payment_mode VARCHAR(50) NULL,
  for_month DATE NULL,
  remarks VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_source (source_type, source_id)
) ENGINE=InnoDB;

CREATE TABLE organization_module_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  module_key VARCHAR(50) NOT NULL,
  is_enabled TINYINT(1) DEFAULT 1,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_org_module (organization_id, module_key)
) ENGINE=InnoDB;

-- DEMO DATA
-- ============================================================

INSERT INTO organizations (name, slug, type, contact_email) VALUES
('Shivaji Education Trust', 'shivaji-education-trust', 'trust', 'info@shivajitrust.edu.in');

INSERT INTO campuses (organization_id, name, code, city, state) VALUES
(1, 'Kolhapur Main Campus', 'KOL', 'Kolhapur', 'Maharashtra');

INSERT INTO departments (organization_id, campus_id, name, name_local, code, icon) VALUES
(1, 1, 'Maintenance', 'देखभाल', 'MTN', 'fa-solid fa-screwdriver-wrench'),
(1, 1, 'Plumbing', 'प्लंबिंग', 'PLM', 'fa-solid fa-faucet-drip'),
(1, 1, 'Electrician', 'इलेक्ट्रिशियन', 'ELC', 'fa-solid fa-bolt'),
(1, 1, 'Fire', 'अग्निशमन', 'FIR', 'fa-solid fa-fire-extinguisher'),
(1, 1, 'Housekeeping', 'हाऊसकीपिंग', 'HSK', 'fa-solid fa-broom'),
(1, 1, 'Security', 'सुरक्षा', 'SEC', 'fa-solid fa-shield'),
(1, 1, 'HR', 'मनुष्यबळ', 'HR', 'fa-solid fa-users'),
(1, 1, 'Transport', 'वाहतूक', 'TRP', 'fa-solid fa-bus'),
(1, 1, 'Store', 'स्टोअर', 'STR', 'fa-solid fa-boxes-stacked'),
(1, 1, 'Purchase', 'खरेदी', 'PUR', 'fa-solid fa-cart-shopping'),
(1, 1, 'Civil', 'सिव्हिल', 'CVL', 'fa-solid fa-trowel'),
(1, 1, 'Workshop', 'वर्कशॉप', 'WKS', 'fa-solid fa-industry'),
(1, 1, 'Hostel', 'वसतिगृह', 'HOS', 'fa-solid fa-building'),
(1, 1, 'Residency', 'रहिवासी विभाग', 'RES', 'fa-solid fa-house-chimney-user');

-- All demo users' password is: password
INSERT INTO users (organization_id, campus_id, department_id, employee_code, name, email, designation, password, role) VALUES
(1, 1, NULL, 'ADM-001', 'Super Admin', 'admin@shivajitrust.edu.in', 'Super Admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin'),
(1, 1, NULL, 'PRES-001', 'President', 'president@shivajitrust.edu.in', 'President', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'president'),
(1, 1, NULL, 'EXP-001', 'Executive President', 'execpresident@shivajitrust.edu.in', 'Executive President', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'executive_president'),
(1, 1, NULL, 'GM-001', 'General Manager', 'gm@shivajitrust.edu.in', 'General Manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'general_manager'),
(1, 1, 1, 'HOD-MTN', 'देखभाल विभागप्रमुख', 'mtn.hod@shivajitrust.edu.in', 'HOD', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hod'),
(1, 1, 1, 'MTN-STF1', 'देखभाल कर्मचारी 1', 'mtn.staff1@shivajitrust.edu.in', 'Staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff'),
(1, 1, 2, 'HOD-PLM', 'प्लंबिंग विभागप्रमुख', 'plm.hod@shivajitrust.edu.in', 'HOD', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hod'),
(1, 1, 2, 'PLM-STF1', 'प्लंबिंग कर्मचारी 1', 'plm.staff1@shivajitrust.edu.in', 'Staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff'),
(1, 1, 8, 'HOD-TRP', 'वाहतूक विभागप्रमुख', 'trp.hod@shivajitrust.edu.in', 'HOD', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hod'),
(1, 1, 8, 'TRP-STF1', 'वाहतूक कर्मचारी 1', 'trp.staff1@shivajitrust.edu.in', 'Staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff');

UPDATE departments SET hod_id = (SELECT id FROM users WHERE email='mtn.hod@shivajitrust.edu.in') WHERE code='MTN';
UPDATE departments SET hod_id = (SELECT id FROM users WHERE email='plm.hod@shivajitrust.edu.in') WHERE code='PLM';
UPDATE departments SET hod_id = (SELECT id FROM users WHERE email='trp.hod@shivajitrust.edu.in') WHERE code='TRP';

INSERT INTO renewal_items (organization_id, campus_id, department_id, item_type, item_name, reference_no, issued_date, expiry_date, status) VALUES
(1, 1, 8, 'vehicle_insurance', 'बस क्र. ०७ विमा', 'INS-2026-0712', '2025-07-08', DATE_ADD(CURDATE(), INTERVAL 12 DAY), 'active'),
(1, 1, 8, 'fitness_certificate', 'बस क्र. १२ फिटनेस प्रमाणपत्र', 'FIT-2026-0044', '2025-07-08', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'expired'),
(1, 1, 4, 'fire_noc', 'मुख्य इमारत अग्निशमन एनओसी', 'NOC-2025-118', '2024-07-08', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'expired'),
(1, 1, 3, 'generator_amc', 'जनरेटर एएमसी नूतनीकरण', 'AMC-GEN-22', '2025-07-08', DATE_ADD(CURDATE(), INTERVAL 20 DAY), 'active'),
(1, 1, 7, 'contract_renewal', 'कंत्राटी कर्मचारी करार नूतनीकरण', NULL, '2025-07-08', DATE_ADD(CURDATE(), INTERVAL 28 DAY), 'active');

-- ============================================================
-- SECOND DEMO ORGANIZATION — to test the multi-organization switcher
-- Super Admin's login (admin@shivajitrust.edu.in) can switch into this
-- from the sidebar dropdown once 2+ organizations exist.
-- ============================================================

INSERT INTO organizations (name, slug, type, contact_email) VALUES
('Sanjeevani Hospital Group', 'sanjeevani-hospital-group', 'hospital', 'info@sanjeevanihospital.in');

INSERT INTO campuses (organization_id, name, code, city, state) VALUES
(2, 'Pune Main Hospital', 'PUN', 'Pune', 'Maharashtra');

INSERT INTO departments (organization_id, campus_id, name, name_local, code, icon) VALUES
(2, 2, 'Maintenance', 'देखभाल', 'MTN', 'fa-solid fa-screwdriver-wrench'),
(2, 2, 'Housekeeping', 'हाऊसकीपिंग', 'HSK', 'fa-solid fa-broom'),
(2, 2, 'Security', 'सुरक्षा', 'SEC', 'fa-solid fa-shield'),
(2, 2, 'HR', 'मनुष्यबळ', 'HR', 'fa-solid fa-users');

INSERT INTO users (organization_id, campus_id, department_id, employee_code, name, email, designation, password, role) VALUES
(2, 2, 1, 'PUN-HOD-MTN', 'देखभाल विभागप्रमुख (Pune)', 'mtn.hod@sanjeevanihospital.in', 'HOD', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hod'),
(2, 2, 1, 'PUN-MTN-STF1', 'देखभाल कर्मचारी (Pune)', 'mtn.staff1@sanjeevanihospital.in', 'Staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff');

UPDATE departments SET hod_id = (SELECT id FROM users WHERE email='mtn.hod@sanjeevanihospital.in') WHERE code='MTN' AND organization_id=2;

-- Maintenance-type department gets the Issue Register tab by default (Super Admin can enable more later)
UPDATE departments SET has_issue_register = 1 WHERE code = 'MTN';
