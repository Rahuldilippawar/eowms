<?php
// ✍️ बिल परवानगी पत्रक — खरेदी विभागाची एकत्रित बिलांची फाईल मा. कार्यकारी अध्यक्षांच्या सहीसाठी; परवानगी झाली की Done, नंतर लेखा विभागास जमा
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/bill-sign.php';
require_once __DIR__ . '/../../includes/smart-insights.php';
if (!bsCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बिल परवानगी पत्रक पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
$ready = bsReady($pdo);
$can = bsCan();
$canDecide = bsCanDecide();
$ST = bsStatuses();

// ---------- कृती ----------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $back = (string)($_POST['back'] ?? 'index.php'); if (!preg_match('#^(index|view)\.php(\?[^"\'<>]*)?$#', $back)) $back = 'index.php';
    if ($act === 'import_seed' && $can) {
        $n = bsSeedImport($pdo, $orgId, (int)$user['id']);
        if ($n) logActivity('बिल परवानगी पत्रक — Excel मधील पत्रके आयात', 'organization', $orgId, $n . ' पत्रके');
        $_SESSION['flash'] = $n ? '✓ Excel मधील ' . $n . ' बिल परवानगी पत्रके आयात झाली. पुढचा Sheet No. आपोआप.' : '⚠️ पत्रके आधीच आयात केलेली आहेत.';
        header('Location: index.php'); exit;
    }
    if ($act === 'save_bs_settings' && $can) {
        $lines = fn($k) => json_encode(array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 120), preg_split('/\R/u', (string)($_POST[$k] ?? ''))), 'strlen')), JSON_UNESCAPED_UNICODE);
        foreach (['bs_letterhead' => mb_substr(trim((string)($_POST['letterhead'] ?? '')), 0, 800), 'bs_title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150), 'bs_subtitle' => mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 150),
            'bs_submit_to' => mb_substr(trim((string)($_POST['submit_to'] ?? '')), 0, 150), 'bs_signs' => $lines('signs'), 'bs_acc_title' => mb_substr(trim((string)($_POST['acc_title'] ?? '')), 0, 200),
            'bs_acc_note' => mb_substr(trim((string)($_POST['acc_note'] ?? '')), 0, 300), 'bs_acc_signs' => $lines('acc_signs')] as $k => $v) orgSettingSet($pdo, $orgId, $k, $v);
        $_SESSION['flash'] = '✓ बिल परवानगी पत्रकाची सेटिंग्ज जतन झाली.';
        header('Location: index.php'); exit;
    }
    if ($act === 'set_bs_status') {
        $s = bsLoad($pdo, $orgId, (int)($_POST['id'] ?? 0));
        $op = (string)($_POST['op'] ?? '');
        $allowed = $s && ($can || ($canDecide && $op === 'approve_all'));
        if ($allowed) {
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
            $new = bsSetStatus($pdo, $orgId, $s, $op, (int)$user['id'], $note);
            if ($new) {
                logActivity('बिल परवानगी पत्रक — स्थिती', 'bill_sign_sheet', $s['id'], 'Sheet ' . bsSheetLabel($s) . ': ' . ($ST[$new][0] ?? $new));
                $_SESSION['flash'] = '✓ Sheet No. ' . bsSheetLabel($s) . ' — ' . ($ST[$new][0] ?? $new);
            }
        }
        header('Location: ' . $back); exit;
    }
    if ($act === 'drop_bs_sheet' && $can) {
        $s = bsLoad($pdo, $orgId, (int)($_POST['id'] ?? 0));
        if ($s && $s['status'] === 'draft') {
            $pdo->prepare("DELETE FROM bill_sign_items WHERE sheet_id = ?")->execute([$s['id']]);
            $pdo->prepare("DELETE FROM bill_sign_events WHERE sheet_id = ?")->execute([$s['id']]);
            $pdo->prepare("DELETE FROM bill_sign_sheets WHERE id = ? AND organization_id = ?")->execute([$s['id'], $orgId]);
            logActivity('बिल परवानगी पत्रक — काढले', 'bill_sign_sheet', $s['id'], 'Sheet ' . bsSheetLabel($s));
            $_SESSION['flash'] = '✓ तयार होत असलेले पत्रक (Sheet No. ' . bsSheetLabel($s) . ') काढले.';
        }
        header('Location: index.php'); exit;
    }
}

// ---------- Filters ----------
$curFy = purchaseFy();
$fys = [];
if ($ready) { $st = $pdo->prepare("SELECT DISTINCT fy FROM bill_sign_sheets WHERE organization_id = ? ORDER BY fy DESC"); $st->execute([$orgId]); $fys = $st->fetchAll(PDO::FETCH_COLUMN); }
if (!in_array($curFy, $fys, true)) array_unshift($fys, $curFy);
$f = ['fy' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : $curFy, 'st' => isset($ST[$_GET['st'] ?? '']) ? $_GET['st'] : '', 'q' => trim((string)($_GET['q'] ?? ''))];
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['fy' => $f['fy'], 'st' => $f['st'], 'q' => $f['q']], $over), fn($v) => $v !== '' && $v !== null); return 'index.php' . ($q ? '?' . http_build_query($q) : ''); };

$rows = []; $K = []; $pending = []; $hits = []; $nextNo = 1; $byParty = [];
if ($ready) {
    $nextNo = bsNextNo($pdo, $orgId, $curFy);
    $base = " FROM bill_sign_sheets s WHERE s.organization_id = ? AND s.fy = ?"; $bp = [$orgId, $f['fy']];
    $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_amount), 0) amt, COALESCE(SUM(n_bills), 0) bills, SUM(status = 'draft') dr, SUM(status = 'submitted') sb, SUM(CASE WHEN status = 'submitted' THEN total_amount ELSE 0 END) sbamt,
        SUM(status = 'partial') pt, SUM(status = 'approved') ok, SUM(status = 'accounts') ac, SUM(CASE WHEN status IN ('approved', 'partial') THEN total_amount ELSE 0 END) okamt,
        SUM(DATE_FORMAT(sheet_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) mn, SUM(CASE WHEN DATE_FORMAT(sheet_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN total_amount ELSE 0 END) mamt $base");
    $st->execute($bp); $K = $st->fetch();
    // परवानगी बाकी — सर्व वर्षांची, जुनी आधी
    $st = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM bill_sign_items i WHERE i.sheet_id = s.id AND i.is_sub = 0 AND i.status <> 'pending') decided FROM bill_sign_sheets s WHERE s.organization_id = ? AND s.status IN ('submitted', 'partial') ORDER BY s.submitted_at, s.sheet_no LIMIT 30");
    $st->execute([$orgId]); $pending = $st->fetchAll();
    // थांबवलेली / नाकारलेली बिलं — पुढच्या पत्रकात घ्यायची
    $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(i.amount), 0) amt FROM bill_sign_items i JOIN bill_sign_sheets s ON s.id = i.sheet_id WHERE i.organization_id = ? AND s.fy = ? AND i.status IN ('hold', 'rejected')"); $st->execute($bp); $held = $st->fetch();
    $st = $pdo->prepare("SELECT i.party, COUNT(*) n, SUM(i.amount) amt FROM bill_sign_items i JOIN bill_sign_sheets s ON s.id = i.sheet_id WHERE i.organization_id = ? AND s.fy = ? AND i.party IS NOT NULL GROUP BY i.party ORDER BY amt DESC LIMIT 6"); $st->execute($bp); $byParty = $st->fetchAll();
    $where = $base; $wp = $bp;
    if ($f['st'] !== '') { $where .= " AND s.status = ?"; $wp[] = $f['st']; }
    if ($f['q'] !== '') {
        $lk = '%' . $f['q'] . '%';
        $where .= " AND (s.sheet_no = ? OR EXISTS (SELECT 1 FROM bill_sign_items i WHERE i.sheet_id = s.id AND (i.party LIKE ? OR i.particular LIKE ? OR i.tippani_no = ? OR i.bill_no LIKE ? OR i.paid_by LIKE ?)))"; array_push($wp, (int)$f['q'], $lk, $lk, $f['q'], $lk, $lk);
        $st = $pdo->prepare("SELECT i.*, s.sheet_no, s.sheet_date, s.status sheet_status FROM bill_sign_items i JOIN bill_sign_sheets s ON s.id = i.sheet_id WHERE i.organization_id = ? AND s.fy = ? AND (i.party LIKE ? OR i.particular LIKE ? OR i.tippani_no = ? OR i.bill_no LIKE ? OR i.paid_by LIKE ?) ORDER BY s.sheet_date DESC, i.sort_order LIMIT 80");
        $st->execute([$orgId, $f['fy'], $lk, $lk, $f['q'], $lk, $lk]); $hits = $st->fetchAll();
    }
    if (($_GET['export'] ?? '') === 'csv') {
        $st = $pdo->prepare("SELECT s.sheet_no, s.sheet_date, s.status sst, i.* FROM bill_sign_sheets s JOIN bill_sign_items i ON i.sheet_id = s.id " . substr($where, strlen(" FROM bill_sign_sheets s")) . " ORDER BY s.sheet_date, s.sheet_no, i.sort_order");
        $st->execute($wp);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="bill-sign-' . $f['fy'] . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        $IS = bsItemStatuses();
        fputcsv($o, ['Sheet No', 'Sheet Date', 'Sheet Status', 'Sr', 'PO/Bill', 'Tippani No', 'Party Name', 'Particular', 'Bill Paid By', 'Bill No', 'Bill Date', 'Amount', 'Bill Inward Date', 'Material Receive Date', 'Bill Return Date', 'Remark', 'Permission']);
        while ($r = $st->fetch()) fputcsv($o, [$r['sheet_no'] ?: '', purchaseDate($r['sheet_date']), strip_tags($ST[$r['sst']][0] ?? $r['sst']), $r['is_sub'] ? '' : $r['sr'], $r['doc_type'], $r['tippani_no'], $r['party'], $r['particular'], $r['paid_by'], $r['bill_no'], $r['bill_date'], $r['amount'], $r['inward_date'], $r['material_date'], $r['return_date'], $r['remark'], $r['is_sub'] ? '' : strip_tags($IS[$r['status']][0] ?? $r['status'])]);
        fclose($o); exit;
    }
    $st = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM bill_sign_items i WHERE i.sheet_id = s.id AND i.is_sub = 0 AND i.status = 'approved') n_ok, (SELECT COUNT(*) FROM bill_sign_items i WHERE i.sheet_id = s.id AND i.is_sub = 0 AND i.status IN ('hold', 'rejected')) n_bad,
        (SELECT GROUP_CONCAT(DISTINCT i.party ORDER BY i.sort_order SEPARATOR ', ') FROM bill_sign_items i WHERE i.sheet_id = s.id AND i.party IS NOT NULL) parties $where ORDER BY s.sheet_date DESC, s.sheet_no DESC, s.id DESC LIMIT 300");
    $st->execute($wp); $rows = $st->fetchAll();
}
$seedPending = $ready && $can && orgSetting($pdo, $orgId, 'bs_seed_done', '') === '';
$SET = $ready ? bsSettings($pdo, $orgId) : null;

$pageTitle = 'बिल परवानगी पत्रक';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
$IS = bsItemStatuses();
$stForm = function ($s, $op, $label, $cls, $confirm, $back = null) use ($qs) {
    return '<form method="POST" class="m-0" onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ');"><input type="hidden" name="action" value="set_bs_status"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><input type="hidden" name="op" value="' . h($op) . '"><input type="hidden" name="back" value="' . h($back ?? basename($qs())) . '"><button type="submit" class="' . h($cls) . '">' . h($label) . '</button></form>';
};
?>
<style>
  .bs-hero{border-radius:20px;padding:20px 22px;background:linear-gradient(120deg,#312E81,#4338CA 55%,#6366F1);color:#fff;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:14px;box-shadow:0 14px 34px -18px rgba(67,56,202,.8);position:relative;overflow:hidden;}
  .bs-hero:after{content:"";position:absolute;right:-40px;top:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08);}
  .bs-hero h1{font-size:22px;font-weight:900;margin:0;color:#fff;} .bs-hero .ey{font-size:12px;opacity:.85;font-weight:700;} .bs-hero p{margin:4px 0 0;font-size:12.5px;opacity:.9;max-width:560px;}
  .bs-hero .nx{text-align:center;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:14px;padding:8px 16px;position:relative;z-index:1;}
  .bs-hero .nx small{display:block;font-size:11px;opacity:.85;} .bs-hero .nx b{font-size:22px;font-weight:900;}
  .bs-hero .acts{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1;}
  .bs-hero .acts a,.bs-hero .acts button{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:11px;padding:9px 14px;font-size:13px;font-weight:700;text-decoration:none;}
  .bs-hero .acts .pri{background:#fff;color:#4338CA;border-color:#fff;}
  .bs-flow{display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12px;font-weight:700;color:var(--text-dim);margin:-4px 2px 12px;} .bs-flow span{background:var(--surface);border:1px solid var(--line);border-radius:99px;padding:3px 10px;} .bs-flow i{font-style:normal;opacity:.6;}
  .bs-pend{border:2px solid #F59E0B;border-radius:16px;background:color-mix(in srgb,#F59E0B 7%,var(--surface));padding:12px 14px;margin-bottom:14px;}
  .bs-pend h6{font-weight:900;font-size:14px;margin:0 0 8px;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;} .bs-pend h6 small{font-weight:700;color:var(--text-dim);}
  .bs-pr{display:grid;grid-template-columns:70px minmax(0,1fr) auto;gap:12px;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:9px 12px;margin-top:7px;}
  .bs-pr .no{text-align:center;font-weight:900;font-size:19px;color:#4338CA;line-height:1.05;} .bs-pr .no small{display:block;font-size:10px;color:var(--text-dim);font-weight:700;}
  .bs-age{display:inline-block;font-size:11px;font-weight:800;border-radius:99px;padding:1px 8px;background:#FEF3C7;color:#92400E;} .bs-age.old{background:#FEE2E2;color:#991B1B;}
  .bs-prog{height:6px;border-radius:5px;background:var(--canvas);overflow:hidden;margin-top:5px;max-width:260px;} .bs-prog i{display:block;height:100%;background:linear-gradient(90deg,#16A34A,#4ADE80);}
  .bs-done{background:#16A34A !important;border-color:#16A34A !important;color:#fff !important;font-size:13px !important;padding:7px 14px !important;box-shadow:0 6px 14px -8px rgba(22,163,74,.9);}
  .bs-bar2{display:flex;gap:8px;flex-wrap:wrap;align-items:center;border:1px solid var(--line);background:var(--surface);border-radius:14px;padding:8px;margin-bottom:12px;}
  .bs-bar2 .srch{flex:1;min-width:220px;position:relative;} .bs-bar2 .srch input{width:100%;border:1px solid var(--line);border-radius:10px;padding:8px 10px 8px 34px;font:inherit;font-size:13px;background:var(--canvas);color:var(--text);} .bs-bar2 .srch:before{content:"🔍";position:absolute;left:10px;top:7px;font-size:13px;}
  .bs-seg{display:flex;gap:4px;flex-wrap:wrap;} .bs-seg a{font-size:12.5px;font-weight:700;padding:7px 11px;border-radius:10px;text-decoration:none;color:var(--text);background:var(--canvas);} .bs-seg a.on{background:#4338CA;color:#fff;} .bs-seg a small{font-weight:800;opacity:.7;margin-left:4px;}
  .bs-mon{font-size:12px;font-weight:800;color:var(--text-dim);margin:16px 2px 8px;display:flex;align-items:center;gap:8px;} .bs-mon:after{content:"";flex:1;height:1px;background:var(--line);} .bs-mon b{color:#4338CA;}
  .bs-card{display:grid;grid-template-columns:92px minmax(0,1fr) auto;gap:14px;align-items:center;border:1px solid var(--line);border-left:5px solid #F59E0B;border-radius:14px;background:var(--surface);padding:12px 14px;margin-bottom:8px;}
  .bs-card.s-draft{border-left-color:#9CA3AF;} .bs-card.s-approved{border-left-color:#16A34A;} .bs-card.s-partial{border-left-color:#0EA5E9;} .bs-card.s-accounts{border-left-color:#4338CA;}
  .bs-num{text-align:center;border-radius:12px;background:color-mix(in srgb,#4338CA 9%,var(--surface));padding:8px 4px;} .bs-num small{display:block;font-size:10px;color:var(--text-dim);font-weight:800;letter-spacing:.4px;} .bs-num b{display:block;font-size:21px;font-weight:900;color:#4338CA;line-height:1.1;} .bs-num span{font-size:10.5px;color:var(--text-dim);font-weight:700;}
  .bs-ttl{font-weight:800;font-size:13.5px;line-height:1.4;color:inherit;text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;} .bs-ttl:hover{color:#4338CA;}
  .bs-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;font-size:11.5px;} .bs-tags span{background:var(--canvas);border-radius:7px;padding:2px 8px;color:var(--text-dim);} .bs-tags b{color:var(--text);}
  .bs-right{text-align:right;min-width:190px;} .bs-amt{font-weight:900;font-size:18px;font-variant-numeric:tabular-nums;}
  .bs-chip{display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:99px;white-space:nowrap;margin-top:4px;}
  .st-dr{background:#E5E7EB;color:#374151;} .st-sb{background:#FEF3C7;color:#92400E;} .st-pt{background:#E0F2FE;color:#075985;} .st-ok{background:#DCFCE7;color:#166534;} .st-ac{background:#E0E7FF;color:#3730A3;}
  .it-pn{background:#FEF3C7;color:#92400E;} .it-ok{background:#DCFCE7;color:#166534;} .it-hd{background:#E0F2FE;color:#075985;} .it-rj{background:#FEE2E2;color:#991B1B;}
  .bs-btns{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;margin-top:8px;} .bs-btns a,.bs-btns button{font-size:12px;border-radius:9px;padding:5px 10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:700;white-space:nowrap;}
  .bs-btns form{display:contents;} .bs-btns .pr{border-color:#4338CA;color:#4338CA;} .bs-btns .sb{background:#F59E0B;border-color:#F59E0B;color:#fff;} .bs-btns .ac{background:#4338CA;border-color:#4338CA;color:#fff;} .bs-btns .dl{color:#B91C1C;}
  .bs-ins{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-bottom:14px;} .bs-ins > div{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:12px 14px;font-size:12.5px;}
  .bs-ins h6{font-size:12.5px;font-weight:800;margin:0 0 8px;} .bs-b{display:flex;align-items:center;gap:8px;margin:4px 0;color:inherit;text-decoration:none;} .bs-b span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;} .bs-b i{display:block;height:8px;border-radius:5px;background:linear-gradient(90deg,#4338CA,#A5B4FC);} .bs-b b{white-space:nowrap;}
  .bs-hits{border:1px solid var(--line);border-radius:14px;background:var(--surface);overflow:auto;margin-bottom:14px;} .bs-hits table{width:100%;font-size:12.5px;border-collapse:collapse;} .bs-hits th{background:var(--canvas);padding:7px 9px;font-size:11.5px;text-align:left;white-space:nowrap;} .bs-hits td{padding:6px 9px;border-top:1px solid var(--line);vertical-align:top;}
  .bs-seed{border:2px dashed #4338CA;border-radius:16px;padding:14px 16px;margin-bottom:14px;background:color-mix(in srgb,#4338CA 6%,var(--surface));display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  .bs-empty{border:1px dashed var(--line);border-radius:16px;padding:36px 20px;text-align:center;color:var(--text-dim);font-size:13.5px;background:var(--surface);} .bs-empty div{font-size:40px;}
  @media (max-width:760px){ .bs-hero{padding:16px;} .bs-hero h1{font-size:19px;} .bs-card{grid-template-columns:64px minmax(0,1fr);} .bs-num b{font-size:17px;} .bs-right{grid-column:1/-1;text-align:left;min-width:0;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;} .bs-btns{justify-content:flex-start;margin-top:0;}
    .bs-pr{grid-template-columns:54px minmax(0,1fr);} .bs-pr > :last-child{grid-column:1/-1;} }
</style>

<div class="bs-hero">
  <div style="position:relative;z-index:1;"><div class="ey">🛒 खरेदी विभाग</div><h1>✍️ बिल परवानगी पत्रक</h1>
    <p>एकत्रित बिलांच्या फाईलवर लावायचे पत्रक — मा. कार्यकारी अध्यक्षांच्या सहीसाठी. परवानगी झाली की ✅ Done, मग लेखा विभागास जमा.</p></div>
  <?php if ($ready): ?><div class="nx"><small>🔢 पुढचा Sheet No.</small><b><?= (int)$nextNo ?></b></div><?php endif; ?>
  <div class="acts">
    <?php if ($ready && $can): ?><a class="pri" href="sheet.php">＋ नवीन पत्रक</a><?php endif; ?>
    <?php if ($ready): ?><a href="report.php" target="_blank">📈 मासिक / वार्षिक अहवाल</a><a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a><?php endif; ?>
    <?php if ($ready && $can): ?><button type="button" data-bs-toggle="modal" data-bs-target="#bsSet">⚙️ सेटिंग्ज</button><?php endif; ?>
  </div>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ बिल परवानगी पत्रकासाठी Database Update बाकी आहे — Super Admin ने System Update → Database Update चालवावं.</div><?php endif; ?>
<?php if ($ready): ?>
<div class="bs-flow"><span>📝 पत्रक बनवा</span><i>→</i><span>📤 सहीसाठी जमा</span><i>→</i><span>✅ परवानगी झाली (Done)</span><i>→</i><span>🏦 लेखा विभागास जमा</span></div>
<?php endif; ?>

<?php if ($seedPending): ?>
<form method="POST" class="bs-seed" onsubmit="return confirm('Excel मधील बिल परवानगी पत्रके (Sheet No. 9 ते 83, आ.व. 2026-27) आयात करायची?');">
  <input type="hidden" name="action" value="import_seed"><span style="font-size:26px;">📥</span>
  <div style="flex:1;min-width:220px;"><b>Bill Inward To Executive President Sir Sign 2026-27 — 40 पत्रके, 635 ओळी</b><div style="font-size:12.5px;color:var(--text-dim);">Excel मधील प्रत्येक दिवसाचे पत्रक: टिप्पणी क्र., पार्टी, तपशील, बिल क्र., रक्कम, आवक दिनांक. लेखा विभागाला दिलेली पत्रके "🏦 जमा", नोव्हेंबरमधील "📤 सहीसाठी जमा" (Done करायची बाकी).</div></div>
  <button type="submit" class="btn btn-sm" style="background:#4338CA;color:#fff;border-radius:9px;font-weight:700;">आयात करा</button>
</form>
<?php endif; ?>

<?php if ($ready): ?>
<?php if ((int)$K['n']): ?>
<?= smartTiles([
    ['label' => '✍️ पत्रके', 'value' => (int)$K['n'], 'sub' => 'आ.व. ' . purchaseFyLabel($f['fy']) . ' · ' . (int)$K['bills'] . ' बिलं'],
    ['label' => '💰 एकूण रक्कम', 'value' => purchaseInr($K['amt']), 'sub' => 'या महिन्यात ' . purchaseInr($K['mamt']), 'tone' => 'ok'],
    ['label' => '📤 परवानगी बाकी', 'value' => (int)$K['sb'] + (int)$K['pt'], 'sub' => purchaseInr($K['sbamt']), 'tone' => (int)$K['sb'] + (int)$K['pt'] ? 'warn' : 'ok', 'href' => $qs(['st' => 'submitted'])],
    ['label' => '✅ परवानगी झाली', 'value' => (int)$K['ok'], 'sub' => 'लेखा विभागास द्यायची · ' . purchaseInr($K['okamt']), 'tone' => 'ok', 'href' => $qs(['st' => 'approved'])],
    ['label' => '🏦 लेखा विभागास जमा', 'value' => (int)$K['ac'], 'sub' => (int)$K['dr'] . ' तयार होत आहेत', 'href' => $qs(['st' => 'accounts'])],
]) ?>
<?php endif; ?>

<?php if ($pending): $tot = array_sum(array_map(fn($x) => (float)$x['total_amount'], $pending)); ?>
<div class="bs-pend">
  <h6><span>⏳ सहीसाठी जमा केलेली पत्रके — परवानगी झाली की ✅ Done करा</span><small><?= count($pending) ?> पत्रके · <?= h(purchaseInr($tot)) ?></small></h6>
  <?php foreach ($pending as $p): $age = bsAgeDays($p); $pc = (int)$p['n_bills'] ? round((int)$p['decided'] * 100 / (int)$p['n_bills']) : 0; ?>
  <div class="bs-pr">
    <div class="no"><small>SHEET</small><?= h(bsSheetLabel($p)) ?></div>
    <div style="min-width:0;">
      <b><?= h(purchaseDate($p['sheet_date'])) ?></b> · <?= (int)$p['n_bills'] ?> बिलं · <b><?= h(purchaseInr($p['total_amount'])) ?></b>
      <span class="bs-age <?= $age >= 3 ? 'old' : '' ?>"><?= $age ? $age . ' दिवस झाले' : 'आज जमा' ?></span>
      <?php if ($p['status'] === 'partial'): ?><span class="bs-chip st-pt">◐ <?= (int)$p['decided'] ?>/<?= (int)$p['n_bills'] ?> बिलांचा निर्णय</span><?php endif; ?>
      <div class="bs-prog"><i style="width:<?= $pc ?>%;"></i></div>
    </div>
    <div class="bs-btns" style="margin-top:0;">
      <a href="view.php?id=<?= (int)$p['id'] ?>">🧾 बिलनिहाय</a>
      <a class="pr" href="print.php?id=<?= (int)$p['id'] ?>" target="_blank">🖨️</a>
      <?php if ($canDecide): ?><?= $stForm($p, 'approve_all', '✅ परवानगी झाली — Done', 'bs-done', 'Sheet No. ' . bsSheetLabel($p) . ' — सर्व ' . (int)$p['n_bills'] . ' बिलांना परवानगी झाली (Done)?') ?><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="GET" class="bs-bar2" data-no-smart-filter>
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()"><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $f['fy'] === $y ? 'selected' : '' ?>>आ.व. <?= h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select>
  <?php if ($f['st'] !== ''): ?><input type="hidden" name="st" value="<?= h($f['st']) ?>"><?php endif; ?>
  <div class="srch"><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="पार्टी, टिप्पणी क्र., बिल क्र., तपशील, Sheet No…"></div>
  <div class="bs-seg">
    <?php $cnt = ['' => (int)($K['n'] ?? 0), 'draft' => (int)($K['dr'] ?? 0), 'submitted' => (int)($K['sb'] ?? 0), 'partial' => (int)($K['pt'] ?? 0), 'approved' => (int)($K['ok'] ?? 0), 'accounts' => (int)($K['ac'] ?? 0)];
    foreach (['' => 'सर्व'] + array_map(fn($x) => $x[0], $ST) as $k => $l): if ($k === 'partial' && !$cnt['partial'] && $f['st'] !== 'partial') continue; ?><a href="<?= h($qs(['st' => $k])) ?>" class="<?= $f['st'] === $k ? 'on' : '' ?>"><?= h($l) ?><small><?= $cnt[$k] ?? 0 ?></small></a><?php endforeach; ?>
  </div>
  <?php if ($f['q'] !== '' || $f['st'] !== ''): ?><a href="<?= h($qs(['q' => '', 'st' => ''])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;">✕ Filter</a><?php endif; ?>
</form>

<?php if ($hits): ?>
<div class="bs-hits"><table>
  <thead><tr><th>Sheet</th><th>टिप्पणी</th><th>पार्टी</th><th>तपशील</th><th>बिल क्र.</th><th>बिल दि.</th><th style="text-align:right;">रक्कम</th><th>परवानगी</th></tr></thead>
  <tbody><?php foreach ($hits as $r): ?><tr>
    <td><a href="view.php?id=<?= (int)$r['sheet_id'] ?>" style="font-weight:800;"><?= (int)$r['sheet_no'] ?: '—' ?></a><div class="wo-dim" style="font-size:11px;color:var(--text-dim);"><?= h(purchaseDate($r['sheet_date'])) ?></div></td>
    <td><?= h($r['tippani_no']) ?></td><td><b><?= h($r['party']) ?></b><?= $r['paid_by'] ? '<div style="font-size:11px;color:var(--text-dim);">👤 ' . h($r['paid_by']) . '</div>' : '' ?></td><td><?= h($r['particular']) ?></td><td><?= h($r['bill_no']) ?></td><td><?= h($r['bill_date']) ?></td>
    <td style="text-align:right;font-weight:800;white-space:nowrap;"><?= $r['amount'] !== null ? h(woMoney($r['amount'])) : '' ?></td>
    <td><span class="bs-chip <?= $IS[$r['status']][1] ?? '' ?>"><?= h($IS[$r['status']][0] ?? '') ?></span></td>
  </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($byParty && !$f['q']): ?>
<div class="bs-ins">
  <div><h6>🏪 सर्वात जास्त बिलं — पार्टी (आ.व. <?= h(purchaseFyLabel($f['fy'])) ?>)</h6><?php $mx = max(1, ...array_map(fn($x) => (float)$x['amt'], $byParty)); foreach ($byParty as $v): ?>
    <a class="bs-b" href="<?= h($qs(['q' => $v['party']])) ?>"><span><?= h($v['party']) ?> <small style="color:var(--text-dim);">(<?= (int)$v['n'] ?>)</small></span><i style="width:<?= round((float)$v['amt'] * 100 / $mx) ?>px;"></i><b><?= h(purchaseInr($v['amt'])) ?></b></a><?php endforeach; ?></div>
  <div><h6>📌 स्थिती</h6>
    <?php foreach (['submitted' => (float)$K['sbamt'], 'approved' => (float)$K['okamt']] as $k => $a): ?><div class="bs-b"><span><?= h($ST[$k][0]) ?></span><b><?= h(purchaseInr($a)) ?></b></div><?php endforeach; ?>
    <div class="bs-b"><span>⏸ / ✖ थांबवलेली / नाकारलेली बिलं</span><b><?= (int)$held['n'] ?> · <?= h(purchaseInr($held['amt'])) ?></b></div>
    <div style="font-size:11.5px;color:var(--text-dim);margin-top:6px;">थांबवलेली बिलं पत्रक उघडून "↪ पुढच्या पत्रकात" घेता येतात.</div></div>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="bs-empty"><div>✍️</div><?= (int)($K['n'] ?? 0) ? 'या अटींनुसार पत्रक नाही.' : 'या आर्थिक वर्षात अजून बिल परवानगी पत्रक नाही.' ?>
    <?php if ($can): ?><div class="mt-2"><a href="sheet.php" class="btn btn-sm" style="background:#4338CA;color:#fff;border-radius:9px;font-weight:700;">＋ नवीन पत्रक</a></div><?php endif; ?></div>
<?php else: ?>
<?php $mon = ''; foreach ($rows as $r): $mk = date('Y-m', strtotime($r['sheet_date']));
  if ($mk !== $mon): $mon = $mk; $mrs = array_filter($rows, fn($x) => date('Y-m', strtotime($x['sheet_date'])) === $mk); ?>
  <div class="bs-mon">📅 <?= h(woMonthName($mk)) ?> <b><?= count($mrs) ?></b> पत्रके · <?= h(purchaseInr(array_sum(array_map(fn($x) => (float)$x['total_amount'], $mrs)))) ?></div>
  <?php endif; $pc = (int)$r['n_bills'] ? round((int)$r['n_ok'] * 100 / (int)$r['n_bills']) : 0; ?>
  <div class="bs-card s-<?= h($r['status']) ?>">
    <div class="bs-num"><small>SHEET NO.</small><b><?= h(bsSheetLabel($r)) ?></b><span><?= h(purchaseDate($r['sheet_date'])) ?></span></div>
    <div style="min-width:0;">
      <a class="bs-ttl" href="view.php?id=<?= (int)$r['id'] ?>"><?= h($r['parties'] ?: '—') ?></a>
      <div class="bs-tags"><span>🧾 <b><?= (int)$r['n_bills'] ?></b> बिलं</span>
        <?php if ($r['status'] !== 'draft'): ?><span>✅ <?= (int)$r['n_ok'] ?>/<?= (int)$r['n_bills'] ?> परवानगी</span><?php endif; ?>
        <?php if ((int)$r['n_bad']): ?><span style="color:#B91C1C;">⏸ <?= (int)$r['n_bad'] ?> थांबवली / नाकारली</span><?php endif; ?>
        <?php if ($r['accounts_at']): ?><span>🏦 <?= h(date('d.m.Y', strtotime($r['accounts_at']))) ?></span><?php endif; ?>
        <?php if ($r['note']): ?><span>📝 <?= h(mb_strimwidth($r['note'], 0, 60, '…')) ?></span><?php endif; ?></div>
      <?php if ($r['status'] !== 'draft'): ?><div class="bs-prog"><i style="width:<?= $pc ?>%;"></i></div><?php endif; ?>
    </div>
    <div class="bs-right">
      <div><div class="bs-amt">₹<?= h(woMoney($r['total_amount'])) ?></div><span class="bs-chip <?= $ST[$r['status']][1] ?? '' ?>"><?= h($ST[$r['status']][0] ?? $r['status']) ?></span></div>
      <div class="bs-btns">
        <a class="pr" href="print.php?id=<?= (int)$r['id'] ?>" target="_blank">🖨️ Print</a>
        <a href="view.php?id=<?= (int)$r['id'] ?>">🧾 पहा</a>
        <?php if ($can && in_array($r['status'], ['draft', 'submitted'], true)): ?><a href="sheet.php?id=<?= (int)$r['id'] ?>">✎ बदला</a><?php endif; ?>
        <?php if ($can && $r['status'] === 'draft'): ?><?= $stForm($r, 'submit', '📤 सहीसाठी जमा', 'sb', 'Sheet No. ' . bsSheetLabel($r) . ' सहीसाठी जमा केले?') ?><?php endif; ?>
        <?php if ($canDecide && in_array($r['status'], ['submitted', 'partial'], true)): ?><?= $stForm($r, 'approve_all', '✅ Done', 'bs-done', 'Sheet No. ' . bsSheetLabel($r) . ' — सर्व बिलांना परवानगी झाली (Done)?') ?><?php endif; ?>
        <?php if ($can && in_array($r['status'], ['approved', 'partial'], true)): ?><a href="print.php?id=<?= (int)$r['id'] ?>&amp;copy=acc" target="_blank">🏦 लेखा Print</a><?= $stForm($r, 'accounts', '🏦 लेखा विभागास जमा', 'ac', 'Sheet No. ' . bsSheetLabel($r) . ' — परवानगी झालेली बिलं लेखा विभागास जमा केली?') ?><?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($can): ?>
<div class="modal fade" id="bsSet" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content" style="border-radius:16px;">
  <form method="POST" data-no-smart-filter><input type="hidden" name="action" value="save_bs_settings">
    <div class="modal-header"><div class="modal-title" style="font-weight:800;">⚙️ बिल परवानगी पत्रक — सेटिंग्ज</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <label class="form-label-custom">Letterhead (ओळी)</label><textarea name="letterhead" class="form-control-custom" rows="3"><?= h($SET['letterhead']) ?></textarea>
      <div class="row g-2 mt-1">
        <div class="col-md-6"><label class="form-label-custom">शीर्षक (पुढे आ.व. आपोआप)</label><input name="title" class="form-control-custom" value="<?= h($SET['title']) ?>"></div>
        <div class="col-md-6"><label class="form-label-custom">उपशीर्षक</label><input name="subtitle" class="form-control-custom" value="<?= h($SET['subtitle']) ?>"></div>
        <div class="col-md-6"><label class="form-label-custom">सहीसाठी कोणाकडे</label><input name="submit_to" class="form-control-custom" value="<?= h($SET['submit_to']) ?>"></div>
        <div class="col-md-6"><label class="form-label-custom">सह्या — एका ओळीत एक</label><textarea name="signs" class="form-control-custom" rows="4"><?= h(implode("\n", $SET['signs'])) ?></textarea></div>
      </div>
      <div style="font-weight:800;font-size:13px;margin:12px 0 4px;">🏦 लेखा विभागास जमा — पत्रक</div>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label-custom">शीर्षक</label><input name="acc_title" class="form-control-custom" value="<?= h($SET['acc_title']) ?>"></div>
        <div class="col-md-6"><label class="form-label-custom">सह्या — एका ओळीत एक</label><textarea name="acc_signs" class="form-control-custom" rows="2"><?= h(implode("\n", $SET['acc_signs'])) ?></textarea></div>
        <div class="col-12"><label class="form-label-custom">टीप</label><input name="acc_note" class="form-control-custom" value="<?= h($SET['acc_note']) ?>"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn" style="background:#4338CA;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button></div>
  </form>
</div></div></div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
