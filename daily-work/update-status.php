<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my-work.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? 'pending';
if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) {
    $_SESSION['flash'] = 'अवैध स्थिती — काहीही बदल केला नाही.';
    header('Location: my-work.php');
    exit;
}
$remarks = trim($_POST['remarks'] ?? '') ?: null;

$stmt = $pdo->prepare("SELECT * FROM work_records WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, activeOrgId()]);
$record = $stmt->fetch();

if (!$record) {
    header('Location: my-work.php');
    exit;
}

// Only a team member on this record, or their department's HOD, can update this record
$isHod = hasCategory('hod');
$teamCheck = $pdo->prepare("SELECT COUNT(*) c FROM work_record_team WHERE work_record_id = ? AND user_id = ?");
$teamCheck->execute([$id, $user['id']]);
$isOwner = (int)$teamCheck->fetch()['c'] > 0;
// HOD — स्वतःचा विभाग + Extra दिलेले विभाग (इतर पानांप्रमाणेच)
$hodVisibleDeptIds = $isHod ? getVisibleDepartmentIds() : [];
$isOwnDeptHod = $isHod && ($hodVisibleDeptIds === null || in_array((int)$record['department_id'], $hodVisibleDeptIds, true));

if (!$isOwner && !$isOwnDeptHod && !hasRole('super_admin')) {
    http_response_code(403);
    die(t('s498'));
}

// पूर्णत्वाची तारीख — "पूर्ण" स्थितीत पहिल्यांदा गेल्यावरच लावतो; पुन्हा Save केल्याने बदलत नाही.
// पूर्ण मधून दुसऱ्या स्थितीत गेल्यास तारीख काढून टाकतो.
if ($status === 'completed') {
    $completionDate = ($record['status'] === 'completed' && !empty($record['completion_date'])) ? $record['completion_date'] : date('Y-m-d');
} else {
    $completionDate = null;
}

$update = $pdo->prepare("UPDATE work_records SET status = ?, remarks = ?, completion_date = ? WHERE id = ?");
$update->execute([$status, $remarks, $completionDate, $id]);

logActivity('कामाची स्थिती अपडेट केली', 'work_record', $id, "{$record['work_code']} → $status");

$_SESSION['flash'] = $status === 'completed' ? 'काम पूर्ण झाले म्हणून चिन्हांकित केले.' : 'स्थिती व टिप्पणी अपडेट झाली.';
header('Location: my-work.php');
exit;
