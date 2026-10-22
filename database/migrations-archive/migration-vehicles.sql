-- ============================================================
-- Migration: वाहन व्यवस्थापन (Vehicle / Transport Management)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  department_id INT NULL,
  vehicle_number VARCHAR(50) NOT NULL,
  vehicle_type VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  driver_id INT NULL,
  fuel_type ENUM('petrol','diesel','cng','electric','other') DEFAULT 'diesel',
  current_odometer DECIMAL(10,1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  trip_date DATE NOT NULL,
  from_location VARCHAR(255) NOT NULL,
  to_location VARCHAR(255) NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  start_odometer DECIMAL(10,1) NOT NULL,
  end_odometer DECIMAL(10,1) NOT NULL,
  distance_km DECIMAL(10,1) NOT NULL,
  purpose VARCHAR(255) NULL,
  driver_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_fuel_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  fill_date DATE NOT NULL,
  odometer_reading DECIMAL(10,1) NOT NULL,
  liters DECIMAL(8,2) NOT NULL,
  cost DECIMAL(10,2) NULL,
  fuel_station VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  maintenance_date DATE NOT NULL,
  type VARCHAR(100) NOT NULL,
  description TEXT NULL,
  cost DECIMAL(10,2) NULL,
  odometer_reading DECIMAL(10,1) NULL,
  next_due_date DATE NULL,
  vendor VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
