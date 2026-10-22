-- ============================================================
-- Migration: Attachment-Remarks — Image वर थेट Point करून Comment
-- ============================================================
ALTER TABLE letter_attachment_remarks ADD COLUMN x_position DECIMAL(5,2) NULL COMMENT '% पासून डावीकडून — फक्त Image-Attachment साठी' AFTER remark_text;
ALTER TABLE letter_attachment_remarks ADD COLUMN y_position DECIMAL(5,2) NULL COMMENT '% पासून वरून' AFTER x_position;
