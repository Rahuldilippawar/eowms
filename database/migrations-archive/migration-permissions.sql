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
