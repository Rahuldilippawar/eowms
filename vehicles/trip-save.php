<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND organization_id = ?");
$stmt->execute([$vehicleId, $orgId]);
$vehicle = $stmt->fetch();
if (!$vehicle) { header('Location: index.php'); exit; }

// Access check — same rule as the dashboard
$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$canLog = hasPermission('manage_vehicles') || $userDeptCode === 'TRP' || (int)$vehicle['driver_id'] === (int)$user['id'];
if (!$canLog) {
    http_response_code(403);
    die('तुम्हाला ट्रिप नोंदवण्याची परवानगी नाही.');
}

$tripDate = $_POST['trip_date'] ?? date('Y-m-d');
$from = trim($_POST['from_location'] ?? '');
$to = trim($_POST['to_location'] ?? '');
$startOdo = (float)($_POST['start_odometer'] ?? 0);
$endOdo = (float)($_POST['end_odometer'] ?? 0);
$purpose = trim($_POST['purpose'] ?? '') ?: null;

if (!$from || !$to || $endOdo <= $startOdo) {
    $_SESSION['form_error'] = 'कृपया योग्य माहिती भरा — शेवटचे मीटर रीडिंग सुरुवातीपेक्षा जास्त असावे.';
    header('Location: dashboard.php?id=' . $vehicleId);
    exit;
}

$distance = $endOdo - $startOdo;

$insert = $pdo->prepare("
    INSERT INTO vehicle_trips (vehicle_id, trip_date, from_location, to_location, start_odometer, end_odometer, distance_km, purpose, driver_id, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$insert->execute([$vehicleId, $tripDate, $from, $to, $startOdo, $endOdo, $distance, $purpose, $vehicle['driver_id'], $user['id']]);

// Keep the vehicle's current odometer up to date
if ($endOdo > $vehicle['current_odometer']) {
    $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$endOdo, $vehicleId]);
}

logActivity('वाहन ट्रिप नोंदवली', 'vehicle', $vehicleId, "$from → $to ($distance km)");
$_SESSION['flash'] = 'ट्रिप यशस्वीरित्या नोंदवली गेली.';
header('Location: dashboard.php?id=' . $vehicleId);
exit;
