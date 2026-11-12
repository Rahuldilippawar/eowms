<?php
// 🎂 वाढदिवस व कामाचा वर्धापनदिन — कोणाचा कधी, आणि शुभेच्छा पाठवणं
//   - कर्मचाऱ्याला: Email + App/Phone सूचना (Push)
//   - Management व त्याच विभागातले सहकारी: एक एकत्रित सूचना ("आज X यांचा वाढदिवस")
// प्रत्येक गोष्ट दिवसातून एकदाच (cron_digest_log) — Cron दोनदा चालला तरी पुन्हा जात नाही.
// संस्थेच्या सेटिंग्ज (org_settings): celebration_email, celebration_push, celebration_colleagues (1/0, default 1)

require_once __DIR__ . '/cron-helpers.php';
require_once __DIR__ . '/app-settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';

/** त्या दिवशी कोणाचे वाढदिवस / वर्धापनदिन — 29 फेब्रुवारीचे वाढदिवस साध्या वर्षात 28 ला */
function celebrationsOn(PDO $pdo, $orgId, $date) {
    $ts = strtotime($date);
    $m = (int)date('n', $ts); $d = (int)date('j', $ts); $y = (int)date('Y', $ts);
    $feb29 = ($m === 2 && $d === 28 && !checkdate(2, 29, $y)) ? ' OR (MONTH(%1$s) = 2 AND DAY(%1$s) = 29)' : '';
    $cond = fn($col) => "((MONTH($col) = $m AND DAY($col) = $d)" . sprintf($feb29, $col) . ")";
    $out = ['birthdays' => [], 'anniversaries' => []];
    try {
        $st = $pdo->prepare("SELECT u.id, u.name, u.email, u.department_id, u.designation, u.photo_path, d.name AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.organization_id = ? AND u.is_active = 1 AND u.date_of_birth IS NOT NULL AND " . $cond('u.date_of_birth') . " ORDER BY u.name");
        $st->execute([(int)$orgId]);
        $out['birthdays'] = $st->fetchAll();
        $st = $pdo->prepare("SELECT u.id, u.name, u.email, u.department_id, u.designation, u.photo_path, d.name AS dept, ? - YEAR(u.joining_date) AS years FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.organization_id = ? AND u.is_active = 1 AND u.joining_date IS NOT NULL AND YEAR(u.joining_date) < ? AND " . $cond('u.joining_date') . " ORDER BY u.name");
        $st->execute([$y, (int)$orgId, $y]);
        $out['anniversaries'] = $st->fetchAll();
    } catch (PDOException $e) {}
    return $out;
}

/** पुढच्या $days दिवसांतले (आजपासून) — [['date','kind' => birthday|anniversary,'user' => row,'years'], …] */
function celebrationsUpcoming(PDO $pdo, $orgId, $days = 30) {
    $list = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+$i days"));
        $c = celebrationsOn($pdo, $orgId, $date);
        foreach ($c['birthdays'] as $u) $list[] = ['date' => $date, 'kind' => 'birthday', 'user' => $u, 'years' => null];
        foreach ($c['anniversaries'] as $u) $list[] = ['date' => $date, 'kind' => 'anniversary', 'user' => $u, 'years' => (int)$u['years']];
    }
    return $list;
}

/** Email गेला नाही तर नोंद काढा — म्हणजे त्याच दिवशी Cron पुन्हा चालला तर पुन्हा प्रयत्न होईल */
function celebrationUnclaim(PDO $pdo, $key, $userId, $date) {
    try { $pdo->prepare("DELETE FROM cron_digest_log WHERE cron_key = ? AND user_id = ? AND digest_date = ?")->execute([$key, (int)$userId, $date]); } catch (PDOException $e) {}
}

/** संस्थेची सेटिंग (1 = चालू) */
function celebrationSetting(PDO $pdo, $orgId, $key) {
    return (string)orgSetting($pdo, $orgId, $key, '1') !== '0';
}

/**
 * शुभेच्छा पाठवा — एका संस्थेसाठी (Cron मधून रोज). परत: ['email' => n, 'push' => n, 'wish' => n]
 */
function celebrationsSend(PDO $pdo, array $org, $date) {
    $r = ['email' => 0, 'push' => 0, 'wish' => 0];
    $orgId = (int)$org['id'];
    $c = celebrationsOn($pdo, $orgId, $date);
    if (!$c['birthdays'] && !$c['anniversaries']) return $r;
    $doEmail = celebrationSetting($pdo, $orgId, 'celebration_email');
    $doPush = celebrationSetting($pdo, $orgId, 'celebration_push');
    $doCol = celebrationSetting($pdo, $orgId, 'celebration_colleagues');
    $people = []; // सहकाऱ्यांसाठी यादी
    foreach ($c['birthdays'] as $u) {
        if ($doEmail && $u['email'] && cronDigestClaim($pdo, 'bday-mail', $u['id'], $date)) { if (sendBirthdayEmail($u['email'], $u['name'], $org['name'])) $r['email']++; else celebrationUnclaim($pdo, 'bday-mail', $u['id'], $date); }
        if ($doPush && cronDigestClaim($pdo, 'bday-self', $u['id'], $date)) { notifyUser($u['id'], $orgId, '🎂 वाढदिवसाच्या हार्दिक शुभेच्छा, ' . $u['name'] . '!', $org['name'] . ' परिवाराकडून मनःपूर्वक शुभेच्छा — तुमचं हे वर्ष आनंदी व यशस्वी जावो! 🎉', '../welcome.php'); $r['push']++; }
        $people[] = ['u' => $u, 'text' => '🎂 ' . $u['name'] . ' — वाढदिवस', 'title' => '🎂 आज ' . $u['name'] . ' यांचा वाढदिवस — शुभेच्छा द्या!'];
    }
    foreach ($c['anniversaries'] as $u) {
        $yrs = (int)$u['years'];
        if ($doEmail && $u['email'] && cronDigestClaim($pdo, 'anniv-mail', $u['id'], $date)) { if (sendWorkAnniversaryEmail($u['email'], $u['name'], $yrs, $org['name'])) $r['email']++; else celebrationUnclaim($pdo, 'anniv-mail', $u['id'], $date); }
        if ($doPush && cronDigestClaim($pdo, 'anniv-self', $u['id'], $date)) { notifyUser($u['id'], $orgId, '🎊 ' . $org['name'] . ' सोबत ' . $yrs . ' वर्षं पूर्ण — अभिनंदन!', 'तुमच्या समर्पणाबद्दल व योगदानाबद्दल मनःपूर्वक धन्यवाद. पुढील वाटचालीसाठी शुभेच्छा!', '../welcome.php'); $r['push']++; }
        $people[] = ['u' => $u, 'text' => '🎊 ' . $u['name'] . ' — ' . $yrs . ' वर्षं सेवा', 'title' => '🎊 आज ' . $u['name'] . ' यांची ' . $yrs . ' वर्षं सेवा पूर्ण — अभिनंदन करा!'];
    }
    // Management + (सेटिंग चालू असेल तर) त्याच विभागातले सहकारी — प्रत्येकाला एकच एकत्रित सूचना
    $recip = [];
    foreach (cronManagementUserIds($pdo, $orgId) as $mid) { foreach ($people as $p) $recip[$mid][$p['u']['id']] = $p; }
    if ($doCol) {
        $st = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1 AND department_id = ? LIMIT 200");
        foreach ($people as $p) {
            if (!$p['u']['department_id']) continue;
            $st->execute([$orgId, (int)$p['u']['department_id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) { $recip[(int)$cid][$p['u']['id']] = $p; }
        }
    }
    foreach ($recip as $uid => $items) {
        unset($items[$uid]); // स्वतःचा वाढदिवस स्वतःला "सहकारी" म्हणून नको
        if (!$items || !cronDigestClaim($pdo, 'celebration-wish', $uid, $date, count($items))) continue;
        $items = array_values($items);
        $title = count($items) === 1 ? $items[0]['title'] : '🎉 आज ' . count($items) . ' जणांचे वाढदिवस / वर्धापनदिन — शुभेच्छा द्या!';
        $items = array_column($items, 'text');
        notifyUser($uid, $orgId, $title, cronDigestList($items, 4), '../welcome.php');
        $r['wish']++;
    }
    return $r;
}

/** कर्मचारी ज्यांची माहिती अपूर्ण आहे — त्यांना शुभेच्छा जाऊ शकत नाहीत */
function celebrationsMissing(PDO $pdo, $orgId) {
    require_once __DIR__ . '/photo-helpers.php';
    try {
        $st = $pdo->prepare("SELECT u.id, u.name, u.email, u.date_of_birth, u.joining_date, u.photo_path, d.name AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY d.name, u.name");
        $st->execute([(int)$orgId]);
        $out = [];
        foreach ($st->fetchAll() as $u) {
            // फोटो: none = दिलेलाच नाही, broken = मार्ग आहे पण File Server वर नाही
            $u['photo_state'] = !$u['photo_path'] ? 'none' : (eowmsPhotoExists($u['photo_path']) ? 'ok' : 'broken');
            if (!$u['date_of_birth'] || !$u['joining_date'] || !$u['email'] || $u['photo_state'] !== 'ok') $out[] = $u;
        }
        return $out;
    } catch (PDOException $e) { return []; }
}
