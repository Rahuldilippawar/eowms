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
