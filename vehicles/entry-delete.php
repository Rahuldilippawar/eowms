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

$tableMap = ['trip' => 'vehicle_trips', 'fuel' => 'vehicle_fuel_log', 'maintenance' => 'vehicle_maintenance'];
if (!isset($tableMap[$entryType])) {
    header('Location: requests.php');
    exit;
}

$pdo->prepare("DELETE FROM {$tableMap[$entryType]} WHERE id = ?")->execute([$entryId]);
// इंधन/देखभाल नोंद काढली — तिचा खर्च Budget मधून परत
$budgetSourceMap = ['fuel' => 'vehicle_fuel_log', 'maintenance' => 'vehicle_maintenance_log'];
if (isset($budgetSourceMap[$entryType])) {
    reverseBudgetTransaction($pdo, $budgetSourceMap[$entryType], $entryId);
}
$pdo->prepare("UPDATE vehicle_correction_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$user['id'], $requestId]);

logActivity('वाहन नोंद विनंतीप्रमाणे डिलीट केली', 'vehicle', $vehicleId, "$entryType #$entryId");
$_SESSION['flash'] = 'नोंद डिलीट केली गेली आणि विनंती मंजूर झाली.';
header('Location: requests.php');
exit;
