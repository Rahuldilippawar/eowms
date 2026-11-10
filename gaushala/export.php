<?php
// 📊 गौशाळा — Excel: ?type=cattle (जनावरं) | milk (दूध, कालावधी) | health (आरोग्य नोंदी)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_cattle', '../');
$orgId = activeOrgId();
$type = in_array($_GET['type'] ?? '', ['cattle', 'milk', 'health'], true) ? $_GET['type'] : 'cattle';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="gaushala-' . $type . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
if ($type === 'milk') {
    $q = $pdo->prepare("SELECT m.record_date, c.tag_number, c.name, m.session, m.quantity_liters FROM gaushala_milk_records m JOIN gaushala_cattle c ON c.id = m.cattle_id
        WHERE c.organization_id = ? AND m.record_date BETWEEN ? AND ? ORDER BY m.record_date, c.tag_number, m.session");
    $q->execute([$orgId, $from, $to]);
    fputcsv($out, ['दिनांक', 'Batch / Tag', 'नाव', 'वेळ', 'लिटर']);
    foreach ($q->fetchAll() as $r) fputcsv($out, [$r['record_date'], $r['tag_number'], $r['name'], $r['session'] === 'Morning' ? 'सकाळ' : 'संध्याकाळ', $r['quantity_liters']]);
} elseif ($type === 'health') {
    $q = $pdo->prepare("SELECT h.*, c.tag_number, c.name FROM gaushala_health_records h JOIN gaushala_cattle c ON c.id = h.cattle_id WHERE c.organization_id = ? AND h.record_date BETWEEN ? AND ? ORDER BY h.record_date DESC");
    $q->execute([$orgId, $from, $to]);
    fputcsv($out, ['दिनांक', 'Batch / Tag', 'नाव', 'प्रकार', 'तपशील', 'डॉक्टर', 'पुढची तारीख', 'खर्च (₹)']);
    foreach ($q->fetchAll() as $r) fputcsv($out, [$r['record_date'], $r['tag_number'], $r['name'], $r['record_type'], $r['description'], $r['vet_name'], $r['next_due_date'], $r['cost']]);
} else {
    $q = $pdo->prepare("SELECT c.*, cp.name campus_name, m.name mother_name, u.name caretaker,
            (SELECT SUM(quantity_liters) FROM gaushala_milk_records WHERE cattle_id = c.id AND record_date >= CURDATE() - INTERVAL 29 DAY) milk30
        FROM gaushala_cattle c LEFT JOIN campuses cp ON cp.id = c.campus_id LEFT JOIN gaushala_cattle m ON m.id = c.mother_cattle_id LEFT JOIN users u ON u.id = c.caretaker_id
        WHERE c.organization_id = ? ORDER BY c.status = 'active' DESC, c.tag_number");
    $q->execute([$orgId]);
    fputcsv($out, ['Batch / Tag', 'नाव', 'प्रकार', 'लिंग', 'जात', 'रंग', 'जन्म', 'कसं आलं', 'आई', 'सांभाळणारे', 'Campus', 'स्थिती', '30 दिवस दूध (L)']);
    foreach ($q->fetchAll() as $r) fputcsv($out, [$r['tag_number'], $r['name'], $r['animal_category'], $r['gender'], $r['breed'], $r['color'], $r['date_of_birth'], $r['acquisition_type'], $r['mother_name'], $r['caretaker'], $r['campus_name'], $r['status'], $r['milk30']]);
}
fclose($out);
