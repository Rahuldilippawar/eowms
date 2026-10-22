<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: requests.php');
    exit;
}

$entryType = $_POST['entry_type'] ?? '';
$entryId = (int)($_POST['entry_id'] ?? 0);
$requestId = (int)($_POST['request_id'] ?? 0);
$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
$action = $_POST['action'] ?? 'save';

if (!in_array($entryType, ['trip', 'fuel', 'maintenance'], true)) {
    header('Location: requests.php');
    exit;
}

if ($action === 'save') {
    if ($entryType === 'trip') {
        $start = (float)($_POST['start_odometer'] ?? 0);
        $end = (float)($_POST['end_odometer'] ?? 0);
        $distance = max(0, $end - $start);
        $update = $pdo->prepare("UPDATE vehicle_trips SET trip_date=?, from_location=?, to_location=?, start_odometer=?, end_odometer=?, distance_km=? WHERE id=?");
        $update->execute([$_POST['trip_date'], trim($_POST['from_location']), trim($_POST['to_location']), $start, $end, $distance, $entryId]);
    } elseif ($entryType === 'fuel') {
        $update = $pdo->prepare("UPDATE vehicle_fuel_log SET fill_date=?, odometer_reading=?, liters=?, cost=? WHERE id=?");
        $update->execute([$_POST['fill_date'], (float)$_POST['odometer_reading'], (float)$_POST['liters'], ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null, $entryId]);
    } else {
        $update = $pdo->prepare("UPDATE vehicle_maintenance SET maintenance_date=?, type=?, cost=?, description=? WHERE id=?");
        $update->execute([$_POST['maintenance_date'], trim($_POST['type']), ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null, trim($_POST['description']), $entryId]);
    }

    $pdo->prepare("UPDATE vehicle_correction_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$user['id'], $requestId]);
    logActivity('वाहन नोंद सुधारणा मंजूर केली', 'vehicle', $vehicleId, "$entryType #$entryId");
    $_SESSION['flash'] = 'नोंद दुरुस्त केली गेली आणि विनंती मंजूर झाली.';
} else {
    // reject
    $pdo->prepare("UPDATE vehicle_correction_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$user['id'], $requestId]);
    $_SESSION['flash'] = 'विनंती नाकारली गेली.';
}

header('Location: requests.php');
exit;
