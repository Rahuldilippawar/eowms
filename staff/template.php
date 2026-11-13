<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="staff-import-template.csv"');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders Marathi correctly
echo "name,department,designation,username,password,mobile,salary,joining_date,date_of_birth,employee_code,gender,aadhar_number,bank_account_no,ifsc_code,bank_name,branch_name,permanent_address,local_address\n";
echo "सुरेश पाटील,Maintenance,Technician,suresh.patil,,9876543210,15000,2024-06-01,1990-05-14,1201,Male,123412341234,50100123456789,HDFC0002470,HDFC Bank,Shahapur,\"At. Vedvahal, Tal. Shahapur\",Shahapur\n";
echo "मीरा जाधव,Housekeeping,Supervisor,meera.jadhav,,9123456780,18000,2023-01-15,1988-11-02,1202,Female,,,,,,,\n";
exit;
