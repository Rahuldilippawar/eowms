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
    die('तुम्हाला देखभाल नोंदवण्याची परवानगी नाही.');
}

$maintenanceDate = $_POST['maintenance_date'] ?? date('Y-m-d');
$type = trim($_POST['type'] ?? '');
$description = trim($_POST['description'] ?? '') ?: null;
$cost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null;
$nextDue = ($_POST['next_due_date'] ?? '') ?: null;

if (!$type) {
    $_SESSION['form_error'] = 'कृपया देखभालीचा प्रकार भरा.';
    header('Location: dashboard.php?id=' . $vehicleId);
    exit;
}

$insert = $pdo->prepare("
    INSERT INTO vehicle_maintenance (vehicle_id, maintenance_date, type, description, cost, odometer_reading, next_due_date, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$insert->execute([$vehicleId, $maintenanceDate, $type, $description, $cost, $vehicle['current_odometer'], $nextDue, $user['id']]);

$vehMaintId = (int)$pdo->lastInsertId();
recordBudgetTransaction($pdo, $orgId, $vehicle['department_id'], $cost, 'इतर', 'vehicle_maintenance_log', $vehMaintId, $vehicle['vehicle_number'] . ' — ' . $type, $user['id'], $maintenanceDate);

logActivity('वाहन देखभाल नोंदवली', 'vehicle', $vehicleId, $type);
$_SESSION['flash'] = 'देखभाल नोंद जतन झाली.';
header('Location: dashboard.php?id=' . $vehicleId);
exit;
