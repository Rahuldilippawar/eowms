<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="student-import-template.csv"');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders Marathi correctly
echo "name,school_name,class_grade,district,gender,admission_date,date_of_birth\n";
echo "सूरज कांबळे,जि.प. शाळा क्र.2,७ वी,ठाणे,मुलगा,2024-06-15,2013-03-20\n";
echo "प्रिया शिंदे,जि.प. कन्या शाळा,६ वी,पालघर,मुलगी,2024-06-15,2014-08-05\n";
exit;
