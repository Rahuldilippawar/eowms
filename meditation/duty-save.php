<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// जबाबदारी पूर्ण / पुन्हा प्रलंबित — व्यवस्थापक किंवा ज्याला Duty दिली तो स्वतः
if (($_POST['action'] ?? '') === 'set_status') {
    $dutyId = (int)($_POST['id'] ?? 0);
    $newStatus = ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'pending';
    $dStmt = $pdo->prepare("SELECT sd.id, sd.assigned_to, sd.satsang_event_id FROM satsang_duties sd JOIN satsang_events se ON se.id = sd.satsang_event_id WHERE sd.id = ? AND se.organization_id = ?");
    $dStmt->execute([$dutyId, activeOrgId()]);
    $dutyRow = $dStmt->fetch();
    if ($dutyRow && (isManagementOrAbove() || hasPermission('manage_meditation') || (int)$dutyRow['assigned_to'] === (int)$user['id'])) {
        $pdo->prepare("UPDATE satsang_duties SET status = ? WHERE id = ?")->execute([$newStatus, $dutyId]);
        $_SESSION['flash'] = $newStatus === 'completed' ? 'जबाबदारी पूर्ण म्हणून नोंदवली.' : 'जबाबदारी पुन्हा प्रलंबित केली.';
    }
    header('Location: event.php?id=' . (int)($dutyRow['satsang_event_id'] ?? 0));
    exit;
}

if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s618'));
}

$eventId = (int)($_POST['event_id'] ?? 0);
$assignedTo = (int)($_POST['assigned_to'] ?? 0);
$duty = trim($_POST['duty_description'] ?? '');

$stmt = $pdo->prepare("SELECT event_date FROM satsang_events WHERE id = ? AND organization_id = ?");
$stmt->execute([$eventId, activeOrgId()]);
$ev = $stmt->fetch();

// नेमलेला कर्मचारी याच संस्थेचा व Active असावा
$assigneeChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
$assigneeChk->execute([$assignedTo, activeOrgId()]);

if ($ev && $assignedTo && $duty && (int)$assigneeChk->fetchColumn() > 0) {
    $insert = $pdo->prepare("INSERT INTO satsang_duties (satsang_event_id, assigned_to, duty_description) VALUES (?, ?, ?)");
    $insert->execute([$eventId, $assignedTo, $duty]);
    notifyUser($assignedTo, activeOrgId(), 'सत्संग जबाबदारी नेमली', $duty . ' — ' . date('d M Y', strtotime($ev['event_date'])), 'meditation/event.php?id=' . $eventId);
    logActivity('सत्संग जबाबदारी नेमली', 'satsang_event', $eventId, $duty);

    $_SESSION['flash'] = t('s619');
}

header('Location: event.php?id=' . $eventId);
exit;
