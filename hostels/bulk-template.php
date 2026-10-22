<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_hostels', '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hostel-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "name,campus,capacity\n";
echo "Boys Hostel A,Main Campus,60\n";
echo "Girls Hostel B,Main Campus,40\n";
exit;
