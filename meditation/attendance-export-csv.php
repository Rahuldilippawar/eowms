<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$eventId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
if (!hasRole('super_admin') && $userDeptCode !== 'MED') {
    http_response_code(403);
    die('तुम्हाला सत्संग हजेरी पाहण्याची परवानगी नाही.');
}

$stmt = $pdo->prepare("SELECT * FROM satsang_events WHERE id = ? AND organization_id = ?");
$stmt->execute([$eventId, $orgId]);
$event = $stmt->fetch();
if (!$event) { die('सत्संग कार्यक्रम सापडला नाही.'); }

$stmt = $pdo->prepare("
    SELECT u.name, u.designation, d.name_local AS dept_name,
      COALESCE(sa.status, 'not_marked') AS status, sa.remark
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN satsang_attendance sa ON sa.user_id = u.id AND sa.satsang_event_id = ?
    WHERE u.organization_id = ? AND u.is_active = 1
    ORDER BY d.name_local, u.name
");
$stmt->execute([$eventId, $orgId]);
$rows = $stmt->fetchAll();

$statusLabel = ['present' => 'उपस्थित', 'absent' => 'अनुपस्थित', 'not_marked' => 'नोंद नाही'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="satsang-attendance-' . $event['event_date'] . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['नाव', 'विभाग', 'पदनाम', 'हजेरी', 'टिप्पणी']);
foreach ($rows as $r) {
    fputcsv($out, [$r['name'], $r['dept_name'] ?? '', $r['designation'] ?? '', $statusLabel[$r['status']], $r['remark'] ?? '']);
}
fclose($out);
exit;
