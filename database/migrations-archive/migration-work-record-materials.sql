-- ============================================================
-- Migration: दैनंदिन कामात — Store-साहित्य वापर Tracking
-- (Vidyut/Plumbing/Swachata इ. विभागांनी कामासाठी Store मधून घेतलेलं
-- साहित्य — त्या-त्या कामाच्या नोंदीशी Link करण्यासाठी)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE work_record_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  work_record_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity_used DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (work_record_id) REFERENCES work_records(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES store_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;
