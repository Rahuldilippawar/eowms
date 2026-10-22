-- ============================================================
-- Migration: Trust-स्तरीय विभाग (कुठल्याही College/School खाली नसलेले)
-- उदा. Trust Office, केंद्रीय हिशोब विभाग, विधी विभाग, इ.
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE departments MODIFY COLUMN campus_id INT NULL;
