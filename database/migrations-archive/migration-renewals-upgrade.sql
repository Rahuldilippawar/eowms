-- ============================================================
-- Migration: नूतनीकरण Module सुधारणा
--  1) स्थिती (Active/लवकरच/मुदत संपली) आता expiry_date वरून काढली जाते —
--     'renewed' कायमची स्थिती काढली; 'inactive' = Soft-Delete (निष्क्रिय)
--  2) Reminder Dedupe Table (item + milestone + expiry_date)
--  3) नूतनीकरण-Cycle नुसार प्रमाणपत्र/दस्तऐवज Attachments
--  4) Cron Jobs — शेवटचं कधी चाललं (Last-Run) + दैनंदिन Digest Dedupe
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items MODIFY status ENUM('active','due_soon','expired','renewed','inactive') DEFAULT 'active';

-- जुन्या 'renewed'/'due_soon'/'expired' नोंदी पुन्हा सामान्य 'active' — इतिहास renewal_history मध्ये तसाच राहतो
UPDATE renewal_items SET status = 'active' WHERE status IN ('renewed','due_soon','expired');

CREATE TABLE renewal_reminder_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  renewal_item_id INT NOT NULL,
  milestone VARCHAR(20) NOT NULL,
  expiry_date DATE NOT NULL,
  recipients INT DEFAULT 0,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_milestone_expiry (renewal_item_id, milestone, expiry_date),
  FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE renewal_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  renewal_item_id INT NOT NULL,
  organization_id INT NOT NULL,
  cycle_expiry_date DATE NULL,
  title VARCHAR(255) NULL,
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NULL,
  file_size INT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_item_cycle (renewal_item_id, cycle_expiry_date)
) ENGINE=InnoDB;

CREATE TABLE cron_job_runs (
  job_key VARCHAR(100) NOT NULL PRIMARY KEY,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_status ENUM('running','success','failed') DEFAULT 'running',
  last_duration_sec INT NULL,
  last_output TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE cron_digest_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cron_key VARCHAR(50) NOT NULL,
  user_id INT NOT NULL,
  digest_date DATE NOT NULL,
  item_count INT DEFAULT 0,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cron_user_day (cron_key, user_id, digest_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
