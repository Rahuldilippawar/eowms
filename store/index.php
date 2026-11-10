<?php
// 📊 भांडार Dashboard — शिल्लक मूल्य, कमी साठा, खरेदी/खप कल, विभागनिहाय वाटप
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();
$ready = storeStockReady($pdo);
$types = storeMoveTypes();

$itemsStmt = $pdo->prepare("SELECT id, item_name, unit, category, reorder_level FROM store_items WHERE organization_id = ? AND is_active = 1");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();
$bal = storeBalances($pdo, $orgId);

$value = 0; $low = []; $out = 0; $tracked = 0; $catVal = [];
foreach ($items as $it) {
    $b = $bal[(int)$it['id']] ?? null;
    if (!$b) continue;
    $tracked++;
    $value += $b['value'];
    $c = trim((string)$it['category']) ?: 'इतर';
    $catVal[$c] = ($catVal[$c] ?? 0) + $b['value'];
    if ($b['bal'] <= 0) { $out++; $low[] = $it + ['bal' => $b['bal']]; }
    elseif ((float)$it['reorder_level'] > 0 && $b['bal'] <= (float)$it['reorder_level']) $low[] = $it + ['bal' => $b['bal']];
}
usort($low, fn($a, $b) => ($a['bal'] <=> $b['bal']));
arsort($catVal);

$month = ['buy' => 0, 'buyN' => 0, 'issue' => 0, 'work' => 0];
$trend = []; $deptUse = []; $topUse = []; $recent = [];
if ($ready) {
    $m = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN move_type = 'purchase' THEN quantity * COALESCE(rate, 0) END), 0) buy,
            COUNT(DISTINCT CASE WHEN move_type = 'purchase' THEN CONCAT(move_date, '|', COALESCE(vendor_id, 0), '|', COALESCE(bill_no, '')) END) buyN,
            SUM(move_type = 'issue') issue, SUM(move_type = 'work') work
        FROM store_stock_moves WHERE organization_id = ? AND move_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $m->execute([$orgId]);
    $month = $m->fetch() ?: $month;

    // मागचे 6 महिने — खरेदी (₹) व खपाचं मूल्य (₹, शेवटच्या दराने)
    $t = $pdo->prepare("SELECT DATE_FORMAT(m.move_date, '%Y-%m') ym,
            SUM(CASE WHEN m.move_type = 'purchase' THEN m.quantity * COALESCE(m.rate, 0) ELSE 0 END) buy,
            SUM(CASE WHEN m.move_type IN ('issue','work') THEN -m.quantity ELSE 0 END * COALESCE((SELECT m2.rate FROM store_stock_moves m2 WHERE m2.item_id = m.item_id AND m2.rate > 0 ORDER BY m2.move_date DESC, m2.id DESC LIMIT 1), 0)) used
        FROM store_stock_moves m WHERE m.organization_id = ? AND m.move_date >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
        GROUP BY ym ORDER BY ym");
    $t->execute([$orgId]);
    foreach ($t->fetchAll() as $r) $trend[$r['ym']] = $r;

    $d = $pdo->prepare("SELECT COALESCE(d.name_local, d.name, 'इतर / व्यक्ती') dn, COUNT(*) n,
            SUM(-m.quantity * COALESCE((SELECT m2.rate FROM store_stock_moves m2 WHERE m2.item_id = m.item_id AND m2.rate > 0 ORDER BY m2.move_date DESC, m2.id DESC LIMIT 1), 0)) val
        FROM store_stock_moves m LEFT JOIN departments d ON d.id = m.department_id
        WHERE m.organization_id = ? AND m.move_type IN ('issue','work') AND m.move_date >= CURDATE() - INTERVAL 89 DAY
        GROUP BY dn ORDER BY val DESC, n DESC LIMIT 8");
    $d->execute([$orgId]);
    $deptUse = $d->fetchAll();

    $u = $pdo->prepare("SELECT si.item_name, si.unit, -SUM(m.quantity) q, COUNT(*) n FROM store_stock_moves m JOIN store_items si ON si.id = m.item_id
        WHERE m.organization_id = ? AND m.move_type IN ('issue','work') AND m.move_date >= CURDATE() - INTERVAL 29 DAY GROUP BY m.item_id ORDER BY n DESC, q DESC LIMIT 6");
    $u->execute([$orgId]);
    $topUse = $u->fetchAll();

    $r = $pdo->prepare("SELECT m.*, si.item_name, si.unit, v.name vendor_name, d.name_local dept_name FROM store_stock_moves m JOIN store_items si ON si.id = m.item_id
        LEFT JOIN store_vendors v ON v.id = m.vendor_id LEFT JOIN departments d ON d.id = m.department_id
        WHERE m.organization_id = ? ORDER BY m.id DESC LIMIT 8");
    $r->execute([$orgId]);
    $recent = $r->fetchAll();
}
$labels = []; $buy = []; $used = [];
$mr = ['', 'जाने', 'फेब्रु', 'मार्च', 'एप्रि', 'मे', 'जून', 'जुलै', 'ऑग', 'सप्टें', 'ऑक्टो', 'नोव्हें', 'डिसें'];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
    $labels[] = $mr[(int)substr($ym, 5)] . ' ' . substr($ym, 2, 2);
    $buy[] = round((float)($trend[$ym]['buy'] ?? 0));
    $used[] = round((float)($trend[$ym]['used'] ?? 0));
}

// 🧠 एका ओळीत निष्कर्ष
$insight = [];
if ($low) $insight[] = '🔴 ' . count($low) . ' वस्तू कमी/संपल्या' . ($out ? " ($out पूर्ण संपल्या)" : '') . ' — आधी "' . $low[0]['item_name'] . '"';
if ($tracked < count($items)) $insight[] = '⚪ ' . (count($items) - $tracked) . ' वस्तूंची सुरुवातीची शिल्लक अजून नोंदवलेली नाही';
if ($deptUse && $deptUse[0]['val'] > 0) $insight[] = '🏢 सर्वाधिक वाटप: ' . $deptUse[0]['dn'] . ' (₹' . number_format($deptUse[0]['val']) . ', 90 दिवस)';

$pageTitle = 'भांडार Dashboard';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-0">🏬 भांडार (Store)</div>
  <div class="d-flex gap-2"><a href="move.php?type=purchase" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">＋ आवक</a><a href="move.php?type=issue" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">－ जावक</a></div>
</div>
<?= storeNav('index') ?>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;">Stock (आवक/जावक, Vendor) सुरू करण्यासाठी Super Admin ने <b>System Update → Database Update</b> एकदा चालवा. तोपर्यंत <a href="items.php">वस्तू यादी</a> पूर्वीसारखी वापरता येईल.</div><?php endif; ?>

<?php if ($insight): ?><div class="st-insight"><?php foreach ($insight as $s): ?><span><?= h($s) ?></span><?php endforeach; ?></div><?php endif; ?>

<div class="hi-totals mb-3">
  <div><span>शिल्लक मूल्य</span><b>₹<?= number_format($value) ?></b><small><?= $tracked ?> / <?= count($items) ?> वस्तूंची नोंद</small></div>
  <div><span>कमी / संपलेल्या</span><b class="<?= $low ? 'c-amber' : 'c-ok' ?>"><?= count($low) ?></b><small><?= $out ?> पूर्ण संपल्या</small></div>
  <div><span>या महिन्यात खरेदी</span><b class="c-ok">₹<?= number_format((float)$month['buy']) ?></b><small><?= (int)$month['buyN'] ?> बिलं</small></div>
  <div><span>या महिन्यात जावक</span><b><?= (int)$month['issue'] + (int)$month['work'] ?></b><small><?= (int)$month['issue'] ?> वाटप · <?= (int)$month['work'] ?> कामात</small></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7"><div class="panel h-100">
    <div class="panel-head"><div class="title">📈 खरेदी व खप — मागचे 6 महिने</div><div class="sub">₹ मध्ये · खप शेवटच्या खरेदी दराने</div></div>
    <div style="padding:12px 18px;height:260px;"><canvas id="stTrend"></canvas></div>
  </div></div>
  <div class="col-lg-5"><div class="panel h-100">
    <div class="panel-head"><div class="title">🔴 कमी / संपलेला साठा</div><div class="sub"><a href="stock.php?f=low">सगळे पहा</a></div></div>
    <div style="padding:8px 18px;max-height:260px;overflow:auto;">
      <?php if (!$low): ?><div class="sm-empty">✅ सगळ्या वस्तू पुरेशा आहेत</div><?php endif; ?>
      <?php foreach (array_slice($low, 0, 10) as $l): ?>
        <div class="st-low"><div><b><?= h($l['item_name']) ?></b><small><?= storeQty($l['bal']) ?> <?= h($l['unit']) ?> · किमान <?= storeQty($l['reorder_level']) ?></small></div>
          <a href="move.php?type=purchase&item=<?= $l['id'] ?>" class="st-a" title="आवक नोंदवा">＋</a></div>
      <?php endforeach; ?>
    </div>
  </div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="panel h-100">
    <div class="panel-head"><div class="title">🏢 विभागनिहाय वाटप</div><div class="sub">मागचे 90 दिवस</div></div>
    <div style="padding:10px 18px;">
      <?php if (!$deptUse): ?><div class="sm-empty">अजून वाटप नाही</div><?php endif; ?>
      <?php $mx = $deptUse ? max(1, max(array_map(fn($x) => (float)$x['val'], $deptUse))) : 1; foreach ($deptUse as $d): ?>
        <div class="wl-row"><div class="wl-name" title="<?= h($d['dn']) ?>"><?= h($d['dn']) ?></div><div class="wl-bar"><i style="width:<?= max(4, round((float)$d['val'] * 100 / $mx)) ?>%;"></i></div><div class="wl-num font-mono"><?= $d['val'] > 0 ? '₹' . number_format($d['val']) : $d['n'] . '×' ?></div></div>
      <?php endforeach; ?>
    </div>
  </div></div>
  <div class="col-lg-4"><div class="panel h-100">
    <div class="panel-head"><div class="title">🔥 सर्वाधिक खप</div><div class="sub">मागचे 30 दिवस</div></div>
    <div style="padding:10px 18px;">
      <?php if (!$topUse): ?><div class="sm-empty">अजून खप नाही</div><?php endif; ?>
      <?php foreach ($topUse as $t): ?><div class="st-low"><div><b><?= h($t['item_name']) ?></b><small><?= (int)$t['n'] ?> वेळा</small></div><span class="font-mono"><?= storeQty($t['q']) ?> <?= h($t['unit']) ?></span></div><?php endforeach; ?>
    </div>
  </div></div>
  <div class="col-lg-4"><div class="panel h-100">
    <div class="panel-head"><div class="title">🗂️ प्रकारनिहाय मूल्य</div></div>
    <div style="padding:10px 18px;">
      <?php if (!array_filter($catVal)): ?><div class="sm-empty">दर नोंदवलेली खरेदी नाही</div><?php endif; ?>
      <?php $mx = $catVal ? max(1, max($catVal)) : 1; foreach (array_slice(array_filter($catVal), 0, 8, true) as $c => $v): ?>
        <div class="wl-row"><div class="wl-name"><?= h($c) ?></div><div class="wl-bar"><i style="width:<?= max(4, round($v * 100 / $mx)) ?>%;background:var(--amber);"></i></div><div class="wl-num font-mono">₹<?= number_format($v) ?></div></div>
      <?php endforeach; ?>
    </div>
  </div></div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">🕘 अलीकडच्या नोंदी</div><div class="sub"><a href="ledger.php">पूर्ण नोंदवही</a></div></div>
  <div class="table-responsive"><table class="rtable">
    <tbody>
    <?php foreach ($recent as $m): $qq = (float)$m['quantity']; ?>
      <tr><td class="font-mono" style="white-space:nowrap;"><?= h(date('d M', strtotime($m['move_date']))) ?></td>
        <td><span class="st-b <?= $m['move_type'] === 'adjust' ? 'adj' : ($qq >= 0 ? 'in' : 'outm') ?>"><?= h($types[$m['move_type']][0] ?? $m['move_type']) ?></span></td>
        <td style="font-weight:600;"><?= h($m['item_name']) ?></td>
        <td class="text-end font-mono" style="color:<?= $qq >= 0 ? '#15803D' : '#C2410C' ?>;"><?= ($qq >= 0 ? '+' : '−') . storeQty(abs($qq)) ?> <?= h($m['unit']) ?></td>
        <td style="font-size:12px;color:var(--text-dim);"><?= h($m['vendor_name'] ?: ($m['dept_name'] ?: ($m['issued_to'] ?: $m['notes']))) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td class="text-center" style="color:var(--text-dim);padding:24px;">अजून नोंदी नाहीत — सुरुवातीला प्रत्येक वस्तूची <a href="move.php?type=opening">सुरुवातीची शिल्लक</a> नोंदवा</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?= storeStyles() ?>
<style>
.st-insight{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.st-insight span{background:var(--panel,#fff);border:1px solid var(--line);border-radius:10px;padding:7px 12px;font-size:12.5px;}
.st-low{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);}
.st-low:last-child{border-bottom:0;}
.st-low b{display:block;font-size:13px;} .st-low small{color:var(--text-dim);font-size:11.5px;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.Chart) return;   // Chart.js Load न झाल्यास पान चालू राहतं
  new Chart(document.getElementById('stTrend'), {
    type: 'bar',
    data: { labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>, datasets: [
      { label: 'खरेदी ₹', data: <?= json_encode($buy) ?>, backgroundColor: '#0F766E', borderRadius: 6 },
      { label: 'खप ₹', data: <?= json_encode($used) ?>, backgroundColor: '#F59E0B', borderRadius: 6 }
    ] },
    options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return '₹' + v.toLocaleString('en-IN'); } } } } }
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
