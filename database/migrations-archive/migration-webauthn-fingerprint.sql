-- ============================================================
-- Migration: Fingerprint/Face ID Login (WebAuthn)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE webauthn_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  credential_id TEXT NOT NULL COMMENT 'base64url encoded credential ID from authenticator',
  public_key_pem TEXT NOT NULL COMMENT 'PEM-encoded EC P-256 public key, used to verify future logins',
  sign_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'replay-attack protection counter',
  device_label VARCHAR(255) NULL COMMENT 'e.g. "Chrome on Windows", user-editable',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
