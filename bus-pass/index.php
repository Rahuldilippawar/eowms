<?php
// 🚌 विद्यार्थी बस पास (स्वतंत्र विभाग) — वर्गानुसार एकदम पास देणे, कुठून → कुठपर्यंत, कधीपासून → कधीपर्यंत; नूतनीकरण, रद्द, Print (A4 / Card Printer)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';
$user = currentUser();
$orgId = activeOrgId();
// बस पास विभागाची स्वतःची विद्यार्थी नोंद (bus_students) — विद्यार्थी विभागापासून वेगळी
if (!canViewBusPass()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बस पास पाहण्याची परवानगी नाही.</div>'); }
$scope = null;
$canIssue = canBusPass();
$ready = busPassReady($pdo) && busStudentsReady($pdo);
$today = date('Y-m-d');

$org = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$org = $org->fetch() ?: [];
$campuses = $pdo->prepare("SELECT id, name, city FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll(PDO::FETCH_UNIQUE);

$f = ['campus' => (int)($_REQUEST['campus'] ?? 0), 'year' => trim((string)($_REQUEST['year'] ?? '')), 'class' => trim((string)($_REQUEST['class'] ?? '')),
    'div' => trim((string)($_REQUEST['div'] ?? '')), 'state' => (string)($_GET['state'] ?? 'current'), 'q' => trim((string)($_GET['q'] ?? ''))];
$years = [];
if ($ready) {
    $years = $pdo->prepare("SELECT DISTINCT academic_year FROM bus_students WHERE organization_id = ? AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
    $years->execute([$orgId]);
    $years = $years->fetchAll(PDO::FETCH_COLUMN);
}
if ($f['year'] === '' && $years) $f['year'] = $years[0];
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div'], 'state' => $f['state'] !== 'current' ? $f['state'] : '', 'q' => $f['q']], $over), fn($v) => $v !== '' && $v !== null); return 'index.php' . ($q ? '?' . http_build_query($q) : ''); };
$cleanDate = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : null;

// ---------- जतन (पास देणे / नूतनीकरण / रद्द) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canIssue && $ready) {
    $action = $_POST['action'] ?? '';
    $vf = $cleanDate($_POST['valid_from'] ?? ''); $vt = $cleanDate($_POST['valid_till'] ?? '');
    $back = $qs();
    if (in_array($action, ['issue', 'renew'], true) && (!$vf || !$vt || $vt < $vf)) {
        $_SESSION['flash'] = '⚠️ Valid तारखा तपासा — "कधीपर्यंत" ही "कधीपासून" नंतरची हवी.';
        header('Location: ' . $back); exit;
    }
    if ($action === 'issue') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['sid'] ?? [])))));
        $pp = [$orgId];
        $okIds = [];
        if ($ids) {
            $st = $pdo->prepare("SELECT s.id FROM bus_students s WHERE s.organization_id = ? AND s.is_active = 1" . studentScopeSql($scope, $pp) . " AND s.id IN (" . implode(',', $ids) . ")");
            $st->execute($pp);
            $okIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $commonFrom = mb_substr(trim((string)($_POST['common_from'] ?? '')), 0, 120);
        $commonTo = mb_substr(trim((string)($_POST['common_to'] ?? '')), 0, 120);
        $n = 0; $skip = 0; $newIds = [];
        $pdo->beginTransaction();
        $commonRoute = mb_substr(trim((string)($_POST['route_name'] ?? '')), 0, 120);
        $own = $pdo->prepare("SELECT route_name FROM bus_students WHERE id = ? AND organization_id = ?");
        foreach ($okIds as $sid) {
            $from = mb_substr(trim((string)($_POST['from'][$sid] ?? '')), 0, 120) ?: $commonFrom;
            $to = mb_substr(trim((string)($_POST['to'][$sid] ?? '')), 0, 120) ?: $commonTo;
            if ($from === '' || $to === '') { $skip++; continue; }
            $own->execute([$sid, $orgId]);
            // मार्ग: वर भरलेला सगळ्यांसाठी; रिकामा असेल तर त्या विद्यार्थ्याचा नोंदलेला मार्ग
            $route = $commonRoute !== '' ? $commonRoute : (string)$own->fetchColumn();
            $newIds[] = busPassIssue($pdo, $orgId, $sid, ['from_place' => busPlace($from), 'to_place' => busPlace($to), 'route_name' => $route,
                'valid_from' => $vf, 'valid_till' => $vt, 'fee' => trim((string)($_POST['fee'] ?? ''))], $user['id']);
            $n++;
        }
        $pdo->commit();
        if ($n) logActivity('बस पास दिले', 'organization', $orgId, $n . ' पास');
        $_SESSION['flash'] = $n ? '✓ ' . $n . ' बस पास तयार झाले.' . ($skip ? ' ' . $skip . ' विद्यार्थ्यांचे "कुठून / कुठपर्यंत" रिकामे — वगळले.' : '') : '⚠️ एकही पास तयार झाला नाही — विद्यार्थी निवडा व "कुठून / कुठपर्यंत" भरा.';
        header('Location: ' . ($n ? $qs(['print_ids' => implode(',', $newIds)]) : $back)); exit;
    }
    if ($action === 'renew') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['pid'] ?? [])))));
        $n = 0; $newIds = [];
        if ($ids) {
            $pp = [$orgId];
            $st = $pdo->prepare("SELECT p.* FROM student_bus_passes p JOIN bus_students s ON s.id = p.bus_student_id WHERE p.organization_id = ? AND s.is_active = 1" . studentScopeSql($scope, $pp) . " AND p.id IN (" . implode(',', $ids) . ")");
            $st->execute($pp);
            $pdo->beginTransaction();
            foreach ($st->fetchAll() as $old) {
                $newIds[] = busPassIssue($pdo, $orgId, (int)$old['bus_student_id'], ['from_place' => $old['from_place'], 'to_place' => $old['to_place'], 'route_name' => $old['route_name'],
                    'valid_from' => $vf, 'valid_till' => $vt, 'fee' => ($_POST['fee'] ?? '') !== '' ? $_POST['fee'] : ($old['fee'] ?? '')], $user['id']);
                $n++;
            }
            $pdo->commit();
        }
        if ($n) logActivity('बस पास नूतनीकरण', 'organization', $orgId, $n . ' पास');
        $_SESSION['flash'] = $n ? '✓ ' . $n . ' पासचं नूतनीकरण झालं (' . date('d/m/Y', strtotime($vf)) . ' → ' . date('d/m/Y', strtotime($vt)) . ').' : '⚠️ पास निवडा.';
        header('Location: ' . ($n ? $qs(['print_ids' => implode(',', $newIds)]) : $back)); exit;
    }
    if ($action === 'save_source') {
        $src = mb_substr(trim((string)($_POST['source'] ?? '')), 0, 120);
        if ($src !== '' && function_exists('orgSettingSet')) { orgSettingSet($pdo, $orgId, 'bus_pass_source', $src); logActivity('बस पास Source बदलला', 'organization', $orgId, $src); $_SESSION['flash'] = '✓ Source नेहमी: ' . $src; }
        header('Location: ' . $back); exit;
    }
    if ($action === 'cancel_pass') {
        $pid = (int)($_POST['pass_id'] ?? 0);
        $pdo->prepare("UPDATE student_bus_passes SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$pid, $orgId]);
        logActivity('बस पास रद्द', 'student_bus_pass', $pid, '');
        $_SESSION['flash'] = '✓ पास रद्द केला.';
        header('Location: ' . $back); exit;
    }
}

// ---------- पासची यादी ----------
$passSql = busPassSelectSql() . " WHERE p.organization_id = ?";
$pp = [$orgId];
$passSql .= studentScopeSql($scope, $pp);

// 🖨️ Print — निवडलेले / आत्ता दिलेले पास
if (!empty($_GET['print']) && $ready) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    $layout = ($_GET['layout'] ?? '') === 'pvc' ? 'pvc' : 'a4';
    $rows = [];
    if ($ids) { $st = $pdo->prepare($passSql . " AND p.id IN (" . implode(',', array_slice($ids, 0, 1000)) . ") ORDER BY s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.name"); $st->execute($pp); $rows = $st->fetchAll(); }
    elseif (!empty($_GET['all'])) {
        // 🖨️ एकदम सगळे — निवडलेल्या College / वर्ष / वर्ग / तुकडीतल्या प्रत्येक विद्यार्थ्याचा सध्याचा चालू पास
        $asql = $passSql . " AND s.is_active = 1 AND p.status = 'active' AND p.valid_till >= CURDATE()
            AND p.id = (SELECT p2.id FROM student_bus_passes p2 WHERE p2.bus_student_id = p.bus_student_id AND p2.status = 'active' ORDER BY p2.valid_till DESC, p2.id DESC LIMIT 1)";
        $ap = $pp;
        if ($f['campus']) { $asql .= " AND s.campus_id = ?"; $ap[] = $f['campus']; }
        if (($_GET['year'] ?? '') !== '') { $asql .= " AND s.academic_year = ?"; $ap[] = (string)$_GET['year']; }
        if (isset($_GET['class'])) { $asql .= " AND COALESCE(s.class_name,'') = ?"; $ap[] = (string)$_GET['class']; }
        if (isset($_GET['div'])) { $asql .= " AND COALESCE(s.division,'') = ?"; $ap[] = (string)$_GET['div']; }
        $st = $pdo->prepare($asql . " ORDER BY s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.name LIMIT 3000");
        $st->execute($ap);
        $rows = $st->fetchAll();
    }
    $designs = studentCardSettingsAll($pdo, $orgId, $org);
    ?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>बस पास (<?= count($rows) ?>)</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/id-card.css?v=7">
<style>
  .bp-sheet{display:flex;flex-wrap:wrap;gap:18px;justify-content:center;padding:22px 16px 40px;}
  @media print{
    .idc-toolbar,.idc-hint{display:none !important;}
    .bp-sheet{display:block;padding:0;}
    body.lay-a4 .bp-pg{display:flex;flex-wrap:wrap;gap:3mm;align-content:flex-start;padding:1.6mm;break-after:page;page-break-after:always;}
    body.lay-a4 .bp-pg:last-child{break-after:auto;page-break-after:auto;}
    body.lay-a4 .bpc{outline:.2mm dashed #9CA3AF;outline-offset:1.2mm;box-shadow:none;}
    body.lay-pvc .bp-pg{display:block;} body.lay-pvc .bpc{box-shadow:none;border-radius:0;break-after:page;page-break-after:always;margin:0;}
  }
  .bp-pg{display:contents;}
</style>
<style id="pageRule"><?= $layout === 'pvc' ? '@media print{@page{size:86mm 54mm;margin:0;}}' : '@media print{@page{size:A4;margin:8mm;}}' ?></style>
</head>
<body class="idc-page lay-<?= $layout ?>">
<div class="idc-toolbar">
  <a class="back" href="<?= h($qs()) ?>">← मागे</a>
  <span style="font-size:13px;color:#C9CCE0;"><?= count($rows) ?> बस पास</span>
  <div class="grp" data-opt="layout" role="group" aria-label="Printer">
    <button type="button" data-v="a4" class="<?= $layout === 'a4' ? 'on' : '' ?>">A4 कागद</button>
    <button type="button" data-v="pvc" class="<?= $layout === 'pvc' ? 'on' : '' ?>">Card Printer</button>
  </div>
  <button type="button" class="print" onclick="window.print()">🖨️ Print करा</button>
</div>
<div class="idc-hint">A4 वर एका पानावर 8 पास (कापायच्या रेषांसह) · Card Printer वर प्रत्येक पास वेगळं 86×54 mm पान. QR Scan केल्यावर पास चालू आहे का ते दिसतं.</div>
<!--i18n:off-->
<div class="bp-sheet idc-sheet" id="bpSheet">
  <?php foreach (array_chunk($rows, 8) as $chunk): ?><div class="bp-pg"><?php foreach ($chunk as $p): ?><?= busPassCard($p, $designs[(int)$p['campus_id']] ?? studentCardDefaults(['name' => $p['campus_name']], $org)) ?><?php endforeach; ?></div><?php endforeach; ?>
</div>
<!--i18n:on-->
<script src="../assets/js/id-card-fit.js?v=4"></script>
<script>
  document.querySelectorAll('.idc-toolbar .grp button').forEach(function (b) {
    b.addEventListener('click', function () {
      b.parentNode.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.body.className = document.body.className.replace(/lay-\w+/, 'lay-' + b.dataset.v);
      document.getElementById('pageRule').textContent = b.dataset.v === 'pvc' ? '@media print{@page{size:86mm 54mm;margin:0;}}' : '@media print{@page{size:A4;margin:8mm;}}';
      var u = new URL(location.href); u.searchParams.set('layout', b.dataset.v); history.replaceState(null, '', u);
    });
  });
</script>
</body>
</html>
    <?php
    exit;
}

$list = []; $kpi = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'without' => 0];
$classes = []; $issueRows = []; $places = ['from' => [], 'to' => []];
if ($ready) {
    // प्रत्येक विद्यार्थ्याचा सर्वात नवीन पास (रद्द सोडून) — "चालू" यादी व आकडे
    $lsql = $passSql . " AND s.is_active = 1 AND p.id = (SELECT p2.id FROM student_bus_passes p2 WHERE p2.bus_student_id = p.bus_student_id AND p2.status = 'active' ORDER BY p2.valid_till DESC, p2.id DESC LIMIT 1)";
    $lp = $pp;
    if ($f['campus']) { $lsql .= " AND s.campus_id = ?"; $lp[] = $f['campus']; }
    if ($f['year'] !== '') { $lsql .= " AND s.academic_year = ?"; $lp[] = $f['year']; }
    if ($f['class'] !== '') { $lsql .= " AND COALESCE(s.class_name,'') = ?"; $lp[] = $f['class']; }
    if ($f['div'] !== '') { $lsql .= " AND COALESCE(s.division,'') = ?"; $lp[] = $f['div']; }
    if ($f['state'] === 'cancelled') {
        $lsql = str_replace("p2.status = 'active'", "p2.status = 'cancelled'", $lsql);
    }
    if ($f['q'] !== '') { $lsql .= " AND (s.name LIKE ? OR s.admission_no = ? OR p.pass_no = ? OR p.from_place LIKE ? OR p.to_place LIKE ? OR p.route_name LIKE ?)"; $like = '%' . $f['q'] . '%'; array_push($lp, $like, $f['q'], $f['q'], $like, $like, $like); }
    $st = $pdo->prepare($lsql . " ORDER BY p.valid_till, s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.name LIMIT 2000");
    $st->execute($lp);
    foreach ($st->fetchAll() as $r) {
        $r['state'] = busPassState($r, $today);
        if (isset($kpi[$r['state']])) $kpi[$r['state']]++;
        if ($r['state'] === 'upcoming') $kpi['active']++;
        if ($f['state'] === 'current' && in_array($r['state'], ['expired', 'cancelled'], true)) continue;
        if (in_array($f['state'], ['expiring', 'expired'], true) && $r['state'] !== $f['state']) continue;
        $list[] = $r;
    }
    // वर्ग chips + पास देण्यासाठी विद्यार्थी (वर्ग निवडल्यावर)
    $cp = [$orgId];
    $csql = "SELECT COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n,
        SUM(EXISTS(SELECT 1 FROM student_bus_passes b WHERE b.bus_student_id = s.id AND b.status = 'active' AND b.valid_till >= CURDATE())) withp
        FROM bus_students s WHERE s.organization_id = ? AND s.is_active = 1" . studentScopeSql($scope, $cp);
    if ($f['campus']) { $csql .= " AND s.campus_id = ?"; $cp[] = $f['campus']; }
    if ($f['year'] !== '') { $csql .= " AND s.academic_year = ?"; $cp[] = $f['year']; }
    $cst = $pdo->prepare($csql . " GROUP BY cl, dv ORDER BY CAST(cl AS UNSIGNED), cl, dv");
    $cst->execute($cp);
    $classes = $cst->fetchAll();
    $kpi['without'] = array_sum(array_column($classes, 'n')) - array_sum(array_column($classes, 'withp'));
    if ($f['class'] !== '' && $canIssue) {
        $ip = [$orgId, $f['class'], $f['div']];
        $isql = "SELECT s.id, s.name, s.roll_no, s.admission_no, s.address, s.campus_id, s.from_place, s.to_place,
                (SELECT CONCAT_WS('|', b.from_place, b.to_place, b.valid_till, b.status, b.pass_no) FROM student_bus_passes b WHERE b.bus_student_id = s.id ORDER BY b.valid_till DESC, b.id DESC LIMIT 1) lastp
            FROM bus_students s WHERE s.organization_id = ? AND s.is_active = 1 AND COALESCE(s.class_name,'') = ? AND COALESCE(s.division,'') = ?" . studentScopeSql($scope, $ip);
        if ($f['campus']) { $isql .= " AND s.campus_id = ?"; $ip[] = $f['campus']; }
        if ($f['year'] !== '') { $isql .= " AND s.academic_year = ?"; $ip[] = $f['year']; }
        $st = $pdo->prepare($isql . " ORDER BY CAST(s.roll_no AS UNSIGNED), s.roll_no, s.name");
        $st->execute($ip);
        $issueRows = $st->fetchAll();
    }
    // आधी वापरलेली ठिकाणं (सुचवण्यासाठी)
    $st = $pdo->prepare("SELECT from_place, COUNT(*) n FROM bus_students WHERE organization_id = ? AND from_place IS NOT NULL AND from_place <> '' GROUP BY from_place ORDER BY n DESC LIMIT 80");
    $st->execute([$orgId]); $places['from'] = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->prepare("SELECT to_place, COUNT(*) n FROM bus_students WHERE organization_id = ? AND to_place IS NOT NULL AND to_place <> '' GROUP BY to_place ORDER BY n DESC LIMIT 30");
    $st->execute([$orgId]); $places['to'] = $st->fetchAll(PDO::FETCH_COLUMN);
}
[$defFrom, $defTill] = busPassDefaultDates();
$defTo = $ready ? busDefaultTo($pdo, $orgId, $f['campus']) : '';
$printIds = preg_replace('/[^0-9,]/', '', (string)($_GET['print_ids'] ?? ''));

$pageTitle = 'बस पास';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .cl-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
  .cl-chips a{font-size:12.5px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .cl-chips a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
  .cl-chips a small{opacity:.75;margin-left:3px;}
  .bp-st{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;white-space:nowrap;}
  .bp-st.active,.bp-st.upcoming{background:#DCFCE7;color:#166534;} .bp-st.expiring{background:#FEF3C7;color:#92400E;} .bp-st.expired{background:#FEE2E2;color:#991B1B;} .bp-st.cancelled{background:#E5E7EB;color:#374151;}
  .bp-route{font-weight:600;white-space:nowrap;} .bp-route i{color:var(--text-dim);font-style:normal;margin:0 4px;}
  .bp-t td,.bp-t th{vertical-align:middle;font-size:13px;}
  .bp-bar{position:sticky;bottom:0;z-index:6;background:var(--surface);border-top:1px solid var(--line);padding:10px 14px;display:none;gap:8px;align-items:center;flex-wrap:wrap;}
  .bp-bar.on{display:flex;}
  .is-t input[type=text]{min-width:120px;}
  .is-t td{vertical-align:middle;font-size:13px;}
  .is-last{font-size:11px;color:var(--text-dim);}
  @media (max-width:767px){
    .bp-t thead{display:none;} .bp-t tr{display:block;border-bottom:1px solid var(--line);padding:8px 4px;} .bp-t td{display:inline-block;border:0 !important;padding:2px 6px !important;}
    .bp-t td.nm{display:block;}
    .is-t thead{display:none;} .is-t tr{display:block;border-bottom:1px solid var(--line);padding:8px 2px;} .is-t td{display:block;border:0 !important;padding:3px 6px !important;}
    .is-t td.ck{display:inline-block;} .is-t td.nm{display:inline-block;}
    .bp-bar{bottom:70px;}
  }
</style>

<div class="page-eyebrow">🚌 विद्यार्थी वाहतूक</div>
<?php $bpTab = 'passes'; require __DIR__ . '/_tabs.php'; ?>
<?php if ($canIssue && $ready): ?>
<form method="POST" class="d-flex align-items-center gap-2 flex-wrap mb-2" style="font-size:12.5px;" data-no-smart-filter>
  <input type="hidden" name="action" value="save_source">
  <span>🏫 Source नेहमी:</span>
  <input type="text" name="source" value="<?= h(busSource($pdo, $orgId)) ?>" class="form-control-custom" style="width:auto;min-width:180px;padding:5px 10px;" maxlength="120" required>
  <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">जतन</button>
  <span style="color:var(--text-dim);">— प्रत्येक पासवर Source ⇄ विद्यार्थ्याचा थांबा (Destination)</span>
</form>
<?php endif; ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">🚌 बस पास</div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($printIds): ?><a href="<?= h($qs(['print' => 1, 'ids' => $printIds])) ?>" target="_blank" class="btn" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;">🖨️ आत्ता दिलेले पास Print करा</a><?php endif; ?>
    <?php $allN = $kpi['active'] + $kpi['expiring']; if ($ready && $allN): ?><a href="<?= h('index.php?' . http_build_query(array_filter(['print' => 1, 'all' => 1, 'campus' => $f['campus'] ?: null, 'year' => $f['year'] !== '' ? $f['year'] : null], fn($v) => $v !== null) + ($f['class'] !== '' || $f['div'] !== '' ? ['class' => $f['class'], 'div' => $f['div']] : []))) ?>" target="_blank" class="btn" style="border:1.5px solid var(--rail);color:var(--rail);border-radius:10px;font-weight:700;">🖨️ <?= $f['class'] !== '' ? h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) . ' —' : '' ?> सगळ्यांचे पास एकदम Print (<?= (int)$allN ?>)</a><?php endif; ?>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ बस पाससाठी Database Update बाकी आहे — Super Admin ने <b>System Update → Database Update</b> चालवावं.</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<form method="GET" class="d-flex flex-wrap gap-2 mb-2" data-no-smart-filter>
  <?php if (count($campuses) > 1): ?>
  <select name="campus" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व College / School</option>
    <?php foreach ($campuses as $cid => $c): ?><option value="<?= (int)$cid ?>" <?= $f['campus'] === (int)$cid ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="year" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php foreach ($years as $y): ?><option value="<?= h($y) ?>" <?= $f['year'] === $y ? 'selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" style="width:auto;min-width:200px;" placeholder="नाव, Gr.No., पास क्र., गाव…">
  <?php foreach (['class', 'div', 'state'] as $k): if ($f[$k] !== '' && !($k === 'state' && $f[$k] === 'current')): ?><input type="hidden" name="<?= $k ?>" value="<?= h($f[$k]) ?>"><?php endif; endforeach; ?>
</form>

<?php if (!$classes): ?>
  <div class="panel mb-3" style="padding:26px 20px;text-align:center;">
    <div style="font-size:15px;font-weight:700;margin-bottom:6px;">👥 बस पाससाठी अजून विद्यार्थी नाहीत</div>
    <div style="font-size:13px;color:var(--text-dim);margin-bottom:12px;">आधी बस विद्यार्थ्यांची नोंद करा — Excel/CSV ने एकदम, एक-एक, किंवा विद्यार्थी विभागातून.</div>
    <?php if ($canIssue): ?><div class="d-flex justify-content-center gap-2 flex-wrap">
      <a href="import.php" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:600;">📥 Excel / CSV Import</a>
      <a href="student.php" class="btn" style="border:1px solid var(--line);border-radius:10px;">＋ विद्यार्थी जोडा</a>
      <a href="students.php#pull" class="btn" style="border:1px solid var(--line);border-radius:10px;">🎓 विद्यार्थी विभागातून घ्या</a>
    </div><?php endif; ?>
  </div>
<?php else: ?>
<div class="cl-chips">
  <a href="<?= h($qs(['class' => '', 'div' => ''])) ?>" class="<?= $f['class'] === '' ? 'on' : '' ?>">सर्व वर्ग</a>
  <?php foreach ($classes as $c): $on = $f['class'] === $c['cl'] && $f['div'] === $c['dv']; ?>
    <a href="<?= h($qs(['class' => $c['cl'], 'div' => $c['dv']])) ?>" class="<?= $on ? 'on' : '' ?>"><?= h(($c['cl'] !== '' ? $c['cl'] : '—') . ($c['dv'] !== '' ? ' - ' . $c['dv'] : '')) ?><small>🚌 <?= (int)$c['withp'] ?>/<?= (int)$c['n'] ?></small></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?= smartTiles([
    ['label' => 'चालू पास', 'value' => $kpi['active'] + $kpi['expiring'], 'sub' => 'आज valid', 'tone' => 'ok', 'href' => $qs(['state' => ''])],
    ['label' => '7 दिवसांत संपणार', 'value' => $kpi['expiring'], 'sub' => $kpi['expiring'] ? 'नूतनीकरण करा' : 'काही नाही', 'tone' => $kpi['expiring'] ? 'warn' : 'ok', 'href' => $qs(['state' => 'expiring'])],
    ['label' => 'संपलेले', 'value' => $kpi['expired'], 'sub' => $kpi['expired'] ? 'नूतनीकरण बाकी' : 'काही नाही', 'tone' => $kpi['expired'] ? 'bad' : 'ok', 'href' => $qs(['state' => 'expired'])],
    ['label' => 'पास नसलेले विद्यार्थी', 'value' => $kpi['without'], 'sub' => 'वर्ग निवडून पास द्या', 'tone' => $kpi['without'] ? 'warn' : 'ok'],
]) ?>

<?php if ($canIssue): ?>
<div class="panel mb-3" id="issue">
  <div class="panel-head"><div class="title">➕ नवीन बस पास<?= $f['class'] !== '' ? ' — ' . h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) : '' ?></div>
    <div class="sub"><?= $f['class'] === '' ? 'आधी वरून वर्ग निवडा — त्या वर्गाचे सगळे विद्यार्थी इथे येतील.' : 'विद्यार्थी निवडा, "कुठून" भरा (सगळ्यांसाठी एकदम किंवा प्रत्येकाचं वेगळं), तारखा तपासा आणि पास द्या.' ?></div></div>
  <?php if ($issueRows): ?>
  <form method="POST" style="padding:14px 18px;" data-no-smart-filter id="issueForm">
    <input type="hidden" name="action" value="issue">
    <?php foreach (['campus', 'year', 'class', 'div'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$f[$k]) ?>"><?php endforeach; ?>
    <div class="row g-2 mb-2">
      <div class="col-md-3 col-6"><label class="form-label-custom">🏫 Source (सगळ्यांसाठी)</label><input type="text" name="common_from" class="form-control-custom" list="dlFrom" value="<?= h(busSource($pdo, $orgId)) ?>"></div>
      <div class="col-md-3 col-6"><label class="form-label-custom">🚏 Destination (सगळ्यांसाठी)</label><input type="text" name="common_to" class="form-control-custom" list="dlTo" placeholder="प्रत्येकाचा थांबा खाली"></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">📅 कधीपासून *</label><input type="date" name="valid_from" class="form-control-custom" value="<?= h($defFrom) ?>" required></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">📅 कधीपर्यंत *</label><input type="date" name="valid_till" class="form-control-custom" value="<?= h($defTill) ?>" required></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">₹ शुल्क</label><input type="number" name="fee" class="form-control-custom" min="0" step="1" placeholder="ऐच्छिक"></div>
      <div class="col-md-4 col-6"><label class="form-label-custom">🛣️ मार्ग / बस (ऐच्छिक)</label><input type="text" name="route_name" class="form-control-custom" maxlength="120" placeholder="रिकामं = प्रत्येकाचा नोंदलेला मार्ग"></div>
      <div class="col-md-8 d-flex align-items-end gap-2 flex-wrap" style="font-size:12px;">
        <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" data-quick="month">1 महिना</button>
        <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" data-quick="3month">3 महिने</button>
        <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" data-quick="year">पूर्ण शैक्षणिक वर्ष</button>
        <span style="color:var(--text-dim);">प्रत्येक विद्यार्थ्याचं "कुठून" वेगळं असेल तर खालच्या यादीत भरा — रिकामं राहिल्यास वरचं वापरलं जातं.</span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm is-t mb-0">
        <thead><tr><th style="width:30px;"><input type="checkbox" id="ckAll" checked></th><th>विद्यार्थी</th><th>Source</th><th>Destination (थांबा)</th><th>आधीचा पास</th></tr></thead>
        <tbody>
        <?php foreach ($issueRows as $r): $lp = $r['lastp'] ? explode('|', $r['lastp']) : null; $has = $lp && $lp[3] === 'active' && $lp[2] >= $today; ?>
          <tr>
            <td class="ck"><input type="checkbox" name="sid[]" value="<?= (int)$r['id'] ?>" <?= $has ? '' : 'checked' ?>></td>
            <td class="nm"><b><?= h(($r['roll_no'] !== null && $r['roll_no'] !== '' ? $r['roll_no'] . '. ' : '') . $r['name']) ?></b> <span style="font-size:11px;color:var(--text-dim);">Gr. <?= h($r['admission_no']) ?></span></td>
            <td><input type="text" name="from[<?= (int)$r['id'] ?>]" class="form-control-custom form-control-sm" list="dlFrom" value="<?= h($r['from_place'] ?: ($lp[0] ?? '')) ?>" placeholder="वरचं वापरा"></td>
            <td><input type="text" name="to[<?= (int)$r['id'] ?>]" class="form-control-custom form-control-sm" list="dlTo" value="<?= h($r['to_place'] ?: ($lp[1] ?? '')) ?>" placeholder="वरचं वापरा"></td>
            <td class="is-last"><?= $lp ? ($has ? '✅ चालू · ' : ($lp[3] === 'cancelled' ? 'रद्द · ' : '⌛ संपला · ')) . h(date('d/m/Y', strtotime($lp[2]))) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
      <span style="font-size:12px;color:var(--text-dim);">✅ चालू पास असलेले विद्यार्थी आपोआप न-निवडलेले. नवीन पास दिला तर त्याच काळातला जुना पास आपोआप रद्द होतो.</span>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:700;padding:9px 22px;">🚌 <span id="issueN"></span> पास द्या</button>
    </div>
  </form>
  <datalist id="dlFrom"><?php foreach ($places['from'] as $pl): ?><option value="<?= h($pl) ?>"><?php endforeach; ?></datalist>
  <datalist id="dlTo"><?php foreach (array_unique(array_merge($places['to'], array_filter([$defTo]))) as $pl): ?><option value="<?= h($pl) ?>"><?php endforeach; ?></datalist>
  <?php elseif ($f['class'] !== ''): ?>
    <div style="padding:16px 18px;color:var(--text-dim);font-size:13px;">या वर्गात सक्रिय विद्यार्थी नाहीत.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="title">📋 बस पास (<?= count($list) ?>)</div>
    <div class="cl-chips mb-0">
      <?php foreach (['current' => 'चालू', 'expiring' => 'लवकरच संपणारे', 'expired' => 'संपलेले', 'cancelled' => 'रद्द', 'all' => 'सर्व'] as $k => $lbl): ?>
        <a href="<?= h($qs(['state' => $k === 'current' ? '' : $k])) ?>" class="<?= $f['state'] === $k ? 'on' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$list): ?>
    <div style="padding:30px;text-align:center;color:var(--text-dim);font-size:13px;">इथे अजून पास नाहीत.<?= $canIssue && $f['class'] === '' ? ' वरून वर्ग निवडा आणि पास द्या.' : '' ?></div>
  <?php else: ?>
  <form method="POST" id="listForm" data-no-smart-filter>
    <input type="hidden" name="action" value="renew">
    <?php foreach (['campus', 'year', 'class', 'div'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$f[$k]) ?>"><?php endforeach; ?>
    <div class="table-responsive">
      <table class="table table-sm bp-t mb-0">
        <thead><tr><th style="width:30px;"><input type="checkbox" id="ckList"></th><th>विद्यार्थी</th><th>मार्ग (जाणे ⇄ येणे)</th><th>Valid</th><th>स्थिती</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): $left = (int)floor((strtotime($r['valid_till']) - strtotime($today)) / 86400); ?>
          <tr>
            <td><input type="checkbox" name="pid[]" value="<?= (int)$r['id'] ?>" class="pk"></td>
            <td class="nm"><b><?= h($r['name']) ?></b><div style="font-size:11.5px;color:var(--text-dim);"><?= h(trim($r['class_name'] . ($r['division'] ? ' - ' . $r['division'] : ''))) ?> · <?= h($r['pass_no']) ?></div></td>
            <td><span class="bp-route"><?= h($r['from_place']) ?><i>⇄</i><?= h($r['to_place']) ?></span><?= $r['route_name'] ? '<div style="font-size:11px;color:var(--text-dim);">' . h($r['route_name']) . '</div>' : '' ?></td>
            <td style="white-space:nowrap;"><?= h(date('d/m/Y', strtotime($r['valid_from']))) ?> → <b><?= h(date('d/m/Y', strtotime($r['valid_till']))) ?></b></td>
            <td><span class="bp-st <?= h($r['state']) ?>"><?= h(busPassStateLabel($r['state'])) ?><?= in_array($r['state'], ['active', 'expiring'], true) ? ' · ' . $left . ' दिवस' : '' ?></span></td>
            <td style="white-space:nowrap;">
              <a href="<?= h($qs(['print' => 1, 'ids' => $r['id']])) ?>" target="_blank" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:3px 9px;">🖨️</a>
              <?php if ($canIssue && $r['status'] === 'active'): ?><button type="button" class="btn btn-sm bp-cancel" data-id="<?= (int)$r['id'] ?>" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:3px 9px;">रद्द</button><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="bp-bar" id="bpBar">
      <b id="bpSel">0</b> निवडले
      <a href="#" id="bpPrint" target="_blank" class="btn btn-sm" style="background:#16A34A;color:#fff;border-radius:8px;">🖨️ Print</a>
      <?php if ($canIssue): ?>
        <span style="margin-left:8px;font-size:12px;">🔁 नूतनीकरण:</span>
        <input type="date" name="valid_from" class="form-control-custom" style="width:auto;" value="<?= h($defFrom) ?>">
        <span>→</span>
        <input type="date" name="valid_till" class="form-control-custom" style="width:auto;" value="<?= h($defTill) ?>">
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;" onclick="return confirm('निवडलेल्या पासचं नवीन तारखांनी नूतनीकरण करायचं? (कुठून/कुठपर्यंत तेच राहील)');">नूतनीकरण करा</button>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>
<form method="POST" id="cancelForm" style="display:none;"><input type="hidden" name="action" value="cancel_pass"><input type="hidden" name="pass_id" value=""><?php foreach (['campus', 'year', 'class', 'div'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$f[$k]) ?>"><?php endforeach; ?></form>

<script>
(function () {
  var printBase = <?= json_encode($qs(['print' => 1])) ?>;
  // नवीन पास: तारखांचे झटपट पर्याय + निवडलेल्यांची संख्या
  var iform = document.getElementById('issueForm');
  if (iform) {
    var vf = iform.querySelector('[name=valid_from]'), vt = iform.querySelector('[name=valid_till]'), n = document.getElementById('issueN');
    var fmt = function (d) { return d.toISOString().slice(0, 10); };
    iform.querySelectorAll('[data-quick]').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = new Date(vf.value || Date.now()), t = new Date(d);
        if (b.dataset.quick === 'month') { t.setMonth(t.getMonth() + 1); t.setDate(t.getDate() - 1); }
        else if (b.dataset.quick === '3month') { t.setMonth(t.getMonth() + 3); t.setDate(t.getDate() - 1); }
        else { vt.value = <?= json_encode($defTill) ?>; return; }
        vt.value = fmt(t);
      });
    });
    var cnt = function () { n.textContent = iform.querySelectorAll('input[name="sid[]"]:checked').length; };
    var all = document.getElementById('ckAll');
    all.addEventListener('change', function () { iform.querySelectorAll('input[name="sid[]"]').forEach(function (c) { c.checked = all.checked; }); cnt(); });
    iform.addEventListener('change', cnt); cnt();
    // एखाद्याचं "कुठून" भरलं तर तो आपोआप निवडला जातो
    iform.querySelectorAll('input[name^="from["],input[name^="to["]').forEach(function (i) { i.addEventListener('input', function () { var c = i.closest('tr').querySelector('input[name="sid[]"]'); if (i.value.trim()) { c.checked = true; cnt(); } }); });
    iform.addEventListener('submit', function (e) {
      var common = iform.querySelector('[name=common_from]').value.trim(), miss = 0;
      iform.querySelectorAll('input[name="sid[]"]:checked').forEach(function (c) { if (!common && !c.closest('tr').querySelector('input[name^="from["]').value.trim()) miss++; });
      if (miss && !confirm(miss + ' विद्यार्थ्यांचं "कुठून" रिकामं आहे — त्यांना पास दिला जाणार नाही. पुढे जायचं?')) e.preventDefault();
    });
  }
  // यादी: निवड, Print, नूतनीकरण
  var lf = document.getElementById('listForm');
  if (lf) {
    var bar = document.getElementById('bpBar'), sel = document.getElementById('bpSel'), pr = document.getElementById('bpPrint');
    var upd = function () { var ids = [].map.call(lf.querySelectorAll('.pk:checked'), function (c) { return c.value; }); sel.textContent = ids.length; bar.classList.toggle('on', ids.length > 0); pr.href = printBase + (printBase.indexOf('?') < 0 ? '?' : '&') + 'ids=' + ids.join(','); };
    var ck = document.getElementById('ckList'); ck.addEventListener('change', function () { lf.querySelectorAll('.pk').forEach(function (c) { c.checked = ck.checked; }); upd(); });
    lf.addEventListener('change', upd);
    document.querySelectorAll('.bp-cancel').forEach(function (b) { b.addEventListener('click', function () { if (!confirm('हा बस पास रद्द करायचा?')) return; var f = document.getElementById('cancelForm'); f.pass_id.value = b.dataset.id; f.submit(); }); });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
