-- ============================================================
-- Migration: दैनंदिन कामाची नोंद — Staff ला स्वतःची नोंद Edit
-- करण्याचा Access (Super Admin प्रत्येक व्यक्तीसाठी वेगळा ठरवतो)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN can_edit_daily_work TINYINT(1) DEFAULT 0 AFTER department_id;
