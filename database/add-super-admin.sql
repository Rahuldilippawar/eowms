-- ============================================================
-- Add Super Admin (safe to run even if organizations/campuses
-- were deleted — it creates them first if none exist)
-- Run this in phpMyAdmin → eowms database → SQL tab
-- ============================================================

-- Step 1: Ensure at least one organization exists
INSERT INTO organizations (name, slug, type, contact_email, is_active)
SELECT 'Shivaji Education Trust', 'shivaji-education-trust', 'trust', 'info@shivajitrust.edu.in', 1
WHERE NOT EXISTS (SELECT 1 FROM organizations);

SET @org_id = (SELECT id FROM organizations ORDER BY id LIMIT 1);

-- Step 2: Ensure at least one campus exists
INSERT INTO campuses (organization_id, name, code, city, state, is_active)
SELECT @org_id, 'Main Campus', 'MAIN', 'Kolhapur', 'Maharashtra', 1
WHERE NOT EXISTS (SELECT 1 FROM campuses WHERE organization_id = @org_id);

SET @campus_id = (SELECT id FROM campuses WHERE organization_id = @org_id ORDER BY id LIMIT 1);

-- Step 3: Add the Super Admin user
-- Login will be: admin@shivajitrust.edu.in / password
INSERT INTO users (organization_id, campus_id, department_id, employee_code, name, email, designation, password, role, is_active)
SELECT @org_id, @campus_id, NULL, 'ADM-001', 'Super Admin', 'admin@shivajitrust.edu.in', 'Super Admin',
       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@shivajitrust.edu.in');
