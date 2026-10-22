-- ============================================================
-- Migration: मानक 4 रजा-प्रकार Seed करणे — CL, CO, Medical, Vacation
-- सगळ्या Organizations साठी आपोआप (आधीच असेल तर वगळलं जाईल)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'CL (Casual Leave)', 'प्रासंगिक रजा (CL)', 12, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'CL%');

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'CO (Compensatory Off)', 'भरपाई सुट्टी (CO)', 6, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'CO%');

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'Medical Leave', 'आजारपणाची रजा (Medical)', 12, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'Medical%');

INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid)
SELECT o.id, 'Vacation Leave', 'नियमित सुट्टी (Vacation)', 15, 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM leave_types lt WHERE lt.organization_id = o.id AND lt.name LIKE 'Vacation%');
