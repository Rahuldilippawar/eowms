-- Biometric: अनोळखी Machine आपोआप "मंजूर" होऊ नये — Super Admin ने मंजूर केल्यावरच तिचे Punches स्वीकारायचे
-- (आधीपासून असलेली Devices मंजूरच राहतात — DEFAULT 1; नवीन, आपोआप नोंदलेली Devices iclock/cdata.php मधून 0 ने येतात)
ALTER TABLE biometric_devices ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

-- Biometric: Machine ने तेच Punches पुन्हा पाठवले तरी दुप्पट नोंद होऊ नये
DELETE p1 FROM biometric_punch_logs p1 JOIN biometric_punch_logs p2 ON p1.device_serial = p2.device_serial AND p1.biometric_pin = p2.biometric_pin AND p1.punch_time = p2.punch_time AND p1.id > p2.id;
ALTER TABLE biometric_punch_logs ADD UNIQUE KEY uniq_device_pin_time (device_serial, biometric_pin, punch_time);

-- Public फॉर्म (request.php, guests/pre-register.php) साठी साधी Rate-Limit नोंद
CREATE TABLE IF NOT EXISTS public_form_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  form_key VARCHAR(40) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_form_ip_time (form_key, ip_address, created_at)
) ENGINE=InnoDB;
