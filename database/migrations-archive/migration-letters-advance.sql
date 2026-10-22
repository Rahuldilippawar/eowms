-- ============================================================
-- Migration: पत्रव्यवहार — Advance Features (Thread-Link, Bulk Send)
-- (Attachments आणि Digital Signature System आधीच अस्तित्वात होतं —
-- फक्त हे 2 नवीन Column लागतात.)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE letters ADD COLUMN parent_letter_id INT NULL AFTER reference_no;
ALTER TABLE letters ADD COLUMN batch_ref VARCHAR(40) NULL AFTER parent_letter_id;
ALTER TABLE letters ADD CONSTRAINT fk_letters_parent FOREIGN KEY (parent_letter_id) REFERENCES letters(id) ON DELETE SET NULL;
ALTER TABLE letters ADD INDEX idx_batch_ref (batch_ref);
