<?php
// 📄 मासिक बिल जमा / ताळमेळ तक्ता — अधिकृत अहवाल (A4): महिनानिहाय संख्या, शेरा, कारणे मिमांसा, विभागांच्या डिजिटल सह्या, कार्याध्यक्ष शेरा + Smart सारांश
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/bill-recon.php';
if (!billReconCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बिल ताळमेळ अहवाल पाहण्याची परवानगी नाही.</div>'); }
if (!billReconReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$me = currentUser();
$org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $org->execute([$orgId]); $orgName = (string)$org->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_head' && billReconCanAny() && function_exists('orgSettingSet')) {
    orgSettingSet($pdo, $orgId, 'bill_recon_head', trim(str_replace("\r", '', mb_substr((string)($_POST['head'] ?? ''), 0, 400))));
    header('Location: report.php?' . http_build_query(array_intersect_key($_GET, array_flip(['fy', 'from', 'to', 'dg', 'sum'])))); exit;
}

$curFy = billReconFy(date('Y-m'));
$fy = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : $curFy;
$all = billReconFyPeriods($fy);
$from = in_array($_GET['from'] ?? '', $all, true) ? $_GET['from'] : $all[0];
$to = in_array($_GET['to'] ?? '', $all, true) ? $_GET['to'] : $all[11];
if ($to < $from) [$from, $to] = [$to, $from];
$mr = ($_GET['dg'] ?? 'mr') !== 'en';
$withSum = ($_GET['sum'] ?? '1') !== '0';
$N = fn($x) => $mr ? billReconMr($x) : (string)$x;
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

$st = $pdo->prepare("SELECT DISTINCT fy FROM bill_recon_months WHERE organization_id = ?"); $st->execute([$orgId]);
$fys = array_values(array_unique(array_merge([$curFy], $st->fetchAll(PDO::FETCH_COLUMN)))); rsort($fys);
$st = $pdo->prepare("SELECT * FROM bill_recon_months WHERE organization_id = ? AND period BETWEEN ? AND ? AND (store_count IS NOT NULL OR purchase_count IS NOT NULL OR accounts_count IS NOT NULL) ORDER BY period");
$st->execute([$orgId, $from, $to]); $rows = $st->fetchAll();
$iss = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach ($pdo->query("SELECT * FROM bill_recon_issues WHERE month_id IN ($ids) ORDER BY id")->fetchAll() as $i) $iss[$i['month_id']][] = $i;
}
$depts = billReconDepts();
$head = billReconHead($pdo, $orgId, $orgName);
$period = '';
if ($rows) {
    $fp = $rows[0]['period']; $lp = end($rows)['period'];
    $period = $fp === $lp ? billReconMonthName($fp) : billReconMonthName($fp, substr($fp, 0, 4) !== substr($lp, 0, 4)) . ' ते ' . billReconMonthName($lp);
}

// सह्या — प्रत्येक विभागासाठी: कोणी (नाव), कोणत्या महिन्यांची, कोणत्या महिन्यांची बाकी
$signs = [];
foreach ($depts as $k => $d) {
    $signs[$k] = ['names' => [], 'pending' => [], 'last' => null, 'digital' => false];
    foreach ($rows as $r) {
        if ($r[$k . '_at']) { $signs[$k]['names'][$r[$k . '_name'] ?: '—'][] = $r['period']; $signs[$k]['last'] = max((string)$signs[$k]['last'], $r[$k . '_at']); if ($r[$k . '_by']) $signs[$k]['digital'] = true; }
        else $signs[$k]['pending'][] = $r['period'];
    }
}
// सारांश
$tot = ['store' => 0, 'purchase' => 0, 'accounts' => 0]; $cnt = ['match' => 0, 'match_wait' => 0, 'diff' => 0, 'entry' => 0]; $openI = []; $parties = [];
foreach ($rows as $r) {
    foreach ($tot as $k => $_) $tot[$k] += (int)$r[$k . '_count'];
    $cnt[billReconStatus($r)[0]]++;
    foreach ($iss[$r['id']] ?? [] as $i) if ($i['status'] === 'open') { $openI[] = $i + ['period' => $r['period']]; $pk = $i['party'] ?: 'इतर'; $parties[$pk] = ($parties[$pk] ?? 0) + (int)$i['bill_count']; }
}
arsort($parties);
$maxC = max(1, ...array_map(fn($r) => max((int)$r['store_count'], (int)$r['purchase_count'], (int)$r['accounts_count']), $rows ?: [['store_count' => 1, 'purchase_count' => 1, 'accounts_count' => 1]]));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>मासिक बिल जमा / ताळमेळ तक्ता — <?= $h($period) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;} body{margin:0;background:#E7EEF1;font-family:'Noto Sans Devanagari','Calibri',sans-serif;color:#111;font-size:13px;}
  .bar{position:sticky;top:0;z-index:5;background:#0B3B47;color:#fff;padding:9px 12px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;font-size:12px;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,255,255,.12);}
  .bar select{border:0;border-radius:7px;padding:5px 7px;font:inherit;font-size:12px;}
  .bar .print{background:linear-gradient(90deg,#16A34A,#0E8C7A);margin-left:auto;}
  .sheet{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:12mm 12mm 14mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);}
  .t1{text-align:center;font-weight:800;font-size:19px;} .t2{text-align:center;font-weight:800;font-size:16px;margin-top:2px;} .t3{text-align:center;font-weight:800;font-size:14.5px;margin-top:2px;}
  .intro{margin:12px 0 8px;font-size:13.5px;line-height:1.6;}
  table.m{width:100%;border-collapse:collapse;}
  table.m th{background:#111;color:#fff;font-size:12.5px;font-weight:700;padding:6px 5px;border:1px solid #111;}
  table.m td{border:1.2px solid #111;padding:8px 6px;text-align:center;font-size:13.5px;vertical-align:middle;}
  table.m td.l{text-align:left;font-size:12.5px;} tr{break-inside:avoid;page-break-inside:avoid;}
  .ok{color:#166534;font-weight:700;} .bad{color:#991B1B;font-weight:700;} .wait{color:#92400E;font-weight:700;}
  .why div{margin:2px 0;} .why .rs{text-decoration:line-through;color:#555;}
  tr.tot td{font-weight:800;background:#F1F5F9;}
  .signs{display:grid;grid-template-columns:repeat(3,1fr);border:1.5px solid #111;margin-top:16px;}
  .signs>div{border-right:1.5px solid #111;padding:8px;text-align:center;font-size:12.5px;line-height:1.45;display:flex;flex-direction:column;}
  .signs>div:last-child{border-right:0;}
  .stamp{border:1.5px solid #16A34A;color:#166534;border-radius:8px;padding:4px 6px;margin-bottom:6px;font-size:11.5px;font-weight:700;}
  .stamp.p{border-color:#D97706;color:#92400E;} .stamp small{display:block;font-weight:600;color:#333;}
  .signs .nm{font-weight:800;font-size:13.5px;border-top:1px solid #999;padding-top:4px;margin-top:auto;}
  .close{margin-top:14px;font-size:13.5px;line-height:1.6;}
  .head{margin-top:12px;font-size:13.5px;} .head .hr{border:1px solid #CBD5E1;border-radius:8px;padding:8px 10px;white-space:pre-line;min-height:60px;margin-top:4px;}
  .foot{margin-top:12px;font-size:9.5px;color:#777;text-align:center;}
  .pgb{break-before:page;page-break-before:always;}
  .sum h3{font-size:15px;margin:14px 0 6px;border-bottom:2px solid #0891B2;padding-bottom:3px;color:#0B3B47;}
  .kp{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;} .kp div{border:1px solid #CBD5E1;border-radius:8px;padding:6px 8px;} .kp span{display:block;font-size:10.5px;color:#555;font-weight:600;} .kp b{font-size:17px;}
  .ch{display:flex;gap:8px;align-items:flex-end;height:150px;border-bottom:1px solid #94A3B8;padding:0 4px;}
  .ch .g{flex:1;display:flex;gap:2px;align-items:flex-end;height:100%;} .ch i{flex:1;display:block;border-radius:3px 3px 0 0;}
  .chl{display:flex;gap:8px;padding:3px 4px 0;font-size:10.5px;} .chl span{flex:1;text-align:center;}
  .lg{font-size:11px;margin-top:4px;} .lg i{display:inline-block;width:10px;height:10px;border-radius:2px;margin:0 3px 0 10px;vertical-align:middle;}
  table.mini{width:100%;border-collapse:collapse;font-size:12px;} table.mini th,table.mini td{border:1px solid #CBD5E1;padding:4px 6px;} table.mini th{background:#F1F5F9;}
  .ins{margin:0;padding-left:18px;line-height:1.75;}
  .hedit{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9;align-items:center;justify-content:center;} .hedit.on{display:flex;}
  .hedit form{background:#fff;border-radius:12px;padding:16px;width:min(520px,92vw);} .hedit textarea{width:100%;height:80px;font:inherit;}
  @media print{ body{background:#fff;} .bar,.hedit{display:none !important;} .sheet{margin:0;box-shadow:none;width:auto;min-height:0;padding:0;} @page{size:A4;margin:10mm;} }
  @media screen and (max-width:820px){ .sheet{width:auto;margin:8px;padding:12px;overflow-x:auto;} .sheet>*{min-width:640px;} }
</style>
</head>
<body>
<form class="bar" method="get">
  <a href="index.php?fy=<?= $h($fy) ?>">← ताळमेळ</a>
  <select name="fy" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()"><?php foreach ($fys as $y): ?><option value="<?= $h($y) ?>" <?= $y === $fy ? 'selected' : '' ?>>आ.व. <?= $h(billReconFyLabel($y)) ?></option><?php endforeach; ?></select>
  <select name="from" onchange="this.form.submit()"><?php foreach ($all as $p): ?><option value="<?= $h($p) ?>" <?= $p === $from ? 'selected' : '' ?>>पासून: <?= $h(billReconMonthName($p)) ?></option><?php endforeach; ?></select>
  <select name="to" onchange="this.form.submit()"><?php foreach ($all as $p): ?><option value="<?= $h($p) ?>" <?= $p === $to ? 'selected' : '' ?>>पर्यंत: <?= $h(billReconMonthName($p)) ?></option><?php endforeach; ?></select>
  <select name="dg" onchange="this.form.submit()"><option value="mr" <?= $mr ? 'selected' : '' ?>>१२३ मराठी अंक</option><option value="en" <?= !$mr ? 'selected' : '' ?>>123 English</option></select>
  <select name="sum" onchange="this.form.submit()"><option value="1" <?= $withSum ? 'selected' : '' ?>>सारांश पान: हो</option><option value="0" <?= !$withSum ? 'selected' : '' ?>>सारांश: नाही</option></select>
  <?php if (billReconCanAny()): ?><button type="button" onclick="document.getElementById('hedit').classList.add('on')">✎ शीर्षक</button><?php endif; ?>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</form>

<div class="sheet">
  <div class="t1"><?= $h($head) ?></div>
  <div class="t2">मासिक बिल जमा / ताळमेळ तक्ता (Monthly Bill Reconciliation)</div>
  <?php if ($rows): ?><div class="t3">कालावधी - <?= $h($N($period)) ?></div><?php endif; ?>
<?php if (!$rows): ?>
  <p style="text-align:center;padding:40px;font-size:14px;">या कालावधीत एकाही महिन्याची नोंद नाही.</p>
<?php else: ?>
  <div class="intro">आर्थिक वर्ष <?= $h($N($fy)) ?> मध्ये माहे <?= $h(billReconMonthName($rows[0]['period'], false)) ?> पासून दरमहा मुख्य भांडार विभाग, खरेदी विभाग व लेखा विभाग यांच्यातील खरेदी संदर्भातील बिले हस्तांतरण माहिती अहवाल खालीलप्रमाणे -</div>
  <table class="m">
    <thead><tr><th style="width:7%">अ.क्र.</th><th style="width:15%">महिना</th><th>भांडार विभाग प्राप्त बिलांची संख्या</th><th>खरेदी विभाग प्राप्त बिलांची संख्या</th><th>लेखा विभागात नोंद संख्या</th><th style="width:22%">शेरा</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $n => $r): [$sk, $sl] = billReconStatus($r); $mi = $iss[$r['id']] ?? []; ?>
      <tr>
        <td rowspan="<?= $mi ? 2 : 1 ?>"><?= $h($N($n + 1)) ?></td>
        <td rowspan="<?= $mi ? 2 : 1 ?>"><?= $h(billReconMonthName($r['period'], false)) ?></td>
        <?php foreach ($depts as $k => $_): ?><td><?= $r[$k . '_count'] === null ? '—' : $h($N($r[$k . '_count'])) ?></td><?php endforeach; ?>
        <td class="<?= $sk === 'match' ? 'ok' : ($sk === 'diff' ? 'bad' : 'wait') ?>"><?= $sk === 'match' ? $h($N('100')) . '% ताळमेळ पूर्ण' : ($sk === 'diff' ? 'फरक ' . $h($N(billReconDiff($r))) . ' बिलं' : $h(trim(preg_replace('/^\S+\s/u', '', $sl)))) ?></td>
      </tr>
      <?php if ($mi): ?>
      <tr><td colspan="4" class="l why"><b>कारणे मिमांसा -</b>
        <?php foreach ($mi as $j => $i): ?><div class="<?= $i['status'] === 'resolved' ? 'rs' : '' ?>"><?= $h($N($j + 1)) ?>) <?= $i['party'] ? $h($i['party']) . ' यांचे ' : '' ?><?= $h($N($i['bill_count'])) ?> बिल<?= (int)$i['bill_count'] > 1 ? 'े' : '' ?> — <?= $h($i['reason']) ?><?= $i['status'] === 'open' ? ' (' . $h($depts[$i['held_at']][3] ?? '') . ')' : '' ?></div><?php if ($i['status'] === 'resolved'): ?><div style="font-size:11.5px;color:#166534;">&nbsp;&nbsp;✅ मिटले<?= $i['resolved_note'] ? ' — ' . $h($i['resolved_note']) : '' ?></div><?php endif; ?><?php endforeach; ?>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (count($rows) > 1): ?><tr class="tot"><td colspan="2">एकूण</td><?php foreach ($depts as $k => $_): ?><td><?= $h($N($tot[$k])) ?></td><?php endforeach; ?><td><?= $h($N($cnt['match'])) ?>/<?= $h($N(count($rows))) ?> महिने पूर्ण</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div class="signs">
    <?php foreach ($depts as $k => $d): $sg = $signs[$k]; ?>
      <div>
        <?php if ($sg['names']): ?>
          <div class="stamp">✍️ <?= $sg['digital'] ? 'डिजिटल मान्यता' : 'मान्यता (कागदी सही)' ?><small><?= $h($N(date('d.m.Y', strtotime($sg['last'])))) ?></small></div>
          <div>वरील आकडेवारी <?php $nm = array_keys($sg['names']); echo $h(implode(', ', $nm)); ?> यांनी खात्री करून दिली असून बरोबर आहे.</div>
        <?php endif; ?>
        <?php if ($sg['pending']): ?><div class="stamp p" style="margin-top:6px;">⏳ मान्यता बाकी<small><?= $h(implode(', ', array_map(fn($p) => billReconMonthName($p, false), $sg['pending']))) ?></small></div><?php endif; ?>
        <div class="nm"><?= $h($d[0]) ?> सही</div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="close">वरील तक्त्यातील सर्व माहिती जबाबदार अधिकारी यांनी अधिकृतरीत्या तपासून व खात्री करून दिलेली आहे. तरी माननीय कार्याध्यक्ष साहेब यांच्या माहितीस्तव सविनय सादर...</div>
  <div class="head"><b>माननीय कार्याध्यक्ष साहेब यांचा शेरा -</b>
    <div class="hr"><?php $hrs = array_filter($rows, fn($r) => $r['head_remark']); foreach ($hrs as $r): ?><div><?= count($rows) > 1 ? '<b>' . $h(billReconMonthName($r['period'], false)) . ':</b> ' : '' ?><?= $h($r['head_remark']) ?> <span style="font-size:11px;color:#555;">— ✍️ <?= $h($r['head_name']) ?>, <?= $h($N(date('d.m.Y', strtotime($r['head_at'])))) ?></span></div><?php endforeach; ?></div>
  </div>
  <div class="foot">E-Admin — <?= date('d/m/Y h:i A') ?> · <?= $h($me['name'] ?? '') ?> · डिजिटल मान्यता: नाव व वेळेसह प्रणालीत नोंद</div>

  <?php if ($withSum): ?>
  <div class="sum pgb">
    <div class="t2">📊 बिल ताळमेळ — सारांश (<?= $h($N($period)) ?>)</div>
    <div class="kp" style="margin-top:10px;">
      <div><span>✅ 100% ताळमेळ</span><b><?= $h($N($cnt['match'])) ?> / <?= $h($N(count($rows))) ?></b></div>
      <div><span>⚠️ फरक असलेले महिने</span><b><?= $h($N($cnt['diff'])) ?></b></div>
      <div><span>📦 भांडार / 🛒 खरेदी / 📒 लेखा</span><b style="font-size:14px;"><?= $h($N($tot['store'])) ?> / <?= $h($N($tot['purchase'])) ?> / <?= $h($N($tot['accounts'])) ?></b></div>
      <div><span>उघडी कारणं (बिलं)</span><b><?= $h($N(count($openI))) ?> (<?= $h($N(array_sum(array_column($openI, 'bill_count')))) ?>)</b></div>
    </div>
    <?php
      $ins = [];
      $rate = count($rows) ? round($cnt['match'] * 100 / count($rows)) : 0;
      $ins[] = 'एकूण ' . $N(count($rows)) . ' महिन्यांपैकी <b>' . $N($cnt['match']) . '</b> महिन्यांत 100% ताळमेळ (' . $N($rate) . '%).';
      if ($tot['purchase'] !== $tot['store']) $ins[] = 'खरेदी विभागाकडे भांडारपेक्षा <b>' . $N(abs($tot['purchase'] - $tot['store'])) . '</b> बिलं ' . ($tot['purchase'] > $tot['store'] ? 'जास्त' : 'कमी') . '.';
      if ($tot['accounts'] !== $tot['purchase']) $ins[] = 'लेखा विभागात खरेदीपेक्षा <b>' . $N(abs($tot['accounts'] - $tot['purchase'])) . '</b> बिलं ' . ($tot['accounts'] > $tot['purchase'] ? 'जास्त' : 'कमी') . ' नोंद.';
      if ($parties) { $tp = array_key_first($parties); $ins[] = 'सर्वात जास्त अडकलेली बिलं: <b>' . $h($tp) . '</b> — ' . $N($parties[$tp]) . ' बिलं.'; }
      foreach ($rows as $r) if (billReconStatus($r)[0] === 'diff' && !array_filter($iss[$r['id']] ?? [], fn($i) => $i['status'] === 'open')) $ins[] = $h(billReconMonthName($r['period'])) . ': फरक आहे पण <b>कारण नोंदलेलं नाही</b>.';
      foreach ($depts as $k => $d) if ($signs[$k]['pending']) $ins[] = $h($d[4]) . ' मान्यता बाकी: ' . $h(implode(', ', array_map(fn($p) => billReconMonthName($p, false), $signs[$k]['pending']))) . '.';
    ?>
    <h3>💡 निरीक्षणं</h3><ul class="ins"><?php foreach ($ins as $i): ?><li><?= $i ?></li><?php endforeach; ?></ul>
    <h3>📈 महिनानिहाय बिलं</h3>
    <div class="ch"><?php foreach ($rows as $r): ?><div class="g"><?php foreach (['store' => '#0891B2', 'purchase' => '#D97706', 'accounts' => '#7C3AED'] as $k => $c): ?><i style="height:<?= round((int)$r[$k . '_count'] * 100 / $maxC) ?>%;background:<?= $c ?>;" title="<?= (int)$r[$k . '_count'] ?>"></i><?php endforeach; ?></div><?php endforeach; ?></div>
    <div class="chl"><?php foreach ($rows as $r): ?><span><?= $h(billReconMonthName($r['period'], false)) ?></span><?php endforeach; ?></div>
    <div class="lg"><i style="background:#0891B2"></i>भांडार <i style="background:#D97706"></i>खरेदी <i style="background:#7C3AED"></i>लेखा</div>
    <?php if ($openI): ?>
      <h3>⚠️ उघडी कारणं (मिटायची बाकी)</h3>
      <table class="mini"><thead><tr><th>महिना</th><th>पुरवठादार / बिल</th><th>बिलं</th><th>कुठे</th><th>कारण</th></tr></thead><tbody>
      <?php foreach ($openI as $i): ?><tr><td><?= $h(billReconMonthName($i['period'], false)) ?></td><td><?= $h($i['party'] ?: '—') ?></td><td style="text-align:center;"><?= $h($N($i['bill_count'])) ?></td><td><?= $h($depts[$i['held_at']][0] ?? '') ?></td><td><?= $h($i['reason']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    <h3>🏢 विभागनिहाय</h3>
    <table class="mini"><thead><tr><th>विभाग</th><th>एकूण बिलं</th><th>मान्यता दिलेले महिने</th><th>मान्यता बाकी</th><th>मान्यता देणारे</th></tr></thead><tbody>
      <?php foreach ($depts as $k => $d): ?><tr><td><?= $d[1] ?> <?= $h($d[0]) ?></td><td style="text-align:center;"><?= $h($N($tot[$k])) ?></td><td style="text-align:center;"><?= $h($N(count($rows) - count($signs[$k]['pending']))) ?></td><td style="text-align:center;"><?= $h($N(count($signs[$k]['pending']))) ?></td><td><?= $h(implode(', ', array_keys($signs[$k]['names']))) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php if (billReconCanAny()): ?>
<div class="hedit" id="hedit" onclick="if(event.target===this)this.classList.remove('on')">
  <form method="POST"><input type="hidden" name="action" value="save_head">
    <b>अहवालाचं शीर्षक (संस्था / शाखा)</b>
    <textarea name="head"><?= $h($head) ?></textarea>
    <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end;"><button type="button" onclick="document.getElementById('hedit').classList.remove('on')">रद्द</button><button type="submit" style="background:#16A34A;color:#fff;border:0;border-radius:7px;padding:6px 14px;font-weight:700;">जतन</button></form>
</div>
<?php endif; ?>
</body>
</html>
