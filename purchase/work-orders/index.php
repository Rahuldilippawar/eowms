<?php
// 🧾 वर्क ऑर्डर — खरेदी विभागात सर्व विभागांच्या (केंद्रीय), विभागांना फक्त स्वतःच्या; सलग W.O. क्रमांक, आकडे, शोध, Excel, Word मधील वर्क ऑर्डर आयात
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/work-order.php';
require_once __DIR__ . '/../../includes/smart-insights.php';
if (!woCanUse()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">वर्क ऑर्डर पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
$ready = woReady($pdo);
$central = woCanViewAll();
$canAll = woCanAll();
$ST = woStatuses();

// ---------- कृती ----------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'import_seed' && $canAll) {
        $n = woSeedImport($pdo, $orgId, (int)$user['id']);
        if ($n) logActivity('वर्क ऑर्डर — Word file मधील वर्क ऑर्डर आयात', 'organization', $orgId, $n . ' वर्क ऑर्डर');
        $_SESSION['flash'] = $n ? '✓ Word file मधील ' . $n . ' वर्क ऑर्डर (W.O. 003 ते 017) आयात झाल्या. पुढचा क्रमांक आपोआप 018.' : '⚠️ वर्क ऑर्डर आधीच आयात केलेल्या आहेत.';
        header('Location: index.php'); exit;
    }
    if ($act === 'save_wo_settings' && $canAll) {
        $terms = [];
        foreach (preg_split('/\R/u', trim((string)($_POST['terms'] ?? ''))) as $ln) { $ln = trim($ln); if ($ln === '') continue; $p = explode('|', $ln, 2); $f = strtoupper(trim($p[1] ?? '')); $terms[] = [mb_substr(trim($p[0]), 0, 1500), in_array($f, ['YES', 'NO'], true) ? $f : '']; }
        $pay = array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 200), preg_split('/\R/u', (string)($_POST['payment'] ?? ''))), 'strlen'));
        foreach (['wo_letterhead' => mb_substr(trim((string)($_POST['letterhead'] ?? '')), 0, 1500), 'wo_sign_org' => mb_substr(trim((string)($_POST['sign_org'] ?? '')), 0, 200), 'wo_footer' => mb_substr(trim((string)($_POST['footer'] ?? '')), 0, 300),
            'wo_terms' => $terms ? json_encode($terms, JSON_UNESCAPED_UNICODE) : '', 'wo_payment' => json_encode($pay, JSON_UNESCAPED_UNICODE)] as $k => $v) orgSettingSet($pdo, $orgId, $k, $v);
        $_SESSION['flash'] = '✓ वर्क ऑर्डरची सेटिंग्ज जतन झाली (नवीन वर्क ऑर्डरसाठी).';
        header('Location: index.php'); exit;
    }
    if ($act === 'set_wo_status') {
        $w = woLoad($pdo, $orgId, (int)($_POST['id'] ?? 0));
        $new = (string)($_POST['status'] ?? '');
        if ($w && isset($ST[$new]) && ($canAll || (woCanSee($w) && woCanDept() && in_array($w['status'], ['draft', 'issued'], true)))) {
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
            $pdo->prepare("UPDATE work_orders SET status = ?, status_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$new, $note ?: null, $user['id'], $w['id'], $orgId]);
            woLog($pdo, $orgId, $w['id'], 'status', $ST[$new][0] . ($note ? ' — ' . $note : ''), $user['id']);
            logActivity('वर्क ऑर्डर — स्थिती', 'work_order', $w['id'], woFullNo($w) . ': ' . $ST[$new][0]);
            $_SESSION['flash'] = '✓ W.O. ' . woFullNo($w) . ' — ' . $ST[$new][0];
        }
        $back = (string)($_POST['back'] ?? 'index.php'); if (!preg_match('#^index\.php(\?[^"\'<>]*)?$#', $back)) $back = 'index.php';
        header('Location: ' . $back); exit;
    }
}

// ---------- Filters ----------
$curFy = purchaseFy();
$fys = [];
if ($ready) { $st = $pdo->prepare("SELECT DISTINCT fy FROM work_orders WHERE organization_id = ? ORDER BY fy DESC"); $st->execute([$orgId]); $fys = $st->fetchAll(PDO::FETCH_COLUMN); }
if (!in_array($curFy, $fys, true)) array_unshift($fys, $curFy);
$f = ['fy' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : $curFy, 'st' => isset($ST[$_GET['st'] ?? '']) ? $_GET['st'] : '',
    'dept' => (int)($_GET['dept'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['fy' => $f['fy'], 'st' => $f['st'], 'dept' => $f['dept'] ?: '', 'q' => $f['q']], $over), fn($v) => $v !== '' && $v !== null && $v !== 0); return 'index.php' . ($q ? '?' . http_build_query($q) : ''); };

$rows = []; $K = []; $byDept = []; $byVendor = []; $nextNo = 1; $depts = [];
$scope = ''; $sp = [];
if (!$central) { $my = woMyDepts() ?: [0]; $scope = " AND w.department_id IN (" . implode(',', array_map('intval', $my)) . ")"; }
if ($ready) {
    $nextNo = woNextNum($pdo, $orgId, $curFy);
    $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local, ''), name) nm FROM departments WHERE organization_id = ? ORDER BY nm"); $st->execute([$orgId]); $depts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $base = " FROM work_orders w WHERE w.organization_id = ? AND w.fy = ?" . $scope; $bp = [$orgId, $f['fy']];
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(CASE WHEN status <> 'cancelled' THEN grand_total ELSE 0 END) amt, SUM(status = 'issued') iss, SUM(status = 'completed') done, SUM(status = 'draft') dr, SUM(status = 'cancelled') cx,
        SUM(DATE_FORMAT(wo_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) mn, SUM(CASE WHEN DATE_FORMAT(wo_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status <> 'cancelled' THEN grand_total ELSE 0 END) mamt,
        SUM(gst_amount) gst, COUNT(DISTINCT vendor_name) vendors $base");
    $st->execute($bp); $K = $st->fetch();
    $st = $pdo->prepare("SELECT w.department_id, COALESCE(MAX(w.department_name), '') dn, COUNT(*) n, SUM(CASE WHEN w.status <> 'cancelled' THEN w.grand_total ELSE 0 END) amt $base GROUP BY w.department_id, CASE WHEN w.department_id IS NULL THEN w.department_name END ORDER BY amt DESC"); $st->execute($bp); $byDept = $st->fetchAll();
    $st = $pdo->prepare("SELECT w.vendor_name, COUNT(*) n, SUM(CASE WHEN w.status <> 'cancelled' THEN w.grand_total ELSE 0 END) amt $base GROUP BY w.vendor_name ORDER BY amt DESC LIMIT 6"); $st->execute($bp); $byVendor = $st->fetchAll();
    $where = $base; $wp = $bp;
    if ($f['st'] !== '') { $where .= " AND w.status = ?"; $wp[] = $f['st']; }
    if ($f['dept']) { $where .= " AND w.department_id = ?"; $wp[] = $f['dept']; }
    if ($f['q'] !== '') { $where .= " AND (w.vendor_name LIKE ? OR w.subject LIKE ? OR w.wo_no LIKE ? OR w.department_name LIKE ? OR EXISTS (SELECT 1 FROM work_order_items i WHERE i.work_order_id = w.id AND i.particular LIKE ?))"; $lk = '%' . $f['q'] . '%'; array_push($wp, $lk, $lk, $f['q'] . '%', $lk, $lk); }
    if (($_GET['export'] ?? '') === 'csv') {
        $st = $pdo->prepare("SELECT w.* $where ORDER BY w.wo_num, w.wo_no"); $st->execute($wp);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="work-orders-' . $f['fy'] . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['W.O. No', 'Date', 'Department', 'Vendor', 'Phone', 'Subject', 'Sub Total', 'GST %', 'GST', 'Round Off', 'Grand Total', 'Status']);
        while ($r = $st->fetch()) fputcsv($o, [woFullNo($r), purchaseDate($r['wo_date']), $r['department_name'] ?: ($depts[$r['department_id']] ?? ''), $r['vendor_name'], $r['vendor_phone'], $r['subject'], $r['subtotal'], $r['gst_pct'], $r['gst_amount'], $r['round_off'], $r['grand_total'], strip_tags($ST[$r['status']][0] ?? $r['status'])]);
        fclose($o); exit;
    }
    $st = $pdo->prepare("SELECT w.*, (SELECT COUNT(*) FROM work_order_items i WHERE i.work_order_id = w.id) n_items $where ORDER BY w.wo_num DESC, w.wo_no DESC LIMIT 300"); $st->execute($wp); $rows = $st->fetchAll();
}
$seedPending = $ready && $canAll && orgSetting($pdo, $orgId, 'wo_seed_done', '') === '';
$SET = $ready ? woSettings($pdo, $orgId) : null;
$myDeptNames = array_values(array_filter(array_map(fn($d) => $depts[$d] ?? '', woMyDepts())));

$pageTitle = 'वर्क ऑर्डर';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .wo-hero{border-radius:20px;padding:20px 22px;background:linear-gradient(120deg,#0F766E,#0D9488 55%,#2DD4BF);color:#fff;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:14px;box-shadow:0 14px 34px -18px rgba(15,118,110,.8);position:relative;overflow:hidden;}
  .wo-hero:after{content:"";position:absolute;right:-40px;top:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08);}
  .wo-hero h1{font-size:22px;font-weight:900;margin:0;color:#fff;} .wo-hero .ey{font-size:12px;opacity:.85;font-weight:700;}
  .wo-hero .sc{display:inline-block;margin-top:6px;font-size:12px;font-weight:700;background:rgba(255,255,255,.18);border-radius:99px;padding:3px 11px;}
  .wo-hero .nx{text-align:center;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:14px;padding:8px 16px;position:relative;z-index:1;}
  .wo-hero .nx small{display:block;font-size:11px;opacity:.85;} .wo-hero .nx b{font-size:22px;font-weight:900;letter-spacing:.4px;}
  .wo-hero .acts{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1;}
  .wo-hero .acts a,.wo-hero .acts button{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:11px;padding:9px 14px;font-size:13px;font-weight:700;text-decoration:none;}
  .wo-hero .acts .pri{background:#fff;color:#0F766E;border-color:#fff;box-shadow:0 6px 14px -8px rgba(0,0,0,.4);}
  .wo-bar2{display:flex;gap:8px;flex-wrap:wrap;align-items:center;border:1px solid var(--line);background:var(--surface);border-radius:14px;padding:8px;margin-bottom:12px;}
  .wo-bar2 .srch{flex:1;min-width:220px;position:relative;} .wo-bar2 .srch input{width:100%;border:1px solid var(--line);border-radius:10px;padding:8px 10px 8px 34px;font:inherit;font-size:13px;background:var(--canvas);color:var(--text);} .wo-bar2 .srch:before{content:"🔍";position:absolute;left:10px;top:7px;font-size:13px;}
  .wo-seg{display:flex;gap:4px;flex-wrap:wrap;} .wo-seg a{font-size:12.5px;font-weight:700;padding:7px 11px;border-radius:10px;text-decoration:none;color:var(--text);background:var(--canvas);}
  .wo-seg a.on{background:#0F766E;color:#fff;} .wo-seg a small{font-weight:800;opacity:.7;margin-left:4px;}
  .wo-ins{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-bottom:14px;} .wo-ins > div{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:12px 14px;font-size:12.5px;}
  .wo-ins h6{font-size:12.5px;font-weight:800;margin:0 0 8px;} .wo-bar{display:flex;align-items:center;gap:8px;margin:4px 0;padding:3px 4px;border-radius:8px;} .wo-bar:hover{background:var(--canvas);} .wo-bar span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .wo-bar i{display:block;height:8px;border-radius:5px;background:linear-gradient(90deg,#0D9488,#5EEAD4);} .wo-bar b{white-space:nowrap;font-variant-numeric:tabular-nums;} .wo-dim{font-size:11.5px;color:var(--text-dim);}
  .wo-mon{font-size:12px;font-weight:800;color:var(--text-dim);margin:16px 2px 8px;display:flex;align-items:center;gap:8px;} .wo-mon:after{content:"";flex:1;height:1px;background:var(--line);} .wo-mon b{color:#0F766E;}
  .wo-card{display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:14px;align-items:center;border:1px solid var(--line);border-left:5px solid #3B82F6;border-radius:14px;background:var(--surface);padding:12px 14px;margin-bottom:8px;transition:box-shadow .15s,transform .15s;}
  .wo-card:hover{box-shadow:0 8px 22px -14px rgba(15,23,42,.45);transform:translateY(-1px);}
  .wo-card.s-draft{border-left-color:#9CA3AF;} .wo-card.s-completed{border-left-color:#16A34A;} .wo-card.s-cancelled{border-left-color:#DC2626;opacity:.75;}
  .wo-num{text-align:center;border-radius:12px;background:color-mix(in srgb,#0D9488 10%,var(--surface));padding:8px 4px;} .wo-num b{display:block;font-size:20px;font-weight:900;color:#0F766E;line-height:1.1;} .wo-num small{font-size:10.5px;color:var(--text-dim);font-weight:700;}
  .wo-sub{font-weight:800;font-size:14px;line-height:1.35;color:inherit;text-decoration:none;} .wo-sub:hover{color:#0F766E;}
  .wo-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;font-size:11.5px;} .wo-tags span{background:var(--canvas);border-radius:7px;padding:2px 8px;color:var(--text-dim);} .wo-tags b{color:var(--text);font-weight:700;}
  .wo-right{text-align:right;min-width:180px;} .wo-amt{font-weight:900;font-size:18px;font-variant-numeric:tabular-nums;} .wo-amt small{display:block;font-size:11px;color:var(--text-dim);font-weight:600;}
  .wo-chip{display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:99px;white-space:nowrap;margin-top:4px;} .st-dr{background:#E5E7EB;color:#374151;} .st-is{background:#DBEAFE;color:#1E40AF;} .st-dn{background:#DCFCE7;color:#166534;} .st-cx{background:#FEE2E2;color:#991B1B;}
  .wo-btns{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;margin-top:8px;} .wo-btns a,.wo-btns button{font-size:12px;border-radius:9px;padding:5px 10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:700;white-space:nowrap;}
  .wo-btns form{display:contents;} .wo-btns .pr{border-color:#0D9488;color:#0F766E;} .wo-btns .ok{background:#16A34A;border-color:#16A34A;color:#fff;}
  .wo-seed{border:2px dashed #0D9488;border-radius:16px;padding:14px 16px;margin-bottom:14px;background:color-mix(in srgb,#0D9488 6%,var(--surface));display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  .wo-empty{border:1px dashed var(--line);border-radius:16px;padding:36px 20px;text-align:center;color:var(--text-dim);font-size:13.5px;background:var(--surface);} .wo-empty div{font-size:40px;}
  @media (max-width:760px){ .wo-hero{padding:16px;} .wo-hero h1{font-size:19px;} .wo-card{grid-template-columns:64px minmax(0,1fr);} .wo-num b{font-size:16px;} .wo-right{grid-column:1/-1;text-align:left;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;min-width:0;} .wo-btns{justify-content:flex-start;margin-top:0;} }
</style>

<div class="wo-hero">
  <div style="position:relative;z-index:1;"><div class="ey">🛒 खरेदी विभाग</div><h1>🧾 वर्क ऑर्डर</h1><span class="sc"><?= $central ? '🏢 केंद्रीय — सर्व विभागांच्या वर्क ऑर्डर' : '🗂️ ' . h(implode(', ', $myDeptNames)) . ' — फक्त तुमच्या विभागाच्या' ?></span></div>
  <?php if ($ready): ?><div class="nx" title="संपूर्ण संस्थेचा सलग क्रम — कोणत्याही विभागाने बनवली तरी"><small>🔢 पुढचा W.O. क्र.</small><b><?= h(woFmtNo($nextNo) . '/' . $curFy) ?></b></div><?php endif; ?>
  <div class="acts">
    <?php if ($ready && woCanCreate()): ?><a class="pri" href="wo.php">＋ नवीन वर्क ऑर्डर</a><?php endif; ?>
    <a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a>
    <?php if ($ready && $canAll): ?><button type="button" data-bs-toggle="modal" data-bs-target="#woSet">⚙️ सेटिंग्ज</button><?php endif; ?>
  </div>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ वर्क ऑर्डरसाठी Database Update बाकी आहे — Super Admin ने System Update → Database Update चालवावं.</div><?php endif; ?>

<?php if ($seedPending): ?>
<form method="POST" class="wo-seed" onsubmit="return confirm('Word file मधील 11 वर्क ऑर्डर (W.O. 003 ते 017, आ.व. 2026-27) आयात करायच्या?');">
  <input type="hidden" name="action" value="import_seed"><span style="font-size:26px;">📥</span>
  <div style="flex:1;min-width:220px;"><b>Work Order 2026-27 — 11 वर्क ऑर्डर</b><div style="font-size:12.5px;color:var(--text-dim);">Word file मधील W.O. 003 ते 017: पुरवठादार, वस्तू, दर, GST, एकूण, 24 अटी, सह्या व विभाग — एका क्लिकमध्ये. नंतरचा क्रमांक 018 पासून.</div></div>
  <button type="submit" class="btn btn-sm" style="background:#0D9488;color:#fff;border-radius:9px;font-weight:700;">आयात करा</button>
</form>
<?php endif; ?>

<?php if ($ready): ?>
<?php if ((int)$K['n']): ?>
<?= smartTiles([
    ['label' => '🧾 वर्क ऑर्डर', 'value' => (int)$K['n'], 'sub' => 'आ.व. ' . purchaseFyLabel($f['fy']) . ' · ' . (int)$K['vendors'] . ' पुरवठादार'],
    ['label' => '💰 एकूण रक्कम', 'value' => purchaseInr($K['amt']), 'sub' => 'GST ' . purchaseInr($K['gst']), 'tone' => 'ok'],
    ['label' => '📅 या महिन्यात', 'value' => (int)$K['mn'], 'sub' => purchaseInr($K['mamt'])],
    ['label' => '✅ दिलेल्या (काम चालू)', 'value' => (int)$K['iss'], 'sub' => (int)$K['dr'] . ' तयार होत आहेत', 'tone' => (int)$K['iss'] ? 'warn' : '', 'href' => $qs(['st' => 'issued'])],
    ['label' => '🏁 काम पूर्ण', 'value' => (int)$K['done'], 'sub' => (int)$K['cx'] . ' रद्द', 'tone' => 'ok', 'href' => $qs(['st' => 'completed'])],
]) ?>
<?php endif; ?>

<form method="GET" class="wo-bar2" data-no-smart-filter>
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()"><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $f['fy'] === $y ? 'selected' : '' ?>>आ.व. <?= h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select>
  <?php if ($f['st'] !== ''): ?><input type="hidden" name="st" value="<?= h($f['st']) ?>"><?php endif; ?><?php if ($f['dept']): ?><input type="hidden" name="dept" value="<?= (int)$f['dept'] ?>"><?php endif; ?>
  <div class="srch"><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="पुरवठादार, विषय, W.O. क्र., विभाग, वस्तू…"></div>
  <div class="wo-seg">
    <?php $cnt = ['' => (int)($K['n'] ?? 0), 'draft' => (int)($K['dr'] ?? 0), 'issued' => (int)($K['iss'] ?? 0), 'completed' => (int)($K['done'] ?? 0), 'cancelled' => (int)($K['cx'] ?? 0)];
    foreach (['' => 'सर्व'] + array_map(fn($x) => $x[0], $ST) as $k => $l): ?><a href="<?= h($qs(['st' => $k])) ?>" class="<?= $f['st'] === $k ? 'on' : '' ?>"><?= h($l) ?><small><?= $cnt[$k] ?? 0 ?></small></a><?php endforeach; ?>
  </div>
  <?php if ($f['q'] !== '' || $f['st'] !== '' || $f['dept']): ?><a href="<?= h($qs(['q' => '', 'st' => '', 'dept' => ''])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;">✕ Filter</a><?php endif; ?>
</form>

<?php if ($byDept && ($central || count($byDept) > 1)): ?>
<div class="wo-ins">
  <div><h6>🏢 विभागनिहाय रक्कम</h6><?php $mx = max(1, ...array_map(fn($x) => (float)$x['amt'], $byDept)); foreach ($byDept as $d): $dn = $depts[$d['department_id']] ?? ($d['dn'] ?: 'विभाग नाही'); ?>
    <a class="wo-bar" href="<?= h((int)$d['department_id'] ? $qs(['dept' => (int)$d['department_id'] === $f['dept'] ? '' : (int)$d['department_id'], 'q' => '']) : $qs(['dept' => '', 'q' => $d['dn']])) ?>" style="color:inherit;text-decoration:none;<?= (int)$d['department_id'] && (int)$d['department_id'] === $f['dept'] ? 'font-weight:800;background:var(--canvas);' : '' ?>"><span><?= h($dn) ?> <small class="wo-dim">(<?= (int)$d['n'] ?>)</small></span><i style="width:<?= round((float)$d['amt'] * 100 / $mx) ?>px;"></i><b><?= h(purchaseInr($d['amt'])) ?></b></a><?php endforeach; ?></div>
  <?php if ($byVendor): ?><div><h6>👷 सर्वात जास्त काम — पुरवठादार / ठेकेदार</h6><?php $mx = max(1, ...array_map(fn($x) => (float)$x['amt'], $byVendor)); foreach ($byVendor as $v): ?>
    <a class="wo-bar" href="<?= h($qs(['q' => $v['vendor_name']])) ?>" style="color:inherit;text-decoration:none;"><span><?= h($v['vendor_name']) ?> <small class="wo-dim">(<?= (int)$v['n'] ?>)</small></span><i style="width:<?= round((float)$v['amt'] * 100 / $mx) ?>px;background:linear-gradient(90deg,#7C3AED,#C4B5FD);"></i><b><?= h(purchaseInr($v['amt'])) ?></b></a><?php endforeach; ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="wo-empty"><div>🧾</div><?= (int)($K['n'] ?? 0) ? 'या अटींनुसार वर्क ऑर्डर नाही.' : 'या आर्थिक वर्षात अजून वर्क ऑर्डर नाही.' ?>
    <?php if (woCanCreate()): ?><div class="mt-2" style="font-size:13px;"><a href="wo.php" class="btn btn-sm" style="background:#0D9488;color:#fff;border-radius:9px;font-weight:700;">＋ नवीन वर्क ऑर्डर</a></div><?php endif; ?></div>
<?php else: ?>
<?php $mon = ''; foreach ($rows as $r): $dn = $depts[$r['department_id']] ?? ($r['department_name'] ?: '—'); $mod = woCanModify($r);
  $mk = date('Y-m', strtotime($r['wo_date'])); if ($mk !== $mon): $mon = $mk; $mrs = array_filter($rows, fn($x) => date('Y-m', strtotime($x['wo_date'])) === $mk); ?>
  <div class="wo-mon">📅 <?= h(woMonthName($mk)) ?> <b><?= count($mrs) ?></b> · <?= h(purchaseInr(array_sum(array_map(fn($x) => $x['status'] !== 'cancelled' ? (float)$x['grand_total'] : 0, $mrs)))) ?></div>
  <?php endif; ?>
  <div class="wo-card s-<?= h($r['status']) ?>">
    <div class="wo-num"><b><?= h($r['wo_no']) ?></b><small><?= h($r['fy']) ?><br><?= h(purchaseDate($r['wo_date'])) ?></small></div>
    <div style="min-width:0;">
      <a class="wo-sub" href="print.php?id=<?= (int)$r['id'] ?>" target="_blank"><?= h($r['subject']) ?></a>
      <div class="wo-tags"><span>👷 <b><?= h($r['vendor_name']) ?></b></span><?= $r['vendor_phone'] ? '<span>📞 ' . h($r['vendor_phone']) . '</span>' : '' ?><span>🏢 <?= h($dn) ?></span><span>🧾 <?= (int)$r['n_items'] ?> ओळी</span></div>
    </div>
    <div class="wo-right">
      <div><div class="wo-amt">₹<?= h(woMoney($r['grand_total'])) ?><small><?= (float)$r['gst_amount'] ? 'GST ' . h(rtrim(rtrim((string)$r['gst_pct'], '0'), '.')) . '% सह' : 'GST शिवाय' ?></small></div><span class="wo-chip <?= $ST[$r['status']][1] ?? '' ?>"><?= h($ST[$r['status']][0] ?? $r['status']) ?></span></div>
      <div class="wo-btns">
        <a class="pr" href="print.php?id=<?= (int)$r['id'] ?>" target="_blank">🖨️ Print</a>
        <?php if ($mod): ?><a href="wo.php?id=<?= (int)$r['id'] ?>">✎ बदला</a><?php endif; ?>
        <?php if (woCanCreate()): ?><a href="wo.php?copy=<?= (int)$r['id'] ?>" title="याच वर्क ऑर्डरवरून नवीन (नवीन क्रमांक)">📄 याच वरून नवीन</a><?php endif; ?>
        <?php if ($mod && $r['status'] === 'issued'): ?><form method="POST" class="m-0" onsubmit="return confirm('W.O. <?= h(woFullNo($r)) ?> — काम पूर्ण झालं?');"><input type="hidden" name="action" value="set_wo_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="back" value="<?= h(basename($qs())) ?>"><button type="submit" class="ok">🏁 पूर्ण</button></form><?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($canAll): ?>
<div class="modal fade" id="woSet" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content" style="border-radius:16px;">
  <form method="POST" data-no-smart-filter><input type="hidden" name="action" value="save_wo_settings">
    <div class="modal-header"><div class="modal-title" style="font-weight:800;">⚙️ वर्क ऑर्डर सेटिंग्ज (नवीन वर्क ऑर्डरसाठी)</div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <label class="form-label-custom">Letterhead (ओळी)</label><textarea name="letterhead" class="form-control-custom" rows="6"><?= h($SET['letterhead']) ?></textarea>
      <div class="row g-2 mt-1"><div class="col-md-6"><label class="form-label-custom">सहीखालची संस्थेची ओळ</label><input name="sign_org" class="form-control-custom" value="<?= h($SET['sign_org']) ?>"></div>
        <div class="col-md-6"><label class="form-label-custom">तळटीप ("For …")</label><input name="footer" class="form-control-custom" value="<?= h($SET['footer']) ?>"></div></div>
      <label class="form-label-custom mt-2">अटी — एका ओळीत एक ("मजकूर|YES" किंवा "|NO")</label>
      <textarea name="terms" class="form-control-custom" rows="10" style="font-size:12px;"><?= h(implode("\n", array_map(fn($t) => $t[0] . ($t[1] !== '' ? '|' . $t[1] : ''), $SET['terms']))) ?></textarea>
      <label class="form-label-custom mt-2">Payment — बँक तपशील ओळी</label><textarea name="payment" class="form-control-custom" rows="4" style="font-size:12px;"><?= h(implode("\n", $SET['payment'])) ?></textarea>
    </div>
    <div class="modal-footer"><button type="submit" class="btn" style="background:#0D9488;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button></div>
  </form>
</div></div></div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
