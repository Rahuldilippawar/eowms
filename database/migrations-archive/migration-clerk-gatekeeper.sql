-- ============================================================
-- Migration: Clerk/Gatekeeper Pattern — Executive कडे जाणारी पत्रं
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- कोणत्या अधिकाऱ्यासाठी कोणता Clerk काम बघतो
ALTER TABLE users ADD COLUMN gatekeeper_clerk_id INT NULL AFTER department_id;
ALTER TABLE users ADD FOREIGN KEY (gatekeeper_clerk_id) REFERENCES users(id) ON DELETE SET NULL;

-- फाईल तांत्रिकदृष्ट्या Clerk कडे असली, तरी ती खरं कोणासाठी आहे हे दाखवण्यासाठी
ALTER TABLE letters ADD COLUMN intended_recipient_id INT NULL AFTER current_holder_id;
ALTER TABLE letters ADD FOREIGN KEY (intended_recipient_id) REFERENCES users(id) ON DELETE SET NULL;

-- Executive ने "कुठे पुढे पाठवायचं" ठरवलं, पण अजून Clerk ने Approve केलेलं नाही
ALTER TABLE letters ADD COLUMN pending_forward_to INT NULL AFTER intended_recipient_id;
ALTER TABLE letters ADD FOREIGN KEY (pending_forward_to) REFERENCES users(id) ON DELETE SET NULL;

-- फाईल सध्या clerk-प्रक्रियेच्या कोणत्या टप्प्यावर आहे
ALTER TABLE letters ADD COLUMN clerk_stage ENUM('none','awaiting_inward','awaiting_forward_approval') NOT NULL DEFAULT 'none' AFTER pending_forward_to;
