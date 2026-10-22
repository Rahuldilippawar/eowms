-- ============================================================
-- Migration: "कर्मचारी हजेरी व्यवस्थापन" साठी नवीन, स्वतंत्र Permission
-- (manage_attendance) — जाणूनबुजून कोणालाही Auto-Grant केलेलं नाही,
-- Super Admin ने "Roles & Permissions" मधून हवं त्याला (उदा. HR) द्यावं.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_attendance', 'Manage staff attendance (mark manually, edit monthly grid, generate certificates) — typically HR', 'कर्मचारी हजेरी व्यवस्थापन (Manually नोंदवणे, Monthly Grid, प्रमाणपत्र) — साधारणतः HR साठी', 22);
