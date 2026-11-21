<?php
/**
 * 🧾 वर्क ऑर्डर (Work Order).
 *  - खरेदी विभाग (व "सर्व विभाग" परवानगी, Super Admin): सर्व विभागांच्या वर्क ऑर्डर — केंद्रीय यादी, नवीन, बदल, अहवाल
 *  - इतर विभाग (HOD किंवा "स्वतःच्या विभागाची" परवानगी): फक्त स्वतःच्या विभागाच्या वर्क ऑर्डर बनवणे / पाहणे
 *  - W.O. क्रमांक: संपूर्ण संस्थेत आर्थिक वर्षनिहाय एकच सलग क्रम — कोणत्याही विभागाने बनवली तरी शेवटच्या क्रमांकाच्या पुढचा
 */
require_once __DIR__ . '/purchase.php';
require_once __DIR__ . '/app-settings.php';

function woReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM work_orders LIMIT 0"); $pdo->query("SELECT 1 FROM work_order_items LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/* ---------- Access ---------- */
/** केंद्रीय (सर्व विभाग): Super Admin, खरेदी विभाग, "वर्क ऑर्डर — सर्व विभाग" परवानगी */
function woCanAll() { return hasRole('super_admin') || canPurchase() || hasPermission('work_order_all'); }
/** सर्व विभागांच्या वर्क ऑर्डर पाहणे — वरचे + Management (कार्यकारी अध्यक्ष इ.) */
function woCanViewAll() { return woCanAll() || isManagementOrAbove(); }
/** स्वतःचे विभाग — विभाग + Super Admin ने दिलेले जास्तीचे विभाग */
function woMyDepts() {
    global $pdo;
    static $ids = null;
    if ($ids !== null) return $ids;
    $u = currentUser(); $ids = [];
    if (!$u) return $ids;
    if (!empty($u['department_id'])) $ids[] = (int)$u['department_id'];
    try { $st = $pdo->prepare("SELECT department_id FROM user_extra_department_access WHERE user_id = ?"); $st->execute([(int)$u['id']]); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $ids[] = (int)$d; } catch (PDOException $e) {}
    return $ids = array_values(array_unique($ids));
}
/** विभागस्तरीय वर्क ऑर्डर — HOD किंवा परवानगी, आणि विभाग असेल तर */
function woCanDept() { return (hasCategory('hod') || hasPermission('work_order_dept')) && woMyDepts(); }
function woCanCreate() { return woCanAll() || woCanDept(); }
function woCanUse() { return woCanCreate() || woCanViewAll(); }
function woCanSee(array $w) { return woCanViewAll() || (woCanDept() && in_array((int)$w['department_id'], woMyDepts(), true)); }
/** बदल: केंद्रीय सगळ्या; विभाग — स्वतःच्या विभागाच्या (रद्द / पूर्ण नसलेल्या) */
function woCanModify(array $w) { return woCanAll() || (woCanDept() && in_array((int)$w['department_id'], woMyDepts(), true) && in_array($w['status'], ['draft', 'issued'], true)); }

function woStatuses() {
    return ['draft' => ['📝 तयार होत आहे', 'st-dr'], 'issued' => ['✅ दिली (Issued)', 'st-is'], 'completed' => ['🏁 काम पूर्ण', 'st-dn'], 'cancelled' => ['✕ रद्द', 'st-cx']];
}

/* ---------- क्रमांक ---------- */
/** या आर्थिक वर्षाचा पुढचा W.O. क्रमांक — सगळ्या विभागांचा मिळून एकच सलग क्रम */
function woNextNum(PDO $pdo, $orgId, $fy) {
    $st = $pdo->prepare("SELECT MAX(wo_num) FROM work_orders WHERE organization_id = ? AND fy = ?"); $st->execute([(int)$orgId, $fy]);
    return (int)$st->fetchColumn() + 1;
}
function woFmtNo($n) { return sprintf('%03d', (int)$n); }
/** "003/2026-27" */
function woFullNo(array $w) { return $w['wo_no'] . '/' . $w['fy']; }

/** रक्कम → इंग्रजी शब्द (भारतीय पद्धत): 21240 → "Twenty One Thousand Two Hundred Forty Rupees Only" */
function woWords($amount) {
    $amount = round((float)$amount, 2);
    $r = (int)floor($amount); $p = (int)round(($amount - $r) * 100);
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn($x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    $three = fn($x) => trim(($x >= 100 ? $ones[intdiv($x, 100)] . ' Hundred ' : '') . $two($x % 100));
    $w = function ($n) use (&$w, $two, $three) {
        if ($n === 0) return '';
        $parts = [];
        $cr = intdiv($n, 10000000); $n %= 10000000;
        if ($cr) $parts[] = ($cr > 99 ? $w($cr) : $two($cr)) . ' Crore';
        foreach ([[100000, 'Lakh'], [1000, 'Thousand']] as [$d, $l]) { $x = intdiv($n, $d); $n %= $d; if ($x) $parts[] = $two($x) . ' ' . $l; }
        if ($n) $parts[] = $three($n);
        return implode(' ', $parts);
    };
    $s = $r ? $w($r) . ' Rupees' : 'Zero Rupees';
    if ($p) $s .= ' and ' . $two($p) . ' Paise';
    return $s . ' Only';
}

/** रक्कम — 1,23,456.00 */
function woMoney($n, $dec = 2) {
    if ($n === null || $n === '') return '';
    $n = round((float)$n, $dec); $neg = $n < 0; $n = abs($n);
    $int = (string)floor($n); $frac = $dec ? '.' . str_pad((string)round(($n - floor($n)) * pow(10, $dec)), $dec, '0', STR_PAD_LEFT) : '';
    $l3 = substr($int, -3); $rest = substr($int, 0, -3);
    if ($rest !== '') $l3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $l3;
    return ($neg ? '-' : '') . $l3 . $frac;
}

/** नग / प्रमाण मधला अंक (उदा. "4 Days + Sunday" → 4, "96 sqcm" → 96) */
function woQtyNum($q) { return preg_match('/^\s*([\d.]+)/', (string)$q, $m) ? (float)$m[1] : null; }

/* ---------- सेटिंग्ज (letterhead, अटी, सह्या) ---------- */
function woDefaultTerms() {
    return [['All safety rules must be followed.', 'YES'], ['Additional work/ Extra work :- Any additional work should be approved by the Trust.', ''],
        ['Rates:- The above rates are firm. Rate shall not be subject to any escalation till completion of work / supply.', ''], ['GST:- As applicable.', ''], ['TDS:- As per Applicable.', ''],
        ['Payment Terms:- Payment would be made after completion of work through Cheque / Bank.', ''], ['Billing instruction:- Please raise your Bills/invoice in favour of the Trust with a reference to this work order.', ''],
        ['Quality:- Material / work strictly in accordance with our order and specification.', '']];
}
function woSettings(PDO $pdo, $orgId) {
    static $c = [];
    if (isset($c[(int)$orgId])) return $c[(int)$orgId];
    $g = fn($k) => trim((string)orgSetting($pdo, $orgId, $k, ''));
    $sd = [];
    if ($g('wo_seed_done') !== '') { require_once __DIR__ . '/work-order-seed.php'; $sd = woSeedData(); }
    $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $st->execute([(int)$orgId]); $org = (string)$st->fetchColumn();
    $terms = json_decode($g('wo_terms'), true);
    $pay = json_decode($g('wo_payment'), true);
    return $c[(int)$orgId] = [
        'letterhead' => $g('wo_letterhead') ?: ($sd['letterhead'] ?? $org),
        'sign_org' => $g('wo_sign_org') ?: ($sd['sign_org'] ?? $org),
        'footer' => $g('wo_footer') ?: ($sd['footer'] ?? ('For ' . $org)),
        'terms' => is_array($terms) && $terms ? $terms : ($sd['terms'] ?? woDefaultTerms()),
        'payment' => is_array($pay) ? $pay : ($sd['payment'] ?? ['01) Name of the Payee in Block Letters:-', '02) Address:-', '03) Bank A/c No. of Payee:-', '04) Name of the Bank:-', '05) Name of the Branch and Branch Code:-', '06) PAN Card No. Of Payee:-', '07) IFS Code:']),
    ];
}
function woDefaultSigns($deptName) { return [trim(($deptName ?: 'Department') . ' / HOD'), 'Purchase Committee', 'Executive President']; }
/** रकान्यांची नावं */
function woLabelPresets() {
    return ['qty' => ['Qty', 'Unit', 'Rate', 'नग / Unit / दर'], 'year' => ['Year', 'Unit/ Year', 'Rate Per YEAR', 'वार्षिक (AMC, विमा, नूतनीकरण)'], 'day' => ['Qty', 'Unit/ Day', 'Rate Per Day', 'दिवसानुसार (जाहिरात, भाडे)'],
        'sqft' => ['Quantity', 'SQFT', 'Rate', 'क्षेत्रफळ (रंगकाम, बांधकाम)'], 'brass' => ['Quantity', 'Brass', 'Rate', 'ब्रास (रंगकाम / मजुरी)']];
}
function woLabels(array $w) { $l = json_decode((string)($w['labels'] ?? ''), true); return ['qty' => $l['qty'] ?? 'Qty', 'unit' => $l['unit'] ?? 'Unit', 'rate' => $l['rate'] ?? 'Rate']; }

/** विभागाचं नाव / शब्द → departments.id */
function woDeptMatch(PDO $pdo, $orgId, $re) {
    if (!$re) return null;
    $st = $pdo->prepare("SELECT id, name, name_local, code FROM departments WHERE organization_id = ? AND is_active = 1"); $st->execute([(int)$orgId]);
    foreach ($st->fetchAll() as $d) if (@preg_match('/' . $re . '/iu', $d['name'] . ' ' . $d['name_local'] . ' ' . $d['code'])) return (int)$d['id'];
    return null;
}
function woDeptName(PDO $pdo, $orgId, $id) {
    if (!$id) return '';
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(name_local, ''), name) FROM departments WHERE id = ? AND organization_id = ?"); $st->execute([(int)$id, (int)$orgId]);
    return (string)$st->fetchColumn();
}

function woLog(PDO $pdo, $orgId, $id, $event, $note, $uid) {
    try { $pdo->prepare("INSERT INTO work_order_events (work_order_id, organization_id, event, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([(int)$id, (int)$orgId, $event, $note === null ? null : mb_substr((string)$note, 0, 500), $uid]); } catch (PDOException $e) {}
}

function woLoad(PDO $pdo, $orgId, $id) {
    $st = $pdo->prepare("SELECT w.*, u.name created_name FROM work_orders w LEFT JOIN users u ON u.id = w.created_by WHERE w.id = ? AND w.organization_id = ?"); $st->execute([(int)$id, (int)$orgId]);
    $w = $st->fetch(); if (!$w) return null;
    $st = $pdo->prepare("SELECT * FROM work_order_items WHERE work_order_id = ? ORDER BY sort_order, id"); $st->execute([(int)$id]); $w['_items'] = $st->fetchAll();
    return $w;
}

/**
 * जतन — नवीन असेल तर संस्थेचा पुढचा सलग क्रमांक (एकाच वेळी दोन विभागांनी बनवली तरी दुबार नाही — DB lock).
 * $d = work_orders चे रकाने, $items = [[particular, qty, unit, rate, amount], …]. परत: id
 */
function woSave(PDO $pdo, $orgId, $uid, array $d, array $items, $id = 0, $source = 'manual') {
    $pdo->query("SELECT GET_LOCK('eowms_wo_" . (int)$orgId . "', 10)");
    try {
        $pdo->beginTransaction();
        if ($id) {
            $cols = array_keys($d);
            $pdo->prepare("UPDATE work_orders SET " . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ", updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([...array_values($d), $uid, $id, $orgId]);
            $pdo->prepare("DELETE FROM work_order_items WHERE work_order_id = ?")->execute([$id]);
        } else {
            if (empty($d['wo_num'])) { $d['wo_num'] = woNextNum($pdo, $orgId, $d['fy']); $d['wo_no'] = woFmtNo($d['wo_num']); }
            $cols = array_keys($d);
            $pdo->prepare("INSERT INTO work_orders (organization_id, " . implode(', ', $cols) . ", source, created_by, created_at) VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, NOW())")
                ->execute([$orgId, ...array_values($d), $source, $uid]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO work_order_items (work_order_id, sort_order, particular, qty, unit, rate, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach (array_values($items) as $i => $it) $ins->execute([$id, $i, $it[0], $it[1] !== '' ? $it[1] : null, $it[2] !== '' ? $it[2] : null, $it[3], $it[4]]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->query("SELECT RELEASE_LOCK('eowms_wo_" . (int)$orgId . "')"); throw $e; }
    $pdo->query("SELECT RELEASE_LOCK('eowms_wo_" . (int)$orgId . "')");
    return $id;
}

/** Word file मधील 11 वर्क ऑर्डर — एकदाच */
function woSeedImport(PDO $pdo, $orgId, $uid) {
    if (orgSetting($pdo, $orgId, 'wo_seed_done', '') !== '') return 0;
    require_once __DIR__ . '/work-order-seed.php';
    $D = woSeedData(); $n = 0;
    foreach ($D['orders'] as $o) {
        $st = $pdo->prepare("SELECT id FROM work_orders WHERE organization_id = ? AND fy = ? AND wo_no = ?"); $st->execute([$orgId, $o['fy'], $o['wo_no']]);
        if ($st->fetchColumn()) continue;
        $d = ['fy' => $o['fy'], 'wo_num' => $o['wo_num'], 'wo_no' => $o['wo_no'], 'wo_date' => $o['wo_date'], 'department_id' => woDeptMatch($pdo, $orgId, $o['dept_re']), 'department_name' => $o['department_name'] ?: null,
            'vendor_name' => $o['vendor_name'], 'vendor_address' => $o['vendor_address'] ?: null, 'vendor_phone' => $o['vendor_phone'] ?: null, 'vendor_email' => $o['vendor_email'] ?: null, 'attn' => $o['attn'] ?: null,
            'subject' => $o['subject'], 'labels' => json_encode($o['labels'], JSON_UNESCAPED_UNICODE), 'subtotal' => $o['subtotal'], 'gst_pct' => $o['gst_pct'], 'gst_amount' => $o['gst_amount'], 'round_off' => $o['round_off'],
            'grand_total' => $o['grand_total'], 'purpose' => $o['purpose'] ?: null, 'taxes_note' => $o['taxes'] ?: null, 'execution_note' => $o['execution'] ?: null, 'completion_note' => $o['completion'] ?: null,
            'terms_json' => json_encode($o['terms'], JSON_UNESCAPED_UNICODE), 'payment_json' => json_encode($o['payment_details'], JSON_UNESCAPED_UNICODE), 'signs_json' => json_encode($o['signs'], JSON_UNESCAPED_UNICODE), 'status' => 'issued'];
        $items = array_map(fn($i) => [$i['particular'], (string)$i['qty'], (string)$i['unit'], $i['rate'], $i['amount']], $o['items']);
        $id = woSave($pdo, $orgId, $uid, $d, $items, 0, 'seed');
        woLog($pdo, $orgId, $id, 'imported', 'Word file मधून (Work_Order_New_2026-27)', $uid);
        $n++;
    }
    orgSettingSet($pdo, $orgId, 'wo_seed_done', date('Y-m-d H:i:s'));
    return $n;
}

/** "2026-05" → "मे 2026" */
function woMonthName($ym) {
    $m = [1 => 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
    $t = strtotime($ym . '-01');
    return ($m[(int)date('n', $t)] ?? '') . ' ' . date('Y', $t);
}
