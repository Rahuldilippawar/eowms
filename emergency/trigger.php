<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

$alertType = $_POST['alert_type'] ?? 'other';
$location = trim($_POST['location'] ?? '');
$message = trim($_POST['message'] ?? '');

$allowedTypes = ['fire', 'medical', 'security', 'other'];
if (!in_array($alertType, $allowedTypes, true)) { $alertType = 'other'; }

$pdo->prepare("INSERT INTO emergency_alerts (organization_id, triggered_by, alert_type, location, message) VALUES (?, ?, ?, ?, ?)")
    ->execute([$orgId, $user['id'], $alertType, $location, $message]);
$alertId = $pdo->lastInsertId();

$typeLabels = ['fire' => '🔥 आग', 'medical' => '🚑 वैद्यकीय', 'security' => '🛡️ सुरक्षा', 'other' => '🆘 इतर'];
$typeLabel = $typeLabels[$alertType];

// Management + Super Admin ला त्वरित Notification
try {
    $mgmtStmt = $pdo->prepare("
        SELECT u.id FROM users u JOIN role_definitions rd ON rd.role_key = u.role
        WHERE u.organization_id = ? AND u.is_active = 1 AND (rd.category = 'management' OR u.role = 'super_admin')
    ");
    $mgmtStmt->execute([$orgId]);
    foreach ($mgmtStmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        if ((int)$mid === (int)$user['id']) continue;
        notifyUser($mid, $orgId, "🆘 आणीबाणी सूचना — $typeLabel", "{$user['name']} — " . ($location ?: 'स्थान नमूद नाही') . ($message ? " — $message" : ''), 'emergency/index.php');
    }
} catch (PDOException $e) {}

logActivity('🆘 आणीबाणी सूचना पाठवली', 'emergency_alert', $alertId, $typeLabel . ' — ' . $location);
$_SESSION['flash'] = '🆘 आणीबाणी सूचना पाठवली गेली — Management/Security ला त्वरित कळवलं आहे.';
header('Location: ../index.php');
exit;
