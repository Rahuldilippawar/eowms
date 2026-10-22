-- ============================================================
-- Migration: Add "manage_vehicles" permission
-- Run this ONLY if you already ran migration-permissions.sql earlier.
-- ============================================================

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_vehicles', 'Add/edit vehicles, log trips & fuel', 'वाहन जोडणे, ट्रिप व इंधन नोंदवणे', 11);

-- Give it to general_manager by default (matches existing manage_campuses/manage_departments defaults)
INSERT INTO role_permissions (role_key, permission_key) VALUES
('general_manager', 'manage_vehicles');
