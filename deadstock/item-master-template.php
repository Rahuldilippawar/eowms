<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_dead_stock_master', '../');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="item-master-template.csv"');

echo "\xEF\xBB\xBF";
echo "item_name,category,default_unit\n";
echo "लोखंडी कपाट,फर्निचर,नग\n";
echo "संगणक (Desktop),इलेक्ट्रॉनिक्स,सेट\n";
echo "प्रयोगशाळा साहित्य,शालेय साहित्य,सेट\n";
exit;
