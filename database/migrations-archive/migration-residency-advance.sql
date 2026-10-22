-- ============================================================
-- Migration: रहिवासी विभाग — Advance Features
-- (Billing/Sharing, Allotment Workflow, Inspection, Transfer
-- History, Occupancy Analytics, Guest Registration)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- मासिक भाडं/वीज-बिल — प्रत्येक Room साठी
CREATE TABLE residency_bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  billing_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM स्वरूपात',
  room_rent DECIMAL(10,2) DEFAULT 0,
  electricity_bill DECIMAL(10,2) DEFAULT 0,
  water_bill DECIMAL(10,2) DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL,
  billing_unit_count INT NOT NULL DEFAULT 1 COMMENT 'या Room मधले किती Staff-Occupant — यांच्यात बिल विभागलं',
  per_person_amount DECIMAL(10,2) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_location_month (location_id, billing_month)
) ENGINE=InnoDB;

-- प्रत्येक Bill मधला — कोणत्या Occupant (Staff) चा किती वाटा, भरला की नाही
CREATE TABLE residency_bill_shares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  occupant_id INT NOT NULL,
  amount_due DECIMAL(10,2) NOT NULL,
  status ENUM('pending','paid') DEFAULT 'pending',
  paid_date DATE NULL,
  FOREIGN KEY (bill_id) REFERENCES residency_bills(id) ON DELETE CASCADE,
  FOREIGN KEY (occupant_id) REFERENCES residency_occupants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- औपचारिक खोली-वाटप विनंती (Allotment Request) + प्रतीक्षा-यादी
CREATE TABLE residency_allotment_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  requested_by INT NOT NULL,
  preferred_building_id INT NULL,
  family_size INT DEFAULT 1,
  reason TEXT NULL,
  status ENUM('pending','approved','rejected','waitlisted') DEFAULT 'pending',
  allotted_location_id INT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (preferred_building_id) REFERENCES buildings(id) ON DELETE SET NULL,
  FOREIGN KEY (allotted_location_id) REFERENCES maintenance_locations(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- खोली-तपासणी अहवाल (Room Inspection)
CREATE TABLE residency_inspections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  inspection_date DATE NOT NULL,
  condition_rating ENUM('excellent','good','fair','poor') NOT NULL,
  remarks TEXT NULL,
  inspected_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (inspected_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- खोली-बदल इतिहास (Room Transfer History)
CREATE TABLE residency_room_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  occupant_id INT NOT NULL,
  from_location_id INT NULL,
  to_location_id INT NOT NULL,
  transfer_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  transferred_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (occupant_id) REFERENCES residency_occupants(id) ON DELETE CASCADE,
  FOREIGN KEY (from_location_id) REFERENCES maintenance_locations(id) ON DELETE SET NULL,
  FOREIGN KEY (to_location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- पाहुणे-नोंदणी (Guest Registration)
CREATE TABLE residency_guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  host_occupant_id INT NULL,
  guest_name VARCHAR(255) NOT NULL,
  guest_contact VARCHAR(50) NULL,
  relation VARCHAR(100) NULL,
  purpose VARCHAR(255) NULL,
  arrival_date DATE NOT NULL,
  expected_departure_date DATE NULL,
  actual_departure_date DATE NULL,
  status ENUM('checked_in','checked_out') DEFAULT 'checked_in',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (host_occupant_id) REFERENCES residency_occupants(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
