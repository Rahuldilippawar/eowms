-- ============================================================
-- Migration: Duplicate Modules एकत्र करणे
--  1. Asset Checkout → Asset Allocation ("Issue / Return Register")
--  2. विभाग Budget + Material Purchase → एकच "विभाग Finance" (एकच खर्च-Ledger)
--  3. Restricted-Mode Roles मधले जुने Module-Keys नवीन Keys ने बदलणे
-- कितीही वेळा चालवली तरी सुरक्षित — प्रत्येक Data-INSERT "NOT EXISTS" ने तपासलेला आहे.
-- जुन्या checkout_items / checkout_logs Tables काढलेल्या नाहीत (फक्त वापर बंद).
-- ============================================================

-- ---------- 1. Allocation: Loan (उसनं) Types + वस्तू-Master + Return नोंद ----------
ALTER TABLE asset_allocation_types
  ADD COLUMN is_loan TINYINT(1) DEFAULT 0,
  ADD COLUMN legacy_checkout_category VARCHAR(150) NULL;

CREATE TABLE asset_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  type_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  default_location VARCHAR(150) NULL,
  is_active TINYINT(1) DEFAULT 1,
  legacy_checkout_item_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_legacy_checkout_item (legacy_checkout_item_id),
  INDEX idx_org_type (organization_id, type_id)
) ENGINE=InnoDB;

ALTER TABLE asset_allocations
  MODIFY campus_id INT NULL,
  ADD COLUMN item_id INT NULL,
  ADD COLUMN purpose VARCHAR(255) NULL,
  ADD COLUMN allocated_at DATETIME NULL,
  ADD COLUMN due_at DATETIME NULL,
  ADD COLUMN returned_at DATETIME NULL,
  ADD COLUMN returned_to INT NULL,
  ADD COLUMN legacy_checkout_log_id INT NULL,
  ADD UNIQUE KEY uniq_legacy_checkout_log (legacy_checkout_log_id),
  ADD INDEX idx_item_active (item_id, unallocated_date),
  ADD FOREIGN KEY (item_id) REFERENCES asset_items(id) ON DELETE SET NULL,
  ADD FOREIGN KEY (returned_to) REFERENCES users(id) ON DELETE SET NULL;

-- आधीच्या Allocations मधल्या "वस्तूचं नाव" (asset_label) वरून वस्तू-Master तयार करतो
INSERT INTO asset_items (organization_id, type_id, campus_id, name, is_active, created_by)
SELECT aa.organization_id, aa.type_id, MIN(aa.campus_id), aa.asset_label, 1, MIN(aa.allocated_by)
FROM asset_allocations aa
WHERE aa.asset_label IS NOT NULL AND aa.asset_label <> '' AND aa.item_id IS NULL AND aa.legacy_checkout_log_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM asset_items ai WHERE ai.organization_id = aa.organization_id AND ai.type_id = aa.type_id AND ai.name COLLATE utf8mb4_unicode_ci = aa.asset_label COLLATE utf8mb4_unicode_ci)
GROUP BY aa.organization_id, aa.type_id, aa.asset_label;

UPDATE asset_allocations aa
JOIN asset_items ai ON ai.organization_id = aa.organization_id AND ai.type_id = aa.type_id AND ai.name COLLATE utf8mb4_unicode_ci = aa.asset_label COLLATE utf8mb4_unicode_ci
SET aa.item_id = ai.id
WHERE aa.item_id IS NULL AND aa.asset_label IS NOT NULL AND aa.asset_label <> '';

-- Checkout वस्तूंच्या प्रत्येक "प्रकार" (category) साठी एक Loan-Type
INSERT INTO asset_allocation_types (organization_id, name, icon, is_active, is_loan, legacy_checkout_category, created_by)
SELECT ci.organization_id, COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू'), 'fa-solid fa-key', 1, 1, COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू'), MIN(ci.created_by)
FROM checkout_items ci
WHERE NOT EXISTS (
  SELECT 1 FROM asset_allocation_types t
  WHERE t.organization_id = ci.organization_id AND t.legacy_checkout_category = COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू')
)
GROUP BY ci.organization_id, COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू');

-- Checkout वस्तू → वस्तू-Master
INSERT INTO asset_items (organization_id, type_id, campus_id, name, default_location, is_active, legacy_checkout_item_id, created_by, created_at)
SELECT ci.organization_id, t.id, ci.campus_id, ci.name, ci.default_location, ci.is_active, ci.id, ci.created_by, ci.created_at
FROM checkout_items ci
JOIN asset_allocation_types t ON t.organization_id = ci.organization_id AND t.legacy_checkout_category = COALESCE(NULLIF(TRIM(ci.category), ''), 'Checkout वस्तू')
WHERE NOT EXISTS (SELECT 1 FROM asset_items ai WHERE ai.legacy_checkout_item_id = ci.id);

-- Checkout Log → Allocation History (कोणी घेतलं, कधी, मुदत, कधी परत आलं, कोणी परत घेतलं)
INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, item_id, asset_label, purpose, allocated_date, allocated_at, due_at, unallocated_date, returned_at, returned_to, unallocated_reason, allocated_by, legacy_checkout_log_id, created_at)
SELECT ai.organization_id, ai.type_id, ai.campus_id, cl.borrowed_by, ai.id, ai.name, cl.purpose, DATE(cl.checkout_at), cl.checkout_at, cl.expected_return_at, DATE(cl.actual_return_at), cl.actual_return_at, cl.returned_to, cl.remarks, cl.issued_by, cl.id, cl.checkout_at
FROM checkout_logs cl
JOIN asset_items ai ON ai.legacy_checkout_item_id = cl.item_id
WHERE NOT EXISTS (SELECT 1 FROM asset_allocations aa WHERE aa.legacy_checkout_log_id = cl.id);

-- ---------- 2. एकच खर्च-Ledger (budget_transactions) ----------
ALTER TABLE budget_transactions
  MODIFY category ENUM('नवीन खरेदी','देखभाल','इंधन','नूतनीकरण','इतर') NOT NULL,
  ADD COLUMN spend_date DATE NULL,
  ADD INDEX idx_source_ref (source_module, reference_id);

-- जुन्या नोंदींसाठी — खर्चाचा खरा दिनांक भरतो
UPDATE budget_transactions bt JOIN dead_stock_items ds ON ds.id = bt.reference_id
SET bt.spend_date = COALESCE(ds.purchase_date, DATE(ds.created_at))
WHERE bt.source_module = 'dead_stock' AND bt.spend_date IS NULL;

UPDATE budget_transactions bt JOIN maintenance_logs ml ON ml.id = bt.reference_id
SET bt.spend_date = ml.work_date
WHERE bt.source_module = 'maintenance' AND bt.spend_date IS NULL;

-- वाहन इंधन/देखभाल: आधी reference_id मध्ये वाहनाचा ID जात होता — योग्य Log-ID शोधून दुरुस्त करतो
UPDATE budget_transactions bt
JOIN vehicle_fuel_log f ON f.vehicle_id = bt.reference_id AND f.cost = bt.amount AND ABS(TIMESTAMPDIFF(SECOND, f.created_at, bt.created_at)) <= 300
SET bt.reference_id = f.id, bt.source_module = 'vehicle_fuel_log', bt.spend_date = f.fill_date
WHERE bt.source_module = 'vehicle_fuel';

UPDATE budget_transactions bt
JOIN vehicle_maintenance vm ON vm.vehicle_id = bt.reference_id AND vm.cost = bt.amount AND ABS(TIMESTAMPDIFF(SECOND, vm.created_at, bt.created_at)) <= 300
SET bt.reference_id = vm.id, bt.source_module = 'vehicle_maintenance_log', bt.spend_date = vm.maintenance_date
WHERE bt.source_module = 'vehicle_maintenance';

UPDATE budget_transactions SET spend_date = DATE(created_at) WHERE spend_date IS NULL;

-- आर्थिक वर्ष "आज" वरून नाही — खर्चाच्या दिनांकावरून
UPDATE budget_transactions SET financial_year = IF(MONTH(spend_date) >= 4, YEAR(spend_date), YEAR(spend_date) - 1) WHERE spend_date IS NOT NULL;

-- Material Purchase — आत्तापर्यंत फक्त Payment-Ledger मध्ये होत्या; Budget-Ledger मध्ये एकदाच नोंदवतो
INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by, spend_date, created_at)
SELECT p.organization_id, p.department_id, IF(MONTH(p.purchase_date) >= 4, YEAR(p.purchase_date), YEAR(p.purchase_date) - 1), p.total_amount, 'नवीन खरेदी', 'material_purchase', p.id, LEFT(CONCAT(p.material_name, ' — ', p.vendor_name), 255), p.created_by, p.purchase_date, p.created_at
FROM dept_material_purchases p
WHERE p.total_amount > 0
  AND NOT EXISTS (SELECT 1 FROM budget_transactions bt WHERE bt.source_module = 'material_purchase' AND bt.reference_id = p.id);

-- Renewal Payments — प्रत्येक Payment एकदाच खर्च म्हणून
INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by, spend_date, created_at)
SELECT l.organization_id, l.department_id, IF(MONTH(l.payment_date) >= 4, YEAR(l.payment_date), YEAR(l.payment_date) - 1), l.amount, 'नूतनीकरण', 'renewal_payment', l.id, LEFT(CONCAT('नूतनीकरण Payment — ', COALESCE(ri.item_name, '')), 255), l.recorded_by, l.payment_date, l.created_at
FROM dept_payment_ledger l
LEFT JOIN renewal_items ri ON ri.id = l.source_id
WHERE l.source_type = 'renewal' AND l.amount > 0
  AND NOT EXISTS (SELECT 1 FROM budget_transactions bt WHERE bt.source_module = 'renewal_payment' AND bt.reference_id = l.id);

-- ---------- 3. Restricted-Mode Roles — काढलेले Module-Keys ----------
UPDATE role_definitions SET restricted_modules = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', restricted_modules, ','), ',checkout,', ',allocations,')) WHERE restricted_modules LIKE '%checkout%';
UPDATE role_definitions SET restricted_modules = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', restricted_modules, ','), ',budget,', ',dept-finance,')) WHERE restricted_modules LIKE '%budget%';

UPDATE permissions SET label_local = 'Asset Issue/Return (उसनं) व्यवस्थापन' WHERE permission_key = 'manage_checkout';
UPDATE permissions SET label_local = 'विभाग Finance — Material Purchase व Payment' WHERE permission_key = 'manage_dept_finance';
