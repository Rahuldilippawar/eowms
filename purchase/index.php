<?php
// 🛒 खरेदी विभाग — खरेदी बाकी / खरेदी पूर्ण: टप्पे (मंजुरी → आवक/आदेश → प्रक्रियेत → पूर्ण), किती दिवस बाकी, विभागनिहाय, "✅ खरेदी पूर्ण", Excel, अहवाल
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/purchase.php';
require_once __DIR__ . '/../includes/smart-insights.php';
if (!canViewPurchase()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">खरेदी विभाग पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
$ready = purchaseReady($pdo);
$canEdit = canPurchase();

// ---------- कृती ----------
if ($ready && $canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $back = (string)($_POST['back'] ?? 'index.php');
    if (!preg_match('#^(index|item)\.php(\?[^"\'<>]*)?$#', $back)) $back = 'index.php';
    if ($act === 'import_seed') {
        $n = purchaseSeedImport($pdo, $orgId, (int)$user['id']);
        if ($n) logActivity('खरेदी बाकी — PDF मधील नोंदी आयात', 'organization', $orgId, $n . ' नोंदी');
        $_SESSION['flash'] = $n ? '✓ PDF मधील ' . $n . ' खरेदी बाकी नोंदी आयात झाल्या.' : '⚠️ नोंदी आधीच आयात केलेल्या आहेत.';
        header('Location: index.php'); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? AND organization_id = ?");
    $st->execute([$id, $orgId]);
    $row = $st->fetch();
    if (!$row) { $_SESSION['flash'] = '⚠️ नोंद सापडली नाही.'; header('Location: ' . $back); exit; }
    if ($act === 'mark_done') {
        $on = (is_string($_POST['done_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['done_on'])) ? $_POST['done_on'] : date('Y-m-d');
        $amt = trim((string)($_POST['actual_amount'] ?? '')); $amt = $amt === '' ? null : round((float)str_replace([',', '₹', ' '], '', $amt), 2);
        $bill = mb_substr(trim((string)($_POST['bill_no'] ?? '')), 0, 80); $sup = mb_substr(trim((string)($_POST['supplier'] ?? '')), 0, 150); $note = mb_substr(trim((string)($_POST['done_note'] ?? '')), 0, 255);
        $pdo->prepare("UPDATE purchase_requests SET status = 'done', done_on = ?, actual_amount = ?, bill_no = ?, supplier = ?, done_note = ?, done_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
            ->execute([$on, $amt, $bill ?: null, $sup ?: null, $note ?: null, $user['id'], $user['id'], $id, $orgId]);
        // आवक क्र. दिलेला नसेल तर आपोआप
        if (trim((string)$row['inward_no']) === '') {
            $ind = $row['inward_date'] ?: $on; $ino = purchaseNextInward($pdo, $orgId, $ind, $id);
            $pdo->prepare("UPDATE purchase_requests SET inward_no = ?, inward_date = ? WHERE id = ? AND organization_id = ?")->execute([$ino, $ind, $id, $orgId]);
            purchaseLog($pdo, $orgId, $id, 'edited', 'आवक क्र. आपोआप: ' . $ino, $user['id']);
        }
        purchaseLog($pdo, $orgId, $id, 'done', trim(date('d.m.Y', strtotime($on)) . ($amt !== null ? ' · ' . purchaseInr($amt) : '') . ($sup ? ' · ' . $sup : '') . ($bill ? ' · बिल ' . $bill : '') . ($note ? ' · ' . $note : '')), $user['id']);
        logActivity('खरेदी पूर्ण', 'purchase_request', $id, $row['title']);
        $_SESSION['flash'] = '✅ "' . mb_strimwidth($row['title'], 0, 60, '…') . '" — खरेदी पूर्ण. आता "खरेदी पूर्ण" यादीत.';
        header('Location: ' . $back); exit;
    }
    if ($act === 'reopen_item') {
        $pdo->prepare("UPDATE purchase_requests SET status = 'pending', updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$user['id'], $id, $orgId]);
        purchaseLog($pdo, $orgId, $id, 'reopened', mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, $user['id']);
        logActivity('खरेदी पुन्हा बाकी', 'purchase_request', $id, $row['title']);
        $_SESSION['flash'] = '↺ "' . mb_strimwidth($row['title'], 0, 60, '…') . '" पुन्हा खरेदी बाकी यादीत.';
        header('Location: ' . $back); exit;
    }
}

// ---------- Filters ----------
$tab = in_array($_GET['tab'] ?? '', ['done', 'cancelled', 'all'], true) ? $_GET['tab'] : 'pending';
$f = ['fy' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : '', 'dept' => trim((string)($_GET['dept'] ?? '')),
    'stage' => in_array($_GET['stage'] ?? '', ['approve', 'order', 'process', 'old'], true) ? $_GET['stage'] : '', 'q' => trim((string)($_GET['q'] ?? ''))];
$qs = function (array $over = []) use ($tab, $f) { $q = array_filter(array_merge(['tab' => $tab !== 'pending' ? $tab : '', 'fy' => $f['fy'], 'dept' => $f['dept'], 'stage' => $f['stage'], 'q' => $f['q']], $over), fn($v) => $v !== '' && $v !== null); return 'index.php' . ($q ? '?' . http_build_query($q) : ''); };

$rows = []; $K = []; $byDept = []; $fys = []; $counts = ['pending' => 0, 'done' => 0, 'cancelled' => 0]; $total = 0; $page = 1; $pages = 1; $hasAny = false;
if ($ready) {
    $st = $pdo->prepare("SELECT DISTINCT fy FROM purchase_requests WHERE organization_id = ? ORDER BY fy DESC"); $st->execute([$orgId]); $fys = $st->fetchAll(PDO::FETCH_COLUMN);
    $base = " FROM purchase_requests r WHERE r.organization_id = ?"; $bp = [$orgId];
    if ($f['fy'] !== '') { $base .= " AND r.fy = ?"; $bp[] = $f['fy']; }
    if ($f['dept'] !== '') { $base .= " AND r.department = ?"; $bp[] = $f['dept']; }
    if ($f['q'] !== '') { $base .= " AND (r.title LIKE ? OR r.items LIKE ? OR r.department LIKE ? OR r.demand_no = ? OR r.inward_no LIKE ? OR r.remarks LIKE ? OR r.supplier LIKE ?)"; $lk = '%' . $f['q'] . '%'; array_push($bp, $lk, $lk, $lk, $f['q'], $lk, $lk, $lk); }
    $st = $pdo->prepare("SELECT status, COUNT(*) n FROM purchase_requests r WHERE r.organization_id = ?" . ($f['fy'] !== '' ? " AND r.fy = ?" : '') . " GROUP BY status");
    $st->execute($f['fy'] !== '' ? [$orgId, $f['fy']] : [$orgId]);
    foreach ($st->fetchAll() as $c) $counts[$c['status']] = (int)$c['n'];
    $hasAny = array_sum($counts) > 0;
    $age = "DATEDIFF(CURDATE(), COALESCE(r.demand_date, r.approval_date, r.inward_date, DATE(r.created_at)))";
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(COALESCE(est_amount,0)) amt, SUM(est_amount IS NULL) noamt,
        SUM(approval_no IS NULL AND approval_date IS NULL AND inward_no IS NULL AND inward_date IS NULL) s_app,
        SUM((approval_no IS NOT NULL OR approval_date IS NOT NULL) AND inward_no IS NULL AND inward_date IS NULL) s_ord,
        SUM(inward_no IS NOT NULL OR inward_date IS NOT NULL) s_pro, SUM($age > 90) old90, MAX($age) maxage $base AND r.status = 'pending'");
    $st->execute($bp); $K = $st->fetch();
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(COALESCE(actual_amount, est_amount, 0)) amt, AVG(DATEDIFF(done_on, COALESCE(demand_date, approval_date, inward_date, DATE(created_at)))) avgdays $base AND r.status = 'done'");
    $st->execute($bp); $D = $st->fetch();
    $st = $pdo->prepare("SELECT department, COUNT(*) n, SUM(COALESCE(est_amount,0)) amt $base AND r.status = 'pending' GROUP BY department ORDER BY amt DESC, n DESC");
    $st->execute($bp); $byDept = $st->fetchAll();

    $where = $base; $wp = $bp;
    if ($tab !== 'all') { $where .= " AND r.status = ?"; $wp[] = $tab; }
    if ($tab === 'pending' && $f['stage'] === 'approve') $where .= " AND r.approval_no IS NULL AND r.approval_date IS NULL AND r.inward_no IS NULL AND r.inward_date IS NULL";
    elseif ($tab === 'pending' && $f['stage'] === 'order') $where .= " AND (r.approval_no IS NOT NULL OR r.approval_date IS NOT NULL) AND r.inward_no IS NULL AND r.inward_date IS NULL";
    elseif ($tab === 'pending' && $f['stage'] === 'process') $where .= " AND (r.inward_no IS NOT NULL OR r.inward_date IS NOT NULL)";
    elseif ($tab === 'pending' && $f['stage'] === 'old') $where .= " AND $age > 90";
    $st = $pdo->prepare("SELECT COUNT(*) $where"); $st->execute($wp); $total = (int)$st->fetchColumn();
    $per = 30; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)($_GET['pg'] ?? 1)));
    $order = $tab === 'done' ? "r.done_on DESC, r.id DESC" : "r.fy, r.sr_no, r.id";
    $st = $pdo->prepare("SELECT r.*, u.name done_by_name FROM purchase_requests r LEFT JOIN users u ON u.id = r.done_by" . substr($where, strlen(" FROM purchase_requests r")) . " ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($wp); $rows = $st->fetchAll();

    // 📊 Excel
    if (($_GET['export'] ?? '') === 'csv') {
        $st = $pdo->prepare("SELECT r.* $where ORDER BY $order"); $st->execute($wp);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kharedi-' . $tab . '-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['आर्थिक वर्ष', 'अ.क्र.', 'विभाग', 'मागणी क्र.', 'मागणी दिनांक', 'मंजुरी क्र.', 'मंजुरी दिनांक', 'आवक क्र.', 'आवक दिनांक', 'तपशील', 'साहित्य', 'अंदाजित रक्कम', 'प्रलंबित कारण', 'शेरा', 'टप्पा', 'दिवस', 'खरेदी दिनांक', 'प्रत्यक्ष रक्कम', 'बिल क्र.', 'पुरवठादार']);
        while ($r = $st->fetch()) {
            fputcsv($o, [$r['fy'], $r['sr_no'], $r['department'], $r['demand_no'], purchaseDate($r['demand_date']), $r['approval_no'], purchaseDate($r['approval_date']), $r['inward_no'], purchaseDate($r['inward_date']),
                $r['title'], str_replace("\n", '; ', (string)$r['items']), $r['est_amount'], $r['pending_reason'], $r['remarks'], strip_tags(purchaseStage($r)[1]), purchaseAgeDays($r), purchaseDate($r['done_on']), $r['actual_amount'], $r['bill_no'], $r['supplier']]);
        }
        fclose($o); exit;
    }
}
$seedPending = $ready && $canEdit && (!function_exists('orgSetting') || orgSetting($pdo, $orgId, 'purchase_seed_done', '') === '');

$pageTitle = 'खरेदी बाकी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$ageCls = fn($d) => $d === null ? '' : ($d > 90 ? 'a-red' : ($d > 45 ? 'a-amb' : 'a-grn'));
?>
<style>
  .pu-act{display:flex;gap:6px;flex-wrap:wrap;}
  .pu-act a,.pu-act button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .pu-act .pri{background:linear-gradient(90deg,#B45309,#D97706);color:#fff;border:0;}
  .pu-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px;}
  .pu-tabs a{padding:8px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-size:13px;font-weight:600;}
  .pu-tabs a.on{background:var(--rail);border-color:var(--rail);color:#fff;} .pu-tabs a small{opacity:.8;margin-left:4px;}
  .pu-depts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
  .pu-depts a{font-size:12px;padding:5px 10px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .pu-depts a.on{background:#B45309;border-color:#B45309;color:#fff;} .pu-depts small{color:var(--text-dim);margin-left:3px;} .pu-depts a.on small{color:#FDE7C7;}
  .pu-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:12px 14px;margin-bottom:10px;display:grid;grid-template-columns:44px 1fr auto;gap:12px;align-items:start;}
  .pu-sr{width:40px;height:40px;border-radius:11px;background:color-mix(in srgb,#B45309 12%,var(--surface));color:#B45309;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:13px;}
  .pu-tt{font-weight:700;font-size:14px;line-height:1.35;}
  .pu-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;font-size:11.5px;}
  .pu-meta span{background:var(--canvas);border-radius:7px;padding:2px 8px;color:var(--text-dim);} .pu-meta b{color:var(--text);font-weight:600;}
  .pu-chip{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;white-space:nowrap;}
  .st-approve{background:#FEE2E2;color:#991B1B;} .st-order{background:#FEF3C7;color:#92400E;} .st-process{background:#DBEAFE;color:#1E40AF;} .st-done{background:#DCFCE7;color:#166534;} .st-cancel{background:#E5E7EB;color:#374151;}
  .a-red{background:#FEE2E2;color:#991B1B;} .a-amb{background:#FEF3C7;color:#92400E;} .a-grn{background:#ECFDF5;color:#166534;}
  .pu-dept{font-size:11.5px;font-weight:700;color:#B45309;}
  .pu-items{margin:6px 0 0;padding-left:16px;font-size:12.5px;color:var(--text);} .pu-items li{margin:1px 0;}
  .pu-more{font-size:12px;color:var(--rail);cursor:pointer;border:0;background:none;padding:0;}
  .pu-note{margin-top:6px;font-size:12px;border-left:3px solid #F59E0B;padding:3px 8px;background:color-mix(in srgb,#F59E0B 8%,transparent);border-radius:0 6px 6px 0;}
  .pu-note.done{border-color:#16A34A;background:color-mix(in srgb,#16A34A 8%,transparent);}
  .pu-right{text-align:right;min-width:120px;} .pu-amt{font-size:16px;font-weight:800;} .pu-amt small{display:block;font-size:11px;color:var(--text-dim);font-weight:600;}
  .pu-btns{display:flex;gap:6px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap;}
  .pu-btns a,.pu-btns button{font-size:12px;border-radius:8px;padding:5px 10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:600;}
  .pu-btns .ok{background:#16A34A;border-color:#16A34A;color:#fff;}
  .pu-seed{border:2px dashed #D97706;border-radius:14px;padding:14px 16px;margin-bottom:12px;background:color-mix(in srgb,#F59E0B 7%,var(--surface));display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  @media (max-width:640px){ .pu-card{grid-template-columns:1fr;} .pu-sr{display:none;} .pu-right{text-align:left;} .pu-btns{justify-content:flex-start;} }
</style>

<div class="page-eyebrow">🛒 खरेदी विभाग</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0"><?= $tab === 'done' ? '✅ खरेदी पूर्ण' : ($tab === 'cancelled' ? '✕ रद्द मागण्या' : '🛒 खरेदी बाकी') ?></div>
  <div class="pu-act">
    <?php if ($canEdit && $ready): ?><a class="pri" href="item.php">＋ नवीन मागणी</a><?php endif; ?>
    <a href="report.php?<?= h(http_build_query(array_filter(['type' => $tab === 'done' ? 'done' : ($tab === 'all' ? 'all' : 'pending'), 'fy' => $f['fy'], 'dept' => $f['dept']]))) ?>" target="_blank">📄 अहवाल (Print / PDF)</a>
    <a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a>
  </div>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ खरेदी विभागासाठी Database Update बाकी आहे — Super Admin ने System Update → Database Update चालवावं.</div><?php endif; ?>

<?php if ($seedPending): ?>
<form method="POST" class="pu-seed" onsubmit="return confirm('PDF मधील 66 खरेदी बाकी नोंदी (आर्थिक वर्ष 2025-26 व 2026-27) आयात करायच्या?');">
  <input type="hidden" name="action" value="import_seed">
  <span style="font-size:26px;">📥</span>
  <div style="flex:1;min-width:220px;"><b>खरेदी बाकी तक्ता (दिनांक 13.11.2026) — 66 नोंदी</b><div style="font-size:12.5px;color:var(--text-dim);">PDF मधील आर्थिक वर्ष 2025-26 (5) व 2026-27 (61) च्या सगळ्या नोंदी एका क्लिकमध्ये. पुढच्या नोंदी इथेच करा.</div></div>
  <button type="submit" class="btn btn-sm" style="background:#D97706;color:#fff;border-radius:9px;font-weight:700;">आयात करा</button>
</form>
<?php endif; ?>

<?php if ($ready): ?>
<div class="pu-tabs">
  <a href="<?= h($qs(['tab' => '', 'stage' => '', 'pg' => null])) ?>" class="<?= $tab === 'pending' ? 'on' : '' ?>">⏳ खरेदी बाकी<small><?= $counts['pending'] ?></small></a>
  <a href="<?= h($qs(['tab' => 'done', 'stage' => '', 'pg' => null])) ?>" class="<?= $tab === 'done' ? 'on' : '' ?>">✅ खरेदी पूर्ण<small><?= $counts['done'] ?></small></a>
  <?php if ($counts['cancelled']): ?><a href="<?= h($qs(['tab' => 'cancelled', 'stage' => '', 'pg' => null])) ?>" class="<?= $tab === 'cancelled' ? 'on' : '' ?>">✕ रद्द<small><?= $counts['cancelled'] ?></small></a><?php endif; ?>
  <a href="<?= h($qs(['tab' => 'all', 'stage' => '', 'pg' => null])) ?>" class="<?= $tab === 'all' ? 'on' : '' ?>">सर्व</a>
</div>

<form method="GET" class="d-flex flex-wrap gap-2 mb-2" data-no-smart-filter>
  <?php if ($tab !== 'pending'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()"><option value="">सर्व आर्थिक वर्षे</option><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $f['fy'] === $y ? 'selected' : '' ?>>आर्थिक वर्ष <?= h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select>
  <?php if ($f['dept'] !== ''): ?><input type="hidden" name="dept" value="<?= h($f['dept']) ?>"><?php endif; ?>
  <?php if ($f['stage'] !== ''): ?><input type="hidden" name="stage" value="<?= h($f['stage']) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" style="width:auto;min-width:220px;" placeholder="तपशील, साहित्य, विभाग, मागणी / आवक क्र., पुरवठादार…">
  <button class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">शोधा</button>
  <?php if ($f['q'] !== '' || $f['dept'] !== '' || $f['stage'] !== ''): ?><a href="<?= h($qs(['q' => '', 'dept' => '', 'stage' => '', 'pg' => null])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">✕ Filter काढा</a><?php endif; ?>
</form>

<?php if ($hasAny): ?>
<?= smartTiles([
    ['label' => 'खरेदी बाकी', 'value' => (int)$K['n'], 'sub' => purchaseInr($K['amt']) . ((int)$K['noamt'] ? ' · ' . (int)$K['noamt'] . ' रक्कम नाही' : ''), 'tone' => (int)$K['n'] ? 'warn' : 'ok', 'href' => $qs(['tab' => '', 'stage' => ''])],
    ['label' => '⏳ मंजुरी बाकी', 'value' => (int)$K['s_app'], 'sub' => 'मंजुरी क्र. / दिनांक नाही', 'tone' => (int)$K['s_app'] ? 'bad' : 'ok', 'href' => $qs(['tab' => '', 'stage' => 'approve'])],
    ['label' => '📝 आवक / आदेश बाकी', 'value' => (int)$K['s_ord'], 'sub' => 'मंजुरी झाली', 'tone' => (int)$K['s_ord'] ? 'warn' : 'ok', 'href' => $qs(['tab' => '', 'stage' => 'order'])],
    ['label' => '🛒 खरेदी प्रक्रियेत', 'value' => (int)$K['s_pro'], 'sub' => 'आवक क्र. दिला', 'href' => $qs(['tab' => '', 'stage' => 'process'])],
    ['label' => '⏰ 90+ दिवस जुने', 'value' => (int)$K['old90'], 'sub' => $K['maxage'] !== null ? 'सर्वात जुनं ' . (int)$K['maxage'] . ' दिवस' : '—', 'tone' => (int)$K['old90'] ? 'bad' : 'ok', 'href' => $qs(['tab' => '', 'stage' => 'old'])],
]) ?>
<?php if ((int)$D['n']): ?><div style="font-size:12.5px;color:var(--text-dim);margin:-6px 0 10px;">✅ खरेदी पूर्ण: <b style="color:var(--text);"><?= (int)$D['n'] ?></b> · <?= h(purchaseInr($D['amt'])) ?> · सरासरी <?= (int)round($D['avgdays']) ?> दिवसांत पूर्ण</div><?php endif; ?>

<?php if ($byDept && $tab === 'pending'): ?>
<div class="pu-depts">
  <a href="<?= h($qs(['dept' => '', 'pg' => null])) ?>" class="<?= $f['dept'] === '' ? 'on' : '' ?>">सर्व विभाग</a>
  <?php foreach ($byDept as $d): ?><a href="<?= h($qs(['dept' => $f['dept'] === $d['department'] ? '' : $d['department'], 'pg' => null])) ?>" class="<?= $f['dept'] === $d['department'] ? 'on' : '' ?>"><?= h($d['department']) ?><small><?= (int)$d['n'] ?> · <?= h(purchaseInr($d['amt'])) ?></small></a><?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);font-size:13.5px;">
    <?= $tab === 'done' ? 'अजून एकही खरेदी पूर्ण म्हणून नोंदलेली नाही.' : ($f['q'] !== '' || $f['dept'] !== '' || $f['stage'] !== '' ? 'या अटींनुसार नोंद नाही.' : 'खरेदी बाकी नाही. 🎉') ?>
    <?php if ($canEdit && $tab === 'pending'): ?><div class="mt-2"><a href="item.php" class="btn btn-sm" style="background:#D97706;color:#fff;border-radius:8px;">＋ नवीन मागणी</a></div><?php endif; ?>
  </div>
<?php else: ?>
  <?php foreach ($rows as $r): [$sk, $sl] = purchaseStage($r); $age = purchaseAgeDays($r); $its = array_values(array_filter(array_map('trim', explode("\n", (string)$r['items'])))); ?>
  <div class="pu-card">
    <div class="pu-sr" title="अ.क्र."><?= (int)$r['sr_no'] ?: '—' ?></div>
    <div style="min-width:0;">
      <div class="d-flex gap-2 flex-wrap align-items-center mb-1">
        <span class="pu-dept"><?= h($r['department']) ?></span>
        <span class="pu-chip st-<?= $sk ?>"><?= h($sl) ?></span>
        <?php if ($age !== null && $r['status'] === 'pending'): ?><span class="pu-chip <?= $ageCls($age) ?>"><?= $age ?> दिवस</span><?php endif; ?>
        <span style="font-size:11px;color:var(--text-dim);">आ.व. <?= h(purchaseFyLabel($r['fy'])) ?></span>
      </div>
      <a href="item.php?id=<?= (int)$r['id'] ?>" class="pu-tt" style="color:inherit;text-decoration:none;"><?= h($r['title']) ?></a>
      <?php if ($its): ?>
        <ul class="pu-items"><?php foreach (array_slice($its, 0, 3) as $it): ?><li><?= h($it) ?></li><?php endforeach; ?>
          <?php if (count($its) > 3): ?><span class="pu-hid" style="display:none;"><?php foreach (array_slice($its, 3) as $it): ?><li><?= h($it) ?></li><?php endforeach; ?></span>
          <li style="list-style:none;margin-left:-16px;"><button type="button" class="pu-more" onclick="var s=this.closest('ul').querySelector('.pu-hid');s.style.display=s.style.display==='none'?'contents':'none';this.textContent=s.style.display==='none'?'＋ आणखी <?= count($its) - 3 ?> साहित्य':'− कमी दाखवा';">＋ आणखी <?= count($its) - 3 ?> साहित्य</button></li><?php endif; ?>
        </ul>
      <?php endif; ?>
      <div class="pu-meta">
        <span>मागणी <b><?= h($r['demand_no'] ?: '—') ?></b><?= $r['demand_date'] ? ' · ' . purchaseDate($r['demand_date']) : '' ?></span>
        <span>मंजुरी <b><?= h(trim(($r['approval_no'] ?: '') . ($r['approval_date'] ? ($r['approval_no'] ? ' / ' : '') . purchaseDate($r['approval_date']) : '')) ?: '—') ?></b></span>
        <span>आवक <b><?= h(trim(($r['inward_no'] ?: '') . ($r['inward_date'] ? ($r['inward_no'] ? ' / ' : '') . purchaseDate($r['inward_date']) : '')) ?: '—') ?></b></span>
      </div>
      <?php if ($r['status'] === 'done'): ?>
        <div class="pu-note done">✅ <?= purchaseDate($r['done_on']) ?><?= $r['supplier'] ? ' · ' . h($r['supplier']) : '' ?><?= $r['bill_no'] ? ' · बिल ' . h($r['bill_no']) : '' ?><?= $r['done_by_name'] ? ' · ' . h($r['done_by_name']) : '' ?><?= $r['done_note'] ? ' — ' . h($r['done_note']) : '' ?></div>
      <?php elseif ($r['pending_reason'] || $r['remarks']): ?>
        <div class="pu-note"><?= $r['pending_reason'] ? '⏸ <b>' . h($r['pending_reason']) . '</b>' : '' ?><?= $r['pending_reason'] && $r['remarks'] ? ' · ' : '' ?><?= $r['remarks'] ? '📝 ' . h($r['remarks']) : '' ?></div>
      <?php endif; ?>
    </div>
    <div class="pu-right">
      <div class="pu-amt"><?= h(purchaseInr($r['status'] === 'done' && $r['actual_amount'] !== null ? $r['actual_amount'] : $r['est_amount'])) ?><small><?= $r['status'] === 'done' && $r['actual_amount'] !== null ? 'प्रत्यक्ष' . ($r['est_amount'] !== null ? ' (अंदाज ' . purchaseInr($r['est_amount']) . ')' : '') : 'अंदाजित' ?></small></div>
      <div class="pu-btns">
        <?php if ($canEdit && $r['status'] === 'pending'): ?><button type="button" class="ok pu-done" data-id="<?= (int)$r['id'] ?>" data-title="<?= h($r['title']) ?>" data-amt="<?= $r['est_amount'] !== null ? (float)$r['est_amount'] : '' ?>">✅ खरेदी पूर्ण</button><?php endif; ?>
        <?php if ($canEdit && $r['status'] === 'done'): ?><form method="POST" class="m-0" onsubmit="return confirm('ही खरेदी पुन्हा बाकी यादीत टाकायची?');"><input type="hidden" name="action" value="reopen_item"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h(basename($qs(['pg' => $page > 1 ? $page : null]))) ?>"><button type="submit">↺ पुन्हा बाकी</button></form><?php endif; ?>
        <a href="item.php?id=<?= (int)$r['id'] ?>"><?= $canEdit ? '✎ उघडा' : 'पहा' ?></a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if ($pages > 1): ?><div class="d-flex justify-content-center gap-1 flex-wrap mb-3"><?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= h($qs(['pg' => $i])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;<?= $i === $page ? 'background:var(--rail);color:#fff;' : '' ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal fade" id="doneModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px;">
  <form method="POST" data-no-smart-filter>
    <input type="hidden" name="action" value="mark_done"><input type="hidden" name="id" value=""><input type="hidden" name="back" value="<?= h(basename($qs(['pg' => $page > 1 ? $page : null]))) ?>">
    <div class="modal-header"><div><div class="modal-title" style="font-weight:800;">✅ खरेदी पूर्ण</div><div id="doneTitle" style="font-size:12.5px;color:var(--text-dim);"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="row g-2">
        <div class="col-6"><label class="form-label-custom">खरेदी दिनांक *</label><input type="date" name="done_on" class="form-control-custom" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div class="col-6"><label class="form-label-custom">प्रत्यक्ष रक्कम ₹</label><input type="number" name="actual_amount" class="form-control-custom" min="0" step="0.01" inputmode="decimal"></div>
        <div class="col-6"><label class="form-label-custom">बिल / Invoice क्र.</label><input type="text" name="bill_no" class="form-control-custom" maxlength="80"></div>
        <div class="col-6"><label class="form-label-custom">पुरवठादार</label><input type="text" name="supplier" class="form-control-custom" maxlength="150" list="puSup"></div>
        <div class="col-12"><label class="form-label-custom">शेरा</label><input type="text" name="done_note" class="form-control-custom" maxlength="255" placeholder="उदा. सर्व साहित्य मिळाले, विभागाला दिले"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;">✓ खरेदी पूर्ण करा</button></div>
  </form>
</div></div></div>
<?php
$sups = [];
if ($ready) { $st = $pdo->prepare("SELECT DISTINCT supplier FROM purchase_requests WHERE organization_id = ? AND supplier IS NOT NULL AND supplier <> '' ORDER BY supplier LIMIT 200"); $st->execute([$orgId]); $sups = $st->fetchAll(PDO::FETCH_COLUMN); }
?>
<datalist id="puSup"><?php foreach ($sups as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>
<script>
window.addEventListener('load', function () {
  var m = document.getElementById('doneModal'); if (!m || !window.bootstrap) return;
  var md = new bootstrap.Modal(m), f = m.querySelector('form');
  document.querySelectorAll('.pu-done').forEach(function (b) {
    b.addEventListener('click', function () { f.id.value = b.dataset.id; f.actual_amount.value = b.dataset.amt || ''; document.getElementById('doneTitle').textContent = b.dataset.title; md.show(); });
  });
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
