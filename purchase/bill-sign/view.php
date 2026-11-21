<?php
// ✍️ बिल परवानगी पत्रक — एक पत्रक: बिलनिहाय परवानगी (✅ / ⏸ / ✖), सर्व बिलांना Done, लेखा विभागास जमा, थांबलेली बिलं पुढच्या पत्रकात, इतिहास
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/bill-sign.php';
if (!bsCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बिल परवानगी पत्रक पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!bsReady($pdo)) { header('Location: index.php'); exit; }
$s = bsLoad($pdo, $orgId, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$s) { header('Location: index.php'); exit; }
$can = bsCan(); $canDecide = bsCanDecide();
$ST = bsStatuses(); $IS = bsItemStatuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_decisions' && $canDecide && in_array($s['status'], ['submitted', 'partial', 'approved'], true)) {
    $dec = (array)($_POST['dec'] ?? []); $notes = (array)($_POST['dnote'] ?? []);
    $now = date('Y-m-d H:i:s'); $today = date('d.m.Y'); $chg = 0; $cnt = ['approved' => 0, 'hold' => 0, 'rejected' => 0, 'pending' => 0];
    $up = $pdo->prepare("UPDATE bill_sign_items SET status = ?, decision_note = ?, decided_at = ?, decided_by = ?, return_date = ? WHERE sheet_id = ? AND organization_id = ? AND sr = ?");
    foreach (bsGroups($s['_items']) as $g) {
        $sr = (int)$g[0]['sr']; $new = (string)($dec[$sr] ?? $g[0]['status']); if (!isset($IS[$new])) continue;
        $note = mb_substr(trim((string)($notes[$sr] ?? '')), 0, 250);
        $keepNote = strpos((string)$g[0]['decision_note'], '↪') === 0 ? $g[0]['decision_note'] : null;
        $cnt[$new]++;
        $tNote = $keepNote ?: ($note !== '' ? $note : null);
        if ($new === $g[0]['status'] && $tNote === $g[0]['decision_note']) continue;
        $ret = $new === 'approved' ? ($g[0]['return_date'] ?: $today) : ($new === 'pending' ? null : $g[0]['return_date']);
        $up->execute([$new, $tNote, $new === 'pending' ? null : $now, $new === 'pending' ? null : $user['id'], $ret, $s['id'], $orgId, $sr]);
        $chg++;
    }
    $res = bsRecalc($pdo, $orgId, $s['id'], (int)$user['id']);
    if ($chg) {
        bsLog($pdo, $orgId, $s['id'], 'decided', 'बिलनिहाय परवानगी: ✅ ' . $cnt['approved'] . ' · ⏸ ' . $cnt['hold'] . ' · ✖ ' . $cnt['rejected'] . ' · ⏳ ' . $cnt['pending'], (int)$user['id']);
        logActivity('बिल परवानगी पत्रक — बिलनिहाय परवानगी', 'bill_sign_sheet', $s['id'], 'Sheet ' . bsSheetLabel($s) . ': ' . ($ST[$res][0] ?? $res));
    }
    $_SESSION['flash'] = $chg ? '✓ Sheet No. ' . bsSheetLabel($s) . ' — ' . ($ST[$res][0] ?? $res) . ' (✅ ' . $cnt['approved'] . ' · ⏸ ' . $cnt['hold'] . ' · ✖ ' . $cnt['rejected'] . ')' : 'कोणताही बदल नाही.';
    header('Location: view.php?id=' . (int)$s['id']); exit;
}

$groups = bsGroups($s['_items']);
$amt = ['approved' => 0, 'hold' => 0, 'rejected' => 0, 'pending' => 0]; $n = $amt;
foreach ($groups as $g) { $k = bsGroupStatus($g); $amt[$k] += bsGroupAmount($g); $n[$k]++; }
$carryable = array_filter($groups, fn($g) => in_array(bsGroupStatus($g), ['hold', 'rejected'], true) && strpos((string)$g[0]['decision_note'], '↪') !== 0);
$st = $pdo->prepare("SELECT e.*, u.name FROM bill_sign_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.sheet_id = ? ORDER BY e.id DESC LIMIT 40"); $st->execute([$s['id']]); $events = $st->fetchAll();
$hasPaid = (bool)array_filter($s['_items'], fn($r) => $r['paid_by']);
$hasMat = (bool)array_filter($s['_items'], fn($r) => $r['material_date']);
$decidable = $canDecide && in_array($s['status'], ['submitted', 'partial', 'approved'], true);
$stForm = function ($op, $label, $cls, $confirm, $withNote = false) use ($s) {
    return '<form method="POST" action="index.php" class="m-0 d-inline-flex gap-1" onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ');"><input type="hidden" name="action" value="set_bs_status"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><input type="hidden" name="op" value="' . h($op) . '"><input type="hidden" name="back" value="view.php?id=' . (int)$s['id'] . '">'
        . ($withNote ? '<input name="note" class="form-control-custom" style="width:170px;font-size:12.5px;padding:6px 8px;" placeholder="' . h($withNote) . '">' : '') . '<button type="submit" class="' . h($cls) . '">' . h($label) . '</button></form>';
};
$steps = [['📝', 'तयार', $s['created_at']], ['📤', 'सहीसाठी जमा', $s['submitted_at']], ['✅', 'परवानगी', $s['approved_at']], ['🏦', 'लेखा विभाग', $s['accounts_at']]];

$pageTitle = 'Sheet No. ' . bsSheetLabel($s) . ' — बिल परवानगी पत्रक';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .bv-head{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;border-radius:18px;padding:16px 18px;background:linear-gradient(120deg,#312E81,#4338CA 60%,#6366F1);color:#fff;margin-bottom:12px;}
  .bv-head h1{font-size:21px;font-weight:900;margin:0;color:#fff;} .bv-head .sub{font-size:12.5px;opacity:.9;margin-top:3px;} .bv-head .amt{font-size:24px;font-weight:900;text-align:right;} .bv-head .amt small{display:block;font-size:11.5px;font-weight:600;opacity:.85;}
  .bv-acts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;align-items:center;} .bv-acts a,.bv-acts button{font-size:12.5px;border-radius:10px;padding:7px 12px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:700;white-space:nowrap;}
  .bv-acts .done{background:#16A34A;border-color:#16A34A;color:#fff;} .bv-acts .sb{background:#F59E0B;border-color:#F59E0B;color:#fff;} .bv-acts .ac{background:#4338CA;border-color:#4338CA;color:#fff;} .bv-acts .rp{color:#B45309;} .bv-acts .dl{color:#B91C1C;}
  .bv-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin-bottom:12px;border:1px solid var(--line);border-radius:14px;background:var(--surface);overflow:hidden;}
  .bv-steps div{padding:9px 10px;text-align:center;font-size:12px;font-weight:700;color:var(--text-dim);border-right:1px solid var(--line);position:relative;} .bv-steps div:last-child{border-right:0;}
  .bv-steps div.on{color:#166534;background:color-mix(in srgb,#16A34A 8%,var(--surface));} .bv-steps b{display:block;font-size:17px;} .bv-steps small{display:block;font-weight:600;font-size:10.5px;}
  .bv-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;} .bv-sum div{border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:8px 12px;font-size:12px;font-weight:700;color:var(--text-dim);} .bv-sum b{display:block;font-size:16px;color:var(--text);font-variant-numeric:tabular-nums;}
  .bv-sum .ok b{color:#16A34A;} .bv-sum .hd b{color:#0369A1;} .bv-sum .rj b{color:#B91C1C;} .bv-sum .pn b{color:#B45309;}
  .bv-tbl{border:1px solid var(--line);border-radius:14px;background:var(--surface);overflow:auto;} .bv-tbl table{width:100%;border-collapse:collapse;font-size:12.5px;}
  .bv-tbl th{background:var(--canvas);padding:8px 9px;font-size:11.5px;text-align:left;white-space:nowrap;position:sticky;top:0;} .bv-tbl td{padding:7px 9px;border-top:1px solid var(--line);vertical-align:top;}
  .bv-tbl tr.sub td{border-top:1px dashed var(--line);} .bv-tbl tr.sub td.sr{color:transparent;} .bv-tbl td.n{text-align:right;white-space:nowrap;font-weight:800;font-variant-numeric:tabular-nums;} .bv-tbl td.sr{font-weight:900;color:#4338CA;text-align:center;}
  .bv-tbl tr.g-approved td:first-child{box-shadow:inset 4px 0 #16A34A;} .bv-tbl tr.g-hold td:first-child{box-shadow:inset 4px 0 #0EA5E9;} .bv-tbl tr.g-rejected td:first-child{box-shadow:inset 4px 0 #DC2626;} .bv-tbl tr.g-rejected td{opacity:.75;}
  .bv-tbl tfoot td{font-weight:900;background:var(--canvas);border-top:2px solid var(--line);}
  .bv-dec{display:flex;gap:3px;flex-wrap:nowrap;} .bv-dec label{cursor:pointer;margin:0;} .bv-dec input{position:absolute;opacity:0;pointer-events:none;}
  .bv-dec span{display:inline-block;border:1px solid var(--line);border-radius:8px;padding:3px 7px;font-size:11.5px;font-weight:800;background:var(--surface);white-space:nowrap;}
  .bv-dec input:checked + span.ok{background:#16A34A;border-color:#16A34A;color:#fff;} .bv-dec input:checked + span.hd{background:#0EA5E9;border-color:#0EA5E9;color:#fff;} .bv-dec input:checked + span.rj{background:#DC2626;border-color:#DC2626;color:#fff;} .bv-dec input:checked + span.pn{background:#F59E0B;border-color:#F59E0B;color:#fff;}
  .bv-dn{width:100%;min-width:120px;border:1px solid var(--line);border-radius:7px;padding:3px 6px;font-size:11.5px;margin-top:4px;background:var(--canvas);color:var(--text);}
  .bs-chip{display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:99px;white-space:nowrap;} .it-pn{background:#FEF3C7;color:#92400E;} .it-ok{background:#DCFCE7;color:#166534;} .it-hd{background:#E0F2FE;color:#075985;} .it-rj{background:#FEE2E2;color:#991B1B;}
  .st-dr{background:#E5E7EB;color:#374151;} .st-sb{background:#FEF3C7;color:#92400E;} .st-pt{background:#E0F2FE;color:#075985;} .st-ok{background:#DCFCE7;color:#166534;} .st-ac{background:#E0E7FF;color:#3730A3;}
  .bv-bar{position:sticky;bottom:0;z-index:20;display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap;border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:10px 14px;margin-top:10px;box-shadow:0 -10px 30px -20px rgba(15,23,42,.5);}
  .bv-bar button{border-radius:10px;font-weight:800;padding:9px 16px;border:1px solid var(--line);background:var(--surface);color:var(--text);font-size:13px;} .bv-bar .sv{background:#4338CA;border-color:#4338CA;color:#fff;} .bv-bar .all{border-color:#16A34A;color:#166534;}
  .bv-ev{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:10px 14px;margin-top:14px;font-size:12.5px;} .bv-ev div{padding:4px 0;border-bottom:1px dashed var(--line);} .bv-ev div:last-child{border-bottom:0;} .bv-ev small{color:var(--text-dim);}
  @media (max-width:760px){ .bv-steps,.bv-sum{grid-template-columns:repeat(2,1fr);} .bv-steps div:nth-child(2){border-right:0;} .bv-steps div:nth-child(-n+2){border-bottom:1px solid var(--line);} .bv-head .amt{text-align:left;} .bv-bar{bottom:58px;} }
</style>

<div class="bv-head">
  <div><div style="font-size:12px;opacity:.85;font-weight:700;"><a href="index.php" style="color:#fff;">✍️ बिल परवानगी पत्रक</a> · आ.व. <?= h(purchaseFyLabel($s['fy'])) ?></div>
    <h1>Sheet No. <?= h(bsSheetLabel($s)) ?> · <?= h(purchaseDate($s['sheet_date'])) ?></h1>
    <div class="sub"><span class="bs-chip <?= $ST[$s['status']][1] ?? '' ?>"><?= h($ST[$s['status']][0] ?? '') ?></span> <?= $s['submitted_to'] ? '· ✍️ ' . h($s['submitted_to']) : '' ?> <?= $s['note'] ? '· 📝 ' . h($s['note']) : '' ?></div></div>
  <div class="amt">₹<?= h(woMoney($s['total_amount'])) ?><small><?= count($groups) ?> बिलं · <?= count($s['_items']) ?> ओळी</small></div>
</div>

<div class="bv-steps">
  <?php foreach ($steps as $i => [$ic, $lb, $at]): ?><div class="<?= $at ? 'on' : '' ?>"><b><?= $at ? $ic : '○' ?></b><?= h($lb) ?><small><?= $at ? h(date('d.m.Y', strtotime($at))) : '—' ?></small></div><?php endforeach; ?>
</div>

<div class="bv-acts">
  <a href="index.php">← यादी</a>
  <a href="print.php?id=<?= (int)$s['id'] ?>" target="_blank" style="border-color:#4338CA;color:#4338CA;">🖨️ Print — सहीसाठी</a>
  <?php if (in_array($s['status'], ['approved', 'partial', 'accounts'], true)): ?><a href="print.php?id=<?= (int)$s['id'] ?>&amp;copy=acc" target="_blank">🏦 Print — लेखा विभाग</a><?php endif; ?>
  <?php if ($can && in_array($s['status'], ['draft', 'submitted'], true)): ?><a href="sheet.php?id=<?= (int)$s['id'] ?>">✎ बदला</a><?php endif; ?>
  <?php if ($can && $s['status'] === 'draft'): ?><?= $stForm('submit', '📤 सहीसाठी जमा', 'sb', 'Sheet No. ' . bsSheetLabel($s) . ' सहीसाठी जमा केले?') ?><?php endif; ?>
  <?php if ($canDecide && in_array($s['status'], ['submitted', 'partial'], true)): ?><?= $stForm('approve_all', '✅ सर्व बिलांना परवानगी — Done', 'done', 'Sheet No. ' . bsSheetLabel($s) . ' — बाकी सर्व बिलांना परवानगी झाली (Done)?') ?><?php endif; ?>
  <?php if ($can && in_array($s['status'], ['approved', 'partial'], true)): ?><?= $stForm('accounts', '🏦 लेखा विभागास जमा', 'ac', 'परवानगी झालेली बिलं लेखा विभागास जमा केली?', 'टीप (ऐच्छिक)') ?><?php endif; ?>
  <?php if ($can && $carryable): ?><a href="sheet.php?carry=<?= (int)$s['id'] ?>" style="border-color:#0EA5E9;color:#0369A1;">↪ थांबलेली <?= count($carryable) ?> बिलं नवीन पत्रकात</a><?php endif; ?>
  <?php if ($can && $s['status'] !== 'draft'): ?><?= $stForm('reopen', '↩ ' . ($s['status'] === 'accounts' ? 'लेखा जमा रद्द' : 'पुन्हा तयार'), 'rp', $s['status'] === 'accounts' ? 'लेखा विभागास जमा — रद्द करायचे?' : 'पत्रक पुन्हा "तयार" करायचे? सर्व बिलांचे निर्णय पुसले जातील.') ?><?php endif; ?>
  <?php if ($can && $s['status'] === 'draft'): ?><form method="POST" action="index.php" class="m-0" onsubmit="return confirm('हे तयार होत असलेले पत्रक काढायचे?');"><input type="hidden" name="action" value="drop_bs_sheet"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="dl">🗑 काढा</button></form><?php endif; ?>
</div>

<?php if ($s['status'] !== 'draft'): ?>
<div class="bv-sum">
  <div class="ok">✅ परवानगी<b><?= $n['approved'] ?> · <?= h(purchaseInr($amt['approved'])) ?></b></div>
  <div class="pn">⏳ बाकी<b><?= $n['pending'] ?> · <?= h(purchaseInr($amt['pending'])) ?></b></div>
  <div class="hd">⏸ थांबवले<b><?= $n['hold'] ?> · <?= h(purchaseInr($amt['hold'])) ?></b></div>
  <div class="rj">✖ नाकारले<b><?= $n['rejected'] ?> · <?= h(purchaseInr($amt['rejected'])) ?></b></div>
</div>
<?php endif; ?>

<form method="POST" data-no-smart-filter>
<input type="hidden" name="action" value="save_decisions"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
<div class="bv-tbl"><table>
  <thead><tr><th>Sr.</th><th>PO/Bill</th><th>टिप्पणी</th><th>पार्टी</th><th>तपशील</th><?php if ($hasPaid): ?><th>बिल भरणारा</th><?php endif; ?><th>बिल क्र.</th><th>बिल दि.</th><th style="text-align:right;">रक्कम</th><th>आवक दि.</th><?php if ($hasMat): ?><th>माल मिळाला</th><?php endif; ?><th>परत दि.</th><th>शेरा</th><th><?= $decidable ? 'परवानगी' : 'स्थिती' ?></th></tr></thead>
  <tbody>
  <?php foreach ($groups as $g): $gs = bsGroupStatus($g); $sr = (int)$g[0]['sr']; foreach ($g as $j => $r): ?>
    <tr class="g-<?= h($gs) ?><?= $j ? ' sub' : '' ?>">
      <td class="sr"><?= $sr ?></td><td><?= h($r['doc_type']) ?></td><td><?= h($r['tippani_no']) ?></td><td><b><?= h($r['party']) ?></b></td><td><?= h($r['particular']) ?></td>
      <?php if ($hasPaid): ?><td><?= h($r['paid_by']) ?></td><?php endif; ?>
      <td><?= h($r['bill_no']) ?></td><td style="white-space:nowrap;"><?= h($r['bill_date']) ?></td><td class="n"><?= $r['amount'] !== null ? h(woMoney($r['amount'])) : '' ?></td><td style="white-space:nowrap;"><?= h($r['inward_date']) ?></td>
      <?php if ($hasMat): ?><td style="white-space:nowrap;"><?= h($r['material_date']) ?></td><?php endif; ?>
      <td style="white-space:nowrap;"><?= h($r['return_date']) ?></td><td><?= h($r['remark']) ?></td>
      <td><?php if ($j === 0): ?>
        <?php if ($decidable): ?>
          <div class="bv-dec"><?php foreach (['approved' => ['✅', 'ok'], 'hold' => ['⏸', 'hd'], 'rejected' => ['✖', 'rj'], 'pending' => ['⏳', 'pn']] as $k => [$ic, $c]): ?><label title="<?= h($IS[$k][0]) ?>"><input type="radio" name="dec[<?= $sr ?>]" value="<?= $k ?>" <?= $gs === $k ? 'checked' : '' ?>><span class="<?= $c ?>"><?= $ic ?></span></label><?php endforeach; ?></div>
          <?php $dn = (string)$g[0]['decision_note']; if (strpos($dn, '↪') === 0): ?><div style="font-size:11px;color:#0369A1;font-weight:700;margin-top:3px;"><?= h($dn) ?></div><?php else: ?><input class="bv-dn" name="dnote[<?= $sr ?>]" value="<?= h($dn) ?>" placeholder="शेरा / कारण"><?php endif; ?>
        <?php else: ?><span class="bs-chip <?= $IS[$gs][1] ?? '' ?>"><?= h($IS[$gs][0] ?? '') ?></span><?= $g[0]['decision_note'] ? '<div style="font-size:11px;color:var(--text-dim);margin-top:3px;">' . h($g[0]['decision_note']) . '</div>' : '' ?><?php endif; ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="<?= 7 + (int)$hasPaid ?>" style="text-align:right;">TOTAL</td><td class="n" style="text-align:right;">₹<?= h(woMoney($s['total_amount'])) ?></td><td colspan="<?= 4 + (int)$hasMat ?>" style="font-weight:600;font-size:11.5px;">(In Words: <?= h(woWords($s['total_amount'])) ?>)</td></tr></tfoot>
</table></div>
<?php if ($decidable): ?>
<div class="bv-bar">
  <div style="font-size:12.5px;color:var(--text-dim);font-weight:600;">✅ परवानगी · ⏸ थांबवले · ✖ नाकारले · ⏳ बाकी — बिलनिहाय निवडा, परवानगी झालेल्या बिलांचा "परत दिनांक" आपोआप आजचा.</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="all" onclick="document.querySelectorAll('.bv-dec input[value=approved]').forEach(i=>i.checked=true);">✅ सर्व निवडा</button><button type="submit" class="sv">✓ निर्णय जतन करा</button></div>
</div>
<?php endif; ?>
</form>

<?php if ($events): ?>
<div class="bv-ev"><div style="font-weight:800;border:0;">🕑 इतिहास</div>
  <?php foreach ($events as $e): ?><div><?= h($e['note'] ?: $e['event']) ?> <small>— <?= h($e['name'] ?: '—') ?> · <?= h(date('d.m.Y h:i A', strtotime($e['created_at']))) ?></small></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
