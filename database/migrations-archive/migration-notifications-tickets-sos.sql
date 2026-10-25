-- ============================================================
-- Migration: सूचना Push-Queue, एकत्रित विनंती (Ticket) प्रणाली + SLA,
-- तक्रार निवारण सुधारणा, आणीबाणी (SOS) प्रतिसाद, परिपत्रक Edit/Pin/मुदत
-- कितीही वेळा चालवली तरी सुरक्षित (Runner: dbmRunMigrationFile)
-- ============================================================

-- ---------------- 1. Push Queue ----------------
CREATE TABLE notification_push_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  notification_id INT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  link VARCHAR(255) NULL,
  is_urgent TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_queue_status (status, is_urgent, id),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE notifications
  ADD INDEX idx_read_created (is_read, created_at);

-- ---------------- 2. एकत्रित विनंती (work_requests = एकमेव Ticket Table) ----------------
ALTER TABLE work_requests
  MODIFY department_id INT NULL,
  MODIFY status ENUM('pending','in_progress','completed','closed','cancelled') DEFAULT 'pending',
  ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'general' AFTER department_id,
  ADD COLUMN sub_category VARCHAR(50) NULL AFTER category,
  ADD COLUMN title VARCHAR(255) NULL AFTER sub_category,
  ADD COLUMN location VARCHAR(255) NULL AFTER title,
  ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER location,
  ADD COLUMN due_at DATETIME NULL AFTER priority,
  ADD COLUMN escalation_level TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN rating TINYINT NULL,
  ADD COLUMN feedback TEXT NULL,
  ADD COLUMN closed_at DATETIME NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN reopened_count INT NOT NULL DEFAULT 0,
  ADD COLUMN legacy_it_request_id INT NULL COMMENT 'जुन्या it_general_requests.id — स्थलांतर शोधण्यासाठी',
  ADD UNIQUE KEY uniq_legacy_it (legacy_it_request_id),
  ADD INDEX idx_org_category_status (organization_id, category, status),
  ADD INDEX idx_status_due (status, due_at);

ALTER TABLE work_request_actions
  MODIFY action_type ENUM('created','status_changed','assigned','comment','reopened','closed','cancelled','escalated') NOT NULL;

CREATE TABLE work_request_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  user_id INT NULL,
  comment TEXT NOT NULL,
  legacy_it_comment_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_legacy_it_comment (legacy_it_comment_id),
  INDEX idx_request (request_id),
  FOREIGN KEY (request_id) REFERENCES work_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- जुन्या IT Requests → work_requests (category = 'it'); मूळ Table जशीच्या तशी (Archive) राहते
INSERT INTO work_requests (request_code, organization_id, department_id, category, sub_category, title, location, priority, requester_name, requester_contact, requested_by_user_id, requesting_department_id, assigned_to_user_id, work_description, completion_remarks, status, completed_by, completed_at, closed_at, created_at, legacy_it_request_id)
SELECT
  IF(EXISTS(SELECT 1 FROM work_requests w2 WHERE w2.request_code = r.request_code), CONCAT(r.request_code, '-L', r.id), r.request_code),
  r.organization_id,
  (SELECT d.id FROM departments d WHERE d.organization_id = r.organization_id AND d.code = 'IT' ORDER BY d.id LIMIT 1),
  'it', r.category, r.title, r.location, r.priority,
  COALESCE(u.name, '—'), u.mobile, r.requested_by, r.department_id, r.assigned_to, r.description, r.resolution_notes,
  CASE r.status WHEN 'in_progress' THEN 'in_progress' WHEN 'resolved' THEN 'completed' WHEN 'closed' THEN 'closed' ELSE 'pending' END,
  r.resolved_by, r.resolved_at, IF(r.status = 'closed', r.resolved_at, NULL), r.created_at, r.id
FROM it_general_requests r LEFT JOIN users u ON u.id = r.requested_by
WHERE NOT EXISTS (SELECT 1 FROM work_requests w WHERE w.legacy_it_request_id = r.id);

INSERT INTO work_request_comments (request_id, user_id, comment, legacy_it_comment_id, created_at)
SELECT w.id, c.commented_by, c.comment, c.id, c.created_at
FROM it_general_request_comments c JOIN work_requests w ON w.legacy_it_request_id = c.request_id
WHERE NOT EXISTS (SELECT 1 FROM work_request_comments x WHERE x.legacy_it_comment_id = c.id);

INSERT INTO work_request_actions (request_id, action_type, new_status, remark, changed_by, created_at)
SELECT w.id, 'created', w.status, 'जुन्या IT Request मधून स्थलांतरित', w.requested_by_user_id, w.created_at
FROM work_requests w
WHERE w.legacy_it_request_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM work_request_actions a WHERE a.request_id = w.id);

-- SLA मुदत — जुन्या नोंदींसाठी (urgent 8 तास, high 1 दिवस, normal 3, low 7)
UPDATE work_requests SET due_at = DATE_ADD(created_at, INTERVAL (CASE priority WHEN 'urgent' THEN 8 WHEN 'high' THEN 24 WHEN 'low' THEN 168 ELSE 72 END) HOUR) WHERE due_at IS NULL;

-- ---------------- 3. तक्रार निवारण ----------------
ALTER TABLE grievances
  MODIFY submitted_by INT NULL,
  ADD COLUMN submitter_hash CHAR(64) NULL COMMENT 'गुप्त तक्रारीसाठी — व्यक्तीचा ID न ठेवता HMAC',
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN reopened_count INT NOT NULL DEFAULT 0,
  ADD INDEX idx_submitter_hash (submitter_hash);

CREATE TABLE grievance_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grievance_id INT NOT NULL,
  user_id INT NULL COMMENT 'गुप्त तक्रारदाराची टिप्पणी असेल तर NULL',
  author_role ENUM('submitter','handler') NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grievance (grievance_id),
  FOREIGN KEY (grievance_id) REFERENCES grievances(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- 4. आणीबाणी (SOS) ----------------
ALTER TABLE emergency_alerts
  ADD COLUMN campus_id INT NULL AFTER triggered_by,
  ADD COLUMN latitude DECIMAL(10,7) NULL,
  ADD COLUMN longitude DECIMAL(10,7) NULL,
  ADD COLUMN first_ack_at DATETIME NULL,
  ADD COLUMN escalated_at DATETIME NULL,
  ADD COLUMN resolution_remark TEXT NULL,
  ADD INDEX idx_user_created (triggered_by, created_at);

CREATE TABLE emergency_alert_recipients (
  alert_id INT NOT NULL,
  user_id INT NOT NULL,
  notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (alert_id, user_id),
  FOREIGN KEY (alert_id) REFERENCES emergency_alerts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE emergency_alert_responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alert_id INT NOT NULL,
  user_id INT NOT NULL,
  responded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alert_user (alert_id, user_id),
  FOREIGN KEY (alert_id) REFERENCES emergency_alerts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- 5. परिपत्रक ----------------
ALTER TABLE announcements
  ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN expires_at DATE NULL,
  ADD COLUMN withdrawn_at DATETIME NULL,
  ADD COLUMN withdrawn_by INT NULL,
  ADD COLUMN updated_at DATETIME NULL;
