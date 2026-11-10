<?php
// 🔔 Test सूचना — स्वतःलाच, लगेच (Queue ची वाट न पाहता). Push चालू आहे का ते तपासण्यासाठी.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }
$subs = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?"); $s->execute([$user['id']]); $subs = (int)$s->fetchColumn(); } catch (PDOException $e) {}
$configured = notifyPushConfigured($orgId);
$qid = notifyUser($user['id'], $orgId, '🔔 Test सूचना', 'हे दिसलं म्हणजे सूचना व्यवस्थित येत आहेत — ' . date('h:i A'), 'notifications/index.php', true);
$sent = $qid ? notifyDeliverPushQueue(1, 10, [$qid], 8) : 0;
echo json_encode(['ok' => true, 'configured' => $configured, 'devices' => $subs, 'sent' => $sent], JSON_UNESCAPED_UNICODE);
exit;
