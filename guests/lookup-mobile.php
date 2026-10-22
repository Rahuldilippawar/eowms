<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$orgId = activeOrgId();
header('Content-Type: application/json; charset=utf-8');

$mobile = trim($_GET['mobile'] ?? '');
if (strlen($mobile) < 10) { echo json_encode(['found' => false]); exit; }

// आधी आजची Pre-Registration आहे का बघतो (असेल तर तेच सगळ्यात Priority)
$preRegStmt = $pdo->prepare("
    SELECT id, name, guest_organization, purpose, meeting_with
    FROM guest_pre_registrations
    WHERE organization_id = ? AND mobile = ? AND status = 'pending' AND expected_date = CURDATE()
    ORDER BY created_at DESC LIMIT 1
");
$preRegStmt->execute([$orgId, $mobile]);
$preReg = $preRegStmt->fetch();

$countStmt = $pdo->prepare("SELECT COUNT(*) c FROM guests WHERE organization_id = ? AND mobile = ?");
$countStmt->execute([$orgId, $mobile]);
$visitCount = (int)$countStmt->fetch()['c'];

if ($preReg) {
    echo json_encode([
        'found' => true,
        'name' => $preReg['name'],
        'guest_organization' => $preReg['guest_organization'],
        'purpose' => $preReg['purpose'],
        'meeting_with' => $preReg['meeting_with'],
        'visit_count' => $visitCount,
        'is_pre_registered' => true,
        'pre_registration_id' => $preReg['id'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($visitCount === 0) { echo json_encode(['found' => false]); exit; }

$latestStmt = $pdo->prepare("
    SELECT name, guest_organization, purpose, meeting_with
    FROM guests WHERE organization_id = ? AND mobile = ?
    ORDER BY check_in_time DESC LIMIT 1
");
$latestStmt->execute([$orgId, $mobile]);
$row = $latestStmt->fetch();

echo json_encode([
    'found' => true,
    'name' => $row['name'],
    'guest_organization' => $row['guest_organization'],
    'purpose' => $row['purpose'],
    'meeting_with' => $row['meeting_with'],
    'visit_count' => $visitCount,
    'is_pre_registered' => false,
], JSON_UNESCAPED_UNICODE);
exit;
