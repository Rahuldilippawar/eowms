<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    $_SESSION['form_error'] = 'वाहन सापडले नाही.';
    header('Location: index.php');
    exit;
}

$confirmText = trim($_POST['confirm_number'] ?? '');
if ($confirmText !== $vehicle['vehicle_number']) {
    $_SESSION['form_error'] = 'वाहन क्रमांक जुळत नाही — डिलीट रद्द केले.';
    header('Location: index.php');
    exit;
}

// FK cascades (ON DELETE CASCADE) remove all trips, fuel logs, and
// maintenance records for this vehicle automatically.
$pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$id]);

logActivity('वाहन कायमचे डिलीट केले', 'vehicle', $id, $vehicle['vehicle_number']);
$_SESSION['flash'] = "'{$vehicle['vehicle_number']}' आणि त्याच्याशी संबंधित सर्व ट्रिप/इंधन/देखभाल नोंदी कायमच्या डिलीट झाल्या.";
header('Location: index.php');
exit;
