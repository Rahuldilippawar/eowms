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
  .wo-act{display:flex;gap:6px;flex-wrap:wrap;} .wo-act a,.wo-act button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .wo-act .pri{background:linear-gradient(90deg,#0F766E,#0D9488);color:#fff;border:0;}
  .wo-next{display:inline-flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;background:color-mix(in srgb,#0D9488 10%,var(--surface));color:#0F766E;border-radius:99px;padding:4px 12px;}
  .wo-scope{font-size:11.5px;font-weight:700;border-radius:99px;padding:2px 10px;background:color-mix(in srgb,#7C3AED 12%,var(--surface));color:#6D28D9;vertical-align:middle;}
  .wo-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;} .wo-chips a{font-size:12px;padding:5px 10px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .wo-chips a.on{background:#0F766E;border-color:#0F766E;color:#fff;} .wo-chips small{color:var(--text-dim);margin-left:3px;} .wo-chips a.on small{color:#CCFBF1;}
  .wo-ins{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-bottom:12px;} .wo-ins > div{border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:10px 12px;font-size:12.5px;}
  .wo-ins h6{font-size:12px;font-weight:800;margin:0 0 6px;color:var(--text-dim);} .wo-bar{display:flex;align-items:center;gap:6px;margin:3px 0;} .wo-bar span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;} .wo-bar i{display:block;height:7px;border-radius:5px;background:linear-gradient(90deg,#0D9488,#5EEAD4);} .wo-bar b{white-space:nowrap;}
  .wo-t{width:100%;border-collapse:separate;border-spacing:0 6px;font-size:13px;} .wo-t td{background:var(--surface);border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:9px 10px;vertical-align:top;} .wo-t td:first-child{border-left:1px solid var(--line);border-radius:12px 0 0 12px;} .wo-t td:last-child{border-right:1px solid var(--line);border-radius:0 12px 12px 0;}
  .wo-no{font-weight:800;color:#0F766E;font-size:14px;white-space:nowrap;} .wo-sub{font-weight:700;line-height:1.35;} .wo-dim{font-size:11.5px;color:var(--text-dim);}
  .wo-amt{font-weight:800;font-size:15px;white-space:nowrap;text-align:right;}
  .wo-chip{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;white-space:nowrap;} .st-dr{background:#E5E7EB;color:#374151;} .st-is{background:#DBEAFE;color:#1E40AF;} .st-dn{background:#DCFCE7;color:#166534;} .st-cx{background:#FEE2E2;color:#991B1B;}
  .wo-btns{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;} .wo-btns a,.wo-btns button{font-size:12px;border-radius:8px;padding:4px 9px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:600;white-space:nowrap;}
  .wo-seed{border:2px dashed #0D9488;border-radius:14px;padding:14px 16px;margin-bottom:12px;background:color-mix(in srgb,#0D9488 6%,var(--surface));display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  @media (max-width:760px){ .wo-t, .wo-t tbody, .wo-t tr, .wo-t td{display:block;} .wo-t tr{border:1px solid var(--line);border-radius:12px;margin-bottom:8px;overflow:hidden;} .wo-t td{border:0 !important;border-radius:0 !important;padding:5px 12px;} .wo-amt,.wo-btns{text-align:left;justify-content:flex-start;} }
</style>

<div class="page-eyebrow">🛒 खरेदी विभाग</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">🧾 वर्क ऑर्डर <span class="wo-scope"><?= $central ? '🏢 केंद्रीय — सर्व विभाग' : '🗂️ ' . h(implode(', ', $myDeptNames)) ?></span></div>
  <div class="wo-act">
    <?php if ($ready): ?><span class="wo-next" title="संपूर्ण संस्थेचा सलग क्रम — कोणत्याही विभागाने बनवली तरी">🔢 पुढचा W.O. क्र. <?= h(woFmtNo($nextNo) . '/' . $curFy) ?></span><?php endif; ?>
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
<form method="GET" class="d-flex flex-wrap gap-2 mb-2" data-no-smart-filter>
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()"><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $f['fy'] === $y ? 'selected' : '' ?>>आ.व. <?= h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select>
  <?php if ($f['st'] !== ''): ?><input type="hidden" name="st" value="<?= h($f['st']) ?>"><?php endif; ?><?php if ($f['dept']): ?><input type="hidden" name="dept" value="<?= (int)$f['dept'] ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" style="width:auto;min-width:240px;flex:1;max-width:420px;" placeholder="पुरवठादार, विषय, W.O. क्र., विभाग, वस्तू…">
  <button class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">शोधा</button>
  <?php if ($f['q'] !== '' || $f['st'] !== '' || $f['dept']): ?><a href="<?= h($qs(['q' => '', 'st' => '', 'dept' => ''])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">✕ Filter काढा</a><?php endif; ?>
</form>

<?php if ((int)$K['n']): ?>
<?= smartTiles([
    ['label' => '🧾 वर्क ऑर्डर', 'value' => (int)$K['n'], 'sub' => 'आ.व. ' . purchaseFyLabel($f['fy']) . ' · ' . (int)$K['vendors'] . ' पुरवठादार'],
    ['label' => '💰 एकूण रक्कम', 'value' => purchaseInr($K['amt']), 'sub' => 'GST ' . purchaseInr($K['gst']), 'tone' => 'ok'],
    ['label' => '📅 या महिन्यात', 'value' => (int)$K['mn'], 'sub' => purchaseInr($K['mamt'])],
    ['label' => '✅ दिलेल्या (काम चालू)', 'value' => (int)$K['iss'], 'sub' => (int)$K['dr'] . ' तयार होत आहेत', 'tone' => (int)$K['iss'] ? 'warn' : '', 'href' => $qs(['st' => 'issued'])],
    ['label' => '🏁 काम पूर्ण', 'value' => (int)$K['done'], 'sub' => (int)$K['cx'] . ' रद्द', 'tone' => 'ok', 'href' => $qs(['st' => 'completed'])],
]) ?>
<?php endif; ?>

<div class="wo-chips">
  <a href="<?= h($qs(['st' => ''])) ?>" class="<?= $f['st'] === '' ? 'on' : '' ?>">सर्व</a>
  <?php foreach ($ST as $k => [$l]): ?><a href="<?= h($qs(['st' => $k])) ?>" class="<?= $f['st'] === $k ? 'on' : '' ?>"><?= h($l) ?></a><?php endforeach; ?>
</div>

<?php if ($byDept && ($central || count($byDept) > 1)): ?>
<div class="wo-ins">
  <div><h6>🏢 विभागनिहाय</h6><?php $mx = max(1, ...array_map(fn($x) => (float)$x['amt'], $byDept)); foreach ($byDept as $d): $dn = $depts[$d['department_id']] ?? ($d['dn'] ?: 'विभाग नाही'); ?>
    <a class="wo-bar" href="<?= h((int)$d['department_id'] ? $qs(['dept' => (int)$d['department_id'] === $f['dept'] ? '' : (int)$d['department_id'], 'q' => '']) : $qs(['dept' => '', 'q' => $d['dn']])) ?>" style="color:inherit;text-decoration:none;<?= (int)$d['department_id'] && (int)$d['department_id'] === $f['dept'] ? 'font-weight:800;' : '' ?>"><span><?= h($dn) ?> <small class="wo-dim">(<?= (int)$d['n'] ?>)</small></span><i style="width:<?= round((float)$d['amt'] * 90 / $mx) ?>px;"></i><b><?= h(purchaseInr($d['amt'])) ?></b></a><?php endforeach; ?></div>
  <?php if ($byVendor): ?><div><h6>👷 सर्वात जास्त काम — पुरवठादार / ठेकेदार</h6><?php $mx = max(1, ...array_map(fn($x) => (float)$x['amt'], $byVendor)); foreach ($byVendor as $v): ?>
    <a class="wo-bar" href="<?= h($qs(['q' => $v['vendor_name']])) ?>" style="color:inherit;text-decoration:none;"><span><?= h($v['vendor_name']) ?> <small class="wo-dim">(<?= (int)$v['n'] ?>)</small></span><i style="width:<?= round((float)$v['amt'] * 90 / $mx) ?>px;background:linear-gradient(90deg,#7C3AED,#C4B5FD);"></i><b><?= h(purchaseInr($v['amt'])) ?></b></a><?php endforeach; ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);font-size:13.5px;"><?= (int)($K['n'] ?? 0) ? 'या अटींनुसार वर्क ऑर्डर नाही.' : 'या आर्थिक वर्षात अजून वर्क ऑर्डर नाही.' ?>
    <?php if (woCanCreate()): ?><div class="mt-2"><a href="wo.php" class="btn btn-sm" style="background:#0D9488;color:#fff;border-radius:8px;">＋ नवीन वर्क ऑर्डर</a></div><?php endif; ?></div>
<?php else: ?>
<table class="wo-t"><tbody>
<?php foreach ($rows as $r): $dn = $depts[$r['department_id']] ?? ($r['department_name'] ?: '—'); $mod = woCanModify($r); ?>
  <tr>
    <td style="width:110px;"><div class="wo-no"><?= h($r['wo_no']) ?><small class="wo-dim">/<?= h($r['fy']) ?></small></div><div class="wo-dim"><?= h(purchaseDate($r['wo_date'])) ?></div></td>
    <td><div class="wo-sub"><?= h($r['subject']) ?></div><div class="wo-dim">👷 <b style="color:var(--text);"><?= h($r['vendor_name']) ?></b><?= $r['vendor_phone'] ? ' · ' . h($r['vendor_phone']) : '' ?> · 🏢 <?= h($dn) ?> · <?= (int)$r['n_items'] ?> ओळी</div></td>
    <td style="width:150px;"><div class="wo-amt">₹<?= h(woMoney($r['grand_total'])) ?></div><div class="wo-dim" style="text-align:right;"><?= (float)$r['gst_amount'] ? 'GST ' . h(rtrim(rtrim((string)$r['gst_pct'], '0'), '.')) . '% सह' : 'GST शिवाय' ?></div></td>
    <td style="width:120px;"><span class="wo-chip <?= $ST[$r['status']][1] ?? '' ?>"><?= h($ST[$r['status']][0] ?? $r['status']) ?></span></td>
    <td style="width:250px;"><div class="wo-btns">
      <a href="print.php?id=<?= (int)$r['id'] ?>" target="_blank">🖨️ Print</a>
      <?php if ($mod): ?><a href="wo.php?id=<?= (int)$r['id'] ?>">✎ बदला</a><?php endif; ?>
      <?php if (woCanCreate()): ?><a href="wo.php?copy=<?= (int)$r['id'] ?>" title="याच वर्क ऑर्डरवरून नवीन (नवीन क्रमांक)">📄 याच वरून नवीन</a><?php endif; ?>
      <?php if ($mod && $r['status'] === 'issued'): ?><form method="POST" class="m-0" onsubmit="return confirm('W.O. <?= h(woFullNo($r)) ?> — काम पूर्ण झालं?');"><input type="hidden" name="action" value="set_wo_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="back" value="<?= h(basename($qs())) ?>"><button type="submit" style="background:#16A34A;border-color:#16A34A;color:#fff;">🏁 पूर्ण</button></form><?php endif; ?>
    </div></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
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
