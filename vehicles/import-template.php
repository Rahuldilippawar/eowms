<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vehicle-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "department_name,vehicle_number,vehicle_type,model,date_of_registration,chassis_no,engine_no,vehicle_class,passenger_capacity,fuel_type,permit_validity,puc_validity,insurance_validity,fitness_validity,road_tax_validity,green_tax_validity,mv_tax_validity\n";
echo "वाहतूक विभाग,MH-04-AB-1234,बस,Tata Starbus,2020-06-15,CHS12345678,ENG98765432,PSV,52,diesel,2027-06-15,2026-12-01,2027-06-15,2027-06-15,2030-06-15,2027-06-15,2027-06-15\n";
echo "वाहतूक विभाग,MH-04-CD-5678,कार,Maruti Ertiga,2021-03-10,CHS87654321,ENG12345678,LMV,7,petrol,,2026-09-01,2026-11-01,,,,\n";
exit;
