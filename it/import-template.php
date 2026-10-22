<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="it-lab-computer-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "lab_name,lab_location,capacity,workstation_no,asset_type,brand,model,serial_no,purchase_date,purchase_cost,warranty_expiry,os_license,license_expiry\n";
echo "Computer Lab 1,तळमजला,40,PC-01,cpu,Dell,OptiPlex 3090,SN-CL1-C01,2026-01-15,35000,2029-01-15,Windows 11 Pro,2027-01-15\n";
echo "Computer Lab 1,तळमजला,40,PC-01,monitor,Dell,E2222H,SN-CL1-M01,2026-01-15,8000,2029-01-15,,\n";
echo "Computer Lab 1,तळमजला,40,PC-01,keyboard,Dell,KB216,SN-CL1-K01,2026-01-15,600,,,\n";
echo "Computer Lab 1,तळमजला,40,PC-01,mouse,Dell,MS116,SN-CL1-MS01,2026-01-15,300,,,\n";
echo "Computer Lab 1,तळमजला,40,PC-02,cpu,Dell,OptiPlex 3090,SN-CL1-C02,2026-01-15,35000,2029-01-15,Windows 11 Pro,2027-01-15\n";
echo "Computer Lab 1,तळमजला,40,,smartboard,Promethean,ActivPanel,SN-CL1-SB1,2026-02-01,120000,2031-02-01,,\n";
echo "Computer Lab 2,पहिला मजला,30,PC-01,cpu,HP,ProDesk 400,SN-CL2-C01,2026-03-10,32000,2029-03-10,Windows 11 Pro,2027-03-10\n";
exit;
