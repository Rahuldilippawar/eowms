<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emergency-helpers.php';
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
if (!isset(EMERGENCY_TYPE_LABELS[$alertType])) { $alertType = 'other'; }

// ऐच्छिक Browser Geolocation — वैध अक्षांश/रेखांश असेल तरच
$lat = is_numeric($_POST['latitude'] ?? null) ? (float)$_POST['latitude'] : null;
$lng = is_numeric($_POST['longitude'] ?? null) ? (float)$_POST['longitude'] : null;
if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)) { $lat = $lng = null; }

// Campus — निवडलेला (याच संस्थेचा असेल तर), नाहीतर स्वतःचा
$campusId = (int)($_POST['campus_id'] ?? 0) ?: (int)($user['campus_id'] ?? 0);
if ($campusId) {
    $cSt = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
    $cSt->execute([$campusId, $orgId]);
    if (!$cSt->fetch()) { $campusId = 0; }
}

$hasNewColumns = true;
try { $pdo->query("SELECT latitude FROM emergency_alerts LIMIT 1"); } catch (PDOException $e) { $hasNewColumns = false; }

// Rate limit — एका व्यक्तीकडून 2 मिनिटांत एकच सक्रिय सूचना (चुकून दोनदा दाबलं तरी)
$recent = $pdo->prepare("SELECT id FROM emergency_alerts WHERE triggered_by = ? AND status = 'active' AND created_at > NOW() - INTERVAL " . (int)EMERGENCY_RATE_LIMIT_SECONDS . " SECOND ORDER BY id DESC LIMIT 1");
$recent->execute([$user['id']]);
if ($recentId = $recent->fetchColumn()) {
    $_SESSION['flash'] = 'तुमची आणीबाणी सूचना आधीच पाठवली गेली आहे — ती इथे दिसते. (2 मिनिटांत पुन्हा पाठवता येत नाही.)';
    header('Location: view.php?id=' . (int)$recentId);
    exit;
}

if ($hasNewColumns) {
    $pdo->prepare("INSERT INTO emergency_alerts (organization_id, triggered_by, campus_id, alert_type, location, message, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$orgId, $user['id'], $campusId ?: null, $alertType, $location, $message, $lat, $lng]);
} else {
    $pdo->prepare("INSERT INTO emergency_alerts (organization_id, triggered_by, alert_type, location, message) VALUES (?, ?, ?, ?, ?)")
        ->execute([$orgId, $user['id'], $alertType, $location, $message]);
}
$alertId = (int)$pdo->lastInsertId();
$typeLabel = EMERGENCY_TYPE_LABELS[$alertType];

// Permission असलेले + Management + विभाग-प्रमुख + सुरक्षा विभाग — त्वरित (Push Queue ची वाट न पाहता)
$recipients = emergencyRecipientIds($pdo, $orgId, $user['id'], $user['department_id'] ?? null);
emergencyDispatch($pdo, ['id' => $alertId, 'organization_id' => $orgId, 'alert_type' => $alertType, 'location' => $location,
    'message' => $message, 'latitude' => $lat, 'longitude' => $lng], $user['name'], $recipients);

logActivity('🆘 आणीबाणी सूचना पाठवली', 'emergency_alert', $alertId, $typeLabel . ' — ' . $location);
$_SESSION['flash'] = '🆘 आणीबाणी सूचना पाठवली गेली — ' . count($recipients) . ' जणांना त्वरित कळवलं आहे.';
header('Location: view.php?id=' . $alertId);
exit;
