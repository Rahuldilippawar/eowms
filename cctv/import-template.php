<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cctv-dvr-camera-import-template.csv"');

echo "\xEF\xBB\xBF";
echo "dvr_name,dvr_location,channel_capacity,storage_capacity_tb,installed_date,camera_name,camera_location,camera_type\n";
echo "मुख्य इमारत DVR,Server Room,16,4,2026-01-15,Cam-01,मुख्य प्रवेशद्वार,dome\n";
echo "मुख्य इमारत DVR,Server Room,16,4,2026-01-15,Cam-02,कार्यालय,bullet\n";
echo "मुख्य इमारत DVR,Server Room,16,4,2026-01-15,Cam-03,वर्ग क्र. 5,dome\n";
echo "वसतिगृह DVR,वसतिगृह कार्यालय,8,2,2026-02-01,Cam-01,वसतिगृह प्रवेशद्वार,ptz\n";
exit;
