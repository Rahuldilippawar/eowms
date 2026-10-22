-- ============================================================
-- Migration: वाहनाचे संपूर्ण तपशील (RTO/Compliance) + नूतनीकरण-जोडणी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE vehicles
  ADD COLUMN date_of_registration DATE NULL AFTER model,
  ADD COLUMN chassis_no VARCHAR(100) NULL AFTER date_of_registration,
  ADD COLUMN engine_no VARCHAR(100) NULL AFTER chassis_no,
  ADD COLUMN vehicle_class VARCHAR(100) NULL AFTER engine_no,
  ADD COLUMN passenger_capacity INT NULL AFTER vehicle_class,
  ADD COLUMN permit_validity DATE NULL AFTER passenger_capacity,
  ADD COLUMN puc_validity DATE NULL AFTER permit_validity,
  ADD COLUMN insurance_validity DATE NULL AFTER puc_validity,
  ADD COLUMN fitness_validity DATE NULL AFTER insurance_validity,
  ADD COLUMN road_tax_validity DATE NULL AFTER fitness_validity,
  ADD COLUMN green_tax_validity DATE NULL AFTER road_tax_validity,
  ADD COLUMN mv_tax_validity DATE NULL AFTER green_tax_validity;

-- वाहनाच्या या 7 नूतनीकरण-तारखा — "नूतनीकरण व अनुपालन" पानावर आपोआप
-- दिसण्यासाठी — renewal_items शी जोडणारे columns (vehicle_id + कोणत्या
-- प्रकारचं नूतनीकरण आहे ते ओळखणारा renewal_field).
ALTER TABLE renewal_items
  ADD COLUMN vehicle_id INT NULL AFTER department_id,
  ADD COLUMN renewal_field VARCHAR(50) NULL AFTER vehicle_id,
  ADD FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE;
