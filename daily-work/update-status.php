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
$remarks = trim($_POST['remarks'] ?? '') ?: null;

$stmt = $pdo->prepare("SELECT * FROM work_records WHERE id = ?");
$stmt->execute([$id]);
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
$isOwnDeptHod = $isHod && (int)$record['department_id'] === (int)$user['department_id'];

if (!$isOwner && !$isOwnDeptHod && !hasRole('super_admin')) {
    http_response_code(403);
    die(t('s498'));
}

$completionDate = $status === 'completed' ? date('Y-m-d') : null;

$update = $pdo->prepare("UPDATE work_records SET status = ?, remarks = ?, completion_date = ? WHERE id = ?");
$update->execute([$status, $remarks, $completionDate, $id]);

logActivity('कामाची स्थिती अपडेट केली', 'work_record', $id, "{$record['work_code']} → $status");

$_SESSION['flash'] = $status === 'completed' ? 'काम पूर्ण झाले म्हणून चिन्हांकित केले.' : 'स्थिती व टिप्पणी अपडेट झाली.';
header('Location: my-work.php');
exit;
