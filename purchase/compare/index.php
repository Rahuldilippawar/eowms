<?php
// 📊 तुलनात्मक तक्ते (Comparison Charts) — यादी, शोध, स्थिती, L1 पुरवठादार, बचत, नवीन / Excel आयात
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/purchase-compare.php';
require_once __DIR__ . '/../../includes/smart-insights.php';
if (!purchaseCmpCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">तुलनात्मक तक्ते पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$ready = purchaseCmpReady($pdo);
$canEdit = purchaseCmpCanEdit();
$S = purchaseCmpStatuses();

$f = ['fy' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : '', 'st' => isset($S[$_GET['st'] ?? '']) ? $_GET['st'] : '',
    'dept' => trim((string)($_GET['dept'] ?? '')), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = []; $fys = []; $depts = []; $all = [];
if ($ready) {
    $st = $pdo->prepare("SELECT DISTINCT fy FROM purchase_comparisons WHERE organization_id = ? ORDER BY fy DESC"); $st->execute([$orgId]); $fys = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->prepare("SELECT DISTINCT department FROM purchase_comparisons WHERE organization_id = ? AND department IS NOT NULL AND department <> '' ORDER BY department"); $st->execute([$orgId]); $depts = $st->fetchAll(PDO::FETCH_COLUMN);
    $w = "WHERE c.organization_id = ?"; $p = [$orgId];
    if ($f['fy'] !== '') { $w .= " AND c.fy = ?"; $p[] = $f['fy']; }
    if ($f['st'] !== '') { $w .= " AND c.status = ?"; $p[] = $f['st']; }
    if ($f['dept'] !== '') { $w .= " AND c.department = ?"; $p[] = $f['dept']; }
    if ($f['q'] !== '') {
        $w .= " AND (c.title LIKE ? OR c.notes LIKE ? OR EXISTS (SELECT 1 FROM purchase_comparison_vendors v WHERE v.comparison_id = c.id AND v.name LIKE ?) OR EXISTS (SELECT 1 FROM purchase_comparison_items i WHERE i.comparison_id = c.id AND i.particular LIKE ?))";
        $lk = '%' . $f['q'] . '%'; array_push($p, $lk, $lk, $lk, $lk);
    }
    $st = $pdo->prepare("SELECT c.id FROM purchase_comparisons c $w ORDER BY c.cmp_date DESC, c.id DESC LIMIT 300"); $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $c = purchaseCmpLoad($pdo, $orgId, $cid); $C = purchaseCmpCompute($c);
        $rows[] = ['c' => $c, 'C' => $C];
    }
}
// सारांश
$kp = ['n' => count($rows), 'appr' => 0, 'wait' => 0, 'value' => 0, 'save' => 0];
$wins = [];
foreach ($rows as $x) {
    $c = $x['c']; $C = $x['C'];
    if ($c['status'] === 'approved') $kp['appr']++;
    if ($c['status'] === 'final') $kp['wait']++;
    $pick = $c['selected_vendor'] !== null && isset($c['vendors'][(int)$c['selected_vendor']]) ? $c['vendors'][(int)$c['selected_vendor']]['id'] : $C['l1'];
    if ($pick !== null && $c['status'] !== 'cancelled') {
        $kp['value'] += $C['vendors'][$pick]['total'];
        $kp['save'] += max(0, $C['max'] - $C['vendors'][$pick]['total']);
        foreach ($c['vendors'] as $v) if ($v['id'] === $pick) { $wins[$v['name']] = ($wins[$v['name']] ?? 0) + 1; }
    }
}
arsort($wins);
$qs = fn(array $o = []) => 'index.php?' . http_build_query(array_filter(array_merge($f, $o), fn($v) => $v !== '' && $v !== null));

$pageTitle = 'तुलनात्मक तक्ते';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .pc-act{display:flex;gap:6px;flex-wrap:wrap;}
  .pc-act a{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .pc-act .pri{background:linear-gradient(90deg,#1D4ED8,#2563EB);color:#fff;border:0;}
  .pc-f{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 12px;}
  .pc-f select,.pc-f input{border:1px solid var(--line);border-radius:10px;padding:7px 10px;font-size:13px;background:var(--surface);color:var(--text);}
  .pc-f input{flex:1;min-width:180px;}
  .pc-card{display:grid;grid-template-columns:62px 1fr auto;gap:12px;align-items:center;border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:11px 14px;margin-bottom:9px;text-decoration:none;color:inherit;}
  .pc-card:hover{border-color:#2563EB;}
  .pc-no{text-align:center;font-weight:800;color:#1D4ED8;background:color-mix(in srgb,#2563EB 10%,var(--surface));border-radius:11px;padding:7px 4px;font-size:13px;line-height:1.15;} .pc-no small{display:block;font-size:10px;font-weight:600;color:var(--text-dim);}
  .pc-t{font-weight:700;font-size:14px;} .pc-m{font-size:12px;color:var(--text-dim);margin-top:2px;}
  .pc-vs{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px;} .pc-vs span{font-size:11.5px;border-radius:8px;padding:2px 8px;background:var(--canvas);} .pc-vs span.l1{background:#DCFCE7;color:#166534;font-weight:700;} .pc-vs span.sel{outline:2px solid #16A34A;}
  .pc-r{text-align:right;min-width:130px;} .pc-amt{font-size:16px;font-weight:800;} .pc-amt small{display:block;font-size:11px;font-weight:600;color:var(--text-dim);}
  .pc-chip{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;margin-top:4px;}
  @media (max-width:700px){ .pc-card{grid-template-columns:52px 1fr;} .pc-r{grid-column:1 / -1;text-align:left;display:flex;justify-content:space-between;align-items:center;} }
</style>

<div class="page-eyebrow">🛒 खरेदी विभाग</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
  <div class="page-title mb-0">📊 तुलनात्मक तक्ते (Comparison Chart)</div>
  <div class="pc-act">
    <?php if ($canEdit && $ready): ?><a class="pri" href="chart.php">＋ नवीन तक्ता</a><a href="import.php">📥 Excel आयात</a><?php endif; ?>
    <a href="../index.php">🛒 खरेदी बाकी</a>
  </div>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ तुलनात्मक तक्त्यांसाठी Database Update बाकी आहे — Super Admin ने System Update → Database Update चालवावं.</div><?php endif; ?>

<?php if ($ready):
  smartTiles([
    ['label' => '📊 तक्ते', 'value' => $kp['n'], 'sub' => $kp['appr'] . ' मंजूर · ' . $kp['wait'] . ' मान्यतेसाठी'],
    ['label' => '⏳ मान्यतेसाठी', 'value' => $kp['wait'], 'sub' => 'पुरवठादार निवड बाकी', 'tone' => $kp['wait'] ? 'warn' : '', 'href' => $qs(['st' => 'final'])],
    ['label' => '💰 निवडलेली / L1 रक्कम', 'value' => purchaseInr($kp['value']), 'sub' => 'या यादीतील तक्ते'],
    ['label' => '📉 बचत (सर्वात महाग दरपत्रकाच्या तुलनेत)', 'value' => purchaseInr($kp['save']), 'sub' => $kp['value'] > 0 ? round($kp['save'] * 100 / ($kp['value'] + $kp['save'])) . '% कमी' : '', 'tone' => 'ok'],
    ['label' => '🏆 सर्वाधिक निवड', 'value' => $wins ? mb_strimwidth(array_key_first($wins), 0, 22, '…') : '—', 'sub' => $wins ? reset($wins) . ' तक्त्यांत L1 / निवड' : ''],
  ]);
?>
<form class="pc-f" method="get">
  <select name="fy" onchange="this.form.submit()"><option value="">सर्व आर्थिक वर्षे</option><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $f['fy'] === $y ? 'selected' : '' ?>>आ.व. <?= h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select>
  <select name="st" onchange="this.form.submit()"><option value="">सर्व स्थिती</option><?php foreach ($S as $k => $s): ?><option value="<?= $k ?>" <?= $f['st'] === $k ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?></select>
  <?php if ($depts): ?><select name="dept" onchange="this.form.submit()"><option value="">सर्व विभाग</option><?php foreach ($depts as $d): ?><option <?= $f['dept'] === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select><?php endif; ?>
  <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="वस्तू, पुरवठादार, विषय शोधा…">
</form>

<?php if (!$rows): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून एकही तुलनात्मक तक्ता नाही.<?php if ($canEdit): ?> <a href="chart.php">नवीन तक्ता बनवा</a> किंवा <a href="import.php">जुनी Excel आयात करा</a>.<?php endif; ?></div>
<?php endif; ?>
<?php foreach ($rows as $x): $c = $x['c']; $C = $x['C']; $st = $S[$c['status']] ?? $S['draft'];
  $selId = $c['selected_vendor'] !== null && isset($c['vendors'][(int)$c['selected_vendor']]) ? $c['vendors'][(int)$c['selected_vendor']]['id'] : null;
  $showId = $selId ?? $C['l1']; ?>
  <a class="pc-card" href="view.php?id=<?= (int)$c['id'] ?>">
    <div class="pc-no">#<?= (int)$c['cmp_no'] ?><small><?= h(purchaseFyLabel($c['fy'])) ?></small></div>
    <div>
      <div class="pc-t"><?= h($c['title']) ?></div>
      <div class="pc-m">📅 <?= h(date('d.m.Y', strtotime($c['cmp_date']))) ?> · <?= count($c['items']) ?> वस्तू · <?= count($c['vendors']) ?> पुरवठादार<?= $c['department'] ? ' · ' . h($c['department']) : '' ?></div>
      <div class="pc-vs"><?php foreach ($C['order'] as $vid): $v = current(array_filter($c['vendors'], fn($z) => $z['id'] === $vid)); $cv = $C['vendors'][$vid]; ?>
        <span class="<?= $cv['rank'] === 1 ? 'l1' : '' ?> <?= $vid === $selId ? 'sel' : '' ?>"><?= $cv['rank'] ? 'L' . $cv['rank'] . ' ' : '' ?><?= h(mb_strimwidth($v['name'], 0, 24, '…')) ?> · <?= h(purchaseCmpMoney($cv['total'])) ?><?= $cv['missing'] ? ' ⚠' : '' ?></span>
      <?php endforeach; ?></div>
    </div>
    <div class="pc-r">
      <div class="pc-amt"><?= $showId !== null ? h(purchaseCmpMoney($C['vendors'][$showId]['total'])) : '—' ?><small><?= $selId !== null ? '✅ निवड' : 'L1' ?></small></div>
      <span class="pc-chip" style="color:<?= $st[1] ?>;background:<?= $st[2] ?>;"><?= h($st[0]) ?></span>
    </div>
  </a>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
