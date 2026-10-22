-- ============================================================
-- Migration: विभाग प्रमुख (HOD) ला Task Assign करण्याची परवानगी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'assign_tasks' FROM role_definitions WHERE category = 'hod';
