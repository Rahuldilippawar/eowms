<?php
/**
 * 🛒 खरेदी विभाग — खरेदी बाकी / खरेदी पूर्ण.
 * मागणी (विभाग, क्र., दिनांक) → मंजुरी → खरेदी आवक / आदेश → खरेदी पूर्ण (दिनांक, प्रत्यक्ष रक्कम, बिल, पुरवठादार).
 * "खरेदी पूर्ण" केल्यावर नोंद बाकी यादीतून "खरेदी पूर्ण" यादीत जाते. प्रत्येक बदलाचा इतिहास (purchase_request_events).
 */
require_once __DIR__ . '/purchase-seed.php';
require_once __DIR__ . '/app-settings.php';

function purchaseReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM purchase_requests LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** नोंद / बदल / खरेदी पूर्ण / अहवाल — Super Admin किंवा "खरेदी विभाग" परवानगी */
function canPurchase() { return hasRole('super_admin') || hasPermission('manage_purchase') || (function_exists('moduleUserDeptCode') && moduleUserDeptCode() === 'PUR'); }
/** पाहणे व अहवाल — वरचे + Management */
function canViewPurchase() { return canPurchase() || isManagementOrAbove(); }

/** आर्थिक वर्ष (एप्रिल–मार्च): 2026-11-20 → "2026-27" */
function purchaseFy($date = null) {
    $t = $date ? strtotime($date) : time();
    $y = (int)date('Y', $t); if ((int)date('n', $t) < 4) $y--;
    return $y . '-' . substr((string)($y + 1), -2);
}

function purchaseFyLabel($fy) { return preg_match('/^(\d{4})-(\d{2})$/', (string)$fy, $m) ? $m[1] . '-' . ((int)substr($m[1], 0, 2) . $m[2]) : (string)$fy; }

/** इंग्रजी अंक → मराठी अंक (छापील अहवालासाठी) */
function mrDigits($s) { return strtr((string)$s, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']); }

function purchaseDate($d) { return $d ? date('d.m.Y', strtotime($d)) : ''; }

/** रक्कम ₹ 1,23,456 */
function purchaseInr($n) {
    if ($n === null || $n === '') return '—';
    $n = round((float)$n); $s = (string)abs($n); $l3 = substr($s, -3); $rest = substr($s, 0, -3);
    if ($rest !== '') $l3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $l3;
    return ($n < 0 ? '-' : '') . '₹' . $l3;
}

/** टप्पा — मंजुरी बाकी / आवक-आदेश बाकी / खरेदी प्रक्रियेत / खरेदी पूर्ण / रद्द */
function purchaseStage(array $r) {
    if (($r['status'] ?? '') === 'done') return ['done', '✅ खरेदी पूर्ण'];
    if (($r['status'] ?? '') === 'cancelled') return ['cancel', '✕ रद्द'];
    // काही वस्तू खरेदी झाल्या, काही बाकी (purchaseItemsAttach() ने _it_total / _it_done भरलेले असतील तर)
    if (!empty($r['_it_total']) && !empty($r['_it_done']) && $r['_it_done'] < $r['_it_total']) return ['partial', '🧩 अंशतः खरेदी'];
    if (!empty($r['inward_no']) || !empty($r['inward_date'])) return ['process', '🛒 खरेदी प्रक्रियेत'];
    if (!empty($r['approval_no']) || !empty($r['approval_date'])) return ['order', '📝 आवक / आदेश बाकी'];
    return ['approve', '⏳ मंजुरी बाकी'];
}

/** मागणीपासून (नसेल तर आवक/नोंद) किती दिवस */
function purchaseAgeDays(array $r) {
    $ref = $r['demand_date'] ?: ($r['approval_date'] ?: ($r['inward_date'] ?: substr((string)$r['created_at'], 0, 10)));
    $end = ($r['status'] ?? '') === 'done' && $r['done_on'] ? strtotime($r['done_on']) : time();
    return $ref ? max(0, (int)floor(($end - strtotime($ref)) / 86400)) : null;
}

function purchaseLog(PDO $pdo, $orgId, $reqId, $event, $note = null, $userId = null) {
    try {
        $pdo->prepare("INSERT INTO purchase_request_events (request_id, organization_id, event, note, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([(int)$reqId, (int)$orgId, $event, $note !== null ? mb_substr($note, 0, 500) : null, $userId]);
    } catch (PDOException $e) {}
}

function purchaseEventLabels() {
    return ['created' => '🆕 नोंद केली', 'edited' => '✎ बदल केला', 'done' => '✅ खरेदी पूर्ण', 'reopened' => '↺ पुन्हा बाकी', 'cancelled' => '✕ रद्द', 'imported' => '📥 PDF मधून आयात', 'items' => '🧩 वस्तू खरेदी झाल्या', 'item_undo' => '↺ वस्तू पुन्हा बाकी'];
}

/** पुढचा अ.क्र. (त्या आर्थिक वर्षात) */
function purchaseNextSr(PDO $pdo, $orgId, $fy) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(sr_no), 0) + 1 FROM purchase_requests WHERE organization_id = ?");
    $st->execute([$orgId]);
    return (int)$st->fetchColumn();
}

/**
 * पुढचा खरेदी आवक क्र. (आपोआप) — त्या आर्थिक वर्षात आधी वापरलेल्या पद्धतीनुसार:
 *  "Nov.009" अशी महिनानिहाय पद्धत वापरली असेल तर → त्या महिन्याचा पुढचा ("Nov.012"; नवीन महिना → "Dec.001"),
 *  नाहीतर साधा क्रमांक → सर्वात मोठा + 1 (उदा. 204 → 205).
 */
function purchaseNextInward(PDO $pdo, $orgId, $date = null, $exceptId = 0) {
    $date = $date ?: date('Y-m-d');
    $st = $pdo->prepare("SELECT inward_no FROM purchase_requests WHERE organization_id = ? AND fy = ? AND inward_no IS NOT NULL AND inward_no <> '' AND id <> ?");
    $st->execute([(int)$orgId, purchaseFy($date), (int)$exceptId]);
    $nos = $st->fetchAll(PDO::FETCH_COLUMN);
    $monthStyle = false; $maxM = 0; $maxN = 0; $mon = date('M', strtotime($date));
    foreach ($nos as $n) {
        $n = trim($n);
        if (preg_match('/^([A-Za-z]{3})\.?\s*(\d+)$/', $n, $m)) { $monthStyle = true; if (strcasecmp($m[1], $mon) === 0) $maxM = max($maxM, (int)$m[2]); }
        elseif (preg_match('/^(\d+)$/', $n, $m)) $maxN = max($maxN, (int)$m[1]);
    }
    return $monthStyle ? $mon . '.' . str_pad((string)($maxM + 1), 3, '0', STR_PAD_LEFT) : (string)($maxN + 1);
}

/** विभागांची नावं — आधी वापरलेली + संस्थेचे विभाग (सुचवण्यासाठी) */
function purchaseDepartments(PDO $pdo, $orgId) {
    $n = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT department FROM purchase_requests WHERE organization_id = ? ORDER BY department");
        $st->execute([$orgId]); $n = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
    try {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(name_local, ''), name) FROM departments WHERE organization_id = ? ORDER BY 1");
        $st->execute([$orgId]); $n = array_merge($n, $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {}
    return array_values(array_unique(array_filter(array_map('trim', $n))));
}

/** अहवालाचं शीर्षक (3 ओळी) — संस्था सेटिंग; नसेल तर संस्थेचं नाव */
function purchaseReportHead(PDO $pdo, $orgId, $orgName) {
    $v = function_exists('orgSetting') ? (string)orgSetting($pdo, $orgId, 'purchase_report_head', '') : '';
    return $v !== '' ? $v : "!! आत्मा मालिक !!\n" . $orgName;
}

/** PDF मधील 66 नोंदी एकदाच आयात — परत: घातलेल्या नोंदी */
function purchaseSeedImport(PDO $pdo, $orgId, $userId) {
    if (function_exists('orgSetting') && orgSetting($pdo, $orgId, 'purchase_seed_done', '') !== '') return 0;
    $ins = $pdo->prepare("INSERT INTO purchase_requests (organization_id, fy, sr_no, department, demand_no, demand_date, approval_no, approval_date, inward_no, inward_date, title, items, est_amount, pending_reason, remarks, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,NOW())");
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach (purchaseSeedRows() as $r) {
            $ins->execute([$orgId, $r['fy'], $r['sr_no'], $r['department'], $r['demand_no'] ?: null, $r['demand_date'], $r['approval_no'] ?: null, $r['approval_date'],
                $r['inward_no'] ?: null, $r['inward_date'], $r['title'], $r['items'] ?: null, $r['est_amount'], $r['pending_reason'] ?: null, $r['remarks'] ?: null, $userId]);
            purchaseLog($pdo, $orgId, (int)$pdo->lastInsertId(), 'imported', 'खरेदी बाकी तक्ता — दिनांक 13.11.2026 (PDF)', $userId);
            $n++;
        }
        if (function_exists('orgSettingSet')) {
            orgSettingSet($pdo, $orgId, 'purchase_seed_done', date('Y-m-d H:i:s'));
            if ((string)orgSetting($pdo, $orgId, 'purchase_report_head', '') === '') {
                orgSettingSet($pdo, $orgId, 'purchase_report_head', "!! आत्मा मालिक !!\nविश्वात्मक जंगली महाराज आश्रम ट्रस्ट,\nमोहिली, अघई, ता. शहापूर, जी. ठाणे");
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $n;
}


/* ======================= 🧩 मागणीतील वस्तूनिहाय खरेदी ======================= */

function purchaseItemsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM purchase_request_items LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** "आरसे - 30 नग" → ['आरसे', 30, 'नग']; नग नसेल तर qty null */
function purchaseParseItemLine($line) {
    $line = trim(preg_replace('/\s+/u', ' ', (string)$line));
    if (preg_match('/^(.*?)\s*[-–—:=]\s*([\d.]+)\s*(\S.*)?$/u', $line, $m) && $m[1] !== '' && is_numeric($m[2])) {
        $u = trim($m[3] ?? '');
        if (mb_strlen($u) > 15) { $w = preg_split('/\s+/u', $u, 2); return [trim($m[1] . ' — ' . ($w[1] ?? '')), (float)$m[2], mb_substr($w[0], 0, 40)]; }   // "2 Nos (3 Year Warranty)" → Unit "Nos", बाकी नावात
        return [trim($m[1]), (float)$m[2], $u];
    }
    if (preg_match('/^(.*\S)\s+([\d.]+)\s*(नग|nos|no|pcs|pc|kg|किलो|ltr|लिटर|liter|mtr|meter|मीटर|box|pkt|set|pair|bundle|jodi|जोडी|rim|बॉक्स)\.?$/iu', $line, $m) && is_numeric($m[2])) return [trim($m[1]), (float)$m[2], $m[3]];
    return [$line, null, ''];
}
function purchaseItemKey($name) { return mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string)$name)), 0, 300); }

/** साहित्य यादी (मजकूर) → वस्तूंच्या ओळी. आधीची खरेदी स्थिती जपते; काढलेल्या (बाकी) वस्तू हटवते. */
function purchaseItemsSync(PDO $pdo, $orgId, $reqId, $itemsText) {
    if (!purchaseItemsReady($pdo)) return;
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$itemsText)), 'strlen'));
    $st = $pdo->prepare("SELECT * FROM purchase_request_items WHERE request_id = ? AND organization_id = ?"); $st->execute([(int)$reqId, (int)$orgId]);
    $have = []; foreach ($st->fetchAll() as $r) $have[$r['name_key']][] = $r;
    $keep = [];
    $ins = $pdo->prepare("INSERT INTO purchase_request_items (request_id, organization_id, sort_order, name, name_key, qty, unit, updated_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $upd = $pdo->prepare("UPDATE purchase_request_items SET sort_order = ?, name = ?, qty = ?, unit = ?, status = CASE WHEN status = 'done' AND ? IS NOT NULL AND qty_done < ? THEN 'pending' WHEN status = 'pending' AND ? IS NOT NULL AND qty_done >= ? AND qty_done > 0 THEN 'done' ELSE status END WHERE id = ?");
    foreach ($lines as $i => $ln) {
        [$nm, $q, $u] = purchaseParseItemLine($ln);
        $k = purchaseItemKey($nm);
        if ($k === '') continue;
        if (!empty($have[$k])) { $r = array_shift($have[$k]); $upd->execute([$i, mb_substr($nm, 0, 300), $q, $u !== '' ? mb_substr($u, 0, 40) : null, $q, $q, $q, $q, $r['id']]); $keep[] = (int)$r['id']; }
        else { $ins->execute([(int)$reqId, (int)$orgId, $i, mb_substr($nm, 0, 300), $k, $q, $u !== '' ? mb_substr($u, 0, 40) : null]); $keep[] = (int)$pdo->lastInsertId(); }
    }
    // यादीतून काढलेल्या — फक्त बाकी (खरेदी न झालेल्या) हटवतो; खरेदी झालेल्या नोंदी इतिहासासाठी ठेवतो
    $del = $pdo->prepare("DELETE FROM purchase_request_items WHERE request_id = ? AND organization_id = ? AND status = 'pending' AND qty_done = 0" . ($keep ? " AND id NOT IN (" . implode(',', $keep) . ")" : ''));
    $del->execute([(int)$reqId, (int)$orgId]);
}

/** ज्या मागण्यांच्या वस्तू अजून ओळींत नाहीत त्या तयार करतो (जुन्या नोंदी) */
function purchaseItemsEnsure(PDO $pdo, $orgId, array $rows) {
    if (!purchaseItemsReady($pdo) || !$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => trim((string)$r['items']) !== ''));
    if (!$ids) return;
    $st = $pdo->query("SELECT DISTINCT request_id FROM purchase_request_items WHERE request_id IN (" . implode(',', $ids) . ")");
    $has = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($rows as $r) if (!isset($has[(int)$r['id']]) && trim((string)$r['items']) !== '') {
        try { purchaseItemsSync($pdo, $orgId, $r['id'], $r['items']); } catch (PDOException $e) { continue; }
        // आधीच "खरेदी पूर्ण" असलेल्या मागणीच्या सगळ्या वस्तू खरेदी झाल्या
        if (($r['status'] ?? '') === 'done') $pdo->prepare("UPDATE purchase_request_items SET status = 'done', qty_done = COALESCE(qty, 0), done_on = ?, supplier = ? WHERE request_id = ? AND status = 'pending'")->execute([$r['done_on'], $r['supplier'], (int)$r['id']]);
    }
}

/** वस्तूंच्या ओळी व आकडे जोडतो: $r['_items'], _it_total, _it_done */
function purchaseItemsAttach(PDO $pdo, $orgId, array &$rows) {
    if (!purchaseItemsReady($pdo) || !$rows) return;
    purchaseItemsEnsure($pdo, $orgId, $rows);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $map = [];
    foreach ($pdo->query("SELECT * FROM purchase_request_items WHERE request_id IN (" . implode(',', $ids) . ") ORDER BY request_id, sort_order, id")->fetchAll() as $it) $map[(int)$it['request_id']][] = $it;
    foreach ($rows as &$r) {
        $its = $map[(int)$r['id']] ?? [];
        $r['_items'] = $its;
        $r['_it_total'] = count(array_filter($its, fn($x) => $x['status'] !== 'cancelled'));
        $r['_it_done'] = count(array_filter($its, fn($x) => $x['status'] === 'done'));
    }
    unset($r);
}

/** "12/30 नग" */
function purchaseItemQtyLabel(array $it) {
    $f = fn($x) => rtrim(rtrim(number_format((float)$x, 3, '.', ''), '0'), '.');
    if ($it['qty'] === null) return $it['status'] === 'done' ? '' : '';
    return ((float)$it['qty_done'] > 0 && (float)$it['qty_done'] < (float)$it['qty'] ? $f($it['qty_done']) . '/' : '') . $f($it['qty']) . ($it['unit'] ? ' ' . $it['unit'] : '');
}

/**
 * निवडलेल्या वस्तू खरेदी झाल्या — $got = [itemId => आता मिळालेले नग (null = पूर्ण)]
 * सगळ्या वस्तू झाल्या की मागणी आपोआप "खरेदी पूर्ण". परत: ['n' => किती, 'all_done' => bool, 'left' => बाकी]
 */
function purchaseItemsMark(PDO $pdo, $orgId, array $req, array $got, $doneOn, $supplier, $bill, $amount, $note, $userId) {
    $names = []; $n = 0; $half = 0;
    $st = $pdo->prepare("SELECT * FROM purchase_request_items WHERE id = ? AND request_id = ? AND organization_id = ?");
    $up = $pdo->prepare("UPDATE purchase_request_items SET qty_done = ?, status = ?, done_on = ?, supplier = COALESCE(?, supplier), bill_no = COALESCE(?, bill_no), amount = CASE WHEN ? IS NULL THEN amount ELSE COALESCE(amount, 0) + ? END, note = COALESCE(?, note), done_by = ?, updated_at = NOW() WHERE id = ?");
    foreach ($got as $iid => $q) {
        $st->execute([(int)$iid, (int)$req['id'], (int)$orgId]); $it = $st->fetch();
        if (!$it || $it['status'] !== 'pending') continue;
        $qty = $it['qty'] === null ? null : (float)$it['qty'];
        $done = (float)$it['qty_done'] + ($q === null ? ($qty === null ? 0 : $qty - (float)$it['qty_done']) : max(0, (float)$q));
        if ($qty !== null) $done = min($done, $qty);
        $full = $qty === null || $done >= $qty - 1e-9;
        $up->execute([$done, $full ? 'done' : 'pending', $doneOn, $supplier ?: null, $bill ?: null, $amount, $amount, $note ?: null, $userId, $it['id']]);
        $names[] = $it['name'] . ($qty !== null ? ' (' . rtrim(rtrim(number_format($done, 3, '.', ''), '0'), '.') . '/' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ')' : '') . ($full ? '' : ' — अंशतः');
        $n++; if (!$full) $half++;
        $amount = null;   // रक्कम एकदाच (पहिल्या वस्तूवर) — एकूण बिलाची
    }
    if (!$n) return ['n' => 0, 'all_done' => false, 'left' => null];
    purchaseLog($pdo, $orgId, $req['id'], 'items', implode(', ', $names) . ($supplier ? ' · ' . $supplier : '') . ($bill ? ' · बिल ' . $bill : ''), $userId);
    $c = $pdo->prepare("SELECT COUNT(*) FROM purchase_request_items WHERE request_id = ? AND status = 'pending'"); $c->execute([(int)$req['id']]);
    $left = (int)$c->fetchColumn();
    if ($left === 0 && $req['status'] === 'pending') {
        $sum = $pdo->prepare("SELECT SUM(amount), GROUP_CONCAT(DISTINCT supplier SEPARATOR ', '), GROUP_CONCAT(DISTINCT bill_no SEPARATOR ', ') FROM purchase_request_items WHERE request_id = ?"); $sum->execute([(int)$req['id']]);
        [$amt, $sups, $bills] = $sum->fetch(PDO::FETCH_NUM);
        $pdo->prepare("UPDATE purchase_requests SET status = 'done', done_on = ?, actual_amount = COALESCE(actual_amount, ?), supplier = COALESCE(supplier, ?), bill_no = COALESCE(bill_no, ?), done_note = COALESCE(done_note, ?), done_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$doneOn, $amt, $sups ? mb_substr($sups, 0, 150) : null, $bills ? mb_substr($bills, 0, 80) : null, 'सगळ्या वस्तू खरेदी झाल्या', $userId, $userId, (int)$req['id']]);
        purchaseLog($pdo, $orgId, $req['id'], 'done', 'सगळ्या वस्तू खरेदी झाल्या — आपोआप "खरेदी पूर्ण"', $userId);
    }
    return ['n' => $n, 'half' => $half, 'all_done' => $left === 0, 'left' => $left];
}
