<?php
// विभाग Finance — एकच Dashboard: Budget vs Actual (एकच खर्च-Ledger) + Paid vs Due
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'dept_payment_ledger', '../');
requireMigratedTable($pdo, 'budget_transactions', '../', 'spend_date');
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

// पूर्वी "विभाग Budget" किंवा "Material Purchase" — यांपैकी कोणत्याही एकाची परवानगी असलेल्यांना
$canAccess = hasPermission('manage_dept_finance') || hasPermission('manage_budget') || hasCategory('hod') || isManagementOrAbove();
$accessibleDeptId = deptFinanceAccessibleDeptId($user);
if (!$canAccess || $accessibleDeptId === 0) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">विभाग Finance पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard वर परत जा</a></div>');
}
$canSetBudget = hasPermission('manage_budget');

$fy = (int)($_GET['fy'] ?? getCurrentFinancialYear());
if ($fy < 2000 || $fy > 2100) { $fy = getCurrentFinancialYear(); }
$filterDeptId = $accessibleDeptId ?? (int)($_GET['department_id'] ?? 0);

$rows = deptFinanceSummaryRows($pdo, $orgId, $fy, $filterDeptId ?: null);

$totAllocated = array_sum(array_column($rows, 'allocated'));
$totSpent = array_sum(array_column($rows, 'spent'));
$totRemaining = $totAllocated - $totSpent;
$totOwed = array_sum(array_column($rows, 'owed'));
$totPaid = array_sum(array_column($rows, 'paid'));

// Source-निहाय एकूण खर्च
$sourceTotals = [];
foreach ($rows as $r) { foreach ($r['by_source'] as $lbl => $amt) { $sourceTotals[$lbl] = ($sourceTotals[$lbl] ?? 0) + $amt; } }
arsort($sourceTotals);

// Paid vs Due — प्रकार-निहाय
$pdTypes = ['material' => '🛒 Material Purchase', 'renewal' => '📋 नूतनीकरण (चालू Cycle)', 'maintenance' => '🔧 देखभाल'];
$pdTotals = [];
foreach ($pdTypes as $k => $lbl) { $pdTotals[$k] = ['total' => 0, 'paid' => 0]; }
foreach ($rows as $r) { foreach ($r['paid_due'] as $k => $v) { $pdTotals[$k]['total'] += $v['total']; $pdTotals[$k]['paid'] += $v['paid']; } }

$allDepts = [];
if ($accessibleDeptId === null) {
    $deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $deptsStmt->execute([$orgId]);
    $allDepts = $deptsStmt->fetchAll();
}

$pageTitle = 'विभाग Finance';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$qsBase = 'fy=' . $fy . ($filterDeptId ? '&department_id=' . $filterDeptId : '');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div>
    <div class="page-title mb-1">💰 विभाग Finance</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">आर्थिक वर्ष <?= h(formatFinancialYearLabel($fy)) ?> — Budget vs प्रत्यक्ष खर्च (प्रत्येक खर्च एकदाच) + Paid vs Due</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 16px;">🛒 Material Purchase</a>
    <?php if ($canSetBudget): ?><a href="budget.php?fy=<?= $fy ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 16px;">🎯 Budget वाटप</a><?php endif; ?>
    <a href="budget-report.php?department_id=<?= $filterDeptId ?: ($canSetBudget ? 'all' : (int)($user['department_id'] ?? 0)) ?>&fy=<?= $fy ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 16px;"><i class="fa-solid fa-file-pdf me-1"></i>अहवाल</a>
    <a href="export.php?<?= $qsBase ?>&type=summary" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-file-excel me-1"></i>Excel (सारांश)</a>
    <a href="export.php?<?= $qsBase ?>&type=ledger" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-file-excel me-1"></i>Excel (खर्च Ledger)</a>
  </div>
</div>

<form method="GET" class="d-flex gap-2 mb-3 flex-wrap">
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php for ($y = getCurrentFinancialYear() + 1; $y >= getCurrentFinancialYear() - 3; $y--): ?>
      <option value="<?= $y ?>" <?= $fy === $y ? 'selected' : '' ?>>FY <?= $y ?>-<?= substr($y + 1, 2) ?></option>
    <?php endfor; ?>
  </select>
  <?php if ($accessibleDeptId === null): ?>
    <select name="department_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">-- सगळे विभाग --</option>
      <?php foreach ($allDepts as $d): ?><option value="<?= $d['id'] ?>" <?= $filterDeptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
</form>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🎯 दिलेलं Budget</div><div class="val" style="color:var(--rail);">₹<?= number_format($totAllocated, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">💸 प्रत्यक्ष खर्च</div><div class="val" style="color:var(--amber);">₹<?= number_format($totSpent, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">शिल्लक</div><div class="val" style="color:<?= $totRemaining < 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($totRemaining, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ एकूण देणे बाकी (Due)</div><div class="val" style="color:<?= ($totOwed - $totPaid) > 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format(max(0, $totOwed - $totPaid), 0) ?></div></div></div>
</div>

<div class="panel mb-4">
  <div class="panel-head"><div class="title">📊 Budget vs Actual — विभाग-निहाय</div><div class="sub">खर्च = विभाग Finance Ledger (Material Purchase, Dead Stock, देखभाल, वाहन, नूतनीकरण Payments, पत्र-मंजुरी)</div></div>
  <div style="overflow-x:auto;">
  <table class="rtable">
    <thead><tr><th>विभाग</th><th>दिलेलं</th><th>खर्च</th><th>शिल्लक</th><th>वापर %</th><th>खर्च — Source-निहाय</th><th>Paid / Due</th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलाही विभाग नाही</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $over = $r['remaining'] < 0; ?>
        <tr>
          <td style="font-weight:600;"><?= h($r['name']) ?><div style="font-size:10.5px;color:var(--text-dim);font-weight:400;"><?= h($r['campus_name'] ?: '🏛️ Trust-स्तरीय') ?></div></td>
          <td class="font-mono"><?= $r['has_budget'] ? '₹' . number_format($r['allocated'], 0) : '<span style="color:var(--text-dim);">सेट नाही</span>' ?></td>
          <td class="font-mono">₹<?= number_format($r['spent'], 0) ?></td>
          <td class="font-mono" style="font-weight:600;color:<?= $over ? 'var(--rose)' : 'var(--ink)' ?>;">₹<?= number_format($r['remaining'], 0) ?><?= $over ? ' ⚠️' : '' ?></td>
          <td>
            <div style="background:var(--canvas);border-radius:10px;height:8px;width:70px;overflow:hidden;display:inline-block;vertical-align:middle;">
              <div style="height:100%;width:<?= $r['percent_used'] ?>%;background:<?= $over ? 'var(--rose)' : ($r['percent_used'] > 80 ? 'var(--amber)' : 'var(--rail)') ?>;"></div>
            </div>
            <span style="font-size:11px;color:var(--text-dim);margin-left:4px;"><?= $r['percent_used'] ?>%</span>
          </td>
          <td style="font-size:10.5px;color:var(--text-dim);line-height:1.6;">
            <?php foreach ($r['by_source'] as $lbl => $amt): if ($amt <= 0) continue; ?><?= h($lbl) ?>: ₹<?= number_format($amt, 0) ?><br><?php endforeach; ?>
            <?= empty($r['by_source']) ? '—' : '' ?>
          </td>
          <td class="font-mono" style="font-size:12px;">
            <span style="color:var(--rail);">₹<?= number_format($r['paid'], 0) ?></span> /
            <span style="color:<?= $r['due'] > 0 ? 'var(--rose)' : 'var(--text-dim)' ?>;">₹<?= number_format($r['due'], 0) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">📂 खर्च — Source-निहाय</div></div>
      <div style="padding:16px 20px;"><canvas id="sourceChart" height="200"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">💳 Paid vs Due</div><div class="sub">Purchase / नूतनीकरण (चालू Cycle) / देखभाल — आजपर्यंतची देणी</div></div>
      <div style="padding:16px 20px;"><canvas id="paidDueChart" height="200"></canvas></div>
      <table class="rtable">
        <thead><tr><th>प्रकार</th><th>एकूण</th><th>Paid</th><th>बाकी</th></tr></thead>
        <tbody>
          <?php foreach ($pdTypes as $k => $lbl): $v = $pdTotals[$k]; ?>
            <tr><td><?= h($lbl) ?></td><td class="font-mono">₹<?= number_format($v['total'], 0) ?></td><td class="font-mono" style="color:var(--rail);">₹<?= number_format($v['paid'], 0) ?></td><td class="font-mono" style="color:<?= ($v['total'] - $v['paid']) > 0 ? 'var(--rose)' : 'var(--text-dim)' ?>;">₹<?= number_format(max(0, $v['total'] - $v['paid']), 0) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('sourceChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($sourceTotals), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_values($sourceTotals)) ?>, backgroundColor: ['#1B8A83','#C97F1D','#B23A48','#8A5CD6','#2255AA','#666B7D','#2FA37A'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, cutout: '55%' }
  });
  new Chart(document.getElementById('paidDueChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_values($pdTypes), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { label: 'Paid', data: <?= json_encode(array_map(fn($v) => $v['paid'], array_values($pdTotals))) ?>, backgroundColor: '#1B8A83', borderRadius: 6 },
        { label: 'Due', data: <?= json_encode(array_map(fn($v) => max(0, $v['total'] - $v['paid']), array_values($pdTotals))) ?>, backgroundColor: '#B23A48', borderRadius: 6 }
      ]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, scales: { x: { stacked: true }, y: { stacked: true } } }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
