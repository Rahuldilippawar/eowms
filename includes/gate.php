<?php
/**
 * 🚪 Gate — कर्मचारी ये-जा (QR Scan) + 📱 Digital ID चा Live QR.
 *  - Digital ID चा QR दर 30 सेकंदाला बदलतो (HMAC: कर्मचारी + वेळेची खिडकी) — Screenshot / Forward केलेला फोटो काही वेळानंतर चालत नाही.
 *  - छापलेल्या Card चा QR (staff/verify-id.php?code=…&s=…) पण Gate वर चालतो.
 *  - ये-जा नोंद: gate_logs (आत / बाहेर, Live / Card / हाताने, कोणी Scan केलं, कोणता Gate).
 */
require_once __DIR__ . '/id-card.php';

const GATE_LIVE_STEP = 30;   // सेकंद — QR इतक्या वेळाने बदलतो
const GATE_LIVE_GRACE = 2;   // मागच्या किती खिडक्या चालतील (≈ 60–90 सेकंद — Scan करायला वेळ)
const GATE_DUP_SECONDS = 60; // इतक्या वेळात तोच कर्मचारी पुन्हा Scan झाला तर दुसरी नोंद नाही

function gateReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM gate_logs LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** Gate Scan / ये-जा अहवाल — Super Admin, Management किंवा "Gate" परवानगी */
function canGate() {
    return hasRole('super_admin') || isManagementOrAbove() || hasPermission('gate_scan');
}

function gateLiveWindow($t = null) { return intdiv($t ?? time(), GATE_LIVE_STEP); }

function gateLiveSig($orgId, $userId, $win) {
    return substr(hash_hmac('sha256', 'liveid|' . (int)$orgId . '|' . (int)$userId . '|' . (int)$win, appSecret()), 0, 16);
}

/** Live token: L1.org.user.window.sig */
function gateLiveToken($orgId, $userId, $win = null) {
    $win = $win ?? gateLiveWindow();
    return 'L1.' . (int)$orgId . '.' . (int)$userId . '.' . (int)$win . '.' . gateLiveSig($orgId, $userId, $win);
}

/** Live QR मध्ये URL — साध्या Camera ने Scan केलं तरी Verify पान उघडतं */
function gateLiveUrl($orgId, $userId, $win = null) {
    $u = idCardVerifyUrl($orgId, 'x');
    return preg_replace('/\?.*$/', '', $u) . '?live=' . rawurlencode(gateLiveToken($orgId, $userId, $win));
}

/** Live token तपासतो → ['org'=>, 'user'=>] / 'expired' / null */
function gateLiveCheck($token) {
    if (!preg_match('/^L1\.(\d+)\.(\d+)\.(\d+)\.([0-9a-f]{16})$/', (string)$token, $m)) return null;
    [, $org, $uid, $win, $sig] = $m;
    if (!hash_equals(gateLiveSig($org, $uid, $win), $sig)) return null;
    $now = gateLiveWindow();
    if ((int)$win > $now + 1 || (int)$win < $now - GATE_LIVE_GRACE) return 'expired';
    return ['org' => (int)$org, 'user' => (int)$uid];
}

/** Scan केलेला मजकूर ओळखतो: Live QR, छापलेल्या Card चा QR, Barcode / हाताने Emp ID */
function gateParse($text) {
    $text = trim((string)$text);
    if ($text === '') return ['type' => 'empty'];
    $q = [];
    if (preg_match('#^https?://#i', $text)) parse_str((string)parse_url($text, PHP_URL_QUERY), $q);
    $live = $q['live'] ?? (str_starts_with($text, 'L1.') ? $text : null);
    if ($live !== null) return ['type' => 'live', 'token' => (string)$live];
    if (isset($q['code'], $q['s'])) return ['type' => 'card', 'code' => (string)$q['code'], 'org' => (int)($q['org'] ?? 0), 'sig' => (string)$q['s']];
    if (preg_match('#^https?://#i', $text)) return ['type' => 'unknown'];
    return ['type' => 'code', 'code' => mb_substr($text, 0, 40)];
}

/** Employee code → user (code नसलेल्यांचा Card वर "ID-123") */
function gateFindByCode(PDO $pdo, $orgId, $code) {
    $sql = "SELECT u.* FROM users u WHERE u.organization_id = ? AND (u.employee_code = ?";
    $p = [$orgId, $code];
    if (preg_match('/^ID-(\d+)$/i', $code, $m)) { $sql .= " OR (u.id = ? AND (u.employee_code IS NULL OR u.employee_code = ''))"; $p[] = (int)$m[1]; }
    $st = $pdo->prepare($sql . ") LIMIT 1");
    $st->execute($p);
    return $st->fetch() ?: null;
}

function gateUserRow(PDO $pdo, $orgId, $userId) {
    $st = $pdo->prepare("SELECT u.*, COALESCE(NULLIF(d.name_local, ''), d.name) dept_name, c.name campus_name
        FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id WHERE u.id = ? AND u.organization_id = ?");
    $st->execute([$userId, $orgId]);
    return $st->fetch() ?: null;
}

/** आज शेवटची यशस्वी नोंद */
function gateLastToday(PDO $pdo, $orgId, $userId) {
    $st = $pdo->prepare("SELECT direction, created_at FROM gate_logs WHERE organization_id = ? AND user_id = ? AND result = 'ok' AND created_at >= CURDATE() ORDER BY id DESC LIMIT 1");
    $st->execute([$orgId, $userId]);
    return $st->fetch() ?: null;
}

function gateLog(PDO $pdo, $orgId, $userId, $direction, $result, $method, $gate, $raw, $by) {
    $pdo->prepare("INSERT INTO gate_logs (organization_id, user_id, direction, result, method, gate, raw, scanned_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
        ->execute([$orgId, $userId ?: null, $direction, $result, $method, $gate !== '' ? mb_substr($gate, 0, 60) : null, $raw !== '' ? mb_substr($raw, 0, 120) : null, $by]);
    return (int)$pdo->lastInsertId();
}

/**
 * एक Scan प्रक्रिया — परत: ['result' => ok|duplicate|inactive|invalid|expired|other_org|notfound, 'direction', 'method', 'user' => row|null, 'message']
 * $mode: auto | in | out
 */
function gateProcess(PDO $pdo, $orgId, $text, $mode, $gate, $byUserId) {
    $p = gateParse($text);
    $method = ['live' => 'live', 'card' => 'card', 'code' => 'code'][$p['type']] ?? 'card';
    $raw = mb_substr(trim((string)$text), 0, 120);
    $user = null; $result = null;

    if ($p['type'] === 'live') {
        $c = gateLiveCheck($p['token']);
        if ($c === 'expired') $result = 'expired';
        elseif (!$c) $result = 'invalid';
        elseif ($c['org'] !== (int)$orgId) $result = 'other_org';
        else $user = gateUserRow($pdo, $orgId, $c['user']);
    } elseif ($p['type'] === 'card') {
        if ($p['org'] && $p['org'] !== (int)$orgId) $result = 'other_org';
        elseif (!hash_equals(idCardSig($orgId, $p['code']), $p['sig'])) $result = 'invalid';
        else { $u = gateFindByCode($pdo, $orgId, $p['code']); $user = $u ? gateUserRow($pdo, $orgId, $u['id']) : null; }
    } elseif ($p['type'] === 'code') {
        $u = gateFindByCode($pdo, $orgId, $p['code']); $user = $u ? gateUserRow($pdo, $orgId, $u['id']) : null;
    } else {
        $result = 'invalid';
    }
    if (!$result && !$user) $result = 'notfound';
    if (!$result && !(int)$user['is_active']) $result = 'inactive';

    $direction = null;
    if (!$result) {
        $last = gateLastToday($pdo, $orgId, $user['id']);
        if ($last && strtotime($last['created_at']) > time() - GATE_DUP_SECONDS) {
            return ['result' => 'duplicate', 'direction' => $last['direction'], 'method' => $method, 'user' => $user, 'at' => $last['created_at']];
        }
        $direction = in_array($mode, ['in', 'out'], true) ? $mode : (($last && $last['direction'] === 'in') ? 'out' : 'in');
        $result = 'ok';
    }
    $id = gateLog($pdo, $orgId, $user['id'] ?? null, $direction, $result, $method, $gate, $result === 'ok' ? '' : $raw, $byUserId);
    $att = $result === 'ok' ? gateAttendanceUpdate($pdo, $orgId, (int)$user['id']) : null;
    return ['result' => $result, 'direction' => $direction, 'method' => $method, 'user' => $user, 'log_id' => $id, 'at' => date('Y-m-d H:i:s'), 'att' => $att];
}

function gateResultLabels() {
    return ['ok' => 'नोंद झाली', 'duplicate' => 'आधीच नोंद', 'inactive' => 'कर्मचारी बंद (Inactive)', 'invalid' => 'बनावट / अवैध QR', 'expired' => 'QR जुना — Live ID पुन्हा दाखवा',
        'other_org' => 'दुसऱ्या संस्थेचं Card', 'notfound' => 'कर्मचारी सापडला नाही'];
}

function gateMethodLabels() { return ['live' => '📱 Live ID', 'card' => '🪪 Card', 'code' => '⌨️ हाताने']; }

/** 🕘 Gate Scan → हजेरी: Biometric सारखाच हिशोब (Shift, उशीर, लवकर गेले, काम, Overtime). परत: हिशोब / null */
function gateAttendanceUpdate(PDO $pdo, $orgId, $userId) {
    require_once __DIR__ . '/bio-helpers.php';
    if (!bioReady($pdo) || !bioGateAttendance($pdo, $orgId)) return null;
    try {
        $date = bioWorkDate(bioShift($pdo, $userId), time());
        $r = bioProcessUserDate($pdo, $orgId, $userId, $date);
        return $r ? $r + ['date' => $date] : null;
    } catch (PDOException $e) { return null; }
}

/** हजेरीचा छोटा मजकूर — Scanner / Digital ID वर */
function gateAttendanceText($att, $direction) {
    if (!$att) return '';
    $hm = fn($m) => (int)$m >= 60 ? intdiv((int)$m, 60) . ' ता ' . ((int)$m % 60) . ' मि' : (int)$m . ' मि';
    if ($direction === 'out' && !$att['missed']) {
        return '🕘 हजेरी: काम ' . $hm($att['worked']) . ($att['early'] > 0 ? ' · लवकर ' . $hm($att['early']) : '') . ($att['ot'] > 0 ? ' · OT ' . $hm($att['ot']) : '');
    }
    return '🕘 हजेरी नोंद: ' . date('h:i A', $att['first']) . ($att['late'] > 0 ? ' · ⏰ उशीर ' . $hm($att['late']) : ' · ✓ वेळेत');
}
