<?php
/**
 * ✍️ बिल परवानगी पत्रक (Bill Inward for EP Sir Signing).
 *  - खरेदी विभाग एकत्रित बिलांची फाईल बनवतो — फाईलवर लावायचे पत्रक (Sheet No., दिनांक, बिलांचा तक्ता, एकूण, सह्या)
 *  - पत्रक सहीसाठी जमा → मा. कार्यकारी अध्यक्षांची परवानगी झाली की "✅ Done" (पूर्ण पत्रक किंवा बिलनिहाय: परवानगी / थांबवले / नाकारले)
 *  - परवानगी झालेली बिलं → लेखा विभागास जमा (Acc. Dept. पत्रक), थांबलेली / नाकारलेली बिलं पुढच्या पत्रकात
 */
require_once __DIR__ . '/purchase.php';
require_once __DIR__ . '/work-order.php';
require_once __DIR__ . '/app-settings.php';

function bsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM bill_sign_sheets LIMIT 0"); $pdo->query("SELECT 1 FROM bill_sign_items LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/* ---------- Access ---------- */
/** बनवणे, सहीसाठी जमा, Done, लेखा विभाग — Super Admin, खरेदी विभाग, "बिल परवानगी पत्रक" परवानगी */
function bsCan() { return hasRole('super_admin') || canPurchase() || hasPermission('bill_sign'); }
/** पाहणे व Print — वरचे + Management (कार्यकारी अध्यक्ष इ.) + "पाहणे" परवानगी */
function bsCanView() { return bsCan() || isManagementOrAbove() || hasPermission('bill_sign_view'); }
/** परवानगी नोंदवणे (Done / बिलनिहाय निर्णय) — खरेदी विभाग किंवा स्वतः Management */
function bsCanDecide() { return bsCan() || isManagementOrAbove(); }

function bsStatuses() {
    return [
        'draft' => ['📝 तयार होत आहे', 'st-dr'],
        'submitted' => ['📤 सहीसाठी जमा', 'st-sb'],
        'partial' => ['◐ अंशतः परवानगी', 'st-pt'],
        'approved' => ['✅ परवानगी झाली', 'st-ok'],
        'accounts' => ['🏦 लेखा विभागास जमा', 'st-ac'],
    ];
}
function bsItemStatuses() {
    return ['pending' => ['⏳ बाकी', 'it-pn'], 'approved' => ['✅ परवानगी', 'it-ok'], 'hold' => ['⏸ थांबवले', 'it-hd'], 'rejected' => ['✖ नाकारले', 'it-rj']];
}
function bsDocTypes() { return ['Bill', 'PO/Bill', 'PO', 'WO/Bill', 'Magani', 'Tippani', 'Advance', 'Other']; }

/* ---------- सेटिंग्ज ---------- */
function bsSettings(PDO $pdo, $orgId) {
    $lh = (string)orgSetting($pdo, $orgId, 'bs_letterhead', '');
    $j = fn($k, $def) => (($x = json_decode((string)orgSetting($pdo, $orgId, $k, ''), true)) && is_array($x)) ? $x : $def;
    return [
        'letterhead' => $lh !== '' ? $lh : "!! Atma Malik !!\nVishwatmak Jangali Maharaj Ashram Trust,\nMohili-Aghai, Tal. Shahapur, Dist.Thane",
        'title' => (string)orgSetting($pdo, $orgId, 'bs_title', '') ?: 'Purchase Dept Inword Files',
        'subtitle' => (string)orgSetting($pdo, $orgId, 'bs_subtitle', '') ?: 'Inward Files For EP Sir Signing Purpose',
        'submit_to' => (string)orgSetting($pdo, $orgId, 'bs_submit_to', '') ?: "Hon'ble Executive President",
        'signs' => $j('bs_signs', ['Prepared By', 'Asst. Accountant', 'Purchase Committee Head/Secretary', "Hon'ble Executive President"]),
        'acc_title' => (string)orgSetting($pdo, $orgId, 'bs_acc_title', '') ?: "Bill's submitted from Purchase Dept. to the Account Dept.",
        'acc_note' => (string)orgSetting($pdo, $orgId, 'bs_acc_note', '') ?: 'Pls note that all the bills mentioned in this summary have been submitted to the Acc. Dept.',
        'acc_signs' => $j('bs_acc_signs', ['Asst. Accountant', 'Purchase Committee Head/Secretary']),
    ];
}

/* ---------- क्रमांक ---------- */
/** पुढचा Sheet No. — या आर्थिक वर्षाचा सलग क्रम */
function bsNextNo(PDO $pdo, $orgId, $fy) {
    $st = $pdo->prepare("SELECT MAX(sheet_no) FROM bill_sign_sheets WHERE organization_id = ? AND fy = ?"); $st->execute([(int)$orgId, $fy]);
    return (int)$st->fetchColumn() + 1;
}
/** पुढचा टिप्पणी क्रमांक — शेवटच्या आकडी टिप्पणी क्र. च्या पुढचा */
function bsNextTippani(PDO $pdo, $orgId) {
    $st = $pdo->prepare("SELECT MAX(CAST(tippani_no AS UNSIGNED)) FROM bill_sign_items WHERE organization_id = ? AND tippani_no REGEXP '^[0-9]+$'"); $st->execute([(int)$orgId]);
    $n = (int)$st->fetchColumn();
    return $n ? $n + 1 : '';
}
function bsSheetLabel(array $s) { return (int)$s['sheet_no'] ? (string)(int)$s['sheet_no'] : '—'; }

/* ---------- Load / Save ---------- */
function bsLoad(PDO $pdo, $orgId, $id) {
    $st = $pdo->prepare("SELECT s.*, u.name created_name, a.name approved_name, c.name accounts_name FROM bill_sign_sheets s LEFT JOIN users u ON u.id = s.created_by LEFT JOIN users a ON a.id = s.approved_by LEFT JOIN users c ON c.id = s.accounts_by WHERE s.id = ? AND s.organization_id = ?");
    $st->execute([(int)$id, (int)$orgId]);
    $s = $st->fetch(); if (!$s) return null;
    $st = $pdo->prepare("SELECT * FROM bill_sign_items WHERE sheet_id = ? ORDER BY sort_order, id"); $st->execute([(int)$id]); $s['_items'] = $st->fetchAll();
    return $s;
}
/** ओळी → बिलांचे गट (Sr. नुसार; उप-ओळी त्याच बिलाखाली) */
function bsGroups(array $items) {
    $g = []; $k = -1;
    foreach ($items as $it) { if (!$it['is_sub'] || $k < 0) { $k++; $g[$k] = []; } $g[$k][] = $it; }
    return $g;
}
/** गटाची स्थिती = पहिल्या ओळीची */
function bsGroupStatus(array $grp) { return $grp[0]['status'] ?? 'pending'; }
function bsGroupAmount(array $grp) { return array_sum(array_map(fn($x) => (float)$x['amount'], $grp)); }

function bsLog(PDO $pdo, $orgId, $id, $event, $note, $uid) {
    try { $pdo->prepare("INSERT INTO bill_sign_events (sheet_id, organization_id, event, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([(int)$id, (int)$orgId, $event, $note !== null ? mb_substr((string)$note, 0, 500) : null, $uid ?: null]); } catch (PDOException $e) {}
}

/** एकूण रक्कम, बिलांची संख्या — आणि सहीसाठी जमा असेल तर बिलनिहाय निर्णयांवरून स्थिती */
function bsRecalc(PDO $pdo, $orgId, $id, $uid = null) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) t, SUM(is_sub = 0) n, SUM(is_sub = 0 AND status = 'pending') pn, SUM(is_sub = 0 AND status = 'approved') ok FROM bill_sign_items WHERE sheet_id = ?"); $st->execute([(int)$id]);
    $r = $st->fetch();
    $pdo->prepare("UPDATE bill_sign_sheets SET total_amount = ?, n_bills = ? WHERE id = ? AND organization_id = ?")->execute([(float)$r['t'], (int)$r['n'], (int)$id, (int)$orgId]);
    $st = $pdo->prepare("SELECT status FROM bill_sign_sheets WHERE id = ?"); $st->execute([(int)$id]); $cur = (string)$st->fetchColumn();
    if (in_array($cur, ['submitted', 'partial', 'approved'], true) && (int)$r['n']) {
        $new = (int)$r['pn'] === (int)$r['n'] ? 'submitted' : ((int)$r['ok'] === (int)$r['n'] ? 'approved' : 'partial');
        if ($new !== $cur) {
            $pdo->prepare("UPDATE bill_sign_sheets SET status = ?, approved_at = " . ($new === 'submitted' ? 'NULL' : 'COALESCE(approved_at, NOW())') . ", approved_by = " . ($new === 'submitted' ? 'NULL' : 'COALESCE(approved_by, ?)') . " WHERE id = ?")
                ->execute($new === 'submitted' ? [$new, (int)$id] : [$new, $uid, (int)$id]);
        }
        return $new;
    }
    return $cur;
}

/**
 * जतन. $d = bill_sign_sheets चे रकाने, $items = [[is_sub, doc_type, tippani_no, party, particular, paid_by, bill_no, bill_date, amount, inward_date, material_date, return_date, remark, status], …]
 * नवीन असेल आणि sheet_no दिला नसेल तर पुढचा क्रमांक (DB lock). परत: id
 */
function bsSave(PDO $pdo, $orgId, $uid, array $d, array $items, $id = 0, $source = 'manual') {
    $lock = "eowms_bs_" . (int)$orgId;
    $pdo->query("SELECT GET_LOCK('$lock', 10)");
    try {
        $pdo->beginTransaction();
        if ($id) {
            $cols = array_keys($d);
            $pdo->prepare("UPDATE bill_sign_sheets SET " . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ", updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([...array_values($d), $uid, $id, $orgId]);
            $pdo->prepare("DELETE FROM bill_sign_items WHERE sheet_id = ?")->execute([$id]);
        } else {
            if (!isset($d['sheet_no']) || $d['sheet_no'] === '' || $d['sheet_no'] === null) $d['sheet_no'] = bsNextNo($pdo, $orgId, $d['fy']);
            $cols = array_keys($d);
            $pdo->prepare("INSERT INTO bill_sign_sheets (organization_id, " . implode(', ', $cols) . ", source, created_by, created_at) VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, NOW())")
                ->execute([$orgId, ...array_values($d), $source, $uid]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO bill_sign_items (sheet_id, organization_id, sort_order, sr, is_sub, doc_type, tippani_no, party, particular, paid_by, bill_no, bill_date, amount, inward_date, material_date, return_date, remark, status, decided_at, decided_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $sr = 0; $grpStatus = 'pending';
        foreach (array_values($items) as $i => $it) {
            $sub = $it[0] && $sr ? 1 : 0;
            if (!$sub) { $sr++; $grpStatus = $it[13] ?? 'pending'; }
            $nz = fn($v) => ($v = trim((string)$v)) === '' ? null : $v;
            $stt = isset(bsItemStatuses()[$grpStatus]) ? $grpStatus : 'pending';
            $ins->execute([$id, $orgId, $i, $sr, $sub, $nz($it[1]), $nz($it[2]), $nz($it[3]), $nz($it[4]), $nz($it[5]), $nz($it[6]), $nz($it[7]), $it[8] === '' || $it[8] === null ? null : round((float)$it[8], 2),
                $nz($it[9]), $nz($it[10]), $nz($it[11]), $nz($it[12]), $stt, $stt !== 'pending' ? date('Y-m-d H:i:s') : null, $stt !== 'pending' ? $uid : null]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->query("SELECT RELEASE_LOCK('$lock')"); throw $e; }
    $pdo->query("SELECT RELEASE_LOCK('$lock')");
    bsRecalc($pdo, $orgId, $id, $uid);
    return $id;
}

/**
 * स्थिती बदल: submit (सहीसाठी जमा), approve_all (सर्व बिलांना परवानगी — Done), accounts (लेखा विभागास जमा), reopen (पुन्हा तयार).
 * परत: नवीन स्थिती किंवा null
 */
function bsSetStatus(PDO $pdo, $orgId, array $s, $act, $uid, $note = '') {
    $id = (int)$s['id']; $cur = $s['status']; $now = date('Y-m-d H:i:s'); $today = date('d.m.Y');
    if ($act === 'submit' && $cur === 'draft') {
        $pdo->prepare("UPDATE bill_sign_sheets SET status = 'submitted', submitted_at = ?, submitted_to = COALESCE(NULLIF(?, ''), submitted_to), updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$now, $note, $uid, $id]);
        bsLog($pdo, $orgId, $id, 'submitted', 'सहीसाठी जमा' . ($note ? ' — ' . $note : ''), $uid); return 'submitted';
    }
    if ($act === 'approve_all' && in_array($cur, ['submitted', 'partial'], true)) {
        $pdo->prepare("UPDATE bill_sign_items SET status = 'approved', decided_at = ?, decided_by = ?, return_date = COALESCE(return_date, ?) WHERE sheet_id = ? AND status IN ('pending', 'hold')")->execute([$now, $uid, $today, $id]);
        bsRecalc($pdo, $orgId, $id, $uid);
        $pdo->prepare("UPDATE bill_sign_sheets SET approved_at = COALESCE(approved_at, ?), approved_by = COALESCE(approved_by, ?) WHERE id = ?")->execute([$now, $uid, $id]);
        bsLog($pdo, $orgId, $id, 'approved', '✅ परवानगी झाली (Done)' . ($note ? ' — ' . $note : ''), $uid);
        $st = $pdo->prepare("SELECT status FROM bill_sign_sheets WHERE id = ?"); $st->execute([$id]); return (string)$st->fetchColumn();
    }
    if ($act === 'accounts' && in_array($cur, ['approved', 'partial'], true)) {
        $pdo->prepare("UPDATE bill_sign_sheets SET status = 'accounts', accounts_at = ?, accounts_by = ?, accounts_note = ?, updated_at = NOW() WHERE id = ?")->execute([$now, $uid, $note ?: null, $id]);
        bsLog($pdo, $orgId, $id, 'accounts', '🏦 लेखा विभागास जमा' . ($note ? ' — ' . $note : ''), $uid); return 'accounts';
    }
    if ($act === 'reopen' && $cur !== 'draft') {
        $back = $cur === 'accounts' ? 'approved' : 'draft';
        if ($back === 'draft') {
            $pdo->prepare("UPDATE bill_sign_sheets SET status = 'draft', submitted_at = NULL, approved_at = NULL, approved_by = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$uid, $id]);
            $pdo->prepare("UPDATE bill_sign_items SET status = 'pending', decided_at = NULL, decided_by = NULL, decision_note = NULL WHERE sheet_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE bill_sign_sheets SET status = 'approved', accounts_at = NULL, accounts_by = NULL, accounts_note = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
            bsRecalc($pdo, $orgId, $id, $uid);
        }
        bsLog($pdo, $orgId, $id, 'reopen', '↩ पुन्हा उघडले' . ($note ? ' — ' . $note : ''), $uid);
        $st = $pdo->prepare("SELECT status FROM bill_sign_sheets WHERE id = ?"); $st->execute([$id]); return (string)$st->fetchColumn();
    }
    return null;
}

/** "Bill Inward To EP So. Sign 2026-27" Excel मधील पत्रके — एकदाच */
function bsSeedImport(PDO $pdo, $orgId, $uid) {
    if (orgSetting($pdo, $orgId, 'bs_seed_done', '') !== '') return 0;
    $D = require __DIR__ . '/bill-sign-seed.php'; $n = 0;
    $cut = '2026-11-01';   // यानंतरची पत्रके — परवानगी अजून बाकी (सहीसाठी जमा)
    foreach ($D as $sh) {
        $date = $sh['date'] ?: date('Y-m-d'); $fy = purchaseFy($date);
        if ((int)$sh['no']) { $st = $pdo->prepare("SELECT id FROM bill_sign_sheets WHERE organization_id = ? AND fy = ? AND sheet_no = ? AND source = 'import'"); $st->execute([$orgId, $fy, (int)$sh['no']]); if ($st->fetchColumn()) continue; }
        $status = $sh['acc'] ? 'accounts' : ($date >= $cut ? 'submitted' : 'approved');
        $itSt = $status === 'submitted' ? 'pending' : 'approved';
        $items = array_map(fn($r) => [$r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9], $r[10], $r[11], $r[12], $r[13], $itSt], $sh['items']);
        $d = ['fy' => $fy, 'sheet_no' => (int)$sh['no'], 'sheet_date' => $date, 'status' => $status, 'submitted_at' => $date . ' 12:00:00',
            'approved_at' => $status !== 'submitted' ? $date . ' 18:00:00' : null, 'accounts_at' => $sh['acc'] ? $sh['acc'] . ' 18:00:00' : null];
        $id = bsSave($pdo, $orgId, $uid, $d, $items, 0, 'import');
        bsLog($pdo, $orgId, $id, 'imported', 'Excel मधून (Bill Inward To EP So. Sign 2026-27)', $uid);
        $n++;
    }
    orgSettingSet($pdo, $orgId, 'bs_seed_done', date('Y-m-d H:i:s'));
    return $n;
}

/** सहीसाठी जमा केल्यापासून दिवस */
function bsAgeDays(array $s) { return $s['submitted_at'] ? max(0, (int)floor((time() - strtotime($s['submitted_at'])) / 86400)) : 0; }
