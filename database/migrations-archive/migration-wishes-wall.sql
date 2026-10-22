-- ============================================================
-- Migration: शुभेच्छा-भिंत (Wishes Wall) — वाढदिवस/वर्धापनदिनी
-- सहकाऱ्यांनी टाकलेल्या छोट्या शुभेच्छा
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE birthday_wishes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  target_user_id INT NOT NULL,
  wish_date DATE NOT NULL,
  wish_type ENUM('birthday','anniversary') NOT NULL,
  wish_text VARCHAR(255) NOT NULL,
  posted_by INT NOT NULL,
  posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_wish (target_user_id, wish_date, posted_by)
) ENGINE=InnoDB;
