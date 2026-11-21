<?php
// 📄 आवक-जावक नोंदवही — Excel सारखीच छापील नोंदवही (महिनानिहाय पान, सह्या) + 📊 स्मार्ट सारांश (महिनानिहाय, टक्केवारी, विभागनिहाय बाकी कामे, जुनी पत्रे)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
if (!orCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">आवक-जावक नोंदवही पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$me = currentUser();
if (!orReady($pdo)) { header('Location: index.php'); exit; }
$st = $pdo->prepare("SELECT * FROM organizations WHERE id = ?"); $st->execute([$orgId]); $org = $st->fetch() ?: ['name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_head' && orCanEdit()) {
    orgSettingSet($pdo, $orgId, 'office_reg_head', mb_substr(trim((string)($_POST['head'] ?? '')), 0, 1000));
    orgSettingSet($pdo, $orgId, 'office_reg_title', mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120));
    $sg = [];
    foreach ([1, 2, 3] as $i) { $n = trim((string)($_POST['sn' . $i] ?? '')); $p = trim((string)($_POST['sp' . $i] ?? '')); if ($n !== '' || $p !== '') $sg[] = mb_substr($n, 0, 80) . '|' . mb_substr($p, 0, 80); }
    orgSettingSet($pdo, $orgId, 'office_reg_signs', implode("\n", $sg));
    header('Location: report.php?' . http_build_query(array_intersect_key($_GET, array_flip(['dir', 'fy', 'm', 'st', 'dept', 'dg', 'sum'])))); exit;
}

$dir = in_array($_GET['dir'] ?? '', ['in', 'out'], true) ? $_GET['dir'] : 'all';
$st = $pdo->prepare("SELECT DISTINCT fy FROM office_letters WHERE organization_id = ? ORDER BY fy DESC"); $st->execute([$orgId]); $fys = $st->fetchAll(PDO::FETCH_COLUMN);
$fy = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : ($fys[0] ?? orFy());
$m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] . '-01' : '';
$sf = in_array($_GET['st'] ?? '', ['open', 'done'], true) ? $_GET['st'] : '';
$dept = trim((string)($_GET['dept'] ?? ''));
$mr = ($_GET['dg'] ?? 'mr') !== 'en';
$withSum = ($_GET['sum'] ?? '1') !== '0';
$N = fn($x) => $mr ? orMr($x) : (string)$x;
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

$w = " FROM office_letters o WHERE o.organization_id = ? AND o.fy = ?"; $p = [$orgId, $fy];
if ($dir !== 'all') { $w .= " AND o.direction = ?"; $p[] = $dir; }
if ($m !== '') { $w .= " AND o.reg_month = ?"; $p[] = $m; }
if ($sf === 'open') $w .= " AND o.status IN ('pending','progress')"; elseif ($sf === 'done') $w .= " AND o.status = 'done'";
if ($dept !== '') { $w .= " AND CONCAT(',', REPLACE(o.assigned_to, ', ', ','), ',') LIKE ?"; $p[] = '%,' . $dept . ',%'; }
$st = $pdo->prepare("SELECT o.* $w ORDER BY o.reg_month, o.direction, o.reg_num, o.reg_no"); $st->execute($p); $rows = $st->fetchAll();
// पान = महिना + आवक/जावक (Excel प्रमाणे)
$pages = [];
foreach ($rows as $r) $pages[$r['reg_month'] . '|' . $r['direction']][] = $r;

// 📊 सारांश — संपूर्ण निवड (महिना/विभाग filter सह)
$S = ['n' => count($rows), 'in' => 0, 'out' => 0, 'st' => array_fill_keys(array_keys(orStatuses()), 0), 'mon' => [], 'dep' => [], 'party' => [], 'old' => [], 'age' => ['0–7' => 0, '8–15' => 0, '16–30' => 0, '30+' => 0], 'doneDays' => []];
foreach ($rows as $r) {
    $S[$r['direction']]++; $S['st'][$r['status']]++;
    $mm = $r['reg_month']; $S['mon'][$mm] = $S['mon'][$mm] ?? ['in' => 0, 'out' => 0, 'done' => 0, 'open' => 0, 'act' => 0];
    $S['mon'][$mm][$r['direction']]++;
    $open = in_array($r['status'], orOpenStatuses(), true);
    if ($r['status'] === 'done') $S['mon'][$mm]['done']++;
    if ($open) $S['mon'][$mm]['open']++;
    if (!in_array($r['status'], ['info', 'cancelled'], true)) $S['mon'][$mm]['act']++;
    $ag = orAge($r);
    if ($open && $ag !== null) { $S['age'][$ag > 30 ? '30+' : ($ag > 15 ? '16–30' : ($ag > 7 ? '8–15' : '0–7'))]++; if ($ag > 15) $S['old'][] = $r + ['_age' => $ag]; }
    if ($r['status'] === 'done' && $ag !== null && $r['done_on']) $S['doneDays'][] = $ag;
    foreach (array_filter(array_map('trim', explode(',', (string)$r['assigned_to']))) as $d) {
        $S['dep'][$d] = $S['dep'][$d] ?? ['n' => 0, 'done' => 0, 'open' => 0, 'max' => 0];
        $S['dep'][$d]['n']++; if ($r['status'] === 'done') $S['dep'][$d]['done']++;
        if ($open) { $S['dep'][$d]['open']++; $S['dep'][$d]['max'] = max($S['dep'][$d]['max'], (int)$ag); }
    }
    if ($r['party']) { $k = orPartyKey($r['party']); $S['party'][$k] = ['name' => $S['party'][$k]['name'] ?? $r['party'], 'n' => ($S['party'][$k]['n'] ?? 0) + 1, 'dir' => $r['direction']]; }
}
ksort($S['mon']);
uasort($S['dep'], fn($a, $b) => [$b['open'], $b['n']] <=> [$a['open'], $a['n']]);
uasort($S['party'], fn($a, $b) => $b['n'] <=> $a['n']);
usort($S['old'], fn($a, $b) => $b['_age'] <=> $a['_age']);
$S['old'] = array_slice($S['old'], 0, 12);
$act = max(1, $S['n'] - $S['st']['info'] - $S['st']['cancelled']);
$pc = fn($a, $b) => $N(rtrim(rtrim(number_format($b ? $a * 100 / $b : 0, 1, '.', ''), '0'), '.')) . '%';

$head = orHead($pdo, $orgId, $org['name']);
$title = orTitle($pdo, $orgId);
$signs = orSigns($pdo, $orgId);
$fyMr = $N($fy);
$ST = orStatuses();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?> आवक-जावक रजिस्टर <?= $h($fy) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;} body{margin:0;font-family:'Noto Sans Devanagari',system-ui,sans-serif;background:#E7E5E4;color:#000;}
  .bar{position:sticky;top:0;z-index:5;background:#1E293B;color:#fff;display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px 12px;font-size:12.5px;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,255,255,.12);}
  .bar select{border:0;border-radius:7px;padding:5px 7px;font:inherit;font-size:12px;max-width:200px;}
  .bar .print{background:linear-gradient(90deg,#2563EB,#1D4ED8);margin-left:auto;}
  .sheet{width:210mm;margin:14px auto;background:#fff;padding:10mm 10mm 12mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);}
  .pg{break-after:page;page-break-after:always;} .pg:last-child{break-after:auto;page-break-after:auto;}
  .hd{border:1.5px solid #000;border-bottom:0;text-align:center;}
  .hd div{border-bottom:1px solid #000;padding:3px 6px;font-weight:700;font-size:13px;} .hd div:first-child{font-size:12px;}
  .hd .ti{font-size:14.5px;font-weight:800;}
  .mo{border:1.5px solid #000;border-bottom:0;padding:3px 8px;font-weight:800;font-size:13px;}
  table{width:100%;border-collapse:collapse;table-layout:fixed;}
  th,td{border:1px solid #000;padding:3px 4px;vertical-align:top;word-wrap:break-word;}
  th{font-size:11px;font-weight:800;text-align:center;background:#EEF2FF;vertical-align:middle;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  td{font-size:10.8px;line-height:1.4;} td.c{text-align:center;}
  tr{break-inside:avoid;page-break-inside:avoid;} thead{display:table-header-group;}
  .sig{display:flex;justify-content:space-between;margin-top:30px;font-size:12px;font-weight:700;text-align:center;break-inside:avoid;} .sig div{min-width:30%;}
  .foot{margin-top:8px;font-size:9.5px;color:#777;text-align:center;}
  .sum h3{font-size:14px;margin:12px 0 6px;border-bottom:2px solid #1D4ED8;padding-bottom:3px;color:#1E3A8A;break-after:avoid;}
  .kp{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-top:10px;} .kp div{border:1px solid #CBD5E1;border-radius:8px;padding:6px 8px;} .kp span{display:block;font-size:10px;color:#555;font-weight:600;} .kp b{font-size:16px;} .kp em{font-style:normal;font-size:10.5px;font-weight:700;margin-left:4px;color:#475569;}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .mini th{background:#F1F5F9;} .mini td,.mini th{border-color:#CBD5E1;font-size:10.6px;vertical-align:middle;}
  .stk{display:flex;height:9px;border-radius:5px;overflow:hidden;background:#E5E7EB;-webkit-print-color-adjust:exact;print-color-adjust:exact;} .stk i{display:block;height:100%;}
  .ring{width:120px;height:120px;border-radius:50%;display:grid;place-items:center;-webkit-print-color-adjust:exact;print-color-adjust:exact;flex:0 0 auto;}
  .ring div{width:84px;height:84px;border-radius:50%;background:#fff;display:grid;place-items:center;text-align:center;line-height:1.15;} .ring b{font-size:20px;color:#15803D;} .ring span{font-size:9.5px;color:#555;font-weight:700;}
  .ins{margin:0;padding-left:16px;font-size:11.5px;line-height:1.7;}
  .keep{break-inside:avoid;page-break-inside:avoid;}
  .hedit{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9;align-items:center;justify-content:center;} .hedit.on{display:flex;}
  .hedit form{background:#fff;border-radius:12px;padding:16px;width:min(560px,94vw);font-size:13px;} .hedit textarea,.hedit input{width:100%;font:inherit;font-size:13px;padding:5px 7px;border:1px solid #CBD5E1;border-radius:6px;} .hedit textarea{height:96px;}
  @media print{ body{background:#fff;} .bar,.hedit{display:none !important;} .sheet{margin:0;box-shadow:none;width:auto;padding:0;} @page{size:A4 portrait;margin:9mm;} }
  @media screen and (max-width:820px){ .sheet{width:auto;margin:8px;padding:10px;overflow-x:auto;} .sheet>*{min-width:720px;} }
</style>
</head>
<body>
<form class="bar" method="get">
  <a href="index.php">← नोंदवही</a>
  <select name="dir" onchange="this.form.submit()"><option value="all" <?= $dir === 'all' ? 'selected' : '' ?>>आवक + जावक</option><option value="in" <?= $dir === 'in' ? 'selected' : '' ?>>📥 आवक</option><option value="out" <?= $dir === 'out' ? 'selected' : '' ?>>📤 जावक</option></select>
  <select name="fy" onchange="this.form.submit()"><?php foreach ($fys ?: [$fy] as $y): ?><option value="<?= $h($y) ?>" <?= $fy === $y ? 'selected' : '' ?>>आ.व. <?= $h($y) ?></option><?php endforeach; ?></select>
  <select name="m" onchange="this.form.submit()"><option value="">सर्व महिने</option><?php foreach (orFyMonths($fy) as $mm): ?><option value="<?= substr($mm, 0, 7) ?>" <?= $m === $mm ? 'selected' : '' ?>><?= $h(orMonthName($mm)) ?></option><?php endforeach; ?></select>
  <select name="st" onchange="this.form.submit()"><option value="">सर्व नोंदी</option><option value="open" <?= $sf === 'open' ? 'selected' : '' ?>>⏳ फक्त कार्यवाही बाकी / चालू</option><option value="done" <?= $sf === 'done' ? 'selected' : '' ?>>✅ फक्त पूर्ण</option></select>
  <?php if ($dept !== ''): ?><input type="hidden" name="dept" value="<?= $h($dept) ?>"><span style="background:rgba(255,255,255,.15);border-radius:7px;padding:4px 8px;">🏢 <?= $h($dept) ?> <a href="report.php?<?= $h(http_build_query(array_diff_key($_GET, ['dept' => 1]))) ?>" style="padding:0 4px;">✕</a></span><?php endif; ?>
  <select name="dg" onchange="this.form.submit()"><option value="mr" <?= $mr ? 'selected' : '' ?>>१२३ मराठी अंक</option><option value="en" <?= !$mr ? 'selected' : '' ?>>123 English</option></select>
  <select name="sum" onchange="this.form.submit()"><option value="1" <?= $withSum ? 'selected' : '' ?>>सारांश पान: हो</option><option value="0" <?= !$withSum ? 'selected' : '' ?>>सारांश: नाही</option></select>
  <?php if (orCanEdit()): ?><button type="button" onclick="document.getElementById('hedit').classList.add('on')">✎ शीर्षक व सह्या</button><?php endif; ?>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</form>

<div class="sheet">
<?php if (!$rows): ?><p style="font-size:14px;text-align:center;padding:40px;">या अटींनुसार एकही नोंद नाही.</p><?php endif; ?>

<?php if ($rows && $withSum): ?>
  <div class="sum pg">
    <div class="hd"><?php foreach (array_filter(explode("\n", $head)) as $line): ?><div><?= $h($line) ?></div><?php endforeach; ?><div class="ti">📊 <?= $h($title) ?> आवक-जावक रजिस्टर <?= $h($fyMr) ?> — सारांश<?= $m ? ' (' . $h(orMonthName($m)) . ')' : '' ?><?= $dept !== '' ? ' — ' . $h($dept) : '' ?></div></div>
    <div class="mo" style="border-bottom:1.5px solid #000;text-align:right;">दिनांक : <?= $h($N(date('d.m.Y'))) ?></div>
    <?php $dP = $S['st']['done'] * 100 / $act; $gP = $S['st']['progress'] * 100 / $act; ?>
    <div style="display:flex;gap:14px;align-items:center;margin-top:10px;">
      <div class="ring" style="background:conic-gradient(#16A34A 0 <?= round($dP, 1) ?>%, #F59E0B <?= round($dP, 1) ?>% <?= round($dP + $gP, 1) ?>%, #DC2626 <?= round($dP + $gP, 1) ?>% 100%);"><div><span>काम पूर्ण</span><b><?= $h($pc($S['st']['done'], $act)) ?></b><span><?= $h($N($S['st']['done'])) ?> / <?= $h($N($act)) ?></span></div></div>
      <div class="kp" style="flex:1;margin-top:0;">
        <div><span>📥 आवक</span><b><?= $h($N($S['in'])) ?></b></div>
        <div><span>📤 जावक</span><b><?= $h($N($S['out'])) ?></b></div>
        <div><span>✅ काम पूर्ण</span><b style="color:#15803D;"><?= $h($N($S['st']['done'])) ?></b><em><?= $h($pc($S['st']['done'], $act)) ?></em></div>
        <div><span>🔄 कार्यवाही चालू</span><b style="color:#B45309;"><?= $h($N($S['st']['progress'])) ?></b><em><?= $h($pc($S['st']['progress'], $act)) ?></em></div>
        <div><span>⏳ कार्यवाही बाकी</span><b style="color:#B91C1C;"><?= $h($N($S['st']['pending'])) ?></b><em><?= $h($pc($S['st']['pending'], $act)) ?></em></div>
        <div><span>⏰ 15 दिवसांपेक्षा जुनी उघडी</span><b><?= $h($N($S['age']['16–30'] + $S['age']['30+'])) ?></b></div>
        <div><span>⏱️ सरासरी पूर्ण होण्यास</span><b><?= count($S['doneDays']) >= 5 ? $h($N((int)round(array_sum($S['doneDays']) / count($S['doneDays'])))) . ' <small style="font-size:11px;">दिवस</small>' : '—' ?></b></div>
        <div><span>📄 माहितीसाठी / ✕ रद्द</span><b><?= $h($N($S['st']['info'])) ?> / <?= $h($N($S['st']['cancelled'])) ?></b></div>
      </div>
    </div>
    <?php
      $ins = [];
      $ins[] = 'एकूण <b>' . $h($N($S['n'])) . '</b> पत्रे (आवक ' . $h($N($S['in'])) . ', जावक ' . $h($N($S['out'])) . ') — त्यापैकी <b>' . $h($pc($S['st']['done'], $act)) . '</b> काम पूर्ण, <b>' . $h($pc($S['st']['pending'] + $S['st']['progress'], $act)) . '</b> उघडे.';
      if (count($S['mon']) > 1) { $mx = ''; $mxn = 0; foreach ($S['mon'] as $k => $v) if ($v['in'] + $v['out'] > $mxn) { $mxn = $v['in'] + $v['out']; $mx = $k; } $ins[] = 'सर्वात जास्त पत्रे: <b>' . $h(orMonthName($mx)) . '</b> महिन्यात (' . $h($N($mxn)) . ').'; }
      $dOpen = array_filter($S['dep'], fn($x) => $x['open'] > 0);
      if ($dOpen) { $k = array_key_first($dOpen); $ins[] = 'सर्वात जास्त उघडी कामे: <b>' . $h($k) . '</b> — ' . $h($N($dOpen[$k]['open'])) . ' (सर्वात जुनं ' . $h($N($dOpen[$k]['max'])) . ' दिवस).'; }
      if ($S['party']) { $k = array_key_first($S['party']); $ins[] = 'सर्वात जास्त पत्रव्यवहार: <b>' . $h($S['party'][$k]['name']) . '</b> (' . $h($N($S['party'][$k]['n'])) . ' पत्रे).'; }
      if ($S['old']) $ins[] = '<b>' . $h($N(count($S['old']) >= 12 ? $S['age']['16–30'] + $S['age']['30+'] : count($S['old']))) . '</b> पत्रांवर 15 दिवसांपेक्षा जास्त काळ कार्यवाही उघडी — खालची यादी पहा.';
    ?>
    <h3>💡 निरीक्षणं</h3><ul class="ins"><?php foreach ($ins as $i): ?><li><?= $i ?></li><?php endforeach; ?></ul>

    <?php if (count($S['mon']) > 0): ?>
    <div class="keep"><h3>📅 महिनानिहाय</h3>
    <table class="mini"><thead><tr><th style="width:16%">महिना</th><th>📥 आवक</th><th>📤 जावक</th><th>एकूण</th><th>✅ पूर्ण</th><th>⏳ उघडी</th><th>पूर्ण %</th><th style="width:24%"></th></tr></thead><tbody>
    <?php $tt = ['in' => 0, 'out' => 0, 'done' => 0, 'open' => 0, 'act' => 0]; foreach ($S['mon'] as $mm => $v): foreach ($tt as $k => $_) $tt[$k] += $v[$k]; $a = max(1, $v['act']); ?>
      <tr><td><?= $h(orMonthName($mm)) ?></td><td class="c"><?= $h($N($v['in'])) ?></td><td class="c"><?= $h($N($v['out'])) ?></td><td class="c"><b><?= $h($N($v['in'] + $v['out'])) ?></b></td><td class="c"><?= $h($N($v['done'])) ?></td><td class="c"><?= $h($N($v['open'])) ?></td><td class="c"><?= $h($pc($v['done'], $a)) ?></td>
        <td><div class="stk"><i style="width:<?= round($v['done'] * 100 / $a, 1) ?>%;background:#16A34A;"></i><i style="width:<?= round($v['open'] * 100 / $a, 1) ?>%;background:#F59E0B;"></i></div></td></tr>
    <?php endforeach; ?>
      <tr style="font-weight:800;"><td>एकूण</td><td class="c"><?= $h($N($tt['in'])) ?></td><td class="c"><?= $h($N($tt['out'])) ?></td><td class="c"><?= $h($N($tt['in'] + $tt['out'])) ?></td><td class="c"><?= $h($N($tt['done'])) ?></td><td class="c"><?= $h($N($tt['open'])) ?></td><td class="c"><?= $h($pc($tt['done'], max(1, $tt['act']))) ?></td><td></td></tr>
    </tbody></table></div>
    <?php endif; ?>

    <div class="two">
      <?php if ($S['dep']): ?>
      <div class="keep"><h3>🏢 विभागनिहाय कामे (शेऱ्यातून)</h3>
        <table class="mini"><thead><tr><th style="width:40%">विभाग</th><th>एकूण</th><th>✅</th><th>⏳ उघडी</th><th>जुनी (दिवस)</th></tr></thead><tbody>
        <?php foreach (array_slice($S['dep'], 0, 14, true) as $dn => $v): ?><tr><td><?= $h($dn) ?></td><td class="c"><?= $h($N($v['n'])) ?></td><td class="c"><?= $h($N($v['done'])) ?></td><td class="c"><b style="color:<?= $v['open'] ? '#B91C1C' : '#15803D' ?>;"><?= $h($N($v['open'])) ?></b></td><td class="c"><?= $v['open'] ? $h($N($v['max'])) : '—' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
      <div>
        <div class="keep"><h3>⏰ उघडी कामे — किती दिवसांपासून</h3>
        <?php $oT = max(1, array_sum($S['age'])); ?>
        <table class="mini"><thead><tr><th>दिवस</th><th>पत्रे</th><th>%</th><th style="width:40%"></th></tr></thead><tbody>
        <?php foreach ($S['age'] as $k => $v): ?><tr><td class="c"><?= $h($N($k)) ?></td><td class="c"><?= $h($N($v)) ?></td><td class="c"><?= $h($pc($v, $oT)) ?></td><td><div class="stk"><i style="width:<?= round($v * 100 / $oT, 1) ?>%;background:<?= $k === '30+' ? '#DC2626' : '#F59E0B' ?>;"></i></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($S['party']): ?><div class="keep"><h3>👥 सर्वात जास्त पत्रव्यवहार</h3>
        <table class="mini"><thead><tr><th style="width:70%">कोणाकडून / कोणाकडे</th><th>पत्रे</th></tr></thead><tbody>
        <?php foreach (array_slice($S['party'], 0, 8) as $v): ?><tr><td><?= $h($v['name']) ?></td><td class="c"><?= $h($N($v['n'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
    </div>

    <?php if ($S['old']): ?>
    <div class="keep"><h3>🐢 सर्वात जास्त दिवस उघडी पत्रे</h3>
    <table class="mini"><thead><tr><th style="width:10%">क्र.</th><th style="width:12%">दिनांक</th><th>विषय</th><th style="width:22%">काम कोणाकडे</th><th style="width:9%">दिवस</th></tr></thead><tbody>
    <?php foreach ($S['old'] as $o): ?><tr><td class="c"><?= $h(orDirLabel($o['direction'], true)) ?> <?= $h($N($o['reg_no'])) ?></td><td class="c"><?= $h($N(orDate($o['letter_date']))) ?></td><td><?= $h(mb_strimwidth((string)$o['subject'], 0, 110, '…')) ?></td><td><?= $h($o['assigned_to'] ?: '—') ?></td><td class="c"><b><?= $h($N($o['_age'])) ?></b></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <div class="sig"><?php foreach ($signs as [$sn, $sp]): ?><div><?= $sn !== '' ? '(' . $h($sn) . ')<br>' : '<br>' ?><?= $h($sp) ?></div><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<?php foreach ($pages as $key => $list): [$mm, $dd] = explode('|', $key); ?>
  <div class="pg">
    <div class="hd"><?php foreach (array_filter(explode("\n", $head)) as $line): ?><div><?= $h($line) ?></div><?php endforeach; ?><div class="ti"><?= $h($title) ?> <?= $dd === 'out' ? 'जावक' : 'आवक' ?> रजिस्टर <?= $h($fyMr) ?></div></div>
    <div class="mo">माहे- <?= $h(orMonthName($mm)) ?><?= $sf === 'open' ? ' (फक्त कार्यवाही बाकी / चालू)' : ($sf === 'done' ? ' (फक्त पूर्ण)' : '') ?><?= $dept !== '' ? ' — ' . $h($dept) : '' ?></div>
    <table>
      <?php if ($dd === 'in'): ?>
      <colgroup><col style="width:5%"><col style="width:7%"><col style="width:9.5%"><col style="width:7.5%"><col style="width:14%"><col style="width:22%"><col style="width:21%"><col style="width:14%"></colgroup>
      <thead><tr><th>अ.क्र.</th><th>आवक क्र.</th><th>दिनांक</th><th>प्राप्त पत्राचा जावक क्र.</th><th>कोणाकडून मिळाले</th><th>विषय</th><th>शेरा</th><th>कामाची स्थिती</th></tr></thead>
      <?php else: ?>
      <colgroup><col style="width:5%"><col style="width:7%"><col style="width:9.5%"><col style="width:16%"><col style="width:26%"><col style="width:20%"><col style="width:16.5%"></colgroup>
      <thead><tr><th>अ.क्र.</th><th>जावक क्र.</th><th>दिनांक</th><th>कोणाकडे पाठविलेले</th><th>विषय</th><th>शेरा</th><th>कामाची स्थिती</th></tr></thead>
      <?php endif; ?>
      <tbody>
      <?php foreach ($list as $i => $r): ?>
        <tr><td class="c"><?= $h($N($i + 1)) ?></td><td class="c"><b><?= $h($N($r['reg_no'])) ?></b></td><td class="c"><?= $h($N(orDate($r['letter_date']))) ?></td>
          <?php if ($dd === 'in'): ?><td class="c"><?= $h($N($r['ref_no'] ?? '')) ?></td><?php endif; ?>
          <td><?= $h($r['party']) ?></td><td><?= $h($r['subject']) ?></td><td><?= $h($r['remark']) ?></td>
          <td><?= $h($r['work_status'] ?: (in_array($r['status'], ['pending', 'progress'], true) ? '' : strip_tags(orStatusLabel($r['status'])))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="sig"><?php foreach ($signs as [$sn, $sp]): ?><div><?= $sn !== '' ? '(' . $h($sn) . ')<br>' : '<br>' ?><?= $h($sp) ?></div><?php endforeach; ?></div>
  </div>
<?php endforeach; ?>
<?php if ($rows): ?><div class="foot">E-Admin — <?= date('d/m/Y h:i A') ?> · <?= $h($me['name'] ?? '') ?></div><?php endif; ?>
</div>

<?php if (orCanEdit()): $sg = array_pad($signs, 3, ['', '']); ?>
<div class="hedit" id="hedit">
  <form method="POST"><input type="hidden" name="action" value="save_head">
    <b>✎ नोंदवहीचं शीर्षक व सह्या</b>
    <div style="margin-top:8px;">शीर्षकाच्या ओळी (संस्था, शाखा…)</div><textarea name="head"><?= $h($head) ?></textarea>
    <div style="margin-top:8px;">नोंदवहीचं नाव (उदा. ट्रस्ट व्यवस्थापक → "ट्रस्ट व्यवस्थापक आवक रजिस्टर")</div><input name="title" value="<?= $h($title) ?>" maxlength="120">
    <div style="margin-top:8px;">सह्या — नाव व पद</div>
    <?php foreach ([1, 2, 3] as $i): ?><div style="display:flex;gap:6px;margin-top:4px;"><input name="sn<?= $i ?>" value="<?= $h($sg[$i - 1][0]) ?>" placeholder="नाव (उदा. प्रा.उल्हास पाटील)"><input name="sp<?= $i ?>" value="<?= $h($sg[$i - 1][1]) ?>" placeholder="पद (उदा. व्यवस्थापक-सामान्य प्रशासन)"></div><?php endforeach; ?>
    <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end;"><button type="button" onclick="document.getElementById('hedit').classList.remove('on')">रद्द</button><button type="submit" style="background:#2563EB;color:#fff;border:0;border-radius:8px;padding:6px 14px;font-weight:700;">जतन करा</button></div>
  </form>
</div>
<?php endif; ?>
</body>
</html>
