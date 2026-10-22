<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="staff-import-template.csv"');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders Marathi correctly
echo "name,department,designation,username,password,mobile,salary,joining_date,date_of_birth\n";
echo "सुरेश पाटील,Maintenance,Technician,suresh.patil,,9876543210,15000,2024-06-01,1990-05-14\n";
echo "मीरा जाधव,Housekeeping,Supervisor,meera.jadhav,,9123456780,18000,2023-01-15,1988-11-02\n";
exit;
