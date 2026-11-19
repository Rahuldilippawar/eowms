<?php
// ₹ बस शुल्क अहवाल — एकूण / जमा / बाकी, वर्ग-तुकडी व थांबानिहाय, बाकीदार यादी, ₹ जमा (पावतीसह), दैनिक वसुली, Excel, Print
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';
$user = currentUser();
$orgId = activeOrgId();
if (!canViewBusPass()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बस शुल्क अहवाल पाहण्याची परवानगी नाही.</div>'); }
if (!busStudentsReady($pdo)) { header('Location: index.php'); exit; }
$canCollect = canBusPass();
$payReady = busFeeReady($pdo);
$today = date('Y-m-d');

$campusRows = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusRows->execute([$orgId]);
$campusRows = $campusRows->fetchAll(PDO::FETCH_KEY_PAIR);
$org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$orgName = (string)$org->fetchColumn();

$f = ['campus' => (int)($_GET['campus'] ?? 0), 'year' => trim((string)($_GET['year'] ?? '')), 'class' => trim((string)($_GET['class'] ?? '')), 'div' => trim((string)($_GET['div'] ?? '')),
    'stop' => trim((string)($_GET['stop'] ?? '')), 'st' => in_array($_GET['st'] ?? '', ['pending', 'unpaid', 'partial', 'paid', 'all'], true) ? $_GET['st'] : 'pending', 'q' => trim((string)($_GET['q'] ?? ''))];
$years = $pdo->prepare("SELECT DISTINCT academic_year FROM bus_students WHERE organization_id = ? AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
$years->execute([$orgId]);
$years = $years->fetchAll(PDO::FETCH_COLUMN);
if ($f['year'] === '' && $years && !isset($_GET['year'])) $f['year'] = $years[0];
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div'], 'stop' => $f['stop'], 'st' => $f['st'] !== 'pending' ? $f['st'] : '', 'q' => $f['q']], $over), fn($v) => $v !== '' && $v !== null); return 'fees.php' . ($q ? '?' . http_build_query($q) : ''); };

// ---------- ₹ जमा (पावतीसह) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'collect' && $canCollect && $payReady) {
    $bsId = (int)($_POST['bs'] ?? 0);
    $amt = busMoney($_POST['amount'] ?? '');
    $on = (is_string($_POST['paid_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['paid_on']) && $_POST['paid_on'] <= $today) ? $_POST['paid_on'] : $today;
    $mode = array_key_exists($_POST['mode'] ?? '', busFeeModes()) ? $_POST['mode'] : 'cash';
    $st = $pdo->prepare("SELECT id, name, total_fee, paid_fee FROM bus_students WHERE id = ? AND organization_id = ?");
    $st->execute([$bsId, $orgId]);
    $bs = $st->fetch();
    $back = (string)($_POST['back'] ?? 'fees.php');
    if (!preg_match('#^(fees|student)\.php(\?[^"\'<>]*)?$#', $back)) $back = 'fees.php';
    if (!$bs || !$amt || $amt <= 0) { $_SESSION['flash'] = '⚠️ रक्कम बरोबर भरा.'; header('Location: ' . $back); exit; }
    $bal = (float)$bs['total_fee'] - (float)$bs['paid_fee'];
    if ($bs['total_fee'] !== null && $amt > $bal + 0.001) { $_SESSION['flash'] = '⚠️ ₹' . number_format($amt) . ' हे बाकी (₹' . number_format(max(0, $bal)) . ') पेक्षा जास्त आहे.'; header('Location: ' . $back); exit; }
    $pdo->beginTransaction();
    $rid = busFeeCollect($pdo, $orgId, $bsId, $amt, $on, $mode, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255), $user['id']);
    $pdo->commit();
    logActivity('बस शुल्क जमा', 'bus_student', $bsId, $bs['name'] . ' · ₹' . number_format($amt));
    $_SESSION['flash'] = '✓ ' . $bs['name'] . ' — ₹' . number_format($amt) . ' जमा झाले.';
    $_SESSION['bus_receipt'] = $rid;
    header('Location: ' . $back); exit;
}

// ---------- 🧾 पावती ----------
if (!empty($_GET['receipt']) && $payReady) {
    $st = $pdo->prepare("SELECT r.*, s.name, s.admission_no, s.class_name, s.division, s.from_place, s.to_place, s.total_fee, s.paid_fee, c.name campus_name, u.name by_name
        FROM bus_fee_payments r JOIN bus_students s ON s.id = r.bus_student_id LEFT JOIN campuses c ON c.id = s.campus_id LEFT JOIN users u ON u.id = r.created_by
        WHERE r.id = ? AND r.organization_id = ?");
    $st->execute([(int)$_GET['receipt'], $orgId]);
    $r = $st->fetch();
    if (!$r) { http_response_code(404); die('पावती सापडली नाही.'); }
    $designs = studentCardSettingsAll($pdo, $orgId, ['name' => $orgName]);
    $set = $designs[(int)($pdo->query("SELECT campus_id FROM bus_students WHERE id = " . (int)$r['bus_student_id'])->fetchColumn())] ?? null;
    $title = $set['title'] ?? ($r['campus_name'] ?: $orgName);
    ?>
<!DOCTYPE html><html lang="mr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>पावती <?= h($r['receipt_no']) ?></title>
<style>
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#ECEAF1;margin:0;padding:20px;color:#111;}
  .rc{max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:22px 26px;box-shadow:0 10px 30px -12px rgba(0,0,0,.3);border-top:6px solid #3769AC;}
  .rc h1{font-size:17px;margin:0;text-transform:uppercase;} .rc .sub{font-size:12px;color:#555;}
  .rc .tag{display:inline-block;background:#FFD54A;font-weight:800;font-size:12px;padding:3px 10px;border-radius:6px;margin-top:8px;}
  .rc table{width:100%;border-collapse:collapse;margin-top:14px;font-size:13.5px;} .rc td{padding:7px 4px;border-bottom:1px dashed #ddd;} .rc td:first-child{color:#555;width:42%;}
  .rc .amt{font-size:26px;font-weight:800;color:#15803D;text-align:center;margin:14px 0 4px;}
  .rc .sign{display:flex;justify-content:space-between;margin-top:34px;font-size:12px;color:#555;}
  .bar{max-width:560px;margin:0 auto 12px;display:flex;justify-content:flex-end;gap:8px;}
  .bar button{border:0;background:#3769AC;color:#fff;padding:8px 18px;border-radius:8px;font-weight:700;cursor:pointer;}
  @media print{ body{background:#fff;padding:0;} .bar{display:none;} .rc{box-shadow:none;} }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨️ Print</button></div>
<div class="rc">
  <h1><?= h($title) ?></h1><div class="sub"><?= h($orgName) ?></div>
  <div class="tag">🚌 बस शुल्क पावती · <?= h($r['receipt_no']) ?></div>
  <div class="amt"><?= h(busInr($r['amount'])) ?></div>
  <table>
    <tr><td>विद्यार्थी</td><td><b><?= h($r['name']) ?></b></td></tr>
    <tr><td>Reg No · Class · Division</td><td><?= h(trim(($r['admission_no'] ?: '—') . ' · ' . ($r['class_name'] ?: '—') . ' · ' . ($r['division'] ?: '—'))) ?></td></tr>
    <tr><td>मार्ग</td><td><?= h(($r['from_place'] ?: '—') . ' ⇄ ' . ($r['to_place'] ?: '—')) ?></td></tr>
    <tr><td>दिनांक · पद्धत</td><td><?= h(date('d/m/Y', strtotime($r['paid_on']))) ?> · <?= h(busFeeModes()[$r['mode']] ?? ($r['mode'] ?: '—')) ?></td></tr>
    <?php if ($r['note']): ?><tr><td>शेरा</td><td><?= h($r['note']) ?></td></tr><?php endif; ?>
    <?php if ($r['total_fee'] !== null): ?><tr><td>एकूण शुल्क · आजपर्यंत जमा · बाकी</td><td><?= h(busInr($r['total_fee'])) ?> · <?= h(busInr($r['paid_fee'])) ?> · <b style="color:<?= (float)$r['total_fee'] - (float)$r['paid_fee'] > 0 ? '#B91C1C' : '#15803D' ?>;"><?= h(busInr((float)$r['total_fee'] - (float)$r['paid_fee'])) ?></b></td></tr><?php endif; ?>
  </table>
  <div class="sign"><span>स्वीकारणारे: <?= h($r['by_name'] ?: '—') ?></span><span>सही ____________</span></div>
</div>
</body></html>
    <?php
    exit;
}

// ---------- यादी (filter नुसार) ----------
$base = " FROM bus_students s LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.organization_id = ? AND s.is_active = 1";
$bp = [$orgId];
if ($f['campus']) { $base .= " AND s.campus_id = ?"; $bp[] = $f['campus']; }
if ($f['year'] !== '') { $base .= " AND s.academic_year = ?"; $bp[] = $f['year']; }
$bal = "(COALESCE(s.total_fee,0) - COALESCE(s.paid_fee,0))";
$where = $base; $wp = $bp;
if ($f['class'] !== '' || $f['div'] !== '') { $where .= " AND COALESCE(s.class_name,'') = ? AND COALESCE(s.division,'') = ?"; $wp[] = $f['class']; $wp[] = $f['div']; }
if ($f['stop'] !== '') { $where .= " AND COALESCE(s.to_place,'') = ?"; $wp[] = $f['stop']; }
$whereStat = $where; $wpStat = $wp; // आकडे — स्थिती filter सोडून
if ($f['st'] === 'pending') $where .= " AND $bal > 0";
elseif ($f['st'] === 'unpaid') $where .= " AND s.total_fee > 0 AND COALESCE(s.paid_fee,0) = 0";
elseif ($f['st'] === 'partial') $where .= " AND COALESCE(s.paid_fee,0) > 0 AND $bal > 0";
elseif ($f['st'] === 'paid') $where .= " AND s.total_fee IS NOT NULL AND $bal <= 0";
if ($f['q'] !== '') { $where .= " AND (s.name LIKE ? OR s.admission_no = ? OR s.parent_mobile LIKE ? OR s.mobile LIKE ?)"; $like = '%' . $f['q'] . '%'; array_push($wp, $like, $f['q'], $like, $like); }

$lastPay = $payReady ? "(SELECT MAX(r.paid_on) FROM bus_fee_payments r WHERE r.bus_student_id = s.id)" : "NULL";

// 📊 Excel (CSV)
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare("SELECT s.*, c.name campus_name, $lastPay last_pay $where ORDER BY s.class_name, s.division, $bal DESC, s.name");
    $st->execute($wp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bus-fee-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reg No', 'नाव', 'College', 'Class', 'Division', 'Destination', 'पालक मोबाईल', 'एकूण शुल्क', 'जमा', 'बाकी', 'स्थिती', 'शेवटची जमा']);
    while ($r = $st->fetch()) {
        $b = (float)$r['total_fee'] - (float)$r['paid_fee'];
        fputcsv($out, [$r['admission_no'], $r['name'], $r['campus_name'], $r['class_name'], $r['division'], $r['to_place'], $r['parent_mobile'] ?: $r['mobile'], $r['total_fee'], $r['paid_fee'], $b,
            $r['total_fee'] === null ? 'शुल्क नाही' : ($b <= 0 ? 'पूर्ण' : ((float)$r['paid_fee'] > 0 ? 'अर्धवट' : 'काहीच नाही')), $r['last_pay'] ? date('d/m/Y', strtotime($r['last_pay'])) : '']);
    }
    fclose($out); exit;
}

$fk = $pdo->prepare("SELECT COUNT(*) n, SUM(COALESCE(s.total_fee,0)) tot, SUM(COALESCE(s.paid_fee,0)) paid, SUM($bal > 0) pend,
        SUM(s.total_fee > 0 AND COALESCE(s.paid_fee,0) = 0) unpaid, SUM(COALESCE(s.paid_fee,0) > 0 AND $bal > 0) partial, SUM(s.total_fee IS NOT NULL AND $bal <= 0) full,
        MAX($bal) maxbal $whereStat");
$fk->execute($wpStat);
$fk = $fk->fetch();
$tot = (float)$fk['tot']; $paid = (float)$fk['paid']; $pending = $tot - $paid; $pct = $tot > 0 ? round($paid * 100 / $tot, 1) : 0;

$today2 = ['d' => 0, 'm' => 0, 'n' => 0];
$trend = [];
if ($payReady) {
    $q = $pdo->prepare("SELECT SUM(CASE WHEN r.paid_on = CURDATE() THEN r.amount ELSE 0 END) d, SUM(CASE WHEN r.paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN r.amount ELSE 0 END) m, SUM(r.paid_on = CURDATE()) n
        FROM bus_fee_payments r JOIN bus_students s ON s.id = r.bus_student_id WHERE r.organization_id = ?" . ($f['campus'] ? " AND s.campus_id = " . (int)$f['campus'] : ''));
    $q->execute([$orgId]);
    $today2 = $q->fetch() ?: $today2;
    $q = $pdo->prepare("SELECT r.paid_on d, SUM(r.amount) a, COUNT(*) n FROM bus_fee_payments r JOIN bus_students s ON s.id = r.bus_student_id
        WHERE r.organization_id = ? AND r.paid_on >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)" . ($f['campus'] ? " AND s.campus_id = " . (int)$f['campus'] : '') . " GROUP BY r.paid_on");
    $q->execute([$orgId]);
    foreach ($q->fetchAll() as $r) $trend[$r['d']] = $r;
}

// वर्ग-तुकडीनिहाय व थांबानिहाय
$byClass = $pdo->prepare("SELECT COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n, SUM(COALESCE(s.total_fee,0)) tot, SUM(COALESCE(s.paid_fee,0)) paid, SUM($bal > 0) pend $base GROUP BY cl, dv ORDER BY SUM($bal) DESC, cl, dv");
$byClass->execute($bp);
$byClass = $byClass->fetchAll();
$byStop = $pdo->prepare("SELECT COALESCE(NULLIF(s.to_place,''),'—') stop, COUNT(*) n, SUM(COALESCE(s.total_fee,0)) tot, SUM(COALESCE(s.paid_fee,0)) paid $base GROUP BY stop ORDER BY SUM($bal) DESC LIMIT 15");
$byStop->execute($bp);
$byStop = $byStop->fetchAll();

$total = $pdo->prepare("SELECT COUNT(*) $where"); $total->execute($wp); $total = (int)$total->fetchColumn();
$per = 50; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)($_GET['pg'] ?? 1)));
$st = $pdo->prepare("SELECT s.*, c.name campus_name, $lastPay last_pay $where ORDER BY $bal DESC, s.class_name, s.division, s.name LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($wp);
$rows = $st->fetchAll();
$recent = [];
if ($payReady) {
    $q = $pdo->prepare("SELECT r.*, s.name, s.class_name, s.division FROM bus_fee_payments r JOIN bus_students s ON s.id = r.bus_student_id WHERE r.organization_id = ? ORDER BY r.id DESC LIMIT 8");
    $q->execute([$orgId]);
    $recent = $q->fetchAll();
}

$pageTitle = 'बस शुल्क अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .fe-hero{display:grid;grid-template-columns:1.3fr 1fr;gap:14px;margin-bottom:14px;}
  .fe-ring{display:flex;align-items:center;gap:18px;padding:16px 20px;}
  .fe-ring svg{width:118px;height:118px;flex-shrink:0;}
  .fe-ring .big{font-size:28px;font-weight:800;line-height:1.1;}
  .fe-ring .lbl{font-size:12px;color:var(--text-dim);}
  .fe-legend div{display:flex;align-items:center;gap:8px;font-size:13px;margin:5px 0;} .fe-legend i{width:10px;height:10px;border-radius:3px;display:inline-block;}
  .fe-seg{display:flex;height:12px;border-radius:99px;overflow:hidden;background:var(--canvas);margin-top:10px;} .fe-seg i{display:block;height:100%;}
  .fe-trend{display:flex;align-items:flex-end;gap:3px;height:96px;padding:6px 16px 0;}
  .fe-trend span{flex:1;background:color-mix(in srgb,#16A34A 75%,#fff);border-radius:3px 3px 0 0;min-height:2px;position:relative;}
  .fe-trend span.z{background:var(--canvas);}
  .fe-tl{display:flex;justify-content:space-between;font-size:10.5px;color:var(--text-dim);padding:3px 16px 12px;}
  .fe-t td,.fe-t th{font-size:13px;vertical-align:middle;}
  .fe-bar{height:7px;border-radius:99px;background:#FEE2E2;overflow:hidden;min-width:70px;} .fe-bar i{display:block;height:100%;background:#16A34A;}
  .fe-st{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;white-space:nowrap;}
  .fe-st.paid{background:#DCFCE7;color:#166534;} .fe-st.partial{background:#FEF3C7;color:#92400E;} .fe-st.unpaid{background:#FEE2E2;color:#991B1B;} .fe-st.nofee{background:#E5E7EB;color:#374151;}
  .cl-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
  .cl-chips a{font-size:12.5px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .cl-chips a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
  .fe-act{display:flex;gap:6px;flex-wrap:wrap;}
  .fe-act a,.fe-act button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .print-head{display:none;}
  @media (max-width:991px){ .fe-hero{grid-template-columns:1fr;} }
  @media (max-width:767px){
    .fe-t thead{display:none;} .fe-t,.fe-t tbody{display:block;width:100%;} .fe-t tr{display:block;border-bottom:1px solid var(--line);padding:8px 4px;}
    .fe-t td{display:inline-block;border:0 !important;padding:2px 6px !important;} .fe-t td.nm{display:block;}
    .fe-ring{flex-direction:column;align-items:flex-start;}
  }
  @media print{
    .rail-sidebar,.topbar,.top-nav,.mb-nav,.bp-tabs,.fe-act,.no-print,form,.sos-fab,#sosFab,.pagination-row{display:none !important;}
    .print-head{display:block;margin-bottom:10px;}
    .panel{box-shadow:none !important;border:1px solid #ddd !important;break-inside:avoid;}
    body{background:#fff !important;}
  }
</style>

<div class="page-eyebrow">🚌 विद्यार्थी वाहतूक</div>
<?php $bpTab = 'fees'; require __DIR__ . '/_tabs.php'; ?>
<div class="print-head"><b style="font-size:16px;"><?= h($orgName) ?> — बस शुल्क अहवाल</b><div style="font-size:12px;">दिनांक <?= date('d/m/Y') ?><?= $f['year'] !== '' ? ' · ' . h($f['year']) : '' ?><?= $f['class'] !== '' ? ' · ' . h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) : '' ?></div></div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">₹ बस शुल्क अहवाल</div>
  <div class="fe-act">
    <a href="<?= h('fees-report.php?' . http_build_query(array_filter(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div'], 'stop' => $f['stop']], fn($v) => $v !== '' && $v !== null))) ?>" target="_blank" style="background:linear-gradient(90deg,#3769AC,#16A34A);color:#fff;border:0;">📄 Smart अहवाल (PDF)</a>
    <a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a>
    <button type="button" onclick="window.print()">🖨️ अहवाल Print</button>
  </div>
</div>

<?php if (!empty($_SESSION['bus_receipt'])): $rcpt = (int)$_SESSION['bus_receipt']; unset($_SESSION['bus_receipt']); ?>
  <div class="alert d-flex align-items-center gap-2 flex-wrap no-print" style="border-radius:10px;font-size:13.5px;background:#ECFDF5;border:1px solid #A7F3D0;">🧾 पावती तयार आहे <a href="fees.php?receipt=<?= $rcpt ?>" target="_blank" class="btn btn-sm ms-auto" style="background:#16A34A;color:#fff;border-radius:8px;font-weight:700;">🖨️ पावती Print</a></div>
<?php endif; ?>
<?php if (!$payReady): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ पावत्यांसाठी Database Update बाकी आहे — आकडे दिसतात, पण "₹ जमा" साठी Super Admin ने Database Update चालवावं.</div><?php endif; ?>

<form method="GET" class="d-flex flex-wrap gap-2 mb-2 no-print" data-no-smart-filter>
  <?php if (count($campusRows) > 1): ?>
  <select name="campus" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व College / School</option>
    <?php foreach ($campusRows as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= $f['campus'] === (int)$cid ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="year" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व वर्षे</option>
    <?php foreach ($years as $y): ?><option value="<?= h($y) ?>" <?= $f['year'] === $y ? 'selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?>
  </select>
  <select name="st" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php foreach (['pending' => '⏳ बाकी असलेले', 'unpaid' => '⛔ काहीच भरलं नाही', 'partial' => '◐ अर्धवट भरलं', 'paid' => '✅ पूर्ण भरलं', 'all' => 'सर्व'] as $sk => $sl): ?><option value="<?= $sk ?>" <?= $f['st'] === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" style="width:auto;min-width:190px;" placeholder="नाव, Reg No, मोबाईल…">
  <?php foreach (['class', 'div', 'stop'] as $kk): if ($f[$kk] !== ''): ?><input type="hidden" name="<?= $kk ?>" value="<?= h($f[$kk]) ?>"><?php endif; endforeach; ?>
</form>

<div class="fe-hero">
  <div class="panel">
    <?php $C = 2 * M_PI * 46; $paidLen = $tot > 0 ? $C * $paid / $tot : 0; ?>
    <div class="fe-ring">
      <svg viewBox="0 0 110 110" aria-hidden="true"><circle cx="55" cy="55" r="46" fill="none" stroke="#FEE2E2" stroke-width="14"/><circle cx="55" cy="55" r="46" fill="none" stroke="#16A34A" stroke-width="14" stroke-linecap="round" stroke-dasharray="<?= round($paidLen, 1) ?> <?= round($C, 1) ?>" transform="rotate(-90 55 55)"/><text x="55" y="60" text-anchor="middle" font-size="19" font-weight="800" fill="currentColor"><?= $pct ?>%</text></svg>
      <div style="flex:1;min-width:0;">
        <div class="lbl">एकूण बस शुल्क<?= $f['class'] !== '' ? ' · ' . h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) : '' ?></div>
        <div class="big"><?= h(busInr($tot)) ?></div>
        <div class="fe-legend">
          <div><i style="background:#16A34A;"></i>जमा <b><?= h(busInr($paid)) ?></b></div>
          <div><i style="background:#F87171;"></i>बाकी <b style="color:#B91C1C;"><?= h(busInr($pending)) ?></b></div>
        </div>
      </div>
    </div>
    <?php $nAll = max(1, (int)$fk['n']); ?>
    <div style="padding:0 20px 16px;">
      <div class="fe-seg"><i style="width:<?= round((int)$fk['full'] * 100 / $nAll, 2) ?>%;background:#16A34A;"></i><i style="width:<?= round((int)$fk['partial'] * 100 / $nAll, 2) ?>%;background:#F59E0B;"></i><i style="width:<?= round((int)$fk['unpaid'] * 100 / $nAll, 2) ?>%;background:#EF4444;"></i></div>
      <div class="d-flex flex-wrap gap-3 mt-2" style="font-size:12.5px;">
        <a href="<?= h($qs(['st' => 'paid'])) ?>" style="color:inherit;text-decoration:none;">✅ पूर्ण <b><?= (int)$fk['full'] ?></b></a>
        <a href="<?= h($qs(['st' => 'partial'])) ?>" style="color:inherit;text-decoration:none;">◐ अर्धवट <b><?= (int)$fk['partial'] ?></b></a>
        <a href="<?= h($qs(['st' => 'unpaid'])) ?>" style="color:inherit;text-decoration:none;">⛔ काहीच नाही <b><?= (int)$fk['unpaid'] ?></b></a>
        <span style="color:var(--text-dim);">एकूण <?= (int)$fk['n'] ?> विद्यार्थी</span>
      </div>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head d-flex justify-content-between"><div class="title">📈 दैनिक वसुली — 30 दिवस</div><div class="sub">आज <b><?= h(busInr($today2['d'] ?? 0)) ?></b> · या महिन्यात <b><?= h(busInr($today2['m'] ?? 0)) ?></b></div></div>
    <?php $mx = max(1, ...array_values(array_map(fn($r) => (float)$r["a"], $trend ?: [["a" => 1]]))); ?>
    <div class="fe-trend">
      <?php for ($i = 29; $i >= 0; $i--): $d = date('Y-m-d', strtotime("-$i days")); $a = (float)($trend[$d]['a'] ?? 0); ?>
        <span class="<?= $a ? '' : 'z' ?>" style="height:<?= $a ? max(4, round($a * 100 / $mx)) : 2 ?>%;" title="<?= h(date('d/m', strtotime($d)) . ' — ' . busInr($a) . ($a ? ' (' . (int)$trend[$d]['n'] . ' पावत्या)' : '')) ?>"></span>
      <?php endfor; ?>
    </div>
    <div class="fe-tl"><span><?= date('d/m', strtotime('-29 days')) ?></span><span><?= date('d/m', strtotime('-15 days')) ?></span><span>आज</span></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🏫 वर्ग-तुकडीनिहाय</div><div class="sub">सगळ्यात जास्त बाकी वर — नावावर क्लिक केल्यावर तो वर्ग</div></div>
      <div class="table-responsive"><table class="table table-sm fe-t mb-0">
        <thead><tr><th>वर्ग</th><th>विद्यार्थी</th><th>एकूण</th><th>जमा</th><th>बाकी</th><th style="width:22%;">वसुली</th></tr></thead>
        <tbody>
        <?php foreach ($byClass as $r): $p = $r['tot'] > 0 ? round($r['paid'] * 100 / $r['tot']) : 0; $on = $f['class'] === $r['cl'] && $f['div'] === $r['dv']; ?>
          <tr style="<?= $on ? 'background:color-mix(in srgb,var(--rail) 8%,transparent);' : '' ?>">
            <td class="nm"><a href="<?= h($qs(['class' => $on ? '' : $r['cl'], 'div' => $on ? '' : $r['dv'], 'pg' => null])) ?>" style="font-weight:700;color:inherit;"><?= h(($r['cl'] !== '' ? $r['cl'] : '—') . ($r['dv'] !== '' ? ' - ' . $r['dv'] : '')) ?></a></td>
            <td><?= (int)$r['n'] ?><?= (int)$r['pend'] ? ' <small style="color:#B91C1C;">(' . (int)$r['pend'] . ' बाकी)</small>' : '' ?></td>
            <td><?= h(busInr($r['tot'])) ?></td><td style="color:#15803D;"><?= h(busInr($r['paid'])) ?></td><td style="color:#B91C1C;font-weight:700;"><?= h(busInr($r['tot'] - $r['paid'])) ?></td>
            <td><div class="d-flex align-items-center gap-2"><div class="fe-bar flex-grow-1"><i style="width:<?= $p ?>%;"></i></div><small><?= $p ?>%</small></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🚏 थांबानिहाय (Destination)</div></div>
      <div class="table-responsive"><table class="table table-sm fe-t mb-0">
        <thead><tr><th>थांबा</th><th>विद्यार्थी</th><th>बाकी</th><th>वसुली</th></tr></thead>
        <tbody>
        <?php foreach ($byStop as $r): $p = $r['tot'] > 0 ? round($r['paid'] * 100 / $r['tot']) : 0; ?>
          <tr><td class="nm"><a href="<?= h($qs(['stop' => $f['stop'] === $r['stop'] ? '' : $r['stop'], 'pg' => null])) ?>" style="font-weight:700;color:inherit;"><?= h($r['stop']) ?></a></td><td><?= (int)$r['n'] ?></td>
            <td style="color:#B91C1C;font-weight:700;"><?= h(busInr($r['tot'] - $r['paid'])) ?></td><td><div class="d-flex align-items-center gap-2"><div class="fe-bar flex-grow-1"><i style="width:<?= $p ?>%;"></i></div><small><?= $p ?>%</small></div></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head d-flex justify-content-between flex-wrap gap-2">
    <div><div class="title"><?= ['pending' => '⏳ बाकी असलेले', 'unpaid' => '⛔ काहीच भरलं नाही', 'partial' => '◐ अर्धवट भरलं', 'paid' => '✅ पूर्ण भरलं', 'all' => 'सर्व विद्यार्थी'][$f['st']] ?> (<?= $total ?>)</div>
      <div class="sub"><?= $f['class'] !== '' ? h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) . ' · ' : '' ?><?= $f['stop'] !== '' ? '🚏 ' . h($f['stop']) . ' · ' : '' ?>सगळ्यात जास्त बाकी वर</div></div>
    <?php if ($f['class'] !== '' || $f['stop'] !== ''): ?><a href="<?= h($qs(['class' => '', 'div' => '', 'stop' => ''])) ?>" class="btn btn-sm no-print" style="border:1px solid var(--line);border-radius:8px;">✕ वर्ग / थांबा filter काढा</a><?php endif; ?>
  </div>
  <?php if (!$rows): ?>
    <div style="padding:26px;text-align:center;color:var(--text-dim);font-size:13px;">या अटींनुसार कोणी नाही.</div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm fe-t mb-0">
    <thead><tr><th>विद्यार्थी</th><th>मोबाईल</th><th>एकूण</th><th>जमा</th><th>बाकी</th><th>स्थिती</th><th>शेवटची जमा</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $b = (float)$r['total_fee'] - (float)$r['paid_fee'];
      $stc = $r['total_fee'] === null ? 'nofee' : ($b <= 0 ? 'paid' : ((float)$r['paid_fee'] > 0 ? 'partial' : 'unpaid'));
      $stl = ['nofee' => 'शुल्क नाही', 'paid' => '✓ पूर्ण', 'partial' => 'अर्धवट', 'unpaid' => 'काहीच नाही'][$stc]; $ph = $r['parent_mobile'] ?: $r['mobile']; ?>
      <tr>
        <td class="nm"><a href="student.php?id=<?= (int)$r['id'] ?>" style="color:inherit;text-decoration:none;font-weight:700;"><?= h($r['name']) ?></a>
          <div style="font-size:11.5px;color:var(--text-dim);"><?= h(trim($r['class_name'] . ($r['division'] ? ' · ' . $r['division'] : ''))) ?><?= $r['admission_no'] ? ' · Reg. ' . h($r['admission_no']) : '' ?><?= $r['to_place'] ? ' · 🚏 ' . h($r['to_place']) : '' ?></div></td>
        <td style="white-space:nowrap;"><?= $ph ? '<a href="tel:' . h($ph) . '" style="text-decoration:none;">☎ ' . h($ph) . '</a>' : '—' ?></td>
        <td><?= $r['total_fee'] !== null ? h(busInr($r['total_fee'])) : '—' ?></td>
        <td style="color:#15803D;"><?= h(busInr($r['paid_fee'] ?? 0)) ?></td>
        <td style="color:<?= $b > 0 ? '#B91C1C' : '#15803D' ?>;font-weight:800;"><?= h(busInr(max(0, $b))) ?></td>
        <td><span class="fe-st <?= $stc ?>"><?= h($stl) ?></span></td>
        <td style="font-size:12px;white-space:nowrap;"><?= $r['last_pay'] ? h(date('d/m/Y', strtotime($r['last_pay']))) : '—' ?></td>
        <td class="no-print" style="white-space:nowrap;"><?php if ($canCollect && $payReady && $b > 0): ?><button type="button" class="btn btn-sm fe-pay" data-id="<?= (int)$r['id'] ?>" data-name="<?= h($r['name']) ?>" data-bal="<?= (int)round($b) ?>" style="background:#16A34A;color:#fff;border-radius:7px;font-size:12px;padding:4px 10px;font-weight:700;">₹ जमा</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <div class="d-flex justify-content-center gap-1 flex-wrap no-print pagination-row" style="padding:10px;">
      <?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= h($qs(['pg' => $i])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;<?= $i === $page ? 'background:var(--rail);color:#fff;' : '' ?>"><?= $i ?></a><?php endfor; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($recent): ?>
<div class="panel no-print">
  <div class="panel-head"><div class="title">🧾 अलीकडच्या पावत्या</div></div>
  <?php foreach ($recent as $r): ?>
    <div class="d-flex align-items-center gap-2 flex-wrap" style="padding:8px 18px;border-top:1px solid var(--line);font-size:13px;">
      <b><?= h($r['receipt_no']) ?></b><span><?= h($r['name']) ?> <small style="color:var(--text-dim);"><?= h(trim($r['class_name'] . ' ' . $r['division'])) ?></small></span>
      <span style="color:#15803D;font-weight:800;"><?= h(busInr($r['amount'])) ?></span>
      <span style="color:var(--text-dim);"><?= h(date('d/m/Y', strtotime($r['paid_on']))) ?> · <?= h(busFeeModes()[$r['mode']] ?? '') ?></span>
      <a href="fees.php?receipt=<?= (int)$r['id'] ?>" target="_blank" class="ms-auto" style="text-decoration:none;">🧾 Print</a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canCollect && $payReady): ?>
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px;">
  <form method="POST" data-no-smart-filter>
    <input type="hidden" name="action" value="collect"><input type="hidden" name="bs" value=""><input type="hidden" name="back" value="<?= h(basename($qs())) ?>">
    <div class="modal-header"><div><div style="font-weight:800;" id="payName"></div><div style="font-size:12px;color:var(--text-dim);">बाकी <b id="payBal"></b></div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <label class="form-label-custom">₹ रक्कम *</label>
      <input type="number" name="amount" class="form-control-custom mb-2" min="1" step="1" required inputmode="numeric" style="font-size:20px;font-weight:800;">
      <div class="d-flex gap-2 flex-wrap mb-2" id="payQuick"></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label-custom">दिनांक</label><input type="date" name="paid_on" class="form-control-custom" value="<?= $today ?>" max="<?= $today ?>"></div>
        <div class="col-6"><label class="form-label-custom">पद्धत</label><select name="mode" class="form-select-custom"><?php foreach (busFeeModes() as $mk => $ml): ?><option value="<?= $mk ?>"><?= $ml ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label-custom">शेरा / Transaction ID</label><input type="text" name="note" class="form-control-custom" maxlength="255"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;padding:9px 22px;">✓ जमा करा व पावती</button></div>
  </form>
</div></div></div>
<script>
window.addEventListener('load', function () {
  var m = document.getElementById('payModal'); if (!m || !window.bootstrap) return;
  var md = new bootstrap.Modal(m), f = m.querySelector('form'), fmt = function (n) { return '₹' + Number(n).toLocaleString('en-IN'); };
  document.querySelectorAll('.fe-pay').forEach(function (b) {
    b.addEventListener('click', function () {
      var bal = +b.dataset.bal; f.bs.value = b.dataset.id; f.amount.value = bal; f.amount.max = bal;
      document.getElementById('payName').textContent = b.dataset.name; document.getElementById('payBal').textContent = fmt(bal);
      var q = document.getElementById('payQuick'); q.innerHTML = '';
      [bal, Math.min(bal, 7000), Math.min(bal, 5000), Math.round(bal / 2)].filter(function (v, i, a) { return v > 0 && a.indexOf(v) === i; }).forEach(function (v) {
        var x = document.createElement('button'); x.type = 'button'; x.className = 'btn btn-sm'; x.style.cssText = 'border:1px solid var(--line);border-radius:8px;';
        x.textContent = (v === bal ? 'पूर्ण ' : '') + fmt(v); x.onclick = function () { f.amount.value = v; }; q.appendChild(x);
      });
      md.show(); setTimeout(function () { f.amount.select(); }, 300);
    });
  });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
