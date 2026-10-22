-- ============================================================
-- Migration: पत्रव्यवहार — Attachment-Level Remarks
-- ============================================================
CREATE TABLE letter_attachment_remarks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attachment_id INT NOT NULL,
  user_id INT NOT NULL,
  remark_text TEXT NOT NULL,
  is_resolved TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (attachment_id) REFERENCES letter_attachments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
