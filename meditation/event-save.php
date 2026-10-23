<?php
// सत्संग कार्यक्रम — बदल (Edit) / काढणे (Delete)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s605'));
}
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$eventId = (int)($_POST['event_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM satsang_events WHERE id = ? AND organization_id = ?");
$stmt->execute([$eventId, $orgId]);
$event = $stmt->fetch();
if (!$event) { http_response_code(404); die(t('s616')); }

$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    // Duties/हजेरी Cascade ने जातात
    $pdo->prepare("DELETE FROM satsang_events WHERE id = ? AND organization_id = ?")->execute([$eventId, $orgId]);
    logActivity('सत्संग कार्यक्रम काढला', 'satsang_event', $eventId, $event['event_date']);
    $_SESSION['flash'] = 'सत्संग कार्यक्रम काढला गेला.';
    header('Location: index.php');
    exit;
}

if ($action === 'update') {
    $eventDate = $_POST['event_date'] ?? '';
    $deptId = ($_POST['organizing_department_id'] ?? '') !== '' ? (int)$_POST['organizing_department_id'] : null;
    $theme = trim($_POST['theme'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    if ($deptId) {
        $deptChk = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE id = ? AND organization_id = ?");
        $deptChk->execute([$deptId, $orgId]);
        if (!(int)$deptChk->fetchColumn()) { $deptId = null; }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) && strtotime($eventDate)) {
        $pdo->prepare("UPDATE satsang_events SET event_date = ?, organizing_department_id = ?, theme = ?, notes = ? WHERE id = ? AND organization_id = ?")
            ->execute([$eventDate, $deptId, $theme, $notes, $eventId, $orgId]);
        if ($deptId && (int)$deptId !== (int)$event['organizing_department_id']) {
            notifyDepartment($deptId, $orgId, 'सत्संग आयोजनाची जबाबदारी', date('d M Y', strtotime($eventDate)) . " रोजीच्या सत्संगाचं आयोजन तुमच्या विभागाकडे आहे.", 'meditation/event.php?id=' . $eventId);
        }
        logActivity('सत्संग कार्यक्रम बदलला', 'satsang_event', $eventId, $eventDate);
        $_SESSION['flash'] = 'सत्संग कार्यक्रम Update झाला.';
    } else {
        $_SESSION['flash'] = 'कृपया योग्य दिनांक निवडा.';
    }
}

header('Location: event.php?id=' . $eventId);
exit;
