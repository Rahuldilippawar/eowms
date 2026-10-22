-- ============================================================
-- Migration: लवचिक Department-Visibility — Super Admin एखाद्या
-- विशिष्ट व्यक्तीला, त्याच्या स्वतःच्या विभागाव्यतिरिक्त — निवडक इतर
-- विभागही (किंवा हवं तर संपूर्ण संस्था) बघण्याची परवानगी देऊ शकतो.
-- (Task, नूतनीकरण, कामाची विनंती, Tracker — या सर्वांसाठी लागू)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE user_extra_department_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  department_id INT NOT NULL,
  granted_by INT NULL,
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_dept (user_id, department_id)
) ENGINE=InnoDB;
