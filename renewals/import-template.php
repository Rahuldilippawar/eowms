<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="renewal-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "department_name,item_type,regulatory_body,item_name,reference_no,issued_date,expiry_date,renewal_cost,vendor_name,vendor_contact\n";
echo "IT विभाग,Software License,,Windows 11 Pro License,LIC-2026-001,2026-01-01,2027-01-01,5000,Microsoft Partner,9876543210\n";
echo "वाहतूक विभाग,Vehicle Insurance,,बस क्र. 07 विमा,POL-8899,2026-02-01,2027-02-01,15000,ABC Insurance,9123456789\n";
echo "प्राचार्य कार्यालय,College Affiliation,AICTE,AICTE Approval 2026-27,AICTE-2026-XYZ,2026-06-01,2027-06-01,25000,,\n";
exit;
