<?php
// 🎓 E-Campus Sheet — एका ओळीची % आणि नोंद जतन (fetch/JSON). फक्त ज्याला काम नेमलं तो किंवा Super Admin; चालू + मागचे 12 महिने.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
header('Content-Type: application/json; charset=utf-8');
$out = function ($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ecampusReady($pdo)) { $out(400, ['ok' => false, 'msg' => 'अवैध विनंती']); }
$me = currentUser();
$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$taskId = (int)($in['task_id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM ecampus_tasks WHERE id = ? AND organization_id = ? AND is_active = 1");
$st->execute([$taskId, activeOrgId()]);
$task = $st->fetch();
if (!$task) { $out(404, ['ok' => false, 'msg' => 'काम सापडलं नाही']); }
if ((int)$task['user_id'] !== (int)$me['id'] && !ecampusCanManage()) { $out(403, ['ok' => false, 'msg' => 'हे काम तुम्हाला नेमलेलं नाही']); }
if ($task['access_type'] === 'view_only') { $out(400, ['ok' => false, 'msg' => 'फक्त पाहण्याचा Access — प्रगती लागू नाही']); }
$period = (string)($in['period'] ?? date('Y-m'));
if (!ecampusEditablePeriod($period)) { $out(400, ['ok' => false, 'msg' => 'फक्त चालू आणि मागच्या ' . ECAMPUS_EDIT_MONTHS . ' महिन्यांची नोंद भरता येते']); }

$pct = max(0, min(100, (int)($in['percent'] ?? 0)));
$notes = (string)($in['notes'] ?? '');
ecampusSave($pdo, $taskId, $period, $pct, $notes, $me['id']);
if ($period !== date('Y-m')) { logActivity('E-Campus मागच्या महिन्याची प्रगती भरली', 'ecampus_task', $taskId, ecampusPeriodLabel($period) . ' — ' . $pct . '%'); }
[$status, $label, $color] = ecampusStatus($pct, 'work');
$out(200, ['ok' => true, 'percent' => $pct, 'status' => $status, 'label' => $label, 'color' => $color, 'time' => date('h:i A'), 'by' => $me['name']]);
