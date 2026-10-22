-- ============================================================
-- Migration: वाहन नोंद सुधारणा विनंती (Vehicle Entry Correction Requests)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE vehicle_correction_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  entry_type ENUM('trip','fuel','maintenance') NOT NULL,
  entry_id INT NOT NULL,
  request_type ENUM('edit','delete') NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  requested_by INT NOT NULL,
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
