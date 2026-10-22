-- ============================================================
-- Migration: CCTV विभाग साठी Granular Permission
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_cctv', 'Access the CCTV department module', 'CCTV विभाग पाहणे/व्यवस्थापणे', 20);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_cctv' FROM role_definitions WHERE category = 'management';
