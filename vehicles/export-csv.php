<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$vehicleId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND organization_id = ?");
$stmt->execute([$vehicleId, $orgId]);
$vehicle = $stmt->fetch();
if (!$vehicle) { die('वाहन सापडले नाही.'); }

$canLog = hasPermission('manage_vehicles') || $userDeptCode === 'TRP' || (int)$vehicle['driver_id'] === (int)$user['id'];
if (!$canLog && !hasRole('super_admin') && !isManagementOrAbove()) {
    http_response_code(403);
    die('तुम्हाला हे वाहन पाहण्याची परवानगी नाही.');
}

$stmt = $pdo->prepare("SELECT * FROM vehicle_trips WHERE vehicle_id = ? ORDER BY trip_date DESC");
$stmt->execute([$vehicleId]);
$trips = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vehicle-' . $vehicle['vehicle_number'] . '-trips.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['दिनांक', 'कुठून', 'कुठे', 'सुरुवात मीटर', 'शेवट मीटर', 'अंतर (km)', 'उद्देश']);
foreach ($trips as $t) {
    fputcsv($out, [
        date('d-m-Y', strtotime($t['trip_date'])), $t['from_location'], $t['to_location'],
        $t['start_odometer'], $t['end_odometer'], $t['distance_km'], $t['purpose'] ?? '',
    ]);
}
fclose($out);
exit;
