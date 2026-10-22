<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s618'));
}
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$eventId = (int)($_POST['event_id'] ?? 0);
$assignedTo = (int)($_POST['assigned_to'] ?? 0);
$duty = trim($_POST['duty_description'] ?? '');

$stmt = $pdo->prepare("SELECT event_date FROM satsang_events WHERE id = ? AND organization_id = ?");
$stmt->execute([$eventId, activeOrgId()]);
$ev = $stmt->fetch();

if ($ev && $assignedTo && $duty) {
    $insert = $pdo->prepare("INSERT INTO satsang_duties (satsang_event_id, assigned_to, duty_description) VALUES (?, ?, ?)");
    $insert->execute([$eventId, $assignedTo, $duty]);
    notifyUser($assignedTo, activeOrgId(), 'सत्संग जबाबदारी नेमली', $duty . ' — ' . date('d M Y', strtotime($ev['event_date'])), 'meditation/event.php?id=' . $eventId);
    logActivity('सत्संग जबाबदारी नेमली', 'satsang_event', $eventId, $duty);

    $_SESSION['flash'] = t('s619');
}

header('Location: event.php?id=' . $eventId);
exit;
