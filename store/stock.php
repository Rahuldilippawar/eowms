<?php
// 📦 भांडार — सध्याची शिल्लक (प्रत्येक वस्तू), कमी साठा, मूल्य, Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();

$itemsStmt = $pdo->prepare("SELECT si.*, d.name_local AS dept_name FROM store_items si LEFT JOIN departments d ON d.id = si.department_id WHERE si.organization_id = ? AND si.is_active = 1 ORDER BY si.category IS NULL OR si.category = '', si.category, si.item_name");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();
$bal = storeBalances($pdo, $orgId);

// 30 दिवसांचा खप (जावक + कामात वापर) — किती दिवस पुरेल याचा अंदाज
$use30 = [];
if (storeStockReady($pdo)) {
    $u = $pdo->prepare("SELECT item_id, -SUM(quantity) q FROM store_stock_moves WHERE organization_id = ? AND move_type IN ('issue','work') AND move_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY item_id");
    $u->execute([$orgId]);
    foreach ($u->fetchAll() as $r) $use30[(int)$r['item_id']] = (float)$r['q'];
}

$rows = [];
foreach ($items as $it) {
    $id = (int)$it['id'];
    $b = $bal[$id] ?? null;
    $qty = $b ? $b['bal'] : 0.0;
    $min = (float)$it['reorder_level'];
    $status = !$b ? 'none' : ($qty <= 0 ? 'out' : ($min > 0 && $qty <= $min ? 'low' : 'ok'));
    $daily = ($use30[$id] ?? 0) / 30;
    $rows[] = $it + ['qty' => $qty, 'min' => $min, 'status' => $status, 'rate' => $b['last_rate'] ?? null, 'value' => $b['value'] ?? 0,
        'last' => $b['last_move'] ?? null, 'u30' => $use30[$id] ?? 0, 'days' => $daily > 0 && $qty > 0 ? (int)floor($qty / $daily) : null];
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="store-stock-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['वस्तू', 'प्रकार', 'विभाग', 'एकक', 'शिल्लक', 'किमान मर्यादा', 'स्थिती', 'शेवटचा दर (₹)', 'मूल्य (₹)', '30 दिवस खप', 'किती दिवस पुरेल', 'शेवटची हालचाल']);
    $st = ['ok' => 'पुरेसा', 'low' => 'कमी', 'out' => 'संपला', 'none' => 'नोंद नाही'];
    foreach ($rows as $r) fputcsv($out, [$r['item_name'], $r['category'], $r['dept_name'] ?: 'सामायिक', $r['unit'], storeQty($r['qty']), storeQty($r['min']), $st[$r['status']], $r['rate'] !== null ? storeQty($r['rate']) : '', round($r['value'], 2), storeQty($r['u30']), $r['days'] ?? '', $r['last'] ?? '']);
    fclose($out);
    exit;
}

$pageTitle = 'भांडार शिल्लक';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$cnt = array_count_values(array_column($rows, 'status'));
$cats = array_values(array_unique(array_map(fn($r) => trim((string)$r['category']) ?: 'इतर', $rows)));
$f = $_GET['f'] ?? '';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-0">📦 भांडार शिल्लक</div>
  <div class="d-flex gap-2">
    <a href="?export=csv" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">📊 Excel</a>
    <button type="button" onclick="window.print()" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">🖨️ Print</button>
  </div>
</div>
<?= storeNav('stock') ?>
<?php if (!storeStockReady($pdo)): ?><div class="alert alert-warning" style="border-radius:10px;">Stock साठी Database अद्ययावत करायचा आहे — Super Admin ने <b>System Update → Database Update</b> चालवा.</div><?php endif; ?>

<div class="st-chips no-print">
  <button type="button" data-f="" class="<?= $f === '' ? 'on' : '' ?>">सर्व <b><?= count($rows) ?></b></button>
  <button type="button" data-f="out" class="<?= $f === 'out' ? 'on' : '' ?>">🔴 संपला <b><?= $cnt['out'] ?? 0 ?></b></button>
  <button type="button" data-f="low" class="<?= $f === 'low' ? 'on' : '' ?>">🟠 कमी <b><?= $cnt['low'] ?? 0 ?></b></button>
  <button type="button" data-f="ok" class="<?= $f === 'ok' ? 'on' : '' ?>">🟢 पुरेसा <b><?= $cnt['ok'] ?? 0 ?></b></button>
  <button type="button" data-f="none" class="<?= $f === 'none' ? 'on' : '' ?>">⚪ नोंद नाही <b><?= $cnt['none'] ?? 0 ?></b></button>
  <select id="stCat" class="form-select-custom" style="width:auto;" data-smart="0"><option value="">सर्व प्रकार</option><?php foreach ($cats as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select>
  <input type="search" id="stQ" class="form-control-custom" placeholder="🔍 वस्तू शोधा…" style="max-width:220px;">
</div>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable" id="stTable">
    <thead><tr><th>वस्तू</th><th>प्रकार</th><th class="text-end">शिल्लक</th><th class="text-end">किमान</th><th>स्थिती</th><th class="text-end">दर</th><th class="text-end">मूल्य</th><th class="text-end" title="मागील 30 दिवसांचा खप">30 दिवस खप</th><th title="सध्याच्या खपाच्या वेगाने">पुरेल</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $c = trim((string)$r['category']) ?: 'इतर'; ?>
      <tr data-st="<?= $r['status'] ?>" data-cat="<?= h($c) ?>" data-q="<?= h(mb_strtolower($r['item_name'] . ' ' . $c)) ?>">
        <td style="font-weight:600;"><?= h($r['item_name']) ?><div style="font-size:11px;color:var(--text-dim);font-weight:400;"><?= h($r['dept_name'] ?: 'सामायिक') ?></div></td>
        <td><?= h($c) ?></td>
        <td class="text-end font-mono" style="font-weight:700;"><?= storeQty($r['qty']) ?> <span style="font-weight:400;color:var(--text-dim);"><?= h($r['unit']) ?></span></td>
        <td class="text-end font-mono"><?= $r['min'] > 0 ? storeQty($r['min']) : '—' ?></td>
        <td><?= ['ok' => '<span class="st-b ok">पुरेसा</span>', 'low' => '<span class="st-b low">कमी</span>', 'out' => '<span class="st-b out">संपला</span>', 'none' => '<span class="st-b none">नोंद नाही</span>'][$r['status']] ?></td>
        <td class="text-end font-mono"><?= $r['rate'] !== null ? '₹' . storeQty($r['rate']) : '—' ?></td>
        <td class="text-end font-mono"><?= $r['value'] > 0 ? '₹' . number_format($r['value']) : '—' ?></td>
        <td class="text-end font-mono"><?= $r['u30'] > 0 ? storeQty($r['u30']) : '—' ?></td>
        <td style="white-space:nowrap;"><?= $r['days'] === null ? '—' : ($r['days'] <= 7 ? '<b style="color:var(--rose);">' . $r['days'] . ' दिवस</b>' : $r['days'] . ' दिवस') ?></td>
        <td class="no-print" style="white-space:nowrap;">
          <a href="move.php?type=purchase&item=<?= $r['id'] ?>" class="st-a" title="आवक">＋</a>
          <a href="move.php?type=issue&item=<?= $r['id'] ?>" class="st-a" title="जावक">－</a>
          <a href="ledger.php?item=<?= $r['id'] ?>" class="st-a" title="नोंदवही">📒</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center" style="color:var(--text-dim);padding:30px;">वस्तू नाहीत — <a href="items.php">वस्तू यादी</a> मध्ये जोडा</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="6" class="text-end" style="font-weight:600;">एकूण मूल्य (दिसणाऱ्या)</td><td class="text-end font-mono" id="stTot" style="font-weight:700;"></td><td colspan="3"></td></tr></tfoot>
  </table>
  </div>
</div>
<?= storeStyles() ?>
<script>
(function () {
  var f = <?= json_encode($f) ?>, rows = document.querySelectorAll('#stTable tbody tr[data-st]'), VAL = <?= json_encode(array_map(fn($r) => round($r['value'], 2), $rows)) ?>;
  function apply() {
    var q = document.getElementById('stQ').value.trim().toLowerCase(), c = document.getElementById('stCat').value, tot = 0;
    rows.forEach(function (tr, i) {
      var ok = (!f || tr.dataset.st === f) && (!c || tr.dataset.cat === c) && (!q || tr.dataset.q.indexOf(q) !== -1);
      tr.style.display = ok ? '' : 'none'; if (ok) tot += VAL[i] || 0;
    });
    document.getElementById('stTot').textContent = '₹' + Math.round(tot).toLocaleString('en-IN');
  }
  document.querySelectorAll('.st-chips button').forEach(function (b) { b.onclick = function () { f = b.dataset.f; document.querySelectorAll('.st-chips button').forEach(function (x) { x.classList.toggle('on', x === b); }); apply(); }; });
  document.getElementById('stQ').oninput = apply; document.getElementById('stCat').onchange = apply; apply();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
