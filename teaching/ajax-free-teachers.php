<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$orgId = activeOrgId();
header('Content-Type: application/json; charset=utf-8');

$periodNo = (int)($_GET['period_no'] ?? 0);
$dayOfWeek = (int)($_GET['day_of_week'] ?? 0);
$excludeId = (int)($_GET['exclude'] ?? 0);
// शैक्षणिक वर्ष जूनपासून सुरू — जानेवारी-मे मध्ये मागच्या वर्षाचंच Academic Year
$fyStartYear = (int)date('Y') - ((int)date('n') < 6 ? 1 : 0);
$fy = $fyStartYear . '-' . substr((string)($fyStartYear + 1), 2);

$allStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 AND id != ? ORDER BY name");
$allStmt->execute([$orgId, $excludeId]);
$all = $allStmt->fetchAll();

$busyStmt = $pdo->prepare("SELECT DISTINCT teacher_id FROM teacher_timetable WHERE organization_id = ? AND day_of_week = ? AND period_no = ? AND academic_year = ?");
$busyStmt->execute([$orgId, $dayOfWeek, $periodNo, $fy]);
$busyIds = $busyStmt->fetchAll(PDO::FETCH_COLUMN);

$free = []; $busy = [];
foreach ($all as $t) {
    if (in_array($t['id'], $busyIds)) { $busy[] = $t; } else { $free[] = $t; }
}

echo json_encode(['free' => $free, 'busy' => $busy], JSON_UNESCAPED_UNICODE);
exit;
