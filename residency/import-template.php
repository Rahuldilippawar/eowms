<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="residency-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "building_name,room_name,occupant_type,name,staff_email,relation,moved_in_date\n";
echo "स्टाफ निवास A,खोली 101,staff,राहुल पवार,rahul@example.com,,2026-01-15\n";
echo "स्टाफ निवास A,खोली 101,family,सीमा पवार,,पत्नी,2026-01-15\n";
echo "स्टाफ निवास A,खोली 101,family,अर्जुन पवार,,मुलगा,2026-01-15\n";
echo "स्टाफ निवास A,खोली 102,staff,अमोल जाधव,amol@example.com,,2026-02-01\n";
echo "स्टाफ निवास A,खोली 102,staff,विकास शिंदे,vikas@example.com,,2026-02-01\n";
exit;
