<?php
// 📊 तुलनात्मक तक्ता — अधिकृत Print / PDF (A4), L1 / प्रत्येक वस्तूचा कमी दर, सारांश, निवड व मान्यता (Approve), सह्या
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/purchase-compare.php';
if (!purchaseCmpCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">तुलनात्मक तक्ता पाहण्याची परवानगी नाही.</div>'); }
if (!purchaseCmpReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
$c = purchaseCmpLoad($pdo, $orgId, $id);
if (!$c) { header('Location: index.php'); exit; }
$self = 'view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'approve_chart' && purchaseCmpCanApprove()) {
        $vi = (int)($_POST['vendor'] ?? -1);
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        $C = purchaseCmpCompute($c);
        if (!isset($c['vendors'][$vi])) $_SESSION['flash'] = '⚠️ पुरवठादार निवडा.';
        elseif ($c['vendors'][$vi]['id'] !== $C['l1'] && mb_strlen($note) < 5) $_SESSION['flash'] = '⚠️ L1 (सर्वात कमी) नसलेला पुरवठादार निवडताना कारण लिहा.';
        else {
            $nm = trim(($user['name'] ?? '') . (!empty($user['designation']) && $user['designation'] !== ($user['name'] ?? '') ? ', ' . $user['designation'] : ''));
            $pdo->prepare("UPDATE purchase_comparisons SET status = 'approved', selected_vendor = ?, approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ? WHERE id = ? AND organization_id = ?")
                ->execute([$vi, $user['id'], $nm, $note ?: null, $id, $orgId]);
            logActivity('तुलनात्मक तक्ता — मंजूर', 'purchase_comparison', $id, $c['title'] . ' → ' . $c['vendors'][$vi]['name']);
            $_SESSION['flash'] = '✅ मंजूर — निवडलेला पुरवठादार: ' . $c['vendors'][$vi]['name'];
        }
    } elseif ($act === 'withdraw_approval' && purchaseCmpCanApprove()) {
        $pdo->prepare("UPDATE purchase_comparisons SET status = 'final', selected_vendor = NULL, approved_by = NULL, approved_name = NULL, approved_at = NULL, approval_note = NULL WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        logActivity('तुलनात्मक तक्ता — मान्यता काढली', 'purchase_comparison', $id, $c['title']);
        $_SESSION['flash'] = '↺ मान्यता काढली.';
    } elseif ($act === 'cancel_chart' && purchaseCmpCanEdit()) {
        $pdo->prepare("UPDATE purchase_comparisons SET status = 'cancelled', updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$user['id'], $id, $orgId]);
        logActivity('तुलनात्मक तक्ता — रद्द', 'purchase_comparison', $id, $c['title']);
        $_SESSION['flash'] = '✕ तक्ता रद्द केला.';
    } elseif ($act === 'reopen_chart' && purchaseCmpCanEdit()) {
        $pdo->prepare("UPDATE purchase_comparisons SET status = 'final', updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$user['id'], $id, $orgId]);
        $_SESSION['flash'] = '↺ तक्ता पुन्हा मान्यतेसाठी.';
    } elseif ($act === 'delete_chart' && purchaseCmpCanEdit()) {
        if (function_exists('recycleCapture')) { @recycleCapture($pdo, 'purchase_comparisons', $id, 'तुलनात्मक तक्ता: ' . $c['title'], $orgId); }
        $ids = array_map(fn($x) => (int)$x['id'], $c['items']);
        if ($ids) $pdo->exec("DELETE FROM purchase_comparison_rates WHERE item_id IN (" . implode(',', $ids) . ")");
        $pdo->prepare("DELETE FROM purchase_comparison_items WHERE comparison_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM purchase_comparison_vendors WHERE comparison_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM purchase_comparisons WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        logActivity('तुलनात्मक तक्ता हटवला', 'purchase_comparison', $id, $c['title']);
        $_SESSION['flash'] = '🗑️ तक्ता हटवला.';
        header('Location: index.php'); exit;
    }
    header('Location: ' . $self); exit;
}

$C = purchaseCmpCompute($c);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $org->execute([$orgId]); $orgName = (string)$org->fetchColumn();
$head = function_exists('purchaseReportHead') ? purchaseReportHead($pdo, $orgId, $orgName) : $orgName;
$V = $c['vendors']; $nV = count($V);
$sel = $c['selected_vendor'] !== null && isset($V[(int)$c['selected_vendor']]) ? (int)$c['selected_vendor'] : null;
$vIdx = []; foreach ($V as $i => $v) $vIdx[$v['id']] = $i;
$l1i = $C['l1'] !== null ? $vIdx[$C['l1']] : null;
$ST = purchaseCmpStatuses()[$c['status']] ?? purchaseCmpStatuses()['draft'];
$land = $nV >= 3 || ($_GET['o'] ?? '') === 'l';
if (($_GET['o'] ?? '') === 'p') $land = false;
$req = null;
if ($c['request_id'] && purchaseReady($pdo)) { $st = $pdo->prepare("SELECT id, sr_no, fy, title FROM purchase_requests WHERE id = ? AND organization_id = ?"); $st->execute([$c['request_id'], $orgId]); $req = $st->fetch(); }
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$M = fn($x) => purchaseCmpMoney($x);
$signs = array_values(array_filter(array_map('trim', explode("\n", (string)($c['signatories'] ?: purchaseCmpDefaultSigns())))));
$anyExtra = fn($k) => (bool)array_filter($V, fn($v) => ($C['vendors'][$v['id']][$k] ?? 0) != 0);
$anyTrIncl = (bool)array_filter($V, fn($v) => (int)$v['transport_incl']);
$anyGstIncl = (bool)array_filter($V, fn($v) => (int)$v['gst_incl']);
$otherLbl = implode(' / ', array_unique(array_filter(array_map(fn($v) => (string)$v['other_label'], $V)))) ?: 'इतर खर्च';
$gstLbl = implode(' / ', array_unique(array_filter(array_map(fn($v) => $v['gst_pct'] !== null ? purchaseCmpNum($v['gst_pct']) . '%' : '', $V))));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>तुलनात्मक तक्ता #<?= (int)$c['cmp_no'] ?> — <?= $h($c['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;} body{margin:0;background:#E8ECF4;font-family:'Noto Sans Devanagari','Calibri',sans-serif;color:#111;font-size:12px;}
  .bar{position:sticky;top:0;z-index:5;background:#172554;color:#fff;padding:9px 12px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;font-size:12.5px;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,255,255,.13);}
  .bar .print{background:linear-gradient(90deg,#16A34A,#0E8C7A);margin-left:auto;} .bar .ok{background:#16A34A;} .bar .warn{background:#B45309;}
  .flash{max-width:<?= $land ? '297' : '210' ?>mm;margin:10px auto 0;background:#DCFCE7;border:1px solid #86EFAC;color:#14532D;border-radius:10px;padding:8px 12px;font-size:13px;}
  .sheet{width:<?= $land ? '297' : '210' ?>mm;margin:12px auto;background:#fff;padding:10mm 10mm 12mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);}
  .hd{text-align:center;} .hd div{font-weight:700;font-size:14px;line-height:1.45;} .hd div:first-child{font-size:13px;} .hd .ti{font-size:17px;font-weight:800;margin-top:4px;letter-spacing:.3px;}
  .meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:8px 0 6px;font-size:12.5px;} .meta b{font-weight:800;}
  table.t{width:100%;border-collapse:collapse;table-layout:auto;}
  table.t th,table.t td{border:1px solid #111;padding:4px 5px;vertical-align:middle;}
  table.t th{background:#EEF2FF;font-weight:800;font-size:11.5px;text-align:center;}
  table.t td{font-size:11.5px;} table.t td.n{text-align:right;white-space:nowrap;} table.t td.c{text-align:center;}
  table.t td.min{background:#DCFCE7;font-weight:800;color:#14532D;}
  table.t td.na{color:#999;text-align:center;}
  table.t tr.x td{background:#FAFAFA;} table.t tr.x td.lb{text-align:right;font-weight:700;}
  table.t tr.g td{font-weight:800;font-size:12.5px;background:#EEF2FF;} table.t tr.g td.best{background:#16A34A;color:#fff;}
  table.t tr.rk td{font-weight:800;text-align:center;} .rk1{color:#15803D;} .rkn{color:#64748B;}
  th.sel,td.sel{outline:2.5px solid #16A34A;outline-offset:-2px;}
  tr{break-inside:avoid;page-break-inside:avoid;} thead{display:table-header-group;}
  .sum{display:grid;grid-template-columns:1.2fr 1fr;gap:10px;margin-top:10px;}
  .box{border:1px solid #CBD5E1;border-radius:8px;padding:8px 10px;font-size:12px;line-height:1.6;} .box h4{margin:0 0 4px;font-size:12.5px;color:#1E3A8A;}
  .box ul{margin:0;padding-left:16px;}
  .appr{border:2px solid #16A34A;border-radius:10px;padding:8px 12px;margin-top:10px;font-size:12.5px;background:#F0FDF4;}
  .signs{display:flex;justify-content:space-between;gap:12px;margin-top:38px;} .signs div{flex:1;text-align:center;border-top:1px solid #111;padding-top:4px;font-size:12px;font-weight:700;}
  .foot{margin-top:10px;font-size:9.5px;color:#777;text-align:center;}
  .mod{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9;align-items:center;justify-content:center;} .mod.on{display:flex;}
  .mod form{background:#fff;border-radius:14px;padding:16px;width:min(520px,92vw);font-size:13px;} .mod select,.mod textarea{width:100%;font:inherit;padding:6px;border:1px solid #CBD5E1;border-radius:8px;margin-top:4px;}
  .vr{font-size:10.5px;color:#475569;font-weight:600;}
  @media print{ body{background:#fff;} .bar,.mod,.flash,.noprint{display:none !important;} .sheet{margin:0;box-shadow:none;width:auto;padding:0;} @page{size:A4 <?= $land ? 'landscape' : 'portrait' ?>;margin:8mm;} }
  @media screen and (max-width:900px){ .sheet{width:auto;margin:8px;padding:10px;overflow-x:auto;} .sheet>*{min-width:<?= $land ? 860 : 640 ?>px;} .flash{margin:8px;} }
</style>
</head>
<body>
<div class="bar">
  <a href="index.php">← तक्ते</a>
  <?php if (purchaseCmpCanEdit() && $c['status'] !== 'cancelled'): ?><a href="chart.php?id=<?= $id ?>">✎ बदला</a><?php endif; ?>
  <?php if (purchaseCmpCanApprove() && in_array($c['status'], ['draft', 'final'], true)): ?><button type="button" class="ok" onclick="document.getElementById('apr').classList.add('on')">✅ पुरवठादार निवड — मान्यता</button><?php endif; ?>
  <?php if (purchaseCmpCanApprove() && $c['status'] === 'approved'): ?><form method="POST" style="margin:0;" onsubmit="return confirm('मान्यता काढायची?');"><input type="hidden" name="action" value="withdraw_approval"><button type="submit" class="warn">↺ मान्यता काढा</button></form><?php endif; ?>
  <?php if ($req): ?><a href="../item.php?id=<?= (int)$req['id'] ?>">🛒 मागणी अ.क्र. <?= (int)$req['sr_no'] ?></a><?php endif; ?>
  <a href="?id=<?= $id ?>&o=<?= $land ? 'p' : 'l' ?>"><?= $land ? '▯ उभा' : '▭ आडवा' ?> कागद</a>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</div>
<?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

<div class="sheet">
  <div class="hd"><?php foreach (array_filter(explode("\n", $head)) as $ln): ?><div><?= $h($ln) ?></div><?php endforeach; ?><div class="ti">तुलनात्मक तक्ता (Comparison Chart)</div></div>
  <div class="meta">
    <span>क्र. <b><?= (int)$c['cmp_no'] ?>/<?= $h(purchaseFyLabel($c['fy'])) ?></b> &nbsp;·&nbsp; विषय: <b><?= $h($c['title']) ?></b><?= $c['department'] ? ' &nbsp;·&nbsp; विभाग: <b>' . $h($c['department']) . '</b>' : '' ?><?= $req ? ' &nbsp;·&nbsp; खरेदी मागणी अ.क्र. ' . (int)$req['sr_no'] : '' ?></span>
    <span>दिनांक : <b><?= $h(date('d.m.Y', strtotime($c['cmp_date']))) ?></b> <span class="noprint" style="margin-left:6px;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:700;color:<?= $ST[1] ?>;background:<?= $ST[2] ?>;"><?= $h($ST[0]) ?></span></span>
  </div>
  <table class="t">
    <thead>
      <tr><th rowspan="2" style="width:30px;">अ.क्र.</th><th rowspan="2">तपशील (Particular)</th><th rowspan="2" style="width:52px;">नग</th><th rowspan="2" style="width:44px;">Unit</th>
        <?php foreach ($V as $i => $v): ?><th colspan="2" class="<?= $i === $sel ? 'sel' : '' ?>"><?= $h($v['name']) ?><?= $i === $sel ? ' ✅' : '' ?></th><?php endforeach; ?></tr>
      <tr><?php foreach ($V as $i => $v): ?><th>दर (Rate)</th><th>रक्कम (Amount)</th><?php endforeach; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($c['items'] as $n => $it): $mi = $C['items'][$it['id']]; ?>
      <tr><td class="c"><?= $n + 1 ?></td><td><?= $h($it['particular']) ?></td><td class="n"><?= $h(purchaseCmpNum($it['qty'])) ?></td><td class="c"><?= $h($it['unit']) ?></td>
      <?php foreach ($V as $i => $v): $r = $c['rates'][$it['id']][$v['id']] ?? null; $isMin = $r !== null && $nV > 1 && in_array($v['id'], $mi['who'], true) && count($mi['who']) < $nV; ?>
        <?php if ($r === null): ?><td class="na">—</td><td class="na">—</td>
        <?php else: $amt = $c['amounts'][$it['id']][$v['id']] ?? $r * (float)$it['qty']; ?><td class="n <?= $isMin ? 'min' : '' ?>"><?= $h(purchaseCmpNum($r)) ?></td><td class="n <?= $isMin ? 'min' : '' ?>"><?= $h($M($amt)) ?></td><?php endif; ?>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
      <tr class="x"><td colspan="4" class="lb">एकूण (Total)</td><?php foreach ($V as $v): ?><td colspan="2" class="n"><b><?= $h($M($C['vendors'][$v['id']]['subtotal'])) ?></b></td><?php endforeach; ?></tr>
      <?php if ($anyExtra('gst') || $anyGstIncl || $gstLbl): ?><tr class="x"><td colspan="4" class="lb">GST<?= $gstLbl ? ' ' . $h($gstLbl) : '' ?></td><?php foreach ($V as $v): ?><td colspan="2" class="n"><?= (int)$v['gst_incl'] ? 'समाविष्ट (Incl.)' : $h($M($C['vendors'][$v['id']]['gst'])) ?></td><?php endforeach; ?></tr><?php endif; ?>
      <?php if ($anyExtra('transport') || $anyTrIncl): ?><tr class="x"><td colspan="4" class="lb">वाहतूक / Courier</td><?php foreach ($V as $v): ?><td colspan="2" class="n"><?= (int)$v['transport_incl'] ? 'समाविष्ट (Incl.)' : ($C['vendors'][$v['id']]['transport'] ? $h($M($C['vendors'][$v['id']]['transport'])) : '—') ?></td><?php endforeach; ?></tr><?php endif; ?>
      <?php if ($anyExtra('other')): ?><tr class="x"><td colspan="4" class="lb"><?= $h($otherLbl) ?></td><?php foreach ($V as $v): ?><td colspan="2" class="n"><?= $C['vendors'][$v['id']]['other'] ? $h($M($C['vendors'][$v['id']]['other'])) : '—' ?></td><?php endforeach; ?></tr><?php endif; ?>
      <?php if ($anyExtra('discount')): ?><tr class="x"><td colspan="4" class="lb">सूट / वजावट (Discount)</td><?php foreach ($V as $v): ?><td colspan="2" class="n"><?= $C['vendors'][$v['id']]['discount'] ? '- ' . $h($M($C['vendors'][$v['id']]['discount'])) : '—' ?></td><?php endforeach; ?></tr><?php endif; ?>
      <tr class="g"><td colspan="4" style="text-align:right;">एकूण रक्कम (Grand Total)</td><?php foreach ($V as $i => $v): ?><td colspan="2" class="n <?= $i === $l1i ? 'best' : '' ?> <?= $i === $sel ? 'sel' : '' ?>"><?= $h($M($C['vendors'][$v['id']]['total'])) ?></td><?php endforeach; ?></tr>
      <tr class="rk"><td colspan="4" style="text-align:right;">क्रम (Rank)</td><?php foreach ($V as $v): $cv = $C['vendors'][$v['id']]; ?><td colspan="2" class="<?= $cv['rank'] === 1 ? 'rk1' : 'rkn' ?>"><?= $cv['rank'] ? 'L' . $cv['rank'] : '—' ?><?= $cv['missing'] ? '<div class="vr">⚠ ' . $cv['missing'] . ' वस्तूंचे दर नाहीत</div>' : '' ?></td><?php endforeach; ?></tr>
      <?php if (array_filter($V, fn($v) => trim((string)$v['remarks']) !== '')): ?><tr class="x"><td colspan="4" class="lb">शेरा</td><?php foreach ($V as $v): ?><td colspan="2" style="font-size:10.5px;"><?= $h($v['remarks']) ?></td><?php endforeach; ?></tr><?php endif; ?>
    </tbody>
  </table>

  <?php
    $ins = [];
    if ($l1i !== null) {
        $l1 = $C['vendors'][$C['l1']];
        $ins[] = '<b>L1 (सर्वात कमी):</b> ' . $h($V[$l1i]['name']) . ' — <b>' . $h($M($l1['total'])) . '</b>';
        if ($C['l2'] !== null) $ins[] = 'L2 पेक्षा <b>' . $h($M($C['l2'] - $l1['total'])) . '</b> कमी' . ($C['l2'] > 0 ? ' (' . round(($C['l2'] - $l1['total']) * 100 / $C['l2'], 1) . '%)' : '');
        if ($C['max'] > $l1['total']) $ins[] = 'सर्वात महाग दरपत्रकापेक्षा <b>' . $h($M($C['max'] - $l1['total'])) . '</b> बचत';
    }
    if ($C['mix'] !== null && $nV > 1 && $l1i !== null && $C['mix'] < $C['vendors'][$C['l1']]['subtotal'] - 0.5) $ins[] = 'प्रत्येक वस्तू सर्वात कमी दराने घेतल्यास (GST/वाहतूक वगळून) एकूण <b>' . $h($M($C['mix'])) . '</b> — L1 पेक्षा ' . $h($M($C['vendors'][$C['l1']]['subtotal'] - $C['mix'])) . ' कमी';
    foreach ($V as $v) if ($C['vendors'][$v['id']]['missing']) $ins[] = $h($v['name']) . ' — ' . $C['vendors'][$v['id']]['missing'] . ' वस्तूंचे दर दिलेले नाहीत (अपूर्ण दरपत्रक)';
    $wins = []; foreach ($C['items'] as $mi) foreach ($mi['who'] as $w) $wins[$w] = ($wins[$w] ?? 0) + 1;
  ?>
  <div class="sum">
    <div class="box"><h4>📊 निष्कर्ष</h4><ul><?php foreach ($ins as $i): ?><li><?= $i ?></li><?php endforeach; ?></ul></div>
    <div class="box"><h4>🏷️ वस्तूनिहाय सर्वात कमी दर</h4><?php foreach ($V as $v): ?><div><?= $h($v['name']) ?>: <b><?= (int)($wins[$v['id']] ?? 0) ?></b> / <?= count($c['items']) ?> वस्तू</div><?php endforeach; ?>
      <div class="vr" style="margin-top:3px;">हिरवा रंग = त्या वस्तूचा सर्वात कमी दर</div></div>
  </div>
  <?php if (trim((string)$c['notes']) !== ''): ?><div class="box" style="margin-top:10px;"><b>टीप :</b> <?= nl2br($h($c['notes'])) ?></div><?php endif; ?>
  <?php if ($c['status'] === 'approved' && $sel !== null): ?>
    <div class="appr">✅ <b>निवडलेला पुरवठादार: <?= $h($V[$sel]['name']) ?></b> — <?= $h($M($C['vendors'][$V[$sel]['id']]['total'])) ?><?= $sel !== $l1i ? ' (L' . (int)$C['vendors'][$V[$sel]['id']]['rank'] . ')' : ' (L1)' ?>
      <?= $c['approval_note'] ? '<div>कारण: ' . $h($c['approval_note']) . '</div>' : '' ?>
      <div style="font-size:11.5px;color:#14532D;">Approved by: <b><?= $h($c['approved_name']) ?></b> · <?= $h(date('d.m.Y h:i A', strtotime($c['approved_at']))) ?></div></div>
  <?php endif; ?>
  <div class="signs"><?php foreach ($signs as $s): ?><div><?= $h($s) ?></div><?php endforeach; ?></div>
  <div class="foot">E-Admin — <?= date('d/m/Y h:i A') ?><?= $c['source'] ? ' · ' . $h($c['source']) : '' ?></div>

  <div class="noprint" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;font-size:12px;">
    <?php if (purchaseCmpCanEdit() && $c['status'] !== 'cancelled' && $c['status'] !== 'approved'): ?><form method="POST" onsubmit="return confirm('हा तक्ता रद्द करायचा?');"><input type="hidden" name="action" value="cancel_chart"><button type="submit" style="border:1px solid #FCA5A5;color:#B91C1C;background:#fff;border-radius:8px;padding:5px 10px;">✕ तक्ता रद्द</button></form><?php endif; ?>
    <?php if (purchaseCmpCanEdit() && $c['status'] === 'cancelled'): ?><form method="POST"><input type="hidden" name="action" value="reopen_chart"><button type="submit" style="border:1px solid #CBD5E1;background:#fff;border-radius:8px;padding:5px 10px;">↺ पुन्हा सुरू</button></form><?php endif; ?>
    <?php if (purchaseCmpCanEdit() && !(function_exists('recordLockActive') && recordLockActive())): ?><form method="POST" onsubmit="return confirm('हा तक्ता कायमचा हटवायचा?');"><input type="hidden" name="action" value="delete_chart"><button type="submit" style="border:0;background:none;color:#DC2626;">🗑️ हटवा</button></form><?php endif; ?>
  </div>
</div>

<?php if (purchaseCmpCanApprove()): ?>
<div class="mod" id="apr" onclick="if(event.target===this)this.classList.remove('on')">
  <form method="POST"><input type="hidden" name="action" value="approve_chart">
    <b style="font-size:15px;">✅ पुरवठादार निवड — मान्यता</b>
    <div style="margin-top:8px;">पुरवठादार</div>
    <select name="vendor"><?php foreach ($C['order'] as $vid): $i = $vIdx[$vid]; $cv = $C['vendors'][$vid]; ?><option value="<?= $i ?>" <?= $i === $l1i ? 'selected' : '' ?>><?= $cv['rank'] ? 'L' . $cv['rank'] . ' — ' : '' ?><?= $h($V[$i]['name']) ?> — <?= $h($M($cv['total'])) ?><?= $cv['missing'] ? ' (अपूर्ण)' : '' ?></option><?php endforeach; ?></select>
    <div style="margin-top:8px;">कारण / शेरा <span style="color:#64748B;">(L1 नसलेला निवडल्यास आवश्यक)</span></div>
    <textarea name="note" rows="3" maxlength="500" placeholder="उदा. आधीच्या मशीनचा पुरवठादार, गुणवत्ता, वेळेत पुरवठा…"></textarea>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;"><button type="button" onclick="document.getElementById('apr').classList.remove('on')" style="border:1px solid #CBD5E1;background:#fff;border-radius:8px;padding:6px 12px;">रद्द</button><button type="submit" style="background:#16A34A;color:#fff;border:0;border-radius:8px;padding:6px 14px;font-weight:700;">✓ मंजूर करा</button></div>
  </form>
</div>
<?php endif; ?>
</body>
</html>
