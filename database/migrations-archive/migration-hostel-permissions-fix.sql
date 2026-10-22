-- ============================================================
-- Migration: वसतिगृह Permissions — Management ला Default मध्ये
-- कधीच दिलेले नव्हते, ती चूक दुरुस्त करतो.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_hostels' FROM role_definitions WHERE category = 'management';

INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_hostel_students' FROM role_definitions WHERE category = 'management';
