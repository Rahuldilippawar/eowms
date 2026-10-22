-- ============================================================
-- Migration: Sign & Approve — Digital Signature (Fingerprint/Password)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE letter_signatures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  signed_by INT NULL,
  signer_name VARCHAR(255) NOT NULL COMMENT 'नाव जसंच्या तसं साठवलेलं — नंतर User Edit/Delete झाला तरी इतिहास अचूक राहतो',
  signer_designation VARCHAR(255) NULL,
  action_type VARCHAR(50) NOT NULL COMMENT 'उदा. complete, forward',
  verification_method ENUM('fingerprint','password') NOT NULL,
  verification_code VARCHAR(20) NOT NULL UNIQUE COMMENT 'सार्वजनिक पडताळणीसाठी — QR मध्ये वापरला जातो',
  signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (signed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
