<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode(['results' => []]); exit; }

$orgId = activeOrgId();
$like = '%' . $q . '%';
$results = [];

// कर्मचारी (Staff)
try {
    $stmt = $pdo->prepare("SELECT id, name, employee_code, designation FROM users WHERE organization_id = ? AND is_active = 1 AND (name LIKE ? OR employee_code LIKE ?) LIMIT 5");
    $stmt->execute([$orgId, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['type' => 'कर्मचारी', 'icon' => '👤', 'title' => $r['name'], 'subtitle' => trim(($r['employee_code'] ?? '') . ' · ' . ($r['designation'] ?? '')), 'url' => 'staff/view.php?id=' . $r['id']];
    }
} catch (PDOException $e) {}

// पत्रे (Letters)
try {
    $stmt = $pdo->prepare("SELECT id, subject, file_number FROM letters WHERE organization_id = ? AND (subject LIKE ? OR file_number LIKE ?) ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$orgId, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['type' => 'पत्र', 'icon' => '📄', 'title' => $r['subject'], 'subtitle' => $r['file_number'], 'url' => 'letters/view.php?id=' . $r['id']];
    }
} catch (PDOException $e) {}

// वाहनं (Vehicles)
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, vehicle_type FROM vehicles WHERE organization_id = ? AND vehicle_number LIKE ? LIMIT 5");
    $stmt->execute([$orgId, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['type' => 'वाहन', 'icon' => '🚐', 'title' => $r['vehicle_number'], 'subtitle' => $r['vehicle_type'], 'url' => 'vehicles/dashboard.php?id=' . $r['id']];
    }
} catch (PDOException $e) {}

// IT Assets
try {
    $stmt = $pdo->prepare("SELECT id, asset_tag, asset_type, serial_no FROM it_assets WHERE organization_id = ? AND (asset_tag LIKE ? OR serial_no LIKE ?) LIMIT 5");
    $stmt->execute([$orgId, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['type' => 'IT Asset', 'icon' => '💻', 'title' => $r['asset_tag'], 'subtitle' => $r['asset_type'], 'url' => 'it/asset-view.php?id=' . $r['id']];
    }
} catch (PDOException $e) {}

// विभाग (Departments)
try {
    $stmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND (name_local LIKE ? OR name LIKE ?) LIMIT 3");
    $stmt->execute([$orgId, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = ['type' => 'विभाग', 'icon' => '🏢', 'title' => $r['name_local'] ?: $r['name'], 'subtitle' => 'विभाग Dashboard', 'url' => 'departments/dashboard.php?id=' . $r['id']];
    }
} catch (PDOException $e) {}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
exit;
