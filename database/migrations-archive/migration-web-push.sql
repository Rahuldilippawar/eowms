-- ============================================================
-- Migration: खरं Push Notification (App बंद असतानाही सूचना)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE organizations
  ADD COLUMN vapid_public_key TEXT NULL,
  ADD COLUMN vapid_private_key TEXT NULL;

CREATE TABLE push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  endpoint VARCHAR(512) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_endpoint (endpoint(255))
) ENGINE=InnoDB;
