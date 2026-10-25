<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/budget-helpers.php';
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
        $fuelCost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null;
        $update = $pdo->prepare("UPDATE vehicle_fuel_log SET fill_date=?, odometer_reading=?, liters=?, cost=? WHERE id=?");
        $update->execute([$_POST['fill_date'], (float)$_POST['odometer_reading'], (float)$_POST['liters'], $fuelCost, $entryId]);
        // Budget मधला खर्च-Row सुद्धा दुरुस्त करतो
        $vStmt = $pdo->prepare("SELECT v.* FROM vehicle_fuel_log f JOIN vehicles v ON v.id = f.vehicle_id WHERE f.id = ? AND v.organization_id = ?");
        $vStmt->execute([$entryId, activeOrgId()]);
        if ($veh = $vStmt->fetch()) {
            syncBudgetTransaction($pdo, activeOrgId(), $veh['department_id'], $fuelCost, 'इंधन', 'vehicle_fuel_log', $entryId, $veh['vehicle_number'] . ' — ' . (float)$_POST['liters'] . ' लिटर', $user['id'], $_POST['fill_date']);
        }
    } else {
        $mCost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null;
        $update = $pdo->prepare("UPDATE vehicle_maintenance SET maintenance_date=?, type=?, cost=?, description=? WHERE id=?");
        $update->execute([$_POST['maintenance_date'], trim($_POST['type']), $mCost, trim($_POST['description']), $entryId]);
        $vStmt = $pdo->prepare("SELECT v.* FROM vehicle_maintenance m JOIN vehicles v ON v.id = m.vehicle_id WHERE m.id = ? AND v.organization_id = ?");
        $vStmt->execute([$entryId, activeOrgId()]);
        if ($veh = $vStmt->fetch()) {
            syncBudgetTransaction($pdo, activeOrgId(), $veh['department_id'], $mCost, 'इतर', 'vehicle_maintenance_log', $entryId, $veh['vehicle_number'] . ' — ' . trim($_POST['type']), $user['id'], $_POST['maintenance_date']);
        }
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
