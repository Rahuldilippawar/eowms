<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
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

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$canLog = hasPermission('manage_vehicles') || $userDeptCode === 'TRP' || (int)$vehicle['driver_id'] === (int)$user['id'];
if (!$canLog) {
    http_response_code(403);
    die('तुम्हाला इंधन नोंदवण्याची परवानगी नाही.');
}

$fillDate = $_POST['fill_date'] ?? date('Y-m-d');
$odometer = (float)($_POST['odometer_reading'] ?? 0);
$liters = (float)($_POST['liters'] ?? 0);
$cost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null;
$station = trim($_POST['fuel_station'] ?? '') ?: null;

if ($liters <= 0) {
    $_SESSION['form_error'] = 'कृपया योग्य लिटर भरा.';
    header('Location: dashboard.php?id=' . $vehicleId);
    exit;
}

$insert = $pdo->prepare("INSERT INTO vehicle_fuel_log (vehicle_id, fill_date, odometer_reading, liters, cost, fuel_station, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
$insert->execute([$vehicleId, $fillDate, $odometer, $liters, $cost, $station, $user['id']]);

recordBudgetTransaction($pdo, $orgId, $vehicle['department_id'], $cost, 'इंधन', 'vehicle_fuel', $vehicleId, $vehicle['vehicle_number'] . ' — ' . $liters . ' लिटर', $user['id']);

if ($odometer > $vehicle['current_odometer']) {
    $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$odometer, $vehicleId]);
}

logActivity('वाहन इंधन नोंदवले', 'vehicle', $vehicleId, "$liters लिटर");
$_SESSION['flash'] = 'इंधन नोंद जतन झाली.';
header('Location: dashboard.php?id=' . $vehicleId);
exit;
