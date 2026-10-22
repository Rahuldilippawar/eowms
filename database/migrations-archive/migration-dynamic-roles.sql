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
