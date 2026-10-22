<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="daily-work-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "date,department,title,description,requested_by,priority,staff_names,estimated_cost,actual_cost\n";
echo "2026-03-15,MTN,पाईप दुरुस्ती,मुख्य इमारतीतील पाईप गळती दुरुस्त केली,प्राचार्य कार्यालय,normal,सुरेश पाटील,,500\n";
echo "2026-03-16,HSK,संपूर्ण साफसफाई,वर्गखोल्या व वऱ्हांडा साफ केला,,low,\"मीरा जाधव, रमेश यादव\",,\n";
exit;
