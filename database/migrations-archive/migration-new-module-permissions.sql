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
