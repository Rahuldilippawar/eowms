-- ============================================================
-- Migration: Staff Profile/Track-Record — Tiered Visibility
-- (स्वतःचा विभाग → अनेक विभाग → एक कॉलेज → अनेक कॉलेज → संपूर्ण संस्था)
-- ============================================================

-- "एक कॉलेज" (स्वतःच्या Campus मधले सगळे विभाग) आणि "संपूर्ण संस्था"
-- साठी नवीन Permissions
INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('view_staff_track_own_campus', 'View staff profile/track record for ALL departments within own campus', 'स्वतःच्या Campus मधल्या सगळ्या विभागांचे Staff Profile/Track Record पाहणे', 32),
('view_staff_track_org_wide', 'View staff profile/track record across the ENTIRE organization', 'संपूर्ण संस्थेतल्या सगळ्यांचे Staff Profile/Track Record पाहणे', 33);

-- "अनेक कॉलेज" — विशिष्ट व्यक्तीला, विशिष्ट Extra Campuses चा Access
-- देण्यासाठी (user_extra_department_access च्याच Pattern प्रमाणे)
CREATE TABLE user_extra_campus_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  campus_id INT NOT NULL,
  granted_by INT NULL,
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_campus (user_id, campus_id)
) ENGINE=InnoDB;
