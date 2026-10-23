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

// विनंती — याच संस्थेच्या वाहनाची व अजून प्रलंबित असलेली; नोंदीचा प्रकार/id विनंतीतूनच घेतो
$reqStmt = $pdo->prepare("SELECT r.* FROM vehicle_correction_requests r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ? AND v.organization_id = ? AND r.status = 'pending'");
$reqStmt->execute([$requestId, $orgId]);
$req = $reqStmt->fetch();

$tableMap = ['trip' => 'vehicle_trips', 'fuel' => 'vehicle_fuel_log', 'maintenance' => 'vehicle_maintenance'];
if (!$req || !isset($tableMap[$req['entry_type']])) {
    $_SESSION['flash'] = 'विनंती सापडली नाही (किंवा आधीच निकाली निघाली).';
    header('Location: requests.php');
    exit;
}
$entryType = $req['entry_type'];
$entryId = (int)$req['entry_id'];
$vehicleId = (int)$req['vehicle_id'];

$pdo->prepare("DELETE FROM {$tableMap[$entryType]} WHERE id = ? AND vehicle_id = ?")->execute([$entryId, $vehicleId]);
if ($entryType !== 'maintenance') {
    vehicleRecomputeOdometer($pdo, $vehicleId);
}
$pdo->prepare("UPDATE vehicle_correction_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$user['id'], $requestId]);

logActivity('वाहन नोंद विनंतीप्रमाणे डिलीट केली', 'vehicle', $vehicleId, "$entryType #$entryId");
$_SESSION['flash'] = 'नोंद डिलीट केली गेली आणि विनंती मंजूर झाली.';
header('Location: requests.php');
exit;
