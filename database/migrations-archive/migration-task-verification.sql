-- टास्क — Assign करणाऱ्याकडून रद्द करणे (cancelled) आणि पूर्ण टास्कची पडताळणी/पुन्हा उघडणे
ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending';
ALTER TABLE tasks ADD COLUMN verified_at DATETIME NULL AFTER cost_incurred;
ALTER TABLE tasks ADD COLUMN verified_by INT NULL AFTER verified_at;
ALTER TABLE tasks ADD COLUMN verification_remarks TEXT NULL AFTER verified_by;
