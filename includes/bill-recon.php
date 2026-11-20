<?php
/**
 * 🧾 मासिक बिल जमा / ताळमेळ तक्ता (Monthly Bill Reconciliation).
 * दरमहा: मुख्य भांडार विभाग, खरेदी विभाग व लेखा विभाग — प्रत्येकाकडे आलेल्या बिलांची संख्या.
 * प्रत्येक विभाग स्वतःची संख्या भरतो व "डिजिटल मान्यता" देतो (नाव + वेळ + पडताळणी कोड).
 * संख्या जुळत नसतील तर फरकाची कारणं (पुरवठादार, बिलं, कुठे अडकली, कारण) — मिटली की "मिटले".
 * तिन्ही संख्या भरल्यावर मा. कार्याध्यक्ष शेरा देतात.
 */
require_once __DIR__ . '/app-settings.php';

function billReconReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM bill_recon_months LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/**
 * चार विभाग — key => [नाव, Icon, परवानगी, "…कडे", "…ची"]
 * बिलं येतात भांडार व भोजनालय विभागात → त्यांची बेरीज = खरेदी विभाग = लेखा विभाग (उदा. 225 + 64 = 289)
 */
function billReconDepts() {
    return [
        'store'    => ['भांडार विभाग', '📦', 'bill_recon_store', 'भांडार विभागाकडे', 'भांडार विभागाची'],
        'kitchen'  => ['भोजनालय विभाग', '🍲', 'bill_recon_kitchen', 'भोजनालय विभागाकडे', 'भोजनालय विभागाची'],
        'purchase' => ['खरेदी विभाग', '🛒', 'manage_purchase', 'खरेदी विभागाकडे', 'खरेदी विभागाची'],
        'accounts' => ['लेखा विभाग', '📒', 'bill_recon_accounts', 'लेखा विभागाकडे', 'लेखा विभागाची'],
    ];
}

/** विभाग ओळखणे — Department code, आणि भोजनालयासाठी नावातील शब्द (कोड वेगवेगळे असू शकतात) */
function billReconDeptMatch() {
    return [
        'store'    => [['STR'], []],
        'kitchen'  => [['KIT', 'MESS', 'BHJ', 'BHO', 'CNT'], ['भोजनालय', 'भोजन', 'Kitchen', 'Mess', 'Canteen']],
        'purchase' => [['PUR'], []],
        'accounts' => [['ACC'], []],
    ];
}

/** सध्याचा वापरकर्ता कोणत्या विभागाचा (store/kitchen/purchase/accounts) — नसेल तर '' */
function billReconUserDept() {
    global $pdo;
    static $k = null;
    if ($k !== null) return $k;
    $k = ''; $u = currentUser();
    if (!$u || empty($u['department_id']) || !($pdo instanceof PDO)) return $k;
    try { $st = $pdo->prepare("SELECT code, name, name_local FROM departments WHERE id = ?"); $st->execute([(int)$u['department_id']]); $d = $st->fetch(); } catch (PDOException $e) { $d = null; }
    if (!$d) return $k;
    foreach (billReconDeptMatch() as $key => [$codes, $words]) {
        if (in_array(strtoupper((string)$d['code']), $codes, true)) return $k = $key;
        foreach ($words as $w) if (mb_stripos((string)$d['name'], $w) !== false || mb_stripos((string)$d['name_local'], $w) !== false) return $k = $key;
    }
    return $k;
}

function billReconCan($dept) {
    $d = billReconDepts();
    if (!isset($d[$dept])) return false;
    if (hasRole('super_admin') || hasPermission($d[$dept][2])) return true;
    return billReconUserDept() === $dept;
}
/** मा. कार्याध्यक्ष / व्यवस्थापन शेरा */
function billReconCanHead() { return hasRole('super_admin') || isManagementOrAbove(); }
function billReconCanAny() { foreach (billReconDepts() as $k => $_) if (billReconCan($k)) return true; return false; }
/** पाहणे व अहवाल — तिन्ही विभाग, व्यवस्थापन, किंवा पदे व्यवस्थापनातून "बिल ताळमेळ — पाहणे" Access */
function billReconCanView() { return billReconCanAny() || billReconCanHead() || hasPermission('bill_recon_view'); }

/** विभागाचा जबाबदार अधिकारी (फक्त तोच मान्यता देऊ शकतो) — संस्था सेटिंग "bill_recon_resp_<dept>" */
function billReconResponsible(PDO $pdo, $orgId, $dept) {
    static $c = [];
    if (array_key_exists($dept, $c)) return $c[$dept];
    $id = function_exists('orgSetting') ? (int)orgSetting($pdo, $orgId, 'bill_recon_resp_' . $dept, 0) : 0;
    if (!$id) return $c[$dept] = null;
    $st = $pdo->prepare("SELECT id, name, designation FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
    $st->execute([$id, (int)$orgId]);
    return $c[$dept] = ($st->fetch() ?: null);
}
/** "नाव, पद" — Approved by मध्ये दाखवायला */
function billReconPersonLabel($u) { return $u ? trim($u['name'] . (!empty($u['designation']) ? ', ' . $u['designation'] : '')) : ''; }
/**
 * मान्यता कोण देऊ शकतो: जबाबदार अधिकारी नेमला असेल तर फक्त तोच;
 * नेमला नसेल तर त्या विभागाची परवानगी असलेला कर्मचारी (Super Admin दुसऱ्याच्या वतीने मान्यता देत नाही).
 */
function billReconCanApprove(PDO $pdo, $orgId, $dept) {
    $u = currentUser(); if (!$u) return false;
    $resp = billReconResponsible($pdo, $orgId, $dept);
    if ($resp) return (int)$resp['id'] === (int)$u['id'];
    return $u['role'] !== 'super_admin' && billReconCan($dept);
}
/** जबाबदार अधिकारी नेमणे — Super Admin / व्यवस्थापन */
function billReconCanSetResponsible() { return hasRole('super_admin') || isManagementOrAbove(); }

/** "2026-10" → आर्थिक वर्ष "2026-27" (एप्रिल–मार्च) */
function billReconFy($period) {
    [$y, $m] = array_map('intval', explode('-', $period));
    if ($m < 4) $y--;
    return $y . '-' . substr((string)($y + 1), -2);
}
function billReconFyLabel($fy) { return preg_match('/^(\d{4})-(\d{2})$/', (string)$fy, $m) ? $m[1] . '-' . substr($m[1], 0, 2) . $m[2] : (string)$fy; }

/** आर्थिक वर्षाचे 12 महिने (एप्रिल → मार्च) */
function billReconFyPeriods($fy) {
    $y = (int)substr($fy, 0, 4); $out = [];
    for ($i = 0; $i < 12; $i++) { $m = 4 + $i; $yy = $y + ($m > 12 ? 1 : 0); $out[] = sprintf('%04d-%02d', $yy, $m > 12 ? $m - 12 : $m); }
    return $out;
}

function billReconMonthName($period, $withYear = true) {
    $n = ['', 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
    [$y, $m] = array_map('intval', explode('-', $period));
    return $n[$m] . ($withYear ? ' ' . $y : '');
}

function billReconMr($s) { return strtr((string)$s, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']); }

/**
 * ताळमेळ स्थिती — [key, लेबल, tone]
 * entry: संख्या भरणे बाकी · match: जुळली + तिन्ही मान्यता · match_wait: जुळली, मान्यता बाकी · diff: फरक आहे
 */
function billReconStatus(array $r) {
    if ($r['store_count'] === null || ($r['kitchen_count'] ?? null) === null || $r['purchase_count'] === null || $r['accounts_count'] === null) return ['entry', '⏳ नोंद बाकी', 'warn'];
    $recv = billReconReceived($r);
    $eq = $recv === (int)$r['purchase_count'] && (int)$r['purchase_count'] === (int)$r['accounts_count'];
    // भोजनालयाची बिलं 0 असतील तर त्यांची मान्यता लागत नाही
    $appr = $r['store_at'] && $r['purchase_at'] && $r['accounts_at'] && ((int)$r['kitchen_count'] === 0 || !empty($r['kitchen_at']));
    if ($eq) return $appr ? ['match', '✅ 100% ताळमेळ पूर्ण', 'ok'] : ['match_wait', '✍️ जुळले — मान्यता बाकी', 'warn'];
    return ['diff', '⚠️ ताळमेळ बाकी', 'bad'];
}

/** विभागाचे रंग */
function billReconColors() { return ['store' => '#0891B2', 'kitchen' => '#16A34A', 'purchase' => '#D97706', 'accounts' => '#7C3AED']; }

/** नोंद नसलेल्या महिन्यासाठी रिकामी ओळ */
function billReconEmpty($period) {
    $r = ['id' => 0, 'period' => $period, 'head_remark' => null, 'head_name' => null, 'head_at' => null];
    foreach (array_keys(billReconDepts()) as $k) foreach (['count', 'note', 'by', 'name', 'at'] as $f) $r[$k . '_' . $f] = null;
    return $r;
}

/** भांडार + भोजनालय = प्राप्त बिलं (खरेदी विभागाशी जुळायला हवी) */
function billReconReceived(array $r) { return (int)$r['store_count'] + (int)($r['kitchen_count'] ?? 0); }

/** सगळ्यात जास्त संख्येच्या तुलनेत फरक (उदा. 289 − 225 = 64) */
function billReconDiff(array $r) {
    $c = array_filter([$r['store_count'] === null && ($r['kitchen_count'] ?? null) === null ? null : billReconReceived($r), $r['purchase_count'], $r['accounts_count']], fn($x) => $x !== null);
    return count($c) > 1 ? max($c) - min($c) : 0;
}

/** डिजिटल मान्यतेचा पडताळणी कोड (नोंद बदलली तर कोड बदलतो) */
function billReconSignCode(array $r, $dept) {
    $secret = function_exists('appSecret') ? appSecret() : 'eadmin';
    return strtoupper(substr(hash_hmac('sha256', implode('|', [$r['id'], $r['period'], $dept, $r[$dept . '_count'], $r[$dept . '_by'], $r[$dept . '_at']]), (string)$secret), 0, 10));
}

function billReconLog(PDO $pdo, $orgId, $monthId, $event, $note = null, $userId = null) {
    try {
        $pdo->prepare("INSERT INTO bill_recon_events (month_id, organization_id, event, note, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([(int)$monthId, (int)$orgId, $event, $note !== null ? mb_substr($note, 0, 500) : null, $userId]);
    } catch (PDOException $e) {}
}

function billReconEventLabels() {
    return ['count' => '🔢 संख्या भरली', 'approved' => '✍️ डिजिटल मान्यता', 'unapproved' => '↺ मान्यता काढली', 'issue' => '⚠️ फरकाचं कारण', 'resolved' => '✅ फरक मिटला', 'reopened' => '↺ फरक पुन्हा उघडला',
        'head' => '🏛️ कार्याध्यक्ष शेरा', 'imported' => '📥 कागदी तक्त्यावरून'];
}

/** महिना मिळवा / नसेल तर तयार करा */
function billReconMonth(PDO $pdo, $orgId, $period, $userId = null, $create = true) {
    $st = $pdo->prepare("SELECT * FROM bill_recon_months WHERE organization_id = ? AND period = ?");
    $st->execute([(int)$orgId, $period]);
    $r = $st->fetch();
    if ($r || !$create) return $r ?: null;
    $pdo->prepare("INSERT IGNORE INTO bill_recon_months (organization_id, period, fy, created_by, created_at) VALUES (?,?,?,?,NOW())")->execute([(int)$orgId, $period, billReconFy($period), $userId]);
    $st->execute([(int)$orgId, $period]);
    return $st->fetch() ?: null;
}

/** त्या विभागाच्या परवानगीचे सक्रिय वापरकर्ते (सूचना पाठवण्यासाठी) */
function billReconUsers(PDO $pdo, $orgId, $perm) {
    try {
        // त्या विभागाचे कर्मचारी (Department code / नाव) + परवानगी असलेले
        $codes = ['__none__']; $words = [];
        foreach (billReconDepts() as $dk => $dv) if ($dv[2] === $perm) [$codes, $words] = billReconDeptMatch()[$dk] + [1 => []];
        $codes = $codes ?: ['__none__'];
        $dq = 'dd.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        foreach ($words as $_) $dq .= ' OR dd.name LIKE ? OR dd.name_local LIKE ?';
        $dp = $codes; foreach ($words as $w) { $dp[] = '%' . $w . '%'; $dp[] = '%' . $w . '%'; }
        $st = $pdo->prepare("SELECT u.id FROM users u WHERE u.organization_id = ? AND u.is_active = 1 AND (
                EXISTS (SELECT 1 FROM departments dd WHERE dd.id = u.department_id AND ($dq))
                OR EXISTS (SELECT 1 FROM user_permissions up WHERE up.user_id = u.id AND up.permission_key = ? AND up.mode = 'grant' AND (up.expires_on IS NULL OR up.expires_on >= CURDATE()))
                OR (EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_key = u.role AND rp.permission_key = ?)
                    AND NOT EXISTS (SELECT 1 FROM user_permissions ud WHERE ud.user_id = u.id AND ud.permission_key = ? AND ud.mode = 'deny')))");
        $st->execute(array_merge([(int)$orgId], $dp, [$perm, $perm, $perm]));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) { return []; }
}

/** इतर दोन विभागांना सूचना */
function billReconNotifyOthers(PDO $pdo, $orgId, $exceptDept, $title, $msg, $link, $actorId) {
    if (!function_exists('notifyUsers')) { @include_once __DIR__ . '/notify.php'; }
    if (!function_exists('notifyUsers')) return;
    $ids = [];
    foreach (billReconDepts() as $k => $d) { if ($k !== $exceptDept) $ids = array_merge($ids, billReconUsers($pdo, $orgId, $d[2])); }
    try { notifyUsers(array_unique($ids), $orgId, $title, $msg, $link, $actorId); } catch (Throwable $e) {}
}

/** "लिंक" — इतर Modules मधून त्या महिन्याची सुचवलेली संख्या (मार्गदर्शनासाठी) */
function billReconHints(PDO $pdo, $orgId, $period) {
    $from = $period . '-01'; $to = date('Y-m-t', strtotime($from)); $h = [];
    try {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(IFNULL(vendor_id, 0), '|', bill_no)) FROM store_stock_moves WHERE organization_id = ? AND move_type = 'purchase' AND bill_no IS NOT NULL AND bill_no <> '' AND move_date BETWEEN ? AND ?");
        $st->execute([(int)$orgId, $from, $to]); $n = (int)$st->fetchColumn();
        if ($n) $h['store'] = [$n, 'Store मध्ये या महिन्यात बिलासह माल आवक'];
    } catch (PDOException $e) {}
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM purchase_requests WHERE organization_id = ? AND status = 'done' AND done_on BETWEEN ? AND ?");
        $st->execute([(int)$orgId, $from, $to]); $n = (int)$st->fetchColumn();
        if ($n) $h['purchase'] = [$n, 'खरेदी विभागात या महिन्यात "खरेदी पूर्ण" नोंदी'];
    } catch (PDOException $e) {}
    return $h;
}

/** अहवालाचं शीर्षक — संस्था सेटिंग; नसेल तर संस्थेचं नाव */
function billReconHead(PDO $pdo, $orgId, $orgName) {
    $v = function_exists('orgSetting') ? (string)orgSetting($pdo, $orgId, 'bill_recon_head', '') : '';
    return $v !== '' ? $v : $orgName;
}

/**
 * कागदी तक्त्यावरून (माहे एप्रिल ते ऑक्टोबर 2026) एकदाच आयात.
 * सह्या कागदावर झाल्या होत्या — त्या "कागदी सही" म्हणून नावासह नोंदवतो.
 */
function billReconSeedImport(PDO $pdo, $orgId, $userId) {
    if (function_exists('orgSetting') && orgSetting($pdo, $orgId, 'bill_recon_seed_done', '') !== '') return 0;
    // [महिना, भांडार, भोजनालय, खरेदी, लेखा] — ऑक्टोबर: भांडार 225 + भोजनालय 64 = खरेदी 289
    $rows = [['2026-04', 204, 0, 204, 204], ['2026-05', 160, 0, 160, 160], ['2026-06', 166, 0, 166, 166], ['2026-07', 169, 0, 169, 169],
             ['2026-08', 300, 0, 300, 300], ['2026-09', 254, 0, 254, 254], ['2026-10', 225, 64, 289, 287]];
    $paper = ['store' => 'श्री राजेंद्र भोयर', 'purchase' => 'श्री युनिस बात्रा', 'accounts' => 'श्री शुभम पवार'];
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as [$p, $s, $ki, $pu, $a]) {
            $m = billReconMonth($pdo, $orgId, $p, $userId);
            if ($m['store_count'] !== null || $m['purchase_count'] !== null || $m['accounts_count'] !== null) continue;
            $at = '2026-11-03 10:00:00';
            $pdo->prepare("UPDATE bill_recon_months SET store_count=?, store_name=?, store_at=?, kitchen_count=?, purchase_count=?, purchase_name=?, purchase_at=?, accounts_count=?, accounts_name=?, accounts_at=?, updated_at=NOW() WHERE id=?")
                ->execute([$s, $paper['store'], $at, $ki, $pu, $paper['purchase'], $at, $a, $paper['accounts'], $at, $m['id']]);
            billReconLog($pdo, $orgId, $m['id'], 'imported', 'मासिक बिल जमा / ताळमेळ तक्ता (एप्रिल ते ऑक्टोबर 2026)', $userId);
            if ($p === '2026-10') {
                $ins = $pdo->prepare("INSERT INTO bill_recon_issues (month_id, organization_id, party, bill_count, held_at, reason, status, resolved_note, resolved_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
                // कागदी तक्त्याप्रमाणे: 1) CMK ट्रेडर्सचं 1 बिल खरेदी विभागाकडे; 2) गणेश मेडिकल — खोडलेलं (मिटलं)
                $ins->execute([$m['id'], $orgId, 'CMK ट्रेडर्स', 1, 'purchase', 'किचन साहित्य खरेदीचे बिल तीन कोटेशन अभावी जमा नाही — खरेदी विभागाकडे जमा आहे', 'open', null, null, $userId]);
                $ins->execute([$m['id'], $orgId, 'श्री गणेश मेडिकल स्टोअर्स', 2, 'accounts', 'मूळ बिले प्राप्त न झाल्याने जमा करणे बाकी', 'resolved', 'कागदी तक्त्यावर खोडले — मिटले', $at, $userId]);
            }
            $n++;
        }
        if (function_exists('orgSettingSet')) {
            orgSettingSet($pdo, $orgId, 'bill_recon_seed_done', date('Y-m-d H:i:s'));
            if ((string)orgSetting($pdo, $orgId, 'bill_recon_head', '') === '') orgSettingSet($pdo, $orgId, 'bill_recon_head', 'विश्वात्मक जंगली महाराज आश्रम ट्रस्ट, शाखा शहापूर');
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $n;
}
