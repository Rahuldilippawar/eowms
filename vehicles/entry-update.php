<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: requests.php');
    exit;
}

$requestId = (int)($_POST['request_id'] ?? 0);
$action = $_POST['action'] ?? 'save';

// विनंती — याच संस्थेच्या वाहनाची व अजून प्रलंबित असलेली; नोंदीचा प्रकार/id विनंतीतूनच घेतो
$reqStmt = $pdo->prepare("SELECT r.* FROM vehicle_correction_requests r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ? AND v.organization_id = ? AND r.status = 'pending'");
$reqStmt->execute([$requestId, $orgId]);
$req = $reqStmt->fetch();
if (!$req) {
    $_SESSION['flash'] = 'विनंती सापडली नाही (किंवा आधीच निकाली निघाली).';
    header('Location: requests.php');
    exit;
}
$entryType = $req['entry_type'];
$entryId = (int)$req['entry_id'];
$vehicleId = (int)$req['vehicle_id'];

if ($action === 'save') {
    $validDate = fn($d) => (bool)DateTime::createFromFormat('!Y-m-d', (string)$d);
    $isNum = fn($v) => $v === '' || $v === null || is_numeric($v);
    if ($entryType === 'trip') {
        $start = (float)($_POST['start_odometer'] ?? 0);
        $end = (float)($_POST['end_odometer'] ?? 0);
        if (!$validDate($_POST['trip_date'] ?? '') || $end < $start) {
            $_SESSION['flash'] = 'तारीख अवैध किंवा शेवटचं मीटर सुरुवातीपेक्षा कमी आहे — बदल झाला नाही.';
            header('Location: requests.php');
            exit;
        }
        $distance = $end - $start;
        $update = $pdo->prepare("UPDATE vehicle_trips SET trip_date=?, from_location=?, to_location=?, start_odometer=?, end_odometer=?, distance_km=? WHERE id=? AND vehicle_id=?");
        $update->execute([$_POST['trip_date'], trim($_POST['from_location'] ?? ''), trim($_POST['to_location'] ?? ''), $start, $end, $distance, $entryId, $vehicleId]);
    } elseif ($entryType === 'fuel') {
        if (!$validDate($_POST['fill_date'] ?? '') || !$isNum($_POST['cost'] ?? '')) {
            $_SESSION['flash'] = 'तारीख किंवा रक्कम अवैध — बदल झाला नाही.';
            header('Location: requests.php');
            exit;
        }
        $update = $pdo->prepare("UPDATE vehicle_fuel_log SET fill_date=?, odometer_reading=?, liters=?, cost=? WHERE id=? AND vehicle_id=?");
        $update->execute([$_POST['fill_date'], (float)($_POST['odometer_reading'] ?? 0), (float)($_POST['liters'] ?? 0), ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null, $entryId, $vehicleId]);
    } else {
        if (!$validDate($_POST['maintenance_date'] ?? '') || !$isNum($_POST['cost'] ?? '')) {
            $_SESSION['flash'] = 'तारीख किंवा रक्कम अवैध — बदल झाला नाही.';
            header('Location: requests.php');
            exit;
        }
        $update = $pdo->prepare("UPDATE vehicle_maintenance SET maintenance_date=?, type=?, cost=?, description=? WHERE id=? AND vehicle_id=?");
        $update->execute([$_POST['maintenance_date'], trim($_POST['type'] ?? ''), ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null, trim($_POST['description'] ?? ''), $entryId, $vehicleId]);
    }

    // मीटर-वाचन बदललं असेल — वाहनाचं सध्याचं मीटर पुन्हा मोजतो
    if ($entryType !== 'maintenance') {
        vehicleRecomputeOdometer($pdo, $vehicleId);
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
