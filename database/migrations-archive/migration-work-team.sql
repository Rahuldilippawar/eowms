-- ============================================================
-- Migration: टीम-निहाय काम नोंद (एकापेक्षा जास्त कर्मचारी मिळून काम)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_record_team (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  user_id INT NOT NULL,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_record_user (work_record_id, user_id)
) ENGINE=InnoDB;

-- Existing records: backfill so every past record's single employee_id
-- also appears in the new team table (keeps everything consistent).
INSERT IGNORE INTO work_record_team (work_record_id, user_id)
SELECT id, employee_id FROM work_records;
