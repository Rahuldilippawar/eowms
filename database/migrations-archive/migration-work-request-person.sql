-- ============================================================
-- Migration: कामाची विनंती — विशिष्ट व्यक्तीला थेट नेमता येण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE work_requests
  ADD COLUMN assigned_to_user_id INT NULL AFTER requesting_department_id,
  ADD FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL;
