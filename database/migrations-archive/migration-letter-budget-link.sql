-- ============================================================
-- Migration: पत्र-मंजुरी → आपोआप Budget मधून वजा (Letter-Budget Link)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- ही फाईल खर्च/खरेदी/देखभाल-विनंतीसाठी आहे का, ती मूळ मागणी रक्कम,
-- कोणत्या Budget-श्रेणीत मोजायची, प्रत्यक्ष मंजूर झालेली रक्कम, आणि
-- Budget मधून वजा झालं आहे का (दुबार वजा होऊ नये यासाठी)
ALTER TABLE letters ADD COLUMN is_budget_expense TINYINT(1) DEFAULT 0 AFTER reference_no;
ALTER TABLE letters ADD COLUMN budget_category ENUM('नवीन खरेदी','देखभाल','इंधन','इतर') NULL AFTER is_budget_expense;
ALTER TABLE letters ADD COLUMN requested_amount DECIMAL(12,2) NULL AFTER budget_category;
ALTER TABLE letters ADD COLUMN approved_amount DECIMAL(12,2) NULL AFTER requested_amount;
ALTER TABLE letters ADD COLUMN budget_deducted TINYINT(1) DEFAULT 0 AFTER approved_amount;
