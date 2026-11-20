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
function canPurchase() { return hasRole('super_admin') || hasPermission('manage_purchase'); }
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
    return ['created' => '🆕 नोंद केली', 'edited' => '✎ बदल केला', 'done' => '✅ खरेदी पूर्ण', 'reopened' => '↺ पुन्हा बाकी', 'cancelled' => '✕ रद्द', 'imported' => '📥 PDF मधून आयात'];
}

/** पुढचा अ.क्र. (त्या आर्थिक वर्षात) */
function purchaseNextSr(PDO $pdo, $orgId, $fy) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(sr_no), 0) + 1 FROM purchase_requests WHERE organization_id = ?");
    $st->execute([$orgId]);
    return (int)$st->fetchColumn();
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
