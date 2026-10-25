<?php
// 🆘 सूचनेची Live स्थिती (JSON) — view.php दर 10 सेकंदांनी विचारतं.
// स्वस्त तपासणी: 5 मिनिटांत प्रतिसाद नसेल तर याच वेळी Management ला पुन्हा (Escalation).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emergency-helpers.php';
requireLogin('../');
$user = currentUser();
header('Content-Type: application/json; charset=UTF-8');

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT ea.*, ru.name AS resolved_by_name FROM emergency_alerts ea LEFT JOIN users ru ON ru.id = ea.resolved_by WHERE ea.id = ? AND ea.organization_id = ?");
$st->execute([$id, activeOrgId()]);
$alert = $st->fetch();
if (!$alert || !emergencyCanView($pdo, $alert, $user['id'])) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

if ($alert['status'] === 'active' && empty($alert['first_ack_at'])) {
    emergencyEscalateUnacknowledged($pdo, $id);
}

$responders = [];
$recipientCount = 0;
try {
    $r = $pdo->prepare("SELECT u.name, er.responded_at FROM emergency_alert_responses er JOIN users u ON u.id = er.user_id WHERE er.alert_id = ? ORDER BY er.responded_at");
    $r->execute([$id]);
    foreach ($r->fetchAll() as $row) { $responders[] = ['name' => $row['name'], 'at' => date('h:i A', strtotime($row['responded_at']))]; }
    $c = $pdo->prepare("SELECT COUNT(*) FROM emergency_alert_recipients WHERE alert_id = ?");
    $c->execute([$id]);
    $recipientCount = (int)$c->fetchColumn();
} catch (PDOException $e) {}

echo json_encode([
    'status' => $alert['status'],
    'recipients' => $recipientCount,
    'responders' => $responders,
    'escalated' => !empty($alert['escalated_at']),
    'resolved_by' => $alert['resolved_by_name'],
    'resolved_at' => $alert['resolved_at'] ? date('h:i A', strtotime($alert['resolved_at'])) : null,
    'remark' => $alert['resolution_remark'] ?? null,
], JSON_UNESCAPED_UNICODE);
