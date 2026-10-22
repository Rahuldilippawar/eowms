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
