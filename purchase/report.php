<?php
// 📄 खरेदी बाकी / खरेदी पूर्ण — अधिकृत तक्ता (A4 आडवा, आर्थिक वर्षनिहाय, उप-एकूण, एकूण) + Smart सारांश (टप्पे, किती दिवस, विभागनिहाय, सर्वात जुने)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/purchase.php';
if (!canViewPurchase()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">खरेदी अहवाल पाहण्याची परवानगी नाही.</div>'); }
if (!purchaseReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$me = currentUser();
$org = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$org = $org->fetch() ?: ['name' => '', 'logo_path' => ''];

// शीर्षक बदलणे (खरेदी विभाग)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_head' && canPurchase() && function_exists('orgSettingSet')) {
    $hd = trim(str_replace("\r", '', mb_substr((string)($_POST['head'] ?? ''), 0, 600)));
    orgSettingSet($pdo, $orgId, 'purchase_report_head', $hd);
    header('Location: report.php?' . http_build_query(array_intersect_key($_GET, array_flip(['type', 'fy', 'dept', 'dg', 'sum'])))); exit;
}

$type = in_array($_GET['type'] ?? '', ['done', 'all'], true) ? $_GET['type'] : 'pending';
$fy = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : '';
$dept = trim((string)($_GET['dept'] ?? ''));
$mr = ($_GET['dg'] ?? 'mr') !== 'en';
$withSum = ($_GET['sum'] ?? '1') !== '0';
$N = fn($x) => $mr ? mrDigits($x) : (string)$x;

$st = $pdo->prepare("SELECT DISTINCT fy FROM purchase_requests WHERE organization_id = ? ORDER BY fy DESC"); $st->execute([$orgId]); $fys = $st->fetchAll(PDO::FETCH_COLUMN);
$st = $pdo->prepare("SELECT DISTINCT department FROM purchase_requests WHERE organization_id = ? ORDER BY department"); $st->execute([$orgId]); $depts = $st->fetchAll(PDO::FETCH_COLUMN);

$w = " FROM purchase_requests r WHERE r.organization_id = ?"; $p = [$orgId];
if ($type !== 'all') { $w .= " AND r.status = ?"; $p[] = $type; } else { $w .= " AND r.status <> 'cancelled'"; }
if ($fy !== '') { $w .= " AND r.fy = ?"; $p[] = $fy; }
if ($dept !== '') { $w .= " AND r.department = ?"; $p[] = $dept; }
$st = $pdo->prepare("SELECT r.* $w ORDER BY r.fy, r.sr_no, r.id"); $st->execute($p); $rows = $st->fetchAll();
purchaseItemsAttach($pdo, $orgId, $rows);   // 🧩 वस्तूनिहाय खरेदी स्थिती
$byFy = [];
foreach ($rows as $r) $byFy[$r['fy']][] = $r;

// सारांश
$tot = 0; $noAmt = 0; $stages = ['approve' => 0, 'order' => 0, 'process' => 0, 'partial' => 0, 'done' => 0]; $ageB = ['0–30' => 0, '31–60' => 0, '61–90' => 0, '90+' => 0]; $dep = []; $oldest = [];
foreach ($rows as $r) {
    $a = $r['status'] === 'done' && $r['actual_amount'] !== null ? (float)$r['actual_amount'] : ($r['est_amount'] !== null ? (float)$r['est_amount'] : null);
    if ($a === null) $noAmt++; else $tot += $a;
    [$sk] = purchaseStage($r); if (isset($stages[$sk])) $stages[$sk]++;
    $ag = purchaseAgeDays($r);
    if ($r['status'] === 'pending' && $ag !== null) { $ageB[$ag > 90 ? '90+' : ($ag > 60 ? '61–90' : ($ag > 30 ? '31–60' : '0–30'))]++; $oldest[] = $r + ['_age' => $ag]; }
    $d = $r['department']; $dep[$d] = $dep[$d] ?? ['n' => 0, 'amt' => 0, 'max' => 0];
    $dep[$d]['n']++; $dep[$d]['amt'] += $a ?? 0; $dep[$d]['max'] = max($dep[$d]['max'], (int)$ag);
}
uasort($dep, fn($x, $y) => $y['amt'] <=> $x['amt']);
usort($oldest, fn($x, $y) => $y['_age'] <=> $x['_age']);
$oldest = array_slice($oldest, 0, 8);
// 📈 टक्केवारी सारांश — निवडलेल्या वर्ष / विभागातील सगळ्या (बाकी + पूर्ण) मागण्यांच्या तुलनेत किती खरेदी बाकी
$pc = ['req' => 0, 'req_done' => 0, 'req_part' => 0, 'it' => 0, 'it_done' => 0, 'amt' => 0.0, 'amt_left' => 0.0, 'dep' => [], 'fy' => [], 'stage' => ['approve' => 0, 'order' => 0, 'process' => 0, 'partial' => 0], 'age' => ['0–30' => 0, '31–60' => 0, '61–90' => 0, '90+' => 0]];
if ($rows) {
    $w2 = " FROM purchase_requests r WHERE r.organization_id = ? AND r.status <> 'cancelled'"; $p2 = [$orgId];
    if ($fy !== '') { $w2 .= " AND r.fy = ?"; $p2[] = $fy; }
    if ($dept !== '') { $w2 .= " AND r.department = ?"; $p2[] = $dept; }
    $st = $pdo->prepare("SELECT r.* $w2"); $st->execute($p2); $all = $st->fetchAll();
    purchaseItemsAttach($pdo, $orgId, $all);
    $blank = fn() => ['req' => 0, 'req_done' => 0, 'it' => 0, 'it_done' => 0, 'amt' => 0.0, 'amt_left' => 0.0];
    foreach ($all as $r) {
        $done = $r['status'] === 'done';
        $itT = (int)($r['_it_total'] ?? 0); $itD = $done ? $itT : (int)($r['_it_done'] ?? 0);
        if (!$itT) { $itT = 1; $itD = $done ? 1 : 0; }   // वस्तू यादी नसलेली मागणी = 1 वस्तू
        $est = $r['est_amount'] !== null ? (float)$r['est_amount'] : 0.0;
        $a = $done && $r['actual_amount'] !== null ? (float)$r['actual_amount'] : $est;
        // अंशतः खरेदी: बाकी रक्कम = अंदाजित − आत्तापर्यंत खरेदी झालेल्या वस्तूंची रक्कम (वस्तूंच्या प्रमाणात)
        $left = 0.0;
        if (!$done) { $paid = 0.0; foreach ($r['_items'] ?? [] as $it) if ($it['amount'] !== null) $paid += (float)$it['amount']; $left = $paid > 0 ? max(0, $est - $paid) : $est * ($itT - $itD) / $itT; }
        foreach (['all', 'd', 'f'] as $k) {
            if ($k === 'all') $b = &$pc; elseif ($k === 'd') { $pc['dep'][$r['department']] = $pc['dep'][$r['department']] ?? $blank(); $b = &$pc['dep'][$r['department']]; } else { $pc['fy'][$r['fy']] = $pc['fy'][$r['fy']] ?? $blank(); $b = &$pc['fy'][$r['fy']]; }
            $b['req']++; $b['req_done'] += $done ? 1 : 0; $b['it'] += $itT; $b['it_done'] += $itD; $b['amt'] += $a; $b['amt_left'] += $left;
            unset($b);
        }
        if (!$done) {
            if ($itD > 0) $pc['req_part']++;
            [$sk] = purchaseStage($r); if (isset($pc['stage'][$sk])) $pc['stage'][$sk]++;
            $ag = purchaseAgeDays($r); if ($ag !== null) $pc['age'][$ag > 90 ? '90+' : ($ag > 60 ? '61–90' : ($ag > 30 ? '31–60' : '0–30'))]++;
        }
    }
    $pcLeft = fn($b) => $b['it'] ? ($b['it'] - $b['it_done']) * 100 / $b['it'] : 0;
    uasort($pc['dep'], fn($x, $y) => $pcLeft($y) <=> $pcLeft($x) ?: $y['it'] <=> $x['it']);
    ksort($pc['fy']);
}
$pct = fn($a, $b) => $b ? round($a * 100 / $b, 1) : 0;
$pctS = fn($a, $b) => $N(rtrim(rtrim(number_format($b ? $a * 100 / $b : 0, 1, '.', ''), '0'), '.')) . '%';
$head = purchaseReportHead($pdo, $orgId, $org['name']);
$typeT = ['pending' => 'खरेदी बाकी खरेदी वर्गीकरण तक्ता', 'done' => 'खरेदी पूर्ण झालेल्या खरेदीचा तक्ता', 'all' => 'खरेदी मागणी — बाकी व पूर्ण तक्ता'][$type];
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$amt = fn($x) => $x === null || $x === '' ? '' : $N(number_format((float)$x, 0, '.', ''));
$ref = fn($no, $d) => trim(($no ?: '') . ($d ? ($no ? '/' : '') . date('d.m.Y', strtotime($d)) : ''));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $h($typeT) ?> — <?= date('d-m-Y') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;} body{margin:0;background:#E9E7F0;font-family:'Noto Sans Devanagari','Calibri',sans-serif;color:#111;font-size:11px;}
  .bar{position:sticky;top:0;z-index:5;background:#3B1D0A;color:#fff;padding:9px 12px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;font-size:12px;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,255,255,.12);}
  .bar select{border:0;border-radius:7px;padding:5px 7px;font:inherit;font-size:12px;max-width:200px;}
  .bar .print{background:linear-gradient(90deg,#16A34A,#0E8C7A);margin-left:auto;}
  .sheet{width:297mm;margin:14px auto;background:#fff;padding:9mm 9mm 12mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);}
  .hd{border:1.5px solid #000;border-bottom:0;text-align:center;}
  .hd div{border-bottom:1px solid #000;padding:3px 6px;font-weight:700;font-size:13px;} .hd div:first-child{font-size:12px;}
  .hd .ti{font-size:14px;font-weight:800;}
  .dt{display:flex;border:1.5px solid #000;border-bottom:0;} .dt span{flex:1;} .dt b{border-left:1.5px solid #000;padding:3px 10px;font-size:12.5px;min-width:200px;text-align:center;}
  table{width:100%;border-collapse:collapse;table-layout:fixed;}
  th,td{border:1px solid #000;padding:3px 4px;vertical-align:middle;word-wrap:break-word;}
  th{font-size:11px;font-weight:800;text-align:center;background:#F7F3EC;}
  td{font-size:11px;} td.c{text-align:center;} td.r{text-align:right;} td.t b{font-weight:800;}
  td.t ul{margin:2px 0 0;padding-left:14px;font-size:10.3px;} td.t li{margin:0;}
  tr.sub td{font-weight:800;background:#FBF8F2;} tr.grand td{font-weight:800;font-size:12.5px;background:#F1EADC;}
  tr{break-inside:avoid;page-break-inside:avoid;} thead{display:table-header-group;}
  .fyrow td{font-weight:800;font-size:12px;text-align:center;background:#EFE7D6;}
  .sum{margin-top:10px;} .sum h3{font-size:14px;margin:12px 0 6px;border-bottom:2px solid #B45309;padding-bottom:3px;color:#7C2D12;}
  .kp{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;} .kp div{border:1px solid #D6CFC2;border-radius:8px;padding:6px 8px;} .kp span{display:block;font-size:10px;color:#555;font-weight:600;} .kp b{font-size:16px;}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .mini th{background:#FBF5EA;} .mini td,.mini th{border-color:#C9BFAE;font-size:10.8px;}
  .bar2{display:inline-block;height:8px;background:#D97706;border-radius:3px;vertical-align:middle;}
  .ins{margin:0;padding-left:16px;font-size:11.5px;line-height:1.7;}
  .sig{display:flex;justify-content:space-between;margin-top:34px;font-size:11.5px;} .sig div{width:23%;border-top:1px solid #000;text-align:center;padding-top:3px;}
  .foot{margin-top:10px;font-size:9.5px;color:#777;text-align:center;}
  .pcs{display:flex;align-items:center;gap:10px;border:1.5px solid #000;border-bottom:0;padding:5px 8px;font-size:11.5px;flex-wrap:wrap;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .pcs b{font-weight:800;} .pcs .sep{color:#999;}
  .stk{display:flex;height:10px;border-radius:5px;overflow:hidden;background:#E5E7EB;min-width:160px;flex:1;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .stk i{display:block;height:100%;} .stk .g{background:#16A34A;} .stk .o{background:#F59E0B;} .stk .r{background:#DC2626;}
  .pcw{display:grid;grid-template-columns:150px 1fr;gap:14px;align-items:center;margin-top:10px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .ring{width:140px;height:140px;border-radius:50%;display:grid;place-items:center;}
  .ring div{width:98px;height:98px;border-radius:50%;background:#fff;display:grid;place-items:center;text-align:center;line-height:1.15;} .ring b{font-size:24px;color:#B45309;} .ring span{font-size:10px;color:#555;font-weight:700;}
  .pk{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;} .pk div{border:1px solid #D6CFC2;border-radius:8px;padding:6px 8px;} .pk span{display:block;font-size:10px;color:#555;font-weight:600;} .pk b{font-size:15px;} .pk em{font-style:normal;font-size:10.5px;font-weight:700;margin-left:4px;}
  .pk .g b,.pk .g em{color:#15803D;} .pk .o b,.pk .o em{color:#B45309;}
  td .stk{min-width:70px;height:8px;}
  .sum h3{break-after:avoid;page-break-after:avoid;} .keep{break-inside:avoid;page-break-inside:avoid;}
  .pctag{display:inline-block;border-radius:5px;padding:0 5px;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .pctag.hi{background:#FEE2E2;color:#991B1B;} .pctag.md{background:#FEF3C7;color:#92400E;} .pctag.lo{background:#DCFCE7;color:#166534;}
  .pgb{break-before:page;page-break-before:always;}
  .hedit{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9;align-items:center;justify-content:center;} .hedit.on{display:flex;}
  .hedit form{background:#fff;border-radius:12px;padding:16px;width:min(520px,92vw);} .hedit textarea{width:100%;height:110px;font:inherit;font-size:13px;}
  @media print{ body{background:#fff;} .bar,.hedit{display:none !important;} .sheet{margin:0;box-shadow:none;width:auto;padding:0;} @page{size:A4 landscape;margin:8mm;} }
  @media screen and (max-width:1100px){ .sheet{width:auto;margin:8px;padding:10px;overflow-x:auto;} .sheet>*{min-width:900px;} }
</style>
</head>
<body>
<form class="bar" method="get">
  <a href="index.php">← खरेदी</a>
  <select name="type" onchange="this.form.submit()"><option value="pending" <?= $type === 'pending' ? 'selected' : '' ?>>⏳ खरेदी बाकी</option><option value="done" <?= $type === 'done' ? 'selected' : '' ?>>✅ खरेदी पूर्ण</option><option value="all" <?= $type === 'all' ? 'selected' : '' ?>>बाकी + पूर्ण</option></select>
  <select name="fy" onchange="this.form.submit()"><option value="">सर्व आर्थिक वर्षे</option><?php foreach ($fys as $y): ?><option value="<?= $h($y) ?>" <?= $fy === $y ? 'selected' : '' ?>><?= $h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select>
  <select name="dept" onchange="this.form.submit()"><option value="">सर्व विभाग</option><?php foreach ($depts as $d): ?><option <?= $dept === $d ? 'selected' : '' ?>><?= $h($d) ?></option><?php endforeach; ?></select>
  <select name="dg" onchange="this.form.submit()"><option value="mr" <?= $mr ? 'selected' : '' ?>>१२३ मराठी अंक</option><option value="en" <?= !$mr ? 'selected' : '' ?>>123 English</option></select>
  <select name="sum" onchange="this.form.submit()"><option value="1" <?= $withSum ? 'selected' : '' ?>>सारांश पान: हो</option><option value="0" <?= !$withSum ? 'selected' : '' ?>>सारांश: नाही</option></select>
  <?php if (canPurchase()): ?><button type="button" onclick="document.getElementById('hedit').classList.add('on')">✎ शीर्षक</button><?php endif; ?>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</form>

<div class="sheet">
<?php if (!$rows): ?>
  <p style="font-size:14px;text-align:center;padding:40px;">या अटींनुसार एकही नोंद नाही.</p>
<?php endif; ?>
<?php if ($rows): $grand = 0; $fi = 0; ?>
    <div class="hd"><?php foreach (array_filter(explode("\n", $head)) as $line): ?><div><?= $h($line) ?></div><?php endforeach; ?><div class="ti"><?= $fy !== '' ? 'आर्थिक वर्ष-' . $h($N(purchaseFyLabel($fy))) . ' ' : (count($byFy) > 1 ? 'आर्थिक वर्ष-' . $h($N(purchaseFyLabel(array_key_first($byFy)))) . ' ते ' . $h($N(purchaseFyLabel(array_key_last($byFy)))) . ' ' : (count($byFy) === 1 ? 'आर्थिक वर्ष-' . $h($N(purchaseFyLabel(array_key_first($byFy)))) . ' ' : '')) ?><?= $h($typeT) ?><?= $dept !== '' ? ' — ' . $h($dept) : '' ?></div></div>
    <div class="dt"><span></span><b>दिनांक : <?= $h($N(date('d.m.Y'))) ?></b></div>
    <?php if ($pc['it']): $gW = $pct($pc['it_done'], $pc['it']); ?>
    <div class="pcs">📈 <span>एकूण मागण्या <b><?= $h($N($pc['req'])) ?></b> — पूर्ण <b><?= $h($N($pc['req_done'])) ?></b> · बाकी <b><?= $h($N($pc['req'] - $pc['req_done'])) ?></b></span><span class="sep">|</span>
      <span>वस्तू <b><?= $h($N($pc['it'])) ?></b> पैकी खरेदी झाले <b style="color:#15803D;"><?= $h($pctS($pc['it_done'], $pc['it'])) ?></b> · खरेदी बाकी <b style="color:#B45309;"><?= $h($pctS($pc['it'] - $pc['it_done'], $pc['it'])) ?></b></span>
      <span class="stk" title="खरेदी झाले / बाकी"><i class="g" style="width:<?= $gW ?>%"></i><i class="o" style="width:<?= 100 - $gW ?>%"></i></span>
      <?php if ($pc['amt'] > 0): ?><span>बाकी रक्कम <b><?= $h($N(purchaseInr($pc['amt_left']))) ?></b> (<?= $h($pctS($pc['amt_left'], $pc['amt'])) ?>)</span><?php endif; ?></div>
    <?php endif; ?>
    <table>
      <colgroup><col style="width:3.3%"><col style="width:9%"><col style="width:6.5%"><col style="width:7.5%"><col style="width:9%"><col style="width:9%"><col style="width:<?= $type === 'done' ? '26%' : '30%' ?>"><col style="width:7.5%"><?php if ($type === 'done'): ?><col style="width:7.5%"><col style="width:14.7%"><?php else: ?><col style="width:8.5%"><col style="width:9.7%"><?php endif; ?></colgroup>
      <thead><tr><th>अ. क्र.</th><th>विभाग</th><th>मागणी क्रमांक</th><th>मागणी दिनांक</th><th>मंजुरी क्र. / दिनांक</th><th>खरेदी आवक क्र व दिनांक</th><th>तपशील</th><th>अंदाजित रक्कम</th>
        <?php if ($type === 'done'): ?><th>प्रत्यक्ष रक्कम</th><th>खरेदी दिनांक / पुरवठादार / बिल</th><?php else: ?><th>खरेदी प्रलंबित राहण्याचे कारण</th><th>शेरा</th><?php endif; ?></tr></thead>
      <tbody>
<?php foreach ($byFy as $fyk => $list): $sub = 0; $fi++; ?>
        <?php if (count($byFy) > 1): ?><tr class="fyrow"><td colspan="10">आर्थिक वर्ष <?= $h($N(purchaseFyLabel($fyk))) ?></td></tr><?php endif; ?>
      <?php foreach ($list as $r): $its = array_values(array_filter(array_map('trim', explode("\n", (string)$r['items'])))); $sub += (float)($type === 'done' && $r['actual_amount'] !== null ? $r['actual_amount'] : $r['est_amount']); ?>
        <tr>
          <td class="c"><?= $h($N((int)$r['sr_no'] ?: '')) ?></td>
          <td><?= $h($r['department']) ?></td>
          <td class="c"><?= $h($N($r['demand_no'])) ?></td>
          <td class="c"><?= $h($N(purchaseDate($r['demand_date']))) ?></td>
          <td class="c"><?= $h($N($ref($r['approval_no'], $r['approval_date']))) ?></td>
          <td class="c"><?= $h($N($ref($r['inward_no'], $r['inward_date']))) ?></td>
          <td class="t"><b><?= $h($r['title']) ?></b><?php if (!empty($r['_items'])): ?><ul><?php foreach ($r['_items'] as $it): ?><li<?= $it['status'] === 'done' ? ' style="text-decoration:line-through;color:#555;"' : '' ?>><?= $it['status'] === 'done' ? '✓ ' : '' ?><?= $h($it['name']) ?><?= ($ql = purchaseItemQtyLabel($it)) !== '' ? ' - ' . $h($N($ql)) : '' ?><?= $it['status'] !== 'done' && (float)$it['qty_done'] > 0 ? ' (अंशतः)' : '' ?></li><?php endforeach; ?></ul>
            <?php if ($r['status'] === 'pending' && $r['_it_done'] > 0): ?><div style="font-size:10.3px;font-weight:700;color:#9A3412;">🧩 <?= $h($N($r['_it_done'])) ?>/<?= $h($N($r['_it_total'])) ?> खरेदी झाले · <?= $h($N($r['_it_total'] - $r['_it_done'])) ?> बाकी</div><?php endif; ?>
          <?php elseif ($its): ?><ul><?php foreach ($its as $it): ?><li><?= $h($it) ?></li><?php endforeach; ?></ul><?php endif; ?><?php if ($type === 'all' && $r['status'] === 'done'): ?><div style="color:#166534;font-weight:700;">✅ खरेदी पूर्ण <?= $h($N(purchaseDate($r['done_on']))) ?></div><?php endif; ?></td>
          <td class="r"><?= $h($amt($r['est_amount'])) ?></td>
          <?php if ($type === 'done'): ?>
            <td class="r"><?= $h($amt($r['actual_amount'])) ?></td>
            <td><?= $h($N(purchaseDate($r['done_on']))) ?><?= $r['supplier'] ? '<br>' . $h($r['supplier']) : '' ?><?= $r['bill_no'] ? '<br>बिल ' . $h($N($r['bill_no'])) : '' ?><?= $r['done_note'] ? '<br><span style="color:#555;">' . $h($r['done_note']) . '</span>' : '' ?></td>
          <?php else: ?>
            <td><?= $h($r['pending_reason']) ?></td>
            <td><?= $h($r['remarks']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; $grand += $sub; ?>
        <tr class="sub"><td colspan="7" style="text-align:right;">आर्थिक वर्ष <?= $h($N(purchaseFyLabel($fyk))) ?> — एकूण (<?= $h($N(count($list))) ?> नोंदी)</td><td class="r" colspan="<?= $type === 'done' ? 2 : 1 ?>"><?= $h($amt($sub)) ?></td><td colspan="<?= $type === 'done' ? 1 : 2 ?>"></td></tr>
        <?php if ($fi === count($byFy) && count($byFy) > 1): ?><tr class="grand"><td colspan="7" style="text-align:center;">एकूण</td><td class="r" colspan="<?= $type === 'done' ? 2 : 1 ?>"><?= $h($amt($grand)) ?></td><td colspan="<?= $type === 'done' ? 1 : 2 ?>"></td></tr><?php endif; ?>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>

<?php if ($rows && $withSum): ?>
  <div class="sum pgb">
    <div class="hd"><div class="ti">📊 <?= $h($typeT) ?> — सारांश<?= $fy !== '' ? ' (आ.व. ' . $h($N(purchaseFyLabel($fy))) . ')' : '' ?><?= $dept !== '' ? ' — ' . $h($dept) : '' ?></div></div>
    <div class="dt" style="border-bottom:1.5px solid #000;"><span></span><b>दिनांक : <?= $h($N(date('d.m.Y'))) ?></b></div>
    <div class="kp" style="margin-top:10px;">
      <div><span>नोंदी</span><b><?= $h($N(count($rows))) ?></b></div>
      <div><span>एकूण रक्कम<?= $noAmt ? ' (' . $h($N($noAmt)) . ' रक्कम नाही)' : '' ?></span><b><?= $h($N(purchaseInr($tot))) ?></b></div>
      <?php if ($type !== 'done'): ?>
      <div><span>⏳ मंजुरी बाकी</span><b><?= $h($N($stages['approve'])) ?></b></div>
      <div><span>📝 आवक / आदेश बाकी</span><b><?= $h($N($stages['order'])) ?></b></div>
      <div><span>🛒 खरेदी प्रक्रियेत</span><b><?= $h($N($stages['process'])) ?></b></div>
      <?php else: ?><div><span>✅ खरेदी पूर्ण</span><b><?= $h($N($stages['done'])) ?></b></div><?php endif; ?>
    </div>
    <?php if ($pc['it']):
      $itL = $pc['it'] - $pc['it_done']; $rqL = $pc['req'] - $pc['req_done']; $gP = $pct($pc['it_done'], $pc['it']);
      $tag = fn($v) => '<span class="pctag ' . ($v >= 60 ? 'hi' : ($v >= 30 ? 'md' : 'lo')) . '">' . $h($N(rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'))) . '%</span>'; ?>
    <h3>📈 खरेदी किती बाकी — टक्केवारी सारांश<?= $type === 'pending' ? ' (बाकी + पूर्ण मिळून)' : '' ?></h3>
    <div class="pcw">
      <div class="ring" style="background:conic-gradient(#F59E0B 0 <?= 100 - $gP ?>%, #16A34A <?= 100 - $gP ?>% 100%);"><div><span>खरेदी बाकी</span><b><?= $h($pctS($itL, $pc['it'])) ?></b><span><?= $h($N($itL)) ?> / <?= $h($N($pc['it'])) ?> वस्तू</span></div></div>
      <div class="pk">
        <div class="o"><span>मागण्या बाकी</span><b><?= $h($N($rqL)) ?></b><em><?= $h($pctS($rqL, $pc['req'])) ?></em></div>
        <div class="g"><span>मागण्या पूर्ण</span><b><?= $h($N($pc['req_done'])) ?></b><em><?= $h($pctS($pc['req_done'], $pc['req'])) ?></em></div>
        <div class="o"><span>वस्तू खरेदी बाकी</span><b><?= $h($N($itL)) ?></b><em><?= $h($pctS($itL, $pc['it'])) ?></em></div>
        <div class="g"><span>वस्तू खरेदी झाले</span><b><?= $h($N($pc['it_done'])) ?></b><em><?= $h($pctS($pc['it_done'], $pc['it'])) ?></em></div>
        <div><span>🧩 अंशतः खरेदी झालेल्या मागण्या</span><b><?= $h($N($pc['req_part'])) ?></b><em><?= $h($pctS($pc['req_part'], max(1, $rqL))) ?> बाकींपैकी</em></div>
        <?php if ($pc['amt'] > 0): ?><div class="o"><span>बाकी रक्कम (अंदाजित)</span><b><?= $h($N(purchaseInr($pc['amt_left']))) ?></b><em><?= $h($pctS($pc['amt_left'], $pc['amt'])) ?></em></div>
        <div class="g"><span>खरेदी झालेली रक्कम</span><b><?= $h($N(purchaseInr($pc['amt'] - $pc['amt_left']))) ?></b><em><?= $h($pctS($pc['amt'] - $pc['amt_left'], $pc['amt'])) ?></em></div><?php endif; ?>
        <div><span>90+ दिवस जुन्या (बाकींपैकी)</span><b><?= $h($N($pc['age']['90+'])) ?></b><em><?= $h($pctS($pc['age']['90+'], max(1, $rqL))) ?></em></div>
      </div>
    </div>
    <?php
      $pins = [];
      $pins[] = 'एकूण <b>' . $h($N($pc['it'])) . '</b> वस्तूंपैकी <b>' . $h($N($pc['it_done'])) . '</b> (' . $h($pctS($pc['it_done'], $pc['it'])) . ') खरेदी झाले, <b>' . $h($N($itL)) . '</b> (' . $h($pctS($itL, $pc['it'])) . ') खरेदी बाकी.';
      $dk = array_keys(array_filter($pc['dep'], fn($b) => $b['it'] - $b['it_done'] > 0));
      if ($dk && count($pc['dep']) > 1) { $w0 = $pc['dep'][$dk[0]]; $pins[] = 'सर्वात जास्त टक्के बाकी: <b>' . $h($dk[0]) . '</b> — ' . $h($pctS($w0['it'] - $w0['it_done'], $w0['it'])) . ' (' . $h($N($w0['it'] - $w0['it_done'])) . ' वस्तू).'; }
      $best = null; foreach ($pc['dep'] as $dn => $b) if ($b['it'] >= 3 && ($best === null || $b['it_done'] / $b['it'] > $pc['dep'][$best]['it_done'] / $pc['dep'][$best]['it'])) $best = $dn;
      if ($best !== null && count($pc['dep']) > 1 && $pc['dep'][$best]['it_done'] > 0) $pins[] = 'सर्वात चांगली प्रगती: <b>' . $h($best) . '</b> — ' . $h($pctS($pc['dep'][$best]['it_done'], $pc['dep'][$best]['it'])) . ' खरेदी पूर्ण.';
      $stL = ['approve' => '⏳ मंजुरी बाकी', 'order' => '📝 आवक / आदेश बाकी', 'process' => '🛒 खरेदी प्रक्रियेत', 'partial' => '🧩 अंशतः खरेदी'];
      arsort($pc['stage']); $sk0 = array_key_first($pc['stage']);
      if ($rqL && $pc['stage'][$sk0]) $pins[] = 'बाकी मागण्यांपैकी <b>' . $h($pctS($pc['stage'][$sk0], $rqL)) . '</b> "' . $stL[$sk0] . '" टप्प्यावर अडकल्या आहेत.';
      if ($pc['amt'] > 0 && $pc['amt_left'] > 0) $pins[] = 'अजून सुमारे <b>' . $h($N(purchaseInr($pc['amt_left']))) . '</b> (' . $h($pctS($pc['amt_left'], $pc['amt'])) . ') ची खरेदी बाकी आहे.'; ?>
    <ul class="ins" style="margin-top:8px;"><?php foreach ($pins as $i): ?><li><?= $i ?></li><?php endforeach; ?></ul>
    <div class="two">
      <div>
        <h3>🏢 विभागनिहाय खरेदी बाकी %</h3>
        <table class="mini"><thead><tr><th style="width:32%">विभाग</th><th>मागण्या (बाकी/एकूण)</th><th>वस्तू (बाकी/एकूण)</th><th>बाकी %</th><th style="width:22%">प्रगती</th></tr></thead><tbody>
        <?php foreach ($pc['dep'] as $dn => $b): $l = $b['it'] - $b['it_done']; $g = $pct($b['it_done'], $b['it']); ?><tr><td><?= $h($dn) ?></td><td class="c"><?= $h($N($b['req'] - $b['req_done'])) ?>/<?= $h($N($b['req'])) ?></td><td class="c"><?= $h($N($l)) ?>/<?= $h($N($b['it'])) ?></td><td class="c"><?= $tag($pct($l, $b['it'])) ?></td><td><span class="stk"><i class="g" style="width:<?= $g ?>%"></i><i class="o" style="width:<?= 100 - $g ?>%"></i></span></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
      <div>
        <?php if ($rqL): ?>
        <div class="keep"><h3>🚦 बाकी मागण्या — टप्पानिहाय %</h3>
        <table class="mini"><thead><tr><th style="width:44%">टप्पा</th><th>मागण्या</th><th>%</th><th style="width:30%"></th></tr></thead><tbody>
        <?php foreach (['approve', 'order', 'process', 'partial'] as $k): $v = $pc['stage'][$k]; ?><tr><td><?= $stL[$k] ?></td><td class="c"><?= $h($N($v)) ?></td><td class="c"><?= $h($pctS($v, $rqL)) ?></td><td><span class="stk"><i class="o" style="width:<?= $pct($v, $rqL) ?>%"></i></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="keep"><h3>⏰ बाकी मागण्या — किती दिवसांपासून %</h3>
        <table class="mini"><thead><tr><th>दिवस</th><th>मागण्या</th><th>%</th><th style="width:30%"></th></tr></thead><tbody>
        <?php foreach ($pc['age'] as $k => $v): ?><tr><td class="c"><?= $h($N($k)) ?></td><td class="c"><?= $h($N($v)) ?></td><td class="c"><?= $h($pctS($v, $rqL)) ?></td><td><span class="stk"><i class="<?= $k === '90+' ? 'r' : 'o' ?>" style="width:<?= $pct($v, $rqL) ?>%"></i></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <?php if (count($pc['fy']) > 1): ?>
        <div class="keep"><h3>📅 आर्थिक वर्षनिहाय %</h3>
        <table class="mini"><thead><tr><th>आ.व.</th><th>वस्तू (बाकी/एकूण)</th><th>बाकी %</th><th style="width:30%"></th></tr></thead><tbody>
        <?php foreach ($pc['fy'] as $fk => $b): $l = $b['it'] - $b['it_done']; $g = $pct($b['it_done'], $b['it']); ?><tr><td class="c"><?= $h($N(purchaseFyLabel($fk))) ?></td><td class="c"><?= $h($N($l)) ?>/<?= $h($N($b['it'])) ?></td><td class="c"><?= $tag($pct($l, $b['it'])) ?></td><td><span class="stk"><i class="g" style="width:<?= $g ?>%"></i><i class="o" style="width:<?= 100 - $g ?>%"></i></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($type !== 'done'):
      $ins = [];
      $topD = array_key_first($dep); if ($topD !== null) $ins[] = 'सर्वात जास्त बाकी: <b>' . $h($topD) . '</b> — ' . $h($N($dep[$topD]['n'])) . ' नोंदी, ' . $h($N(purchaseInr($dep[$topD]['amt']))) . '.';
      if ($ageB['90+']) $ins[] = '<b>' . $h($N($ageB['90+'])) . '</b> मागण्या 90 दिवसांपेक्षा जुन्या — आधी पूर्ण करा.';
      if ($stages['approve']) $ins[] = '<b>' . $h($N($stages['approve'])) . '</b> मागण्यांना अजून मंजुरी क्र. / दिनांक नाही.';
      if ($noAmt) $ins[] = '<b>' . $h($N($noAmt)) . '</b> नोंदींची अंदाजित रक्कम भरलेली नाही.';
      if ($oldest) $ins[] = 'सर्वात जुनी: "' . $h(mb_strimwidth($oldest[0]['title'], 0, 70, '…')) . '" (' . $h($oldest[0]['department']) . ') — ' . $h($N($oldest[0]['_age'])) . ' दिवस.'; ?>
      <h3>💡 निरीक्षणं</h3><ul class="ins"><?php foreach ($ins as $i): ?><li><?= $i ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <div class="two">
      <div>
        <h3>🏢 विभागनिहाय</h3>
        <?php $dmax = max(1, ...array_map(fn($x) => $x['amt'], array_values($dep) ?: [['amt' => 1]])); ?>
        <table class="mini"><thead><tr><th style="width:38%">विभाग</th><th>नोंदी</th><th>रक्कम</th><th style="width:28%"></th><?php if ($type !== 'done'): ?><th>जुनी (दिवस)</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($dep as $dn => $dv): ?><tr><td><?= $h($dn) ?></td><td class="c"><?= $h($N($dv['n'])) ?></td><td class="r"><?= $h($N(purchaseInr($dv['amt']))) ?></td><td><span class="bar2" style="width:<?= round($dv['amt'] * 100 / $dmax) ?>%;"></span></td><?php if ($type !== 'done'): ?><td class="c"><?= $h($N($dv['max'])) ?></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table>
      </div>
      <?php if ($type !== 'done'): ?>
      <div>
        <h3>⏰ किती दिवस बाकी</h3>
        <table class="mini"><thead><tr><th>दिवस</th><th>नोंदी</th></tr></thead><tbody><?php foreach ($ageB as $k => $c): ?><tr><td class="c"><?= $h($N($k)) ?></td><td class="c"><?= $h($N($c)) ?></td></tr><?php endforeach; ?></tbody></table>
        <h3>🐢 सर्वात जुन्या मागण्या</h3>
        <table class="mini"><thead><tr><th style="width:8%">अ.क्र.</th><th>तपशील</th><th style="width:24%">विभाग</th><th style="width:12%">दिवस</th></tr></thead><tbody>
        <?php foreach ($oldest as $o): ?><tr><td class="c"><?= $h($N($o['sr_no'])) ?></td><td><?= $h(mb_strimwidth($o['title'], 0, 70, '…')) ?></td><td><?= $h($o['department']) ?></td><td class="c"><b><?= $h($N($o['_age'])) ?></b></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if ($rows): ?>
  <div class="sig"><div>खरेदी विभाग</div><div>लेखा विभाग</div><div>व्यवस्थापक</div><div>अध्यक्ष / सचिव</div></div>
  <div class="foot">E-Admin — <?= date('d/m/Y h:i A') ?> · <?= $h($me['name'] ?? '') ?></div>
<?php endif; ?>
</div>

<?php if (canPurchase()): ?>
<div class="hedit" id="hedit" onclick="if(event.target===this)this.classList.remove('on')">
  <form method="POST"><input type="hidden" name="action" value="save_head">
    <b>अहवालाचं शीर्षक</b><div style="font-size:12px;color:#555;margin:4px 0 6px;">प्रत्येक ओळ वेगळी (उदा. !! आत्मा मालिक !! / संस्थेचं नाव / पत्ता)</div>
    <textarea name="head"><?= $h($head) ?></textarea>
    <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end;"><button type="button" onclick="document.getElementById('hedit').classList.remove('on')">रद्द</button><button type="submit" style="background:#16A34A;color:#fff;border:0;border-radius:7px;padding:6px 14px;font-weight:700;">जतन</button></div>
  </form>
</div>
<?php endif; ?>
</body>
</html>
