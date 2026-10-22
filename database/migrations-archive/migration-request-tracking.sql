-- ============================================================
-- Migration: कामाची विनंती — Tracking, Remarks History, Seen-by
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_request_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  action_type ENUM('created','status_changed') NOT NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NULL,
  remark TEXT NULL,
  changed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES work_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE work_request_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  user_id INT NOT NULL,
  viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES work_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_request_user (request_id, user_id)
) ENGINE=InnoDB;
