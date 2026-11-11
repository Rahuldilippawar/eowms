<?php
/**
 * 🖐️ Biometric v2 — eSSL / ZKTeco ADMS ("iClock Push") Machines साठी सामायिक Logic.
 *
 * iclock/ (Machine बोलते ते Endpoints), cron/bio-process.php आणि biometric/ पानं — तिघेही हेच वापरतात.
 * Session / currentUser() लागत नाही — फक्त $pdo.
 *
 *  • Punches जतन — मंजुरी-प्रलंबित Machine चे पण (मंजूर केल्यावर हरवत नाहीत)
 *  • Shift-नुसार हिशोब — उशीर, लवकर गेले, प्रत्यक्ष काम, Overtime, अर्धा दिवस, एकच Punch, रात्रपाळी
 *  • कर्मचाऱ्याला Punch ची सूचना (In-App + Push)
 *  • Machine ला Remote आदेश (कर्मचारी पाठवणे/काढणे, Logs पुन्हा, माहिती, Restart)
 *  • Machine Offline / पुन्हा Online — Admin ला इशारा
 */

require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/app-settings.php';

const BIO_ONLINE_MINUTES = 10;        // इतक्या मिनिटांत संपर्क झाला = Online (Machine दर ~10-30 सेकंदांनी विचारते)
const BIO_OFFLINE_ALERT_MINUTES = 30; // इतका वेळ संपर्क नाही = Admin ला इशारा
const BIO_DUP_SECONDS = 120;          // 2 मिनिटांच्या आत पुन्हा बोट ठेवलं तर एकच Punch मानतो
const BIO_OT_MIN_MINUTES = 30;        // यापेक्षा कमी जास्तीचं काम Overtime धरत नाही
const BIO_NOTIFY_RECENT_HOURS = 3;    // जुने Logs पुन्हा आले तर सूचना नको — फक्त इतक्या तासांतले Punches
const BIO_PENDING_DEVICE_LIMIT = 20;  // खोटे SN पाठवून Table भरू नये
const BIO_PENDING_PUNCH_LIMIT = 50000;

/** bio_* Tables आहेत का (System Update → Database Update झालं का) */
function bioReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { $pdo->query("SELECT 1 FROM bio_devices LIMIT 1"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/**
 * नवीन व्यवस्थेची सुरुवात एकदाच — जुन्या Biometric चे PIN जोड पुसतो (Super Admin ने "सगळं शून्यापासून" निवडलं).
 * प्रत्येक संस्थेसाठी एकदाच (org_settings: bio_v2_reset). आधीची हजेरी (staff_attendance) तशीच राहते.
 */
function bioEnsureV2Reset(PDO $pdo) {
    static $done = false;
    if ($done || !bioReady($pdo) || !orgSettingsReady($pdo)) { return; }
    $done = true;
    try {
        $orgs = $pdo->query("SELECT o.id FROM organizations o LEFT JOIN org_settings s ON s.organization_id = o.id AND s.setting_key = 'bio_v2_reset' WHERE s.organization_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orgs as $oid) {
            $pdo->prepare("UPDATE users SET biometric_pin = NULL WHERE organization_id = ?")->execute([(int)$oid]);
            orgSettingSet($pdo, (int)$oid, 'bio_v2_reset', date('Y-m-d H:i:s'));
        }
    } catch (PDOException $e) {}
}

function bioStateLabel($state) {
    return [0 => 'आत', 1 => 'बाहेर', 2 => 'Break बाहेर', 3 => 'Break आत', 4 => 'OT आत', 5 => 'OT बाहेर'][$state === null ? -1 : (int)$state] ?? '—';
}

function bioVerifyLabel($mode) {
    if ($mode === null || $mode === '') { return '—'; }
    $m = (int)$mode;
    if ($m === 15 || $m === 25) { return '🙂 चेहरा'; }
    if ($m === 4 || $m === 6) { return '💳 Card'; }
    if ($m === 0 || $m === 3) { return '🔢 Password'; }
    if ($m === 1) { return '👆 बोट'; }
    return '👆 ' . $m;
}

/** Machine Online आहे का — शेवटचा संपर्क BIO_ONLINE_MINUTES मध्ये */
function bioDeviceOnline(array $dev) {
    return !empty($dev['last_seen_at']) && strtotime($dev['last_seen_at']) >= time() - BIO_ONLINE_MINUTES * 60;
}

function bioAgo($dt) {
    if (!$dt) { return 'कधीच नाही'; }
    $s = max(0, time() - strtotime($dt));
    if ($s < 60) { return 'आत्ताच'; }
    if ($s < 3600) { return floor($s / 60) . ' मि. पूर्वी'; }
    if ($s < 86400) { return floor($s / 3600) . ' तास पूर्वी'; }
    return floor($s / 86400) . ' दिवसांपूर्वी';
}

function bioValidSerial($sn) {
    return is_string($sn) && preg_match('/^[A-Za-z0-9_-]{4,50}$/', $sn);
}

/** SN वरून Machine — नसेल तर (वैध SN, मर्यादेत) 'pending' नोंद तयार करतो. null = स्वीकारायचं नाही. */
function bioDeviceBySerial(PDO $pdo, $sn, $create = true) {
    if (!bioValidSerial($sn)) { return null; }
    $st = $pdo->prepare("SELECT * FROM bio_devices WHERE serial_number = ?");
    $st->execute([$sn]);
    $dev = $st->fetch();
    if ($dev || !$create) { return $dev ?: null; }
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM bio_devices WHERE status = 'pending'")->fetchColumn();
    if ($pending >= BIO_PENDING_DEVICE_LIMIT) { return null; }
    try {
        $pdo->prepare("INSERT INTO bio_devices (serial_number, device_name, status, last_seen_at) VALUES (?, ?, 'pending', NOW())")
            ->execute([$sn, 'नवीन Machine']);
    } catch (PDOException $e) {}
    $st->execute([$sn]);
    return $st->fetch() ?: null;
}

/** Machine चा संपर्क — शेवटचा संपर्क, IP, Firmware इ.; Offline इशारा दिला असेल तर "पुन्हा Online" सूचना */
function bioTouchDevice(PDO $pdo, array $dev, array $info = []) {
    $set = ['last_seen_at = NOW()']; $p = [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) { $set[] = 'ip_address = ?'; $p[] = mb_substr($ip, 0, 45); }
    foreach (['firmware' => 100, 'push_version' => 20] as $k => $len) {
        if (!empty($info[$k])) { $set[] = "$k = ?"; $p[] = mb_substr((string)$info[$k], 0, $len); }
    }
    foreach (['user_count', 'fp_count', 'face_count', 'att_count'] as $k) {
        if (isset($info[$k]) && $info[$k] !== '' && is_numeric($info[$k])) { $set[] = "$k = ?"; $p[] = (int)$info[$k]; }
    }
    if (!empty($dev['offline_alerted_at'])) { $set[] = 'offline_alerted_at = NULL'; }
    $p[] = (int)$dev['id'];
    try { $pdo->prepare("UPDATE bio_devices SET " . implode(', ', $set) . " WHERE id = ?")->execute($p); } catch (PDOException $e) {}
    if (!empty($dev['offline_alerted_at']) && $dev['status'] === 'active' && $dev['organization_id']) {
        bioNotifyAdmins($pdo, (int)$dev['organization_id'], '✅ Biometric Machine पुन्हा Online — ' . bioDeviceName($dev),
            'Machine चा संपर्क परत सुरू झाला. मधल्या वेळचे Punches Machine आपोआप पाठवेल.', 'biometric/index.php');
    }
}

function bioDeviceName(array $dev) {
    return trim(($dev['device_name'] ?: 'Machine') . ($dev['location'] ? ' (' . $dev['location'] . ')' : '')) ?: $dev['serial_number'];
}

/** getrequest?INFO=Ver 8.0.4,12,10,2012,192.168.1.201,10,7,12,0,111 — पहिले भाग: Firmware, Users, बोटं, Logs, IP; 8वा चेहरे */
function bioParseInfo($info) {
    if (!is_string($info) || $info === '') { return []; }
    $p = array_map('trim', explode(',', $info));
    $out = ['firmware' => $p[0] ?? null, 'user_count' => $p[1] ?? null, 'fp_count' => $p[2] ?? null, 'att_count' => $p[3] ?? null];
    if (isset($p[7]) && is_numeric($p[7])) { $out['face_count'] = $p[7]; }
    return $out;
}

/** संस्थेत PIN → कर्मचारी (सक्रिय) */
function bioUserForPin(PDO $pdo, $orgId, $pin) {
    static $cache = [];
    $k = (int)$orgId . '|' . $pin;
    if (array_key_exists($k, $cache)) { return $cache[$k]; }
    $st = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND biometric_pin = ? AND is_active = 1 LIMIT 1");
    $st->execute([(int)$orgId, (string)$pin]);
    return $cache[$k] = ($st->fetchColumn() ?: null);
}

/**
 * ATTLOG — "PIN \t YYYY-MM-DD HH:MM:SS \t Status \t Verify \t WorkCode ..." ओळी.
 * परत: ['count' => नवीन नोंदी, 'new' => [punch ids], 'pairs' => [[user_id, date], ...]]
 */
function bioIngestAttlog(PDO $pdo, array $dev, $body) {
    $out = ['count' => 0, 'new' => [], 'pairs' => []];
    if ($dev['status'] === 'disabled') { return $out; }
    if ($dev['status'] === 'pending') {
        $n = $pdo->prepare("SELECT COUNT(*) FROM bio_punches WHERE device_id = ?");
        $n->execute([(int)$dev['id']]);
        if ((int)$n->fetchColumn() >= BIO_PENDING_PUNCH_LIMIT) { return $out; }
    }
    $orgId = $dev['status'] === 'active' ? (int)$dev['organization_id'] : 0;
    $ins = $pdo->prepare("INSERT IGNORE INTO bio_punches (device_id, pin, punch_time, punch_state, verify_mode, work_code, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $last = null;
    foreach (preg_split('/\r\n|\r|\n/', trim((string)$body)) as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 2) { continue; }
        $pin = trim($parts[0]);
        $ts = strtotime(trim($parts[1]));
        if ($pin === '' || !preg_match('/^[A-Za-z0-9]{1,20}$/', $pin) || !$ts) { continue; }
        if ($ts > time() + 86400 || $ts < strtotime('2015-01-01')) { continue; } // Machine ची चुकीची तारीख
        $state = isset($parts[2]) && is_numeric(trim($parts[2])) ? (int)trim($parts[2]) : null;
        $verify = isset($parts[3]) && is_numeric(trim($parts[3])) ? (int)trim($parts[3]) : null;
        $work = isset($parts[4]) && trim($parts[4]) !== '' && trim($parts[4]) !== '0' ? mb_substr(trim($parts[4]), 0, 20) : null;
        $uid = $orgId ? bioUserForPin($pdo, $orgId, $pin) : null;
        try {
            $ins->execute([(int)$dev['id'], $pin, date('Y-m-d H:i:s', $ts), $state, $verify, $work, $uid]);
            if ($ins->rowCount()) {
                $out['count']++;
                $out['new'][] = (int)$pdo->lastInsertId();
                if ($uid) { $out['pairs'][$uid . '|' . date('Y-m-d', $ts)] = [(int)$uid, date('Y-m-d', $ts)]; }
                $last = max($last ?? 0, $ts);
            }
        } catch (PDOException $e) {}
    }
    if ($last) {
        try { $pdo->prepare("UPDATE bio_devices SET last_punch_at = GREATEST(COALESCE(last_punch_at, '2000-01-01'), ?) WHERE id = ?")->execute([date('Y-m-d H:i:s', $last), (int)$dev['id']]); } catch (PDOException $e) {}
    }
    $out['pairs'] = array_values($out['pairs']);
    return $out;
}

/** OPERLOG — Machine वरचे कर्मचारी ("USER PIN=1\tName=..\tPri=0\tCard=.."), बोटं ("FP PIN=1\tFID=.."), चेहरे ("FACE PIN=1..") */
function bioIngestOperlog(PDO $pdo, array $dev, $body) {
    $n = 0;
    $kv = function ($s) {
        $r = [];
        foreach (explode("\t", $s) as $pair) { if (strpos($pair, '=') !== false) { [$k, $v] = explode('=', $pair, 2); $r[trim($k)] = trim($v); } }
        return $r;
    };
    foreach (preg_split('/\r\n|\r|\n/', trim((string)$body)) as $line) {
        $line = trim($line);
        if (preg_match('/^USER\s+(.*)$/', $line, $m)) {
            $d = $kv($m[1]);
            $pin = $d['PIN'] ?? '';
            if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $pin)) { continue; }
            try {
                $pdo->prepare("INSERT INTO bio_device_users (device_id, pin, name, privilege, card) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), privilege = VALUES(privilege), card = VALUES(card)")
                    ->execute([(int)$dev['id'], $pin, mb_substr($d['Name'] ?? '', 0, 100) ?: null, isset($d['Pri']) ? (int)$d['Pri'] : null, ($d['Card'] ?? '') !== '' && ($d['Card'] ?? '') !== '0' ? mb_substr($d['Card'], 0, 30) : null]);
                $n++;
            } catch (PDOException $e) {}
        } elseif (preg_match('/^(FP|FACE|BIODATA)\s+(.*)$/', $line, $m)) {
            $d = $kv($m[2]);
            $pin = $d['PIN'] ?? '';
            if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $pin)) { continue; }
            $isFace = $m[1] === 'FACE' || ($m[1] === 'BIODATA' && in_array($d['Type'] ?? '', ['2', '9'], true));
            try {
                $pdo->prepare("INSERT INTO bio_device_users (device_id, pin, fp_count, has_face) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE fp_count = LEAST(10, fp_count + VALUES(fp_count)), has_face = GREATEST(has_face, VALUES(has_face))")
                    ->execute([(int)$dev['id'], $pin, $isFace ? 0 : 1, $isFace ? 1 : 0]);
                $n++;
            } catch (PDOException $e) {}
        } elseif ($line !== '') {
            $n++; // OPLOG इ. — फक्त मोजतो
        }
    }
    return $n;
}

/** कर्मचाऱ्याची Shift — नसेल तर Default 09:30–17:30, 15 मि. सवलत, 4 तास = अर्धा दिवस, रविवार सुट्टी */
function bioShift(PDO $pdo, $userId) {
    static $cache = [];
    if (isset($cache[$userId])) { return $cache[$userId]; }
    $st = $pdo->prepare("SELECT shift_start, shift_end, grace_minutes, half_day_hours, weekly_off_1, weekly_off_2 FROM staff_shift_settings WHERE user_id = ?");
    $st->execute([(int)$userId]);
    $s = $st->fetch() ?: [];
    return $cache[$userId] = [
        'start' => $s['shift_start'] ?? '09:30:00', 'end' => $s['shift_end'] ?? '17:30:00',
        'grace' => (int)($s['grace_minutes'] ?? 15), 'half' => (float)($s['half_day_hours'] ?? 4),
        'off' => array_values(array_filter([$s['weekly_off_1'] ?? 0, $s['weekly_off_2'] ?? null], fn($v) => $v !== null && $v !== '')),
    ];
}

/** रात्रपाळी (उदा. 20:00–06:00) — सकाळचे Punches आदल्या दिवसाच्या हजेरीत */
function bioWorkDate(array $shift, $ts) {
    $night = strtotime('2000-01-01 ' . $shift['end']) <= strtotime('2000-01-01 ' . $shift['start']);
    $d = date('Y-m-d', $ts);
    if ($night && $ts < strtotime($d . ' ' . $shift['end']) + 4 * 3600) {
        return date('Y-m-d', strtotime('-1 day', $ts));
    }
    return $d;
}

/**
 * एका कर्मचाऱ्याचा एका दिवसाचा हिशोब — सगळे Punches (सगळ्या Machines चे) घेऊन staff_attendance मध्ये.
 * हाताने (manual / Regularization) दुरुस्त केलेली स्थिती बदलत नाही — फक्त वेळा व मिनिटं.
 */
function bioProcessUserDate(PDO $pdo, $orgId, $userId, $date) {
    $sh = bioShift($pdo, $userId);
    $night = strtotime('2000-01-01 ' . $sh['end']) <= strtotime('2000-01-01 ' . $sh['start']);
    $sTs = strtotime("$date {$sh['start']}");
    $eTs = strtotime("$date {$sh['end']}") + ($night ? 86400 : 0);
    if ($night) { $from = date('Y-m-d H:i:s', $sTs - 4 * 3600); $to = date('Y-m-d H:i:s', $eTs + 4 * 3600); }
    else { $from = "$date 00:00:00"; $to = date('Y-m-d', strtotime("+1 day", strtotime($date))) . ' 00:00:00'; }
    $st = $pdo->prepare("SELECT p.punch_time, p.punch_state FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.status = 'active' AND d.organization_id = ?
        WHERE p.user_id = ? AND p.punch_time >= ? AND p.punch_time < ? ORDER BY p.punch_time");
    $st->execute([(int)$orgId, (int)$userId, $from, $to]);
    $punches = [];
    foreach ($st->fetchAll() as $r) {
        $ts = strtotime($r['punch_time']);
        if ($punches && $ts - end($punches)['ts'] < BIO_DUP_SECONDS) { continue; } // दुहेरी बोट
        $punches[] = ['ts' => $ts, 'state' => $r['punch_state'] === null ? null : (int)$r['punch_state']];
    }
    if (!$punches) { return null; }

    // प्रत्यक्ष काम — आत/बाहेर जोड्या. Machine ने स्थिती (आत/बाहेर) दिली असेल तर ती, नसेल तर आळीपाळीने.
    $useStates = count(array_unique(array_map(fn($p) => $p['state'] === null ? 'n' : (in_array($p['state'], [1, 2, 5], true) ? 'o' : 'i'), $punches))) > 1
        && !in_array(null, array_column($punches, 'state'), true);
    $worked = 0; $in = null;
    foreach ($punches as $i => $p) {
        $isIn = $useStates ? !in_array($p['state'], [1, 2, 5], true) : ($i % 2 === 0);
        if ($isIn) { if ($in === null) { $in = $p['ts']; } }
        elseif ($in !== null) { $worked += $p['ts'] - $in; $in = null; }
    }
    $worked = (int)floor($worked / 60);
    $first = $punches[0]['ts'];
    $last = count($punches) > 1 ? end($punches)['ts'] : null;
    $missed = $last === null ? 1 : 0;

    $late = $first > $sTs + $sh['grace'] * 60 ? (int)floor(($first - $sTs) / 60) : 0;
    $early = $last && $last < $eTs ? (int)floor(($eTs - $last) / 60) : 0;
    $shiftMin = (int)floor(($eTs - $sTs) / 60);

    // सुट्टी / साप्ताहिक सुट्टीच्या दिवशी काम — पूर्ण वेळ Overtime
    $dow = (int)date('w', strtotime($date));
    $hol = $pdo->prepare("SELECT name FROM holidays WHERE organization_id = ? AND holiday_date = ? AND (campus_id IS NULL OR campus_id = (SELECT campus_id FROM users WHERE id = ?)) LIMIT 1");
    $hol->execute([(int)$orgId, $date, (int)$userId]);
    $holiday = $hol->fetchColumn();
    $offDay = $holiday !== false || in_array($dow, array_map('intval', $sh['off']), true);
    $ot = $offDay ? $worked : ($worked - $shiftMin >= BIO_OT_MIN_MINUTES ? $worked - $shiftMin : 0);
    if ($offDay) { $late = 0; $early = 0; }

    $status = (!$missed && $worked < $sh['half'] * 60) ? 'half_day' : 'present';
    $remarks = $offDay ? ($holiday !== false ? 'सुट्टीच्या दिवशी काम — ' . $holiday : 'साप्ताहिक सुट्टीच्या दिवशी काम') : ($missed ? 'एकच Punch — बाहेर जाताना Punch नाही' : null);

    $pdo->prepare("INSERT INTO staff_attendance (organization_id, user_id, attendance_date, first_punch_time, last_punch_time, total_punches, status, is_late, is_early_leave, source,
            remarks, late_minutes, early_minutes, worked_minutes, overtime_minutes, missed_punch)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'biometric', ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE first_punch_time = VALUES(first_punch_time), last_punch_time = VALUES(last_punch_time), total_punches = VALUES(total_punches),
            status = IF(source = 'manual', status, VALUES(status)), remarks = IF(source = 'manual', remarks, VALUES(remarks)),
            is_late = VALUES(is_late), is_early_leave = VALUES(is_early_leave), late_minutes = VALUES(late_minutes), early_minutes = VALUES(early_minutes),
            worked_minutes = VALUES(worked_minutes), overtime_minutes = VALUES(overtime_minutes), missed_punch = VALUES(missed_punch)")
        ->execute([(int)$orgId, (int)$userId, $date, date('H:i:s', $first), $last ? date('H:i:s', $last) : null, count($punches), $status,
            $late > 0 ? 1 : 0, $early > 0 ? 1 : 0, $remarks, $late, $early, $worked, $ot, $missed]);
    return ['status' => $status, 'late' => $late, 'early' => $early, 'worked' => $worked, 'ot' => $ot, 'missed' => $missed, 'first' => $first, 'count' => count($punches)];
}

/**
 * अजून हिशोब न झालेले Punches — आधी PIN वरून कर्मचारी शोधतो (नंतर PIN जोडले तरी), मग प्रत्येक (कर्मचारी, दिवस).
 * परत: ['done' => n, 'unmatched' => n]
 */
function bioProcessPending(PDO $pdo, $limit = 5000) {
    $res = ['done' => 0, 'unmatched' => 0];
    // कर्मचारी न जोडलेले Punches — PIN Mapping झाला असेल तर आता जोडा
    $pdo->exec("UPDATE bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.status = 'active'
        JOIN users u ON u.organization_id = d.organization_id AND u.biometric_pin = p.pin AND u.is_active = 1
        SET p.user_id = u.id WHERE p.user_id IS NULL");
    $st = $pdo->prepare("SELECT p.id, d.organization_id org, p.user_id, p.punch_time FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.status = 'active'
        WHERE p.processed = 0 AND p.user_id IS NOT NULL ORDER BY p.id LIMIT " . (int)$limit);
    $st->execute();
    $pairs = []; $ids = [];
    foreach ($st->fetchAll() as $r) {
        $ids[] = (int)$r['id'];
        $d = bioWorkDate(bioShift($pdo, (int)$r['user_id']), strtotime($r['punch_time']));
        $pairs[$r['user_id'] . '|' . $d] = [(int)$r['org'], (int)$r['user_id'], $d];
    }
    foreach ($pairs as [$org, $uid, $d]) {
        try { bioProcessUserDate($pdo, $org, $uid, $d); $res['done']++; } catch (PDOException $e) {}
    }
    foreach (array_chunk($ids, 1000) as $chunk) {
        $pdo->exec("UPDATE bio_punches SET processed = 1 WHERE id IN (" . implode(',', $chunk) . ")");
    }
    $res['unmatched'] = (int)$pdo->query("SELECT COUNT(DISTINCT p.device_id, p.pin) FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.status = 'active' WHERE p.user_id IS NULL")->fetchColumn();
    return $res;
}

/** लगेच हिशोब (Machine ने Punch पाठवल्यावर) + कर्मचाऱ्याला सूचना */
function bioProcessAndNotify(PDO $pdo, array $dev, array $newIds) {
    if ($dev['status'] !== 'active' || !$newIds) { return; }
    $orgId = (int)$dev['organization_id'];
    $ph = implode(',', array_fill(0, count($newIds), '?'));
    $st = $pdo->prepare("SELECT id, user_id, punch_time, punch_state FROM bio_punches WHERE id IN ($ph) AND user_id IS NOT NULL ORDER BY punch_time");
    $st->execute($newIds);
    $rows = $st->fetchAll();
    $results = [];
    foreach ($rows as $r) {
        $d = bioWorkDate(bioShift($pdo, (int)$r['user_id']), strtotime($r['punch_time']));
        $k = $r['user_id'] . '|' . $d;
        if (!isset($results[$k])) {
            try { $results[$k] = bioProcessUserDate($pdo, $orgId, (int)$r['user_id'], $d); } catch (PDOException $e) { $results[$k] = null; }
        }
    }
    if ($rows) {
        $pdo->prepare("UPDATE bio_punches SET processed = 1 WHERE id IN ($ph) AND user_id IS NOT NULL")->execute($newIds);
    }
    if (orgSetting($pdo, $orgId, 'bio_punch_notify', '1') !== '1') { return; }
    $place = bioDeviceName($dev);
    foreach ($rows as $r) {
        $ts = strtotime($r['punch_time']);
        if ($ts < time() - BIO_NOTIFY_RECENT_HOURS * 3600) { continue; } // जुने Logs पुन्हा आले — सूचना नको
        $d = bioWorkDate(bioShift($pdo, (int)$r['user_id']), $ts);
        $res = $results[$r['user_id'] . '|' . $d] ?? null;
        $isFirst = $res && $res['first'] === $ts;
        $kind = $r['punch_state'] !== null ? bioStateLabel($r['punch_state']) : ($isFirst ? 'आत' : 'Punch');
        $title = '🖐️ हजेरी नोंदली — ' . date('h:i A', $ts) . ' (' . $kind . ')';
        $msg = $place;
        if ($isFirst && $res['late'] > 0) { $msg .= ' · उशिरा ' . $res['late'] . ' मि.'; }
        elseif (!$isFirst && $res) { $msg .= ' · आजचं काम ' . intdiv($res['worked'], 60) . ' ता. ' . ($res['worked'] % 60) . ' मि.'; }
        notifyUser((int)$r['user_id'], $orgId, $title, $msg, 'biometric/my.php');
        try { $pdo->prepare("UPDATE bio_punches SET notified = 1 WHERE id = ?")->execute([(int)$r['id']]); } catch (PDOException $e) {}
    }
}

/** Super Admin + व्यवस्थापन — Machine चे इशारे */
function bioNotifyAdmins(PDO $pdo, $orgId, $title, $msg, $link) {
    $ids = notifyUserIdsByCategory($orgId, ['super_admin', 'management']);
    if ($ids) { notifyUsers($ids, $orgId, $title, $msg, $link); }
}

// ---------------- Remote आदेश ----------------

/** Machine साठी आदेश रांगेत — Machine पुढच्या संपर्कात (getrequest) घेते */
function bioQueueCommand(PDO $pdo, $deviceId, $command, $label, $byUserId = null) {
    $command = str_replace(["\r", "\n"], '', $command);
    $pdo->prepare("INSERT INTO bio_commands (device_id, label, command, status, created_by, created_at) VALUES (?, ?, ?, 'queued', ?, NOW())")
        ->execute([(int)$deviceId, mb_substr($label, 0, 150), $command, $byUserId]);
    return (int)$pdo->lastInsertId();
}

/** Machine वर कर्मचारी (नाव + PIN) — Machine वरचं नाव इंग्रजी अक्षरांतच नीट दिसतं (मराठी नाव असेल तर कर्मचारी क्रमांक / "Staff PIN") */
function bioUserCommand($pin, $name, $alt = null) {
    $ascii = trim(preg_replace('/[^A-Za-z0-9 .\-]/', '', (string)$name));
    if (mb_strlen($ascii) < 3) { $ascii = trim(preg_replace('/[^A-Za-z0-9 .\-]/', '', (string)$alt)); }
    if ($ascii === '') { $ascii = 'Staff ' . $pin; }
    return "DATA UPDATE USERINFO PIN=$pin\tName=" . mb_substr($ascii, 0, 24) . "\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000100000000\tVerify=0";
}

/** हा PIN संस्थेत दुसऱ्या कर्मचाऱ्याकडे आहे का (निष्क्रिय धरून) — असेल तर त्याचं नाव */
function bioPinOwner(PDO $pdo, $orgId, $pin, $exceptUserId = 0) {
    $st = $pdo->prepare("SELECT name FROM users WHERE organization_id = ? AND biometric_pin = ? AND id <> ? LIMIT 1");
    $st->execute([(int)$orgId, (string)$pin, (int)$exceptUserId]);
    return $st->fetchColumn() ?: null;
}

/**
 * कर्मचाऱ्याची नोंद बदलली (नवीन / PIN बदलला / सक्रिय-निष्क्रिय) — सगळ्या चालू Machines वर आपोआप:
 * सक्रिय + PIN → नाव+PIN पाठवतो; जुना PIN बदलला → जुना काढतो; निष्क्रिय → Machine वरून काढतो (सोडून गेलेले Punch करू शकत नाहीत).
 * परत: किती Machines ना आदेश गेला (0 = Machine नाही / bio Tables नाहीत).
 */
function bioSyncStaff(PDO $pdo, $orgId, $userId, $oldPin = null, $byUserId = null) {
    if (!bioReady($pdo)) { return 0; }
    $u = $pdo->prepare("SELECT id, name, employee_code, biometric_pin, is_active FROM users WHERE id = ? AND organization_id = ?");
    $u->execute([(int)$userId, (int)$orgId]);
    $u = $u->fetch();
    if (!$u) { return 0; }
    $devs = $pdo->prepare("SELECT id FROM bio_devices WHERE organization_id = ? AND status = 'active'");
    $devs->execute([(int)$orgId]);
    $devs = $devs->fetchAll(PDO::FETCH_COLUMN);
    $pin = (string)($u['biometric_pin'] ?? '');
    $old = (string)($oldPin ?? '');
    foreach ($devs as $did) {
        if ($old !== '' && $old !== $pin) { bioQueueCommand($pdo, $did, "DATA DELETE USERINFO PIN=$old", 'जुना PIN काढला — ' . $old, $byUserId); }
        if ($pin === '') { continue; }
        if ((int)$u['is_active'] === 1) { bioQueueCommand($pdo, $did, bioUserCommand($pin, $u['name'], $u['employee_code']), 'कर्मचारी पाठवला — PIN ' . $pin, $byUserId); }
        else { bioQueueCommand($pdo, $did, "DATA DELETE USERINFO PIN=$pin", 'निष्क्रिय कर्मचारी काढला — PIN ' . $pin, $byUserId); }
    }
    return count($devs);
}

/** पुढचा मोकळा PIN — संस्थेतला सर्वात मोठा अंकी PIN + 1 (Machine वरचे PIN पण धरून) */
function bioNextPin(PDO $pdo, $orgId) {
    $a = (int)$pdo->query("SELECT COALESCE(MAX(CAST(biometric_pin AS UNSIGNED)), 0) FROM users WHERE organization_id = " . (int)$orgId . " AND biometric_pin REGEXP '^[0-9]+$'")->fetchColumn();
    $b = (int)$pdo->query("SELECT COALESCE(MAX(CAST(du.pin AS UNSIGNED)), 0) FROM bio_device_users du JOIN bio_devices d ON d.id = du.device_id WHERE d.organization_id = " . (int)$orgId . " AND du.pin REGEXP '^[0-9]+$'")->fetchColumn();
    return (string)(max($a, $b) + 1);
}

/** Machine ची वेळ किती पुढे/मागे — Realtime आलेल्या Punches वरून (आलेली वेळ − Punch ची वेळ), मिनिटांत. null = माहीत नाही */
function bioClockDrift(PDO $pdo, $deviceId) {
    $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, punch_time, created_at) d FROM bio_punches WHERE device_id = ? AND created_at >= NOW() - INTERVAL 2 DAY ORDER BY id DESC LIMIT 15");
    $st->execute([(int)$deviceId]);
    $d = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $d = array_values(array_filter($d, fn($x) => abs($x) < 6 * 3600)); // जुने Logs (उशिरा आलेले) सोडून
    if (count($d) < 3) { return null; }
    sort($d);
    $median = $d[intdiv(count($d), 2)];
    return (int)round(-$median / 60); // + = Machine पुढे, − = मागे
}

/** Offline इशारे — Cron मधून. परत: किती इशारे पाठवले */
function bioCheckOffline(PDO $pdo) {
    $n = 0;
    $st = $pdo->query("SELECT * FROM bio_devices WHERE status = 'active' AND organization_id IS NOT NULL AND offline_alerted_at IS NULL
        AND last_seen_at IS NOT NULL AND last_seen_at < NOW() - INTERVAL " . BIO_OFFLINE_ALERT_MINUTES . " MINUTE");
    foreach ($st->fetchAll() as $dev) {
        bioNotifyAdmins($pdo, (int)$dev['organization_id'], '⚠️ Biometric Machine Offline — ' . bioDeviceName($dev),
            'शेवटचा संपर्क ' . date('d/m h:i A', strtotime($dev['last_seen_at'])) . '. वीज / Internet / LAN Cable तपासा.', 'biometric/index.php');
        $pdo->prepare("UPDATE bio_devices SET offline_alerted_at = NOW() WHERE id = ?")->execute([(int)$dev['id']]);
        $n++;
    }
    return $n;
}
