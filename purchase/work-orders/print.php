<?php
// 🖨️ वर्क ऑर्डर — A4 Print (Word file सारखीच): पान 1 — letterhead, To, Ref, W.O. No, तक्ता, एकूण, शब्दांत, Purpose, कालावधी, सह्या;
// पान 2 — अटी (YES / NO), Payment तपशील, ठेकेदाराची स्वीकृती.
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/work-order.php';
$orgId = activeOrgId();
if (!woReady($pdo)) { header('Location: index.php'); exit; }
$w = woLoad($pdo, $orgId, (int)($_GET['id'] ?? 0));
if (!$w || !woCanSee($w)) { header('Location: index.php'); exit; }
$SET = woSettings($pdo, $orgId);
$L = woLabels($w);
$terms = json_decode((string)$w['terms_json'], true) ?: [];
$pay = json_decode((string)$w['payment_json'], true) ?: [];
$signs = json_decode((string)$w['signs_json'], true) ?: woDefaultSigns($w['department_name']);
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$m = fn($x) => $x === null || $x === '' ? '' : woMoney($x);
$words = woWords($w['grand_total']);
$fyLong = substr($w['fy'], 0, 4) . '-' . (substr($w['fy'], 0, 2) . substr($w['fy'], 5, 2));
$woNo = $w['wo_no'] . '/' . $w['fy'];
$date = date('d.m.Y', strtotime($w['wo_date']));
$hasUnit = trim((string)$L['unit']) !== '';
$cols = $hasUnit ? 6 : 5;
$lh = array_values(array_filter(array_map('trim', explode("\n", (string)$SET['letterhead'])), 'strlen'));
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$gstLbl = 'GST ' . rtrim(rtrim(number_format((float)$w['gst_pct'], 2, '.', ''), '0'), '.') . '%';
// Letterhead: पहिली ओळ (!! Atmamalik !!), संस्थेचं नाव, बाकी पत्ता / संपर्क / GSTIN
$head = function () use ($lh, $h) {
    $o = '<div class="lh">';
    foreach ($lh as $i => $l) $o .= '<div class="' . ($i === 0 ? 'l0' : ($i === 1 ? 'l1' : 'ln')) . '">' . $h($l) . '</div>';
    return $o . '</div>';
};
$signRow = function () use ($signs, $SET, $h) {
    $o = '<div class="sg">';
    foreach (array_slice(array_pad($signs, 3, ''), 0, max(3, count($signs))) as $s) $o .= '<div><div class="sp"></div><b>' . $h($s) . '</b><small>' . $h($SET['sign_org']) . '</small></div>';
    return $o . '</div>';
};
$me = currentUser();
$deptName = $w['department_name'] ?: woDeptName($pdo, $orgId, $w['department_id']);
// शेवटच्या पानाच्या तळाशी — कोणी Print / PDF काढली
$genBy = trim(($me['name'] ?? '') . (!empty($me['designation']) ? ' (' . $me['designation'] . ')' : ''));
$genAt = date('d/m/Y h:i A');
$created = trim(($w['created_name'] ?? '') . ($w['created_at'] ? ' · ' . date('d/m/Y', strtotime($w['created_at'])) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Work Order <?= $h($woNo) ?> — <?= $h($w['vendor_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;600;700;800;900&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--ac:#0F766E;--ac2:#0D9488;--acl:#E6F4F2;--ink:#111827;--mut:#4B5563;--ln:#CBD5E1;}
  *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  body{margin:0;background:#E5E7EB;font-family:"Noto Sans","Noto Sans Devanagari",system-ui,sans-serif;color:var(--ink);}
  .bar{position:sticky;top:0;z-index:5;background:#134E4A;color:#fff;display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px 12px;font:600 12.5px system-ui,sans-serif;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;cursor:pointer;background:rgba(255,255,255,.14);}
  .bar .print{background:linear-gradient(90deg,#0D9488,#14B8A6);margin-left:auto;}
  .ok{background:#DCFCE7;color:#166534;padding:8px 14px;font:600 13px system-ui,sans-serif;text-align:center;}
  .pg{width:210mm;min-height:297mm;margin:16px auto;background:#fff;padding:9mm 11mm;box-shadow:0 12px 40px -14px rgba(0,0,0,.35);font-size:11.8px;line-height:1.42;position:relative;}
  /* Letterhead */
  .lh{text-align:center;border-top:5px solid var(--ac);padding:7px 10px 8px;background:linear-gradient(180deg,var(--acl),#fff);border-bottom:2px solid var(--ac);}
  .lh .l0{font-weight:700;font-size:11px;color:var(--ac);letter-spacing:.6px;} .lh .l1{font-weight:900;font-size:18px;letter-spacing:.6px;color:#0B3B37;margin:1px 0 2px;} .lh .ln{font-size:10.3px;color:var(--mut);line-height:1.45;}
  /* शीर्षक */
  .ttl{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:9px 0 8px;}
  .ttl h1{margin:0;font-size:19px;font-weight:900;letter-spacing:3px;color:var(--ac);} .ttl .fy{font-size:11px;font-weight:800;color:#fff;background:var(--ac);border-radius:99px;padding:3px 11px;}
  .ttl .st{font-size:10.5px;font-weight:800;border:1.5px solid var(--ac);color:var(--ac);border-radius:6px;padding:2px 8px;text-transform:uppercase;}
  /* To + माहिती */
  .two{display:grid;grid-template-columns:1.25fr 1fr;gap:9px;margin-bottom:8px;}
  .bx{border:1px solid var(--ln);border-radius:8px;padding:7px 10px;} .bx .lb{font-size:9.5px;font-weight:800;color:var(--ac);text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px;}
  .bx .vn{font-size:13.5px;font-weight:800;} .bx .ad{color:var(--mut);font-size:11px;} .bx .ct{display:flex;gap:12px;flex-wrap:wrap;font-size:10.8px;margin-top:3px;}
  .meta{border:1px solid var(--ln);border-radius:8px;overflow:hidden;font-size:11.2px;} .meta div{display:grid;grid-template-columns:92px 1fr;border-bottom:1px solid var(--ln);} .meta div:last-child{border-bottom:0;}
  .meta span{background:var(--acl);color:var(--ac);font-weight:800;padding:4px 8px;font-size:10.3px;text-transform:uppercase;letter-spacing:.4px;} .meta b{padding:4px 8px;}
  .ref{border-left:4px solid var(--ac);background:#F8FAFC;border-radius:0 8px 8px 0;padding:6px 10px;margin-bottom:7px;} .ref b{color:var(--ac);}
  .ds{margin:0 0 8px;text-align:justify;}
  /* तक्ता */
  table{border-collapse:collapse;width:100%;}
  .it{border:1px solid var(--ln);border-radius:8px;overflow:hidden;border-collapse:separate;border-spacing:0;}
  .it th{background:var(--ac);color:#fff;font-weight:800;font-size:10.8px;padding:6px;text-align:left;text-transform:uppercase;letter-spacing:.3px;} .it th.r{text-align:right;} .it th.c{text-align:center;}
  .it td{padding:5px 6px;vertical-align:top;border-bottom:1px solid #E5E7EB;} .it tbody tr:nth-child(even) td{background:#F8FAFC;} .it tbody tr:last-child td{border-bottom:0;}
  .it td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;} .it td.c{text-align:center;} .it td.sr{color:var(--mut);font-weight:700;}
  .tot{display:grid;grid-template-columns:1fr 250px;gap:10px;margin-top:8px;align-items:start;}
  .words{border:1px dashed var(--ac2);background:var(--acl);border-radius:8px;padding:7px 10px;font-size:11.2px;} .words b{color:var(--ac);display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.8px;}
  .sum{border:1px solid var(--ln);border-radius:8px;overflow:hidden;font-size:11.5px;} .sum div{display:flex;justify-content:space-between;padding:4px 10px;border-bottom:1px solid #E5E7EB;} .sum b{font-variant-numeric:tabular-nums;}
  .sum .gt{background:var(--ac);color:#fff;font-weight:900;font-size:13.5px;border-bottom:0;padding:6px 10px;}
  /* माहिती */
  .info{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;margin-top:8px;} .info div{border:1px solid var(--ln);border-radius:8px;padding:5px 9px;font-size:11px;} .info div.w{grid-column:1/-1;}
  .info span{display:block;font-size:9.3px;font-weight:800;color:var(--ac);text-transform:uppercase;letter-spacing:.6px;}
  .note{font-size:10.4px;color:var(--mut);text-align:justify;margin-top:7px;line-height:1.4;}
  /* स्वीकृती व सह्या */
  .acc{display:grid;grid-template-columns:1fr 1.3fr;gap:10px;margin-top:9px;align-items:stretch;}
  .acc .ty{border:1px solid var(--ln);border-radius:8px;padding:8px 10px;font-weight:800;color:var(--ac);display:flex;flex-direction:column;justify-content:space-between;} .acc .ty small{font-weight:600;color:var(--mut);}
  .acc .ct2{border:1px solid var(--ln);border-radius:8px;overflow:hidden;font-size:11px;} .acc .ct2 div{display:grid;grid-template-columns:118px 1fr;border-bottom:1px solid #E5E7EB;} .acc .ct2 div:last-child{border-bottom:0;}
  .acc .ct2 span{background:#F8FAFC;padding:4px 8px;color:var(--mut);font-weight:700;} .acc .ct2 b{padding:4px 8px;} .acc .ct2 .seal b{min-height:34px;}
  .sg{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px;} .sg > div{text-align:center;border:1px solid var(--ln);border-radius:8px;padding:6px 6px 7px;}
  .sg .sp{height:40px;border-bottom:1px dashed #94A3B8;margin:0 10px 5px;} .sg b{display:block;font-size:11.3px;} .sg small{display:block;font-size:9.8px;color:var(--mut);line-height:1.35;}
  .ft{margin-top:9px;padding-top:6px;border-top:2px solid var(--ac);text-align:center;font-weight:700;font-size:10.5px;color:#0B3B37;}
  /* अटी */
  .hd2{background:var(--acl);border:1px solid var(--ac2);border-radius:8px;padding:7px 10px;margin:9px 0;display:grid;grid-template-columns:1fr auto;gap:6px 14px;align-items:center;}
  .hd2 h2{margin:0;font-size:14px;font-weight:900;color:var(--ac);letter-spacing:1.5px;text-transform:uppercase;} .hd2 .am{font-size:15px;font-weight:900;color:var(--ac);text-align:right;}
  .hd2 .sb{grid-column:1/-1;font-size:10.8px;color:var(--mut);} .hd2 .sb b{color:var(--ink);}
  .tm{border:1px solid var(--ln);border-radius:8px;overflow:hidden;border-collapse:separate;border-spacing:0;}
  .tm td{padding:5px 7px;vertical-align:top;text-align:justify;border-bottom:1px solid #E5E7EB;font-size:11px;} .tm tr:last-child td{border-bottom:0;} .tm tr:nth-child(even) td{background:#F8FAFC;}
  .tm td.no{width:34px;text-align:center;font-weight:800;color:var(--ac);} .tm td.fl{width:58px;text-align:center;vertical-align:middle;}
  .yn{display:inline-block;font-size:9.8px;font-weight:900;border-radius:99px;padding:2px 9px;} .yn.Y{background:#DCFCE7;color:#166534;} .yn.N{background:#FEE2E2;color:#991B1B;}
  .tm .py{margin:4px 0 0;padding:5px 9px;border-left:3px solid var(--ac2);background:#fff;border-radius:0 6px 6px 0;font-size:10.6px;line-height:1.55;}
  .scb{margin-top:8px;text-align:center;font-weight:800;font-size:11px;color:var(--ac);letter-spacing:.4px;}
  .gen{margin-top:10px;padding-top:6px;border-top:1px dashed var(--ln);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:9.6px;color:var(--mut);}
  .gen b{color:var(--ink);}
  .break{break-before:page;page-break-before:always;}
  @media print{ body{background:#fff;} .bar,.ok{display:none !important;} .pg{margin:0;box-shadow:none;width:auto;min-height:0;padding:0;font-size:11px;line-height:1.35;} @page{size:A4 portrait;margin:8mm 9mm;}
    tr,.sg,.acc,.info div{break-inside:avoid;} .first{break-inside:avoid;} .sg .sp{height:28px;} .info{margin-top:5px;gap:4px 8px;} .info div{padding:3px 8px;} .note{margin-top:4px;line-height:1.3;}
    .lh{padding:4px 8px 5px;} .lh .ln{line-height:1.3;font-size:9.8px;} .lh .l1{font-size:17px;margin:0 0 1px;} .two{margin-bottom:6px;gap:7px;}
    .it th{padding:4px 6px;} .it td{padding:3px 6px;} .acc{margin-top:6px;} .acc .ct2 span,.acc .ct2 b{padding:3px 8px;} .acc .ct2 .seal b{min-height:24px;}
    .sg{margin-top:6px;gap:8px;} .sg > div{padding:4px 6px 5px;} .ft{margin-top:6px;padding-top:4px;} }
  @media screen and (max-width:820px){ .pg{width:auto;min-height:0;margin:8px;padding:12px;overflow-x:auto;} .pg > div{min-width:640px;} }
</style>
</head>
<body>
<div class="bar">
  <a href="index.php">← वर्क ऑर्डर</a>
  <?php if (woCanModify($w)): ?><a href="wo.php?id=<?= (int)$w['id'] ?>">✎ बदला</a><?php endif; ?>
  <?php if (woCanCreate()): ?><a href="wo.php?copy=<?= (int)$w['id'] ?>">📄 याच वरून नवीन</a><?php endif; ?>
  <span style="opacity:.85;">W.O. <?= $h($woNo) ?> · <?= $h(woStatuses()[$w['status']][0] ?? '') ?></span>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</div>
<?php if ($flash): ?><div class="ok"><?= $h($flash) ?></div><?php endif; ?>

<!-- पान 1 -->
<div class="pg"><div class="first">
  <?= $head() ?>
  <div class="ttl"><span class="fy"><?= $h($fyLong) ?></span><h1>WORK ORDER</h1><span class="st"><?= $h(['draft' => 'Draft', 'issued' => 'Issued', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$w['status']] ?? '') ?></span></div>
  <div class="two">
    <div class="bx"><div class="lb">To,</div><div class="vn"><?= $h($w['vendor_name']) ?></div><?= $w['vendor_address'] ? '<div class="ad">' . $h($w['vendor_address']) . '</div>' : '' ?>
      <div class="ct"><span>📞 <?= $h($w['vendor_phone'] ?: '—') ?></span><span>✉ <?= $h($w['vendor_email'] ?: '—') ?></span><?= $w['vendor_gstin'] ? '<span>GSTIN: ' . $h($w['vendor_gstin']) . '</span>' : '' ?></div></div>
    <div class="meta"><div><span>W.O. No.</span><b><?= $h($woNo) ?></b></div><div><span>Date</span><b><?= $h($date) ?></b></div><?php if ($deptName): ?><div><span>Department</span><b><?= $h($deptName) ?></b></div><?php endif; ?><div><span>Kind Attn.</span><b><?= $h($w['attn'] ?: 'M/s ' . $w['vendor_name']) ?></b></div></div>
  </div>
  <div class="ref"><b>Ref:</b> <?= $h($w['subject']) ?></div>
  <div class="ds">Dear Sir,<br>With Reference to “<?= $h($w['subject']) ?>” issuing Following Work Order to you. The details of rate, terms &amp; conditions are as below-</div>
  <table class="it">
    <thead><tr><th class="c" style="width:40px;">Sr.</th><th>Particular</th><th class="c" style="width:<?= $hasUnit ? '72' : '96' ?>px;"><?= $h($L['qty']) ?></th><?php if ($hasUnit): ?><th class="c" style="width:72px;"><?= $h($L['unit']) ?></th><?php endif; ?><th class="r" style="width:92px;"><?= $h($L['rate']) ?></th><th class="r" style="width:108px;">Amount ₹</th></tr></thead>
    <tbody>
    <?php $sr = 0; $grp = false; foreach ($w['_items'] as $it):
      // मथळ्याची ओळ (नग / दर / रक्कम नाही) — क्रमांक; तिच्या खालच्या उप-ओळी — क्रमांक नाही
      $isHead = $it['amount'] === null && $it['rate'] === null && trim((string)$it['qty']) === '';
      $no = ($isHead || !$grp) ? ++$sr : ''; if ($isHead) $grp = true; ?>
      <tr><td class="c sr"><?= $no ?></td><td style="white-space:pre-wrap;<?= $isHead ? 'font-weight:700;' : '' ?>"><?= $h($it['particular']) ?></td><td class="c"><?= $h($it['qty']) ?></td><?php if ($hasUnit): ?><td class="c"><?= $h($it['unit']) ?></td><?php endif; ?><td class="n"><?= $h($m($it['rate'])) ?></td><td class="n"><?= $h($m($it['amount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="tot">
    <div class="words"><b>Amount in words</b><?= $h($words) ?></div>
    <div class="sum"><div><span>Total</span><b><?= $h($m($w['subtotal'])) ?></b></div><div><span><?= $h($gstLbl) ?></span><b><?= $h($m($w['gst_amount'])) ?></b></div>
      <?php if ((float)$w['round_off'] != 0): ?><div><span>Round Off</span><b><?= $h($m($w['round_off'])) ?></b></div><?php endif; ?>
      <div class="gt"><span>Grand Total</span><b>₹ <?= $h($m($w['grand_total'])) ?> /-</b></div></div>
  </div>
  <div class="info">
    <?php if ($w['purpose']): ?><div class="w"><span>Purpose</span><?= $h($w['purpose']) ?></div><?php endif; ?>
    <div><span>Date of Execution (Work start)</span><?= $h($w['execution_note']) ?></div>
    <div><span>Target Date of Completion</span><?= $h($w['completion_note']) ?></div>
    <?php if ($w['taxes_note']): ?><div class="w"><span>Taxes</span><?= $h(preg_replace('/^Taxes\s*-\s*/i', '', $w['taxes_note'])) ?></div><?php endif; ?>
  </div>
  <div class="note">All statement or other communication required or permitted to be given or made under this work order shall be in writing and delivered by hand, email or facsimile transmission addressed to the intended recipient at its address set out above. The above contract/ Work agreement for the above have seen clearly described, read, understood, seen &amp; accepted to execute the above order in line the requirement.</div>
  <div class="acc">
    <div class="ty">Thanking You.<small>For the Trust — terms &amp; conditions overleaf</small></div>
    <div class="ct2"><div><span>W.O. No.</span><b><?= $h($woNo) ?></b></div><div><span>Contractor Name</span><b><?= $h($w['vendor_name']) ?></b></div><div class="seal"><span>Firm Seal</span><b></b></div><div><span>Date</span><b><?= $h($date) ?></b></div></div>
  </div>
  <?= $signRow() ?>
  <div class="ft"><?= $h($SET['footer']) ?></div>
</div></div>

<!-- पान 2 — अटी -->
<div class="pg break"><div>
  <?= $head() ?>
  <div class="hd2"><h2>Terms &amp; Conditions</h2><div class="am">₹ <?= $h($m($w['grand_total'])) ?> /-</div>
    <div class="sb">Work Order No. <b><?= $h($woNo) ?></b> · <b><?= $h(mb_strtoupper($w['vendor_name'])) ?></b> · In Words: <?= $h($words) ?></div></div>
  <table class="tm">
  <?php foreach ($terms as $i => $t): $f = $t[1] ?? ''; ?>
    <tr><td class="no"><?= $i + 1 ?>)</td><td><?= $h($t[0]) ?>
      <?php if (stripos($t[0], 'Payment Terms') !== false && $pay): ?><div class="py"><?php foreach ($pay as $p): ?><div><?= $h($p) ?></div><?php endforeach; ?></div><?php endif; ?></td><td class="fl"><?= $f !== '' ? '<span class="yn ' . ($f === 'YES' ? 'Y' : 'N') . '">' . $h($f) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?>
  </table>
  <div class="acc">
    <div class="ty">Thanking You.<small><?= $h($w['vendor_name']) ?></small></div>
    <div class="ct2"><div><span>W.O. No.</span><b><?= $h($woNo) ?></b></div><div><span>Date</span><b><?= $h($date) ?></b></div><div><span>Amount</span><b>₹ <?= $h($m($w['grand_total'])) ?> /- <small style="font-weight:600;color:var(--mut);">(<?= $h($words) ?>)</small></b></div></div>
  </div>
  <?= $signRow() ?>
  <div class="ft"><?= $h($SET['footer']) ?></div>
  <div class="scb">✍ Signed by Contractor as a token of accepted</div>
  <div class="gen"><span>🖨️ Generated by <b><?= $h($genBy ?: '—') ?></b> · <?= $h($genAt) ?></span><span><?= $created ? 'Created by ' . $h($created) . ' · ' : '' ?>E-Admin · W.O. <?= $h($woNo) ?></span></div>
</div></div>
</body>
</html>
