-- ============================================================
-- Migration: Dead Stock — नवीन खरेदी vs आधीपासून अस्तित्वात असलेली वस्तू
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- 1 = नवीन खरेदी (Budget मधून वजा होते), 0 = आधीपासून अस्तित्वात (वजा होत नाही)
ALTER TABLE dead_stock_items ADD COLUMN is_new_purchase TINYINT(1) DEFAULT 1 AFTER purchase_cost;
