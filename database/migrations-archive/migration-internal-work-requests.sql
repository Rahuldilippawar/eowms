-- ============================================================
-- Migration: आंतर-विभागीय कामाची विनंती (Inter-Department Work Request)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE work_requests
  ADD COLUMN requested_by_user_id INT NULL AFTER requester_contact,
  ADD COLUMN requesting_department_id INT NULL AFTER requested_by_user_id,
  ADD FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD FOREIGN KEY (requesting_department_id) REFERENCES departments(id) ON DELETE SET NULL;
