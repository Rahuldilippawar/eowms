<?php
// 🚪 Gate Scan API — Scanner पान इथे QR / Barcode / Emp ID पाठवतं; उत्तर JSON (फोटो, नाव, आत/बाहेर, स्थिती)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/gate.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
header('Content-Type: application/json; charset=utf-8');
$out = function ($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (!canGate()) $out(['ok' => false, 'message' => 'Gate Scan ची परवानगी नाही.'], 403);
if (!gateReady($pdo)) $out(['ok' => false, 'message' => 'Database Update बाकी आहे — Super Admin ने Database Update चालवावं.'], 503);
$orgId = activeOrgId();

function gateStats(PDO $pdo, $orgId) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(result = 'ok' AND direction = 'in'), 0) i, COALESCE(SUM(result = 'ok' AND direction = 'out'), 0) o, COALESCE(SUM(result NOT IN ('ok')), 0) bad
        FROM gate_logs WHERE organization_id = ? AND created_at >= CURDATE()");
    $st->execute([$orgId]);
    $s = $st->fetch();
    $st = $pdo->prepare("SELECT COUNT(*) FROM (SELECT g.user_id, (SELECT g2.direction FROM gate_logs g2 WHERE g2.organization_id = g.organization_id AND g2.user_id = g.user_id AND g2.result = 'ok' AND g2.created_at >= CURDATE() ORDER BY g2.id DESC LIMIT 1) last
        FROM gate_logs g WHERE g.organization_id = ? AND g.result = 'ok' AND g.created_at >= CURDATE() GROUP BY g.user_id) x WHERE x.last = 'in'");
    $st->execute([$orgId]);
    return ['in' => (int)$s['i'], 'out' => (int)$s['o'], 'bad' => (int)$s['bad'], 'inside' => (int)$st->fetchColumn()];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => true, 'stats' => gateStats($pdo, $orgId)]);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$q = trim((string)($in['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 600) $out(['ok' => false, 'message' => 'Scan रिकामा.'], 422);
$mode = in_array($in['mode'] ?? '', ['in', 'out'], true) ? $in['mode'] : 'auto';
$gate = mb_substr(trim((string)($in['gate'] ?? '')), 0, 60);

$r = gateProcess($pdo, $orgId, $q, $mode, $gate, (int)currentUser()['id']);
$u = $r['user'];
$labels = gateResultLabels();
$out([
    'ok' => true,
    'result' => $r['result'],
    'label' => $labels[$r['result']] ?? $r['result'],
    'direction' => $r['direction'],
    'method' => $r['method'],
    'methodLabel' => gateMethodLabels()[$r['method']] ?? '',
    'time' => date('h:i:s A', strtotime($r['at'])),
    'user' => $u ? [
        'name' => $u['name'],
        'code' => ($u['employee_code'] ?? '') !== '' ? $u['employee_code'] : 'ID-' . $u['id'],
        'designation' => $u['designation'] ?: '',
        'dept' => $u['dept_name'] ?: '',
        'campus' => $u['campus_name'] ?: '',
        'photo' => (!empty($u['photo_path']) && eowmsPhotoExists($u['photo_path'])) ? '../' . $u['photo_path'] : '',
        'active' => (int)$u['is_active'] === 1,
    ] : null,
    'stats' => gateStats($pdo, $orgId),
]);
