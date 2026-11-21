<?php
// 🖨️ बिल परवानगी पत्रक — फाईलवर लावायचे A4 (आडवे) पत्रक, black & white.
//  copy=ep (default): "Inward Files For Executive President Sir Signing Purpose" — सर्व बिलं, 4 सह्या
//  copy=acc: "Bill's submitted from Purchase Dept. to the Account Dept." — फक्त परवानगी झालेली बिलं, टीप, 2 सह्या
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/bill-sign.php';
if (!bsCanView()) { http_response_code(403); exit; }
$orgId = activeOrgId();
if (!bsReady($pdo)) { header('Location: index.php'); exit; }
$s = bsLoad($pdo, $orgId, (int)($_GET['id'] ?? 0));
if (!$s) { header('Location: index.php'); exit; }
$acc = ($_GET['copy'] ?? '') === 'acc';
$SET = bsSettings($pdo, $orgId);
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$groups = bsGroups($s['_items']);
if ($acc) $groups = array_values(array_filter($groups, fn($g) => bsGroupStatus($g) === 'approved'));
$items = array_merge([], ...($groups ?: [[]]));
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $items));
$has = fn($k) => (bool)array_filter($items, fn($r) => trim((string)$r[$k]) !== '');
$cols = ['doc_type' => 'PO/Bill', 'tippani_no' => 'Tippani No', 'party' => 'Party Name', 'particular' => 'Particular'];
if ($has('paid_by')) $cols['paid_by'] = 'Bill Paid By';
$cols += ['bill_no' => 'Bill No', 'bill_date' => 'Bill Date', 'amount' => 'Amount', 'inward_date' => 'Bill Inward Date'];
if ($has('material_date')) $cols['material_date'] = 'Material Receive Date';
if ($acc || $has('return_date')) $cols['return_date'] = 'Bill Return Date';
$cols['remark'] = 'Remark';
$fyLong = substr($s['fy'], 0, 4) . '-' . (substr($s['fy'], 0, 2) . substr($s['fy'], 5, 2));
$lh = array_values(array_filter(array_map('trim', explode("\n", (string)$SET['letterhead'])), 'strlen'));
$signs = $acc ? $SET['acc_signs'] : $SET['signs'];
$me = currentUser();
$genBy = trim(($me['name'] ?? '') . (!empty($me['designation']) ? ' (' . $me['designation'] . ')' : ''));
$genAt = date('d/m/Y h:i A');
$ST = bsStatuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $acc ? 'Acc. Dept.' : 'Executive President Sir Sign' ?> — Sheet No. <?= $h(bsSheetLabel($s)) ?> (<?= $h(purchaseDate($s['sheet_date'])) ?>)</title>
<style>
  *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  body{margin:0;background:#E5E7EB;font-family:"Noto Sans","Noto Sans Devanagari",Arial,system-ui,sans-serif;color:#111;}
  .bar{position:sticky;top:0;z-index:5;background:#312E81;color:#fff;display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px 12px;font:600 12.5px system-ui,sans-serif;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;cursor:pointer;background:rgba(255,255,255,.14);} .bar a.on{background:#fff;color:#312E81;}
  .bar .print{background:#6366F1;margin-left:auto;}
  .pg{width:297mm;min-height:210mm;margin:16px auto;background:#fff;padding:9mm 10mm;box-shadow:0 12px 40px -14px rgba(0,0,0,.35);font-size:11.5px;line-height:1.35;}
  .lh{text-align:center;border-bottom:2px solid #111;padding-bottom:5px;} .lh .l0{font-weight:700;font-size:11px;letter-spacing:.5px;} .lh .l1{font-weight:900;font-size:18px;letter-spacing:.4px;margin:1px 0;} .lh .ln{font-size:11px;}
  .tt{text-align:center;margin:7px 0 2px;} .tt .a{font-weight:800;font-size:13px;text-decoration:underline;text-underline-offset:3px;} .tt .b{font-weight:700;font-size:12px;margin-top:2px;}
  .meta{display:flex;justify-content:space-between;align-items:flex-end;margin:6px 0 6px;font-size:12.5px;font-weight:800;} .meta .no{border:1.5px solid #111;border-radius:6px;padding:2px 10px;} .meta .st{font-size:10.5px;font-weight:700;border:1px solid #777;border-radius:5px;padding:1px 7px;margin-left:8px;}
  table.bt{width:100%;border-collapse:collapse;font-size:11px;}
  .bt th{border:1px solid #111;background:#EDEDED;padding:5px 4px;font-weight:800;font-size:10.5px;text-align:center;vertical-align:middle;line-height:1.2;}
  .bt td{border:1px solid #555;padding:4px 5px;vertical-align:top;} .bt td.c{text-align:center;} .bt td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:700;} .bt td.nw{white-space:nowrap;}
  .bt tr.sub td{border-top:1px dotted #999;} .bt tr.sub td.k{border-top-color:transparent;}
  .bt tr.tot td{border:1.5px solid #111;font-weight:900;font-size:12.5px;background:#F4F4F4;}
  .wd{margin:5px 0 0;font-size:11.5px;font-weight:700;}
  .nt{margin:8px 0 0;font-size:12px;font-weight:700;font-style:italic;}
  .sg{display:grid;gap:14px;margin-top:38px;} .sg div{text-align:center;font-weight:800;font-size:11.5px;border-top:1px solid #111;padding-top:4px;}
  .gen{margin-top:12px;padding-top:5px;border-top:1px dashed #999;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:9.5px;color:#333;} .gen b{color:#111;}
  .empty{border:1px dashed #999;padding:20px;text-align:center;margin-top:10px;}
  @media print{ body{background:#fff;} .bar{display:none !important;} .pg{margin:0;box-shadow:none;width:auto;min-height:0;padding:0;} @page{size:A4 landscape;margin:8mm 9mm;}
    .bt thead{display:table-header-group;} .bt tr{break-inside:avoid;} .sg,.foot{break-inside:avoid;} }
  @media screen and (max-width:900px){ .pg{width:auto;min-height:0;margin:8px;padding:12px;overflow-x:auto;} .pg > div{min-width:860px;} }
</style>
</head>
<body>
<div class="bar">
  <a href="view.php?id=<?= (int)$s['id'] ?>">← Sheet No. <?= $h(bsSheetLabel($s)) ?></a>
  <a href="print.php?id=<?= (int)$s['id'] ?>" class="<?= $acc ? '' : 'on' ?>">✍️ सहीसाठी</a>
  <a href="print.php?id=<?= (int)$s['id'] ?>&amp;copy=acc" class="<?= $acc ? 'on' : '' ?>">🏦 लेखा विभाग</a>
  <span style="opacity:.85;"><?= $h($ST[$s['status']][0] ?? '') ?></span>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</div>
<div class="pg"><div>
  <div class="lh"><?php foreach ($lh as $i => $l): ?><div class="<?= $i === 0 ? 'l0' : ($i === 1 ? 'l1' : 'ln') ?>"><?= $h($l) ?></div><?php endforeach; ?></div>
  <div class="tt"><?php if ($acc): ?><div class="a"><?= $h($SET['acc_title']) ?></div><div class="b"><?= $h($SET['title'] . ' ' . $fyLong) ?></div>
    <?php else: ?><div class="a"><?= $h($SET['subtitle']) ?></div><div class="b"><?= $h($SET['title'] . ' ' . $fyLong) ?></div><?php endif; ?></div>
  <div class="meta"><span><span class="no">Sheet No. <?= $h(bsSheetLabel($s)) ?></span><?php if ($s['status'] === 'draft'): ?><span class="st">DRAFT</span><?php endif; ?></span><span>Date : <?= $h(purchaseDate($acc && $s['accounts_at'] ? $s['accounts_at'] : $s['sheet_date'])) ?></span></div>
  <?php if (!$items): ?><div class="empty">या पत्रकातील कोणत्याही बिलाला अजून परवानगी नाही.</div><?php else: ?>
  <table class="bt">
    <thead><tr><th style="width:32px;">Sr. No.</th><?php foreach ($cols as $k => $l): ?><th<?= $k === 'amount' ? ' style="width:80px;"' : '' ?>><?= $h($l) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($groups as $gi => $g): foreach ($g as $j => $r): ?>
      <tr class="<?= $j ? 'sub' : '' ?>"><td class="c k"><?= $j ? '' : ($gi + 1) ?></td>
        <?php foreach ($cols as $k => $l): $v = $r[$k];
          if ($k === 'amount') echo '<td class="n">' . ($v !== null ? $h(woMoney($v)) : '') . '</td>';
          elseif (in_array($k, ['bill_date', 'inward_date', 'material_date', 'return_date'], true)) echo '<td class="c nw">' . $h($v) . '</td>';
          elseif (in_array($k, ['doc_type', 'tippani_no', 'bill_no'], true)) echo '<td class="c">' . $h($v) . '</td>';
          else echo '<td>' . $h($v) . '</td>';
        endforeach; ?></tr>
    <?php endforeach; endforeach; ?>
      <tr class="tot"><td colspan="<?= array_search('amount', array_keys($cols), true) + 1 ?>" style="text-align:right;">TOTAL</td><td class="n">₹ <?= $h(woMoney($total)) ?></td><td colspan="<?= count($cols) - array_search('amount', array_keys($cols), true) - 1 ?>"></td></tr>
    </tbody>
  </table>
  <div class="wd">(In Words: <?= $h(woWords($total)) ?>)</div>
  <?php endif; ?>
  <div class="foot">
    <?php if ($acc): ?><div class="nt"><?= $h($SET['acc_note']) ?></div><?php endif; ?>
    <div class="sg" style="grid-template-columns:repeat(<?= max(1, count($signs)) ?>,1fr);"><?php foreach ($signs as $sg): ?><div><?= $h($sg) ?></div><?php endforeach; ?></div>
    <div class="gen"><span>Generated by <b><?= $h($genBy ?: '—') ?></b> · <?= $h($genAt) ?></span><span><?= $s['created_name'] ? 'Prepared by ' . $h($s['created_name']) . ' · ' : '' ?>E-Admin · Sheet No. <?= $h(bsSheetLabel($s)) ?> · <?= count($groups) ?> bills</span></div>
  </div>
</div></div>
</body>
</html>
