-- ============================================================
-- Migration: Parent-Child संस्था रचना — शाखांमधला (Sister Org) पत्रव्यवहार
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- कोणती संस्था कोणत्या मुख्य Trust ची उप-शाखा आहे
ALTER TABLE organizations ADD COLUMN parent_organization_id INT NULL AFTER type;
ALTER TABLE organizations ADD FOREIGN KEY (parent_organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

-- उदाहरण: संस्था id=2 ही संस्था id=1 (मुख्य Trust) ची उप-शाखा आहे असं जोडायचं असल्यास:
-- UPDATE organizations SET parent_organization_id = 1 WHERE id = 2;
