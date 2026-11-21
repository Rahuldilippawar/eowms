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
$head = function () use ($lh, $h) {
    $o = '<div class="lh">';
    foreach ($lh as $i => $l) $o .= '<div class="' . ($i === 0 ? 'l0' : ($i === 1 ? 'l1' : 'ln')) . '">' . $h($l) . '</div>';
    return $o . '</div>';
};
$signRow = function () use ($signs, $SET, $h) {
    $o = '<table class="sg"><tr>';
    foreach (array_slice(array_pad($signs, 3, ''), 0, max(3, count($signs))) as $s) $o .= '<td><div class="sp"></div><b>' . $h($s) . '</b><div>' . $h($SET['sign_org']) . '</div></td>';
    return $o . '</tr></table>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Work Order <?= $h($woNo) ?> — <?= $h($w['vendor_name']) ?></title>
<style>
  *{box-sizing:border-box;} body{margin:0;background:#E7E5E4;font-family:"Times New Roman",Georgia,serif;color:#000;}
  .bar{position:sticky;top:0;z-index:5;background:#134E4A;color:#fff;display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px 12px;font:600 12.5px system-ui,sans-serif;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;cursor:pointer;background:rgba(255,255,255,.14);}
  .bar .print{background:linear-gradient(90deg,#0D9488,#0F766E);margin-left:auto;}
  .ok{background:#DCFCE7;color:#166534;padding:8px 14px;font:600 13px system-ui,sans-serif;text-align:center;}
  .pg{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:10mm 12mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);font-size:12.5px;line-height:1.4;}
  .box{border:1.5px solid #000;}
  .lh{text-align:center;padding:6px 8px;border-bottom:1.5px solid #000;} .lh .l0{font-weight:700;font-size:12px;} .lh .l1{font-weight:800;font-size:17px;letter-spacing:.3px;} .lh .ln{font-size:11.5px;}
  .wt{display:flex;justify-content:space-between;align-items:center;padding:4px 8px;border-bottom:1px solid #000;font-weight:800;} .wt span:last-child{font-size:16px;text-decoration:underline;}
  .to{padding:6px 8px;border-bottom:1px solid #000;} .to b{font-size:13.5px;}
  .rf{display:flex;justify-content:space-between;gap:10px;padding:5px 8px;border-bottom:1px solid #000;font-weight:700;} .at{padding:4px 8px;border-bottom:1px solid #000;font-weight:700;}
  .ds{padding:6px 8px;border-bottom:1px solid #000;text-align:justify;}
  table{border-collapse:collapse;width:100%;} .it th,.it td{border:1px solid #000;padding:4px 6px;vertical-align:top;} .it th{background:#F1F5F4;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .it td.n{text-align:right;white-space:nowrap;} .it td.c{text-align:center;} .it tr.t td{font-weight:800;} .it tr.g td{font-weight:800;font-size:13.5px;background:#F1F5F4;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .wd{padding:5px 8px;border-bottom:1px solid #000;font-weight:700;font-style:italic;}
  .ln2{padding:4px 8px;border-bottom:1px solid #000;text-align:justify;}
  .acc{width:100%;} .acc td{border:1px solid #000;padding:4px 8px;vertical-align:top;}
  .sg td{width:33.3%;text-align:center;vertical-align:bottom;padding:4px 6px;font-size:11.5px;border:1px solid #000;} .sg .sp{height:46px;} .sg b{display:block;font-size:12px;}
  .ft{padding:5px 8px;text-align:center;font-weight:700;font-size:11.5px;}
  .hd2{padding:6px 8px;border-bottom:1px solid #000;font-weight:800;text-align:center;}
  .tm td{border:1px solid #000;padding:4px 6px;vertical-align:top;text-align:justify;} .tm td.no{width:38px;text-align:center;font-weight:700;} .tm td.fl{width:52px;text-align:center;font-weight:800;}
  .tm .py{padding-left:18px;font-size:12px;}
  .scb{padding:6px 8px;font-weight:700;text-align:center;font-size:12px;}
  .break{break-before:page;page-break-before:always;}
  @media print{ body{background:#fff;} .bar,.ok{display:none !important;} .pg{margin:0;box-shadow:none;width:auto;min-height:0;padding:0;font-size:11.5px;line-height:1.3;} @page{size:A4 portrait;margin:8mm 9mm;} tr{break-inside:avoid;}
    .lh{padding:4px 8px;} .lh .ln{font-size:10.5px;} .lh .l1{font-size:16px;} .ln2,.ds,.to{padding:3px 8px;} .it th,.it td{padding:3px 6px;} .sg .sp{height:34px;} .tm td{padding:3px 6px;font-size:11px;} .first{break-inside:avoid;} }
  @media screen and (max-width:820px){ .pg{width:auto;min-height:0;margin:8px;padding:10px;overflow-x:auto;} .pg > .box{min-width:640px;} }
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
<div class="pg"><div class="box first">
  <?= $head() ?>
  <div class="wt"><span><?= $h($fyLong) ?></span><span>Work Order</span><span></span></div>
  <div class="to">To,<br><b><?= $h($w['vendor_name']) ?></b><?= $w['vendor_address'] ? '<br>' . $h($w['vendor_address']) : '' ?>
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:2px;"><span>Phone No. :- <?= $h($w['vendor_phone']) ?></span><span>E mail :- <?= $h($w['vendor_email']) ?></span><?= $w['vendor_gstin'] ? '<span>GSTIN :- ' . $h($w['vendor_gstin']) . '</span>' : '' ?></div></div>
  <div class="rf"><span>Ref: <?= $h($w['subject']) ?></span></div>
  <div class="rf"><span>W. O. No :- <?= $h($woNo) ?>.</span><span>Date: <?= $h($date) ?></span></div>
  <div class="at">Kind Attn. <?= $h($w['attn'] ?: 'M/s ' . $w['vendor_name']) ?></div>
  <div class="ds">Dear Sir,<br>With Reference to “<?= $h($w['subject']) ?>” issuing Following Work Order to you. The details of rate, terms &amp; conditions are as below-</div>
  <table class="it">
    <thead><tr><th style="width:42px;">Sr.No.</th><th>Particular</th><th style="width:<?= $hasUnit ? '70' : '95' ?>px;"><?= $h($L['qty']) ?></th><?php if ($hasUnit): ?><th style="width:70px;"><?= $h($L['unit']) ?></th><?php endif; ?><th style="width:90px;"><?= $h($L['rate']) ?></th><th style="width:105px;">Amount</th></tr></thead>
    <tbody>
    <?php $sr = 0; $grp = false; foreach ($w['_items'] as $it):
      // मथळ्याची ओळ (नग / दर / रक्कम नाही) — क्रमांक; तिच्या खालच्या उप-ओळी — क्रमांक नाही
      $isHead = $it['amount'] === null && $it['rate'] === null && trim((string)$it['qty']) === '';
      $no = ($isHead || !$grp) ? ++$sr : ''; if ($isHead) $grp = true; ?>
      <tr><td class="c"><?= $no ?></td><td style="white-space:pre-wrap;"><?= $h($it['particular']) ?></td><td class="c"><?= $h($it['qty']) ?></td><?php if ($hasUnit): ?><td class="c"><?= $h($it['unit']) ?></td><?php endif; ?><td class="n"><?= $h($m($it['rate'])) ?></td><td class="n"><?= $h($m($it['amount'])) ?></td></tr>
    <?php endforeach; ?>
      <tr class="t"><td></td><td colspan="<?= $cols - 2 ?>" style="text-align:right;">TOTAL</td><td class="n"><?= $h($m($w['subtotal'])) ?></td></tr>
      <tr class="t"><td></td><td colspan="<?= $cols - 2 ?>" style="text-align:right;"><?= $h($gstLbl) ?></td><td class="n"><?= $h($m($w['gst_amount'])) ?></td></tr>
      <?php if ((float)$w['round_off'] != 0): ?><tr class="t"><td></td><td colspan="<?= $cols - 2 ?>" style="text-align:right;">Round Off</td><td class="n"><?= $h($m($w['round_off'])) ?></td></tr><?php endif; ?>
      <tr class="g"><td></td><td colspan="<?= $cols - 2 ?>" style="text-align:right;">Grand Total</td><td class="n"><?= $h($m($w['grand_total'])) ?> /-</td></tr>
    </tbody>
  </table>
  <div class="wd">(In Words: <?= $h($words) ?>)</div>
  <?php if ($w['purpose']): ?><div class="ln2"><b>Purpose:-</b> <?= $h($w['purpose']) ?></div><?php endif; ?>
  <?php if ($w['taxes_note']): ?><div class="ln2"><b>Taxes-</b> <?= $h(preg_replace('/^Taxes\s*-\s*/i', '', $w['taxes_note'])) ?></div><?php endif; ?>
  <div class="ln2">All statement or other communication required or permitted to be given or made under this work order shall be in writing and delivered by hand, email or facsimile transmission addressed to the intended recipient at its address set out above.</div>
  <div class="ln2"><b>Date of Execution (Work start date):-</b> <?= $h($w['execution_note']) ?></div>
  <div class="ln2"><b>Target Date of Completion:-</b> <?= $h($w['completion_note']) ?></div>
  <div class="ln2">The above contract/ Work agreement for the above have seen clearly described, read, understood, seen &amp; accepted to execute the above order in line the requirement.</div>
  <table class="acc"><tr><td rowspan="4" style="width:34%;font-weight:700;">Thanking You.</td><td style="width:26%;">W.O. NO. :-</td><td><b><?= $h($woNo) ?></b></td></tr>
    <tr><td>Contractor Name :</td><td><?= $h($w['vendor_name']) ?></td></tr><tr><td>Firm Seal :</td><td style="height:36px;"></td></tr><tr><td>Date:-</td><td><?= $h($date) ?></td></tr></table>
  <?= $signRow() ?>
  <div class="ft"><?= $h($SET['footer']) ?></div>
</div></div>

<!-- पान 2 — अटी -->
<div class="pg break"><div class="box">
  <?= $head() ?>
  <div class="hd2">WORK ORDER NO.:- <?= $h($woNo) ?> (<?= $h(mb_strtoupper($w['vendor_name'])) ?>) With Amount of Rs. :- <?= $h($m($w['grand_total'])) ?>/- IN WORDS: <?= $h(mb_strtoupper($words)) ?></div>
  <table class="tm">
  <?php foreach ($terms as $i => $t): ?>
    <tr><td class="no"><?= $i + 1 ?>)</td><td><?= $h($t[0]) ?>
      <?php if (stripos($t[0], 'Payment Terms') !== false && $pay): ?><div class="py"><?php foreach ($pay as $p): ?><div><?= $h($p) ?></div><?php endforeach; ?></div><?php endif; ?></td><td class="fl"><?= $h($t[1] ?? '') ?></td></tr>
  <?php endforeach; ?>
  </table>
  <table class="acc"><tr><td rowspan="3" style="width:34%;font-weight:700;">Thanking You.<br><span style="font-weight:400;"><?= $h($w['vendor_name']) ?></span></td><td style="width:20%;">W.O. NO.:-</td><td><b><?= $h($woNo) ?></b></td></tr>
    <tr><td>Date:-</td><td><?= $h($date) ?></td></tr><tr><td>Amount</td><td><b><?= $h($m($w['grand_total'])) ?></b> (In Words: <?= $h($words) ?>)</td></tr></table>
  <?= $signRow() ?>
  <div class="ft"><?= $h($SET['footer']) ?></div>
  <div class="scb">Signed by Contractor as a token of accepted</div>
</div></div>
</body>
</html>
