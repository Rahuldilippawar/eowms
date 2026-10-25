<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_budget');

if (!$isMgmt) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला Budget व्यवस्थापन पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

require_once __DIR__ . '/../includes/budget-helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $fy = (int)($_POST['financial_year'] ?? getCurrentFinancialYear());
    $amount = (float)($_POST['allocated_amount'] ?? 0);

    // विभाग याच (सध्याच्या) संस्थेचा आहे का — नाहीतर दुसऱ्या संस्थेचं Budget बदलता आलं असतं
    $deptOrgCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
    $deptOrgCheck->execute([$departmentId, $orgId]);
    if (!$deptOrgCheck->fetch()) { $departmentId = 0; }

    if ($departmentId && $amount >= 0 && $fy >= 2000 && $fy <= 2100) {
        $pdo->prepare("
            INSERT INTO department_budgets (organization_id, department_id, financial_year, allocated_amount, allocated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE allocated_amount = VALUES(allocated_amount), allocated_by = VALUES(allocated_by)
        ")->execute([$orgId, $departmentId, $fy, $amount, $user['id']]);
        logActivity('विभाग Budget सेट केलं', 'department_budget', $departmentId, "FY $fy — ₹" . number_format($amount, 0));
        $_SESSION['flash'] = 'Budget जतन झालं.';
    }
    header('Location: budget.php?fy=' . $fy);
    exit;
}

$pageTitle = 'विभाग Finance — Budget वाटप';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$selectedFy = (int)($_GET['fy'] ?? getCurrentFinancialYear());

$stmt = $pdo->prepare("
    SELECT d.*, c.name AS campus_name FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY c.name, d.name_local
");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

$budgetRows = [];
foreach ($departments as $d) {
    $budgetRows[$d['id']] = getBudgetStatus($pdo, $orgId, $d['id'], $selectedFy);
}

$totalAllocated = array_sum(array_column($budgetRows, 'allocated'));
$totalSpent = array_sum(array_column($budgetRows, 'spent'));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">💰 विभाग Finance — Budget वाटप</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">आर्थिक वर्ष <?= h(formatFinancialYearLabel($selectedFy)) ?> — प्रत्येक विभागाला Budget द्या, खर्च आपोआप वजा होतो</div>
  </div>
  <div class="d-flex gap-2">
    <form method="GET" style="display:inline;">
      <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
        <?php for ($y = getCurrentFinancialYear() + 1; $y >= getCurrentFinancialYear() - 3; $y--): ?>
          <option value="<?= $y ?>" <?= $selectedFy === $y ? 'selected' : '' ?>>FY <?= $y ?>-<?= substr($y + 1, 2) ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <a href="dashboard.php?fy=<?= $selectedFy ?>" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13.5px;padding:9px 18px;">📊 Budget vs Actual</a>
    <a href="budget-report.php?department_id=all&fy=<?= $selectedFy ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-file-pdf me-1"></i>सर्व विभाग — PDF अहवाल</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण दिलेलं Budget</div><div class="val" style="color:var(--rail);">₹<?= number_format($totalAllocated, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण खर्च</div><div class="val" style="color:var(--amber);">₹<?= number_format($totalSpent, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण शिल्लक</div><div class="val" style="color:<?= ($totalAllocated - $totalSpent) < 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($totalAllocated - $totalSpent, 0) ?></div></div></div>
  <div class="col-6 col-lg-3">
    <div class="kpi-mini" style="display:flex;align-items:center;justify-content:center;padding:4px;">
      <canvas id="orgBudgetGauge" style="width:100%;height:80px;"></canvas>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  eowmsDrawGauge('orgBudgetGauge', <?= $totalAllocated > 0 ? round(($totalSpent / $totalAllocated) * 100, 1) : 0 ?>, { label: 'एकूण वापर' });
});
</script>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📊 खर्च — विभाग-निहाय (Click करा — Category-वार खोलात जा)</div><div class="sub" id="drilldownBreadcrumb">सगळे विभाग</div></div>
  <div style="padding:16px 20px;"><canvas id="budgetDrilldownChart" height="90"></canvas></div>
</div>
<script>
(function () {
  const deptData = <?= json_encode(array_map(fn($d) => ['name' => $d['name_local'] ?: $d['name'], 'id' => $d['id'], 'spent' => $budgetRows[$d['id']]['spent'], 'by_category' => $budgetRows[$d['id']]['by_category'] ?? []], array_values($departments))) ?>;
  const ctx = document.getElementById('budgetDrilldownChart');
  const palette = ['#1B8A83','#C97F1D','#B23A48','#8A5CD6','#2255AA','#666B7D','#2FA37A','#D4A017'];
  let currentLevel = 'department';
  let chartInstance = null;

  function renderDeptLevel() {
    currentLevel = 'department';
    document.getElementById('drilldownBreadcrumb').textContent = 'सगळे विभाग';
    const filtered = deptData.filter(function (d) { return d.spent > 0; });
    if (chartInstance) chartInstance.destroy();
    chartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: filtered.map(function (d) { return d.name; }),
        datasets: [{ data: filtered.map(function (d) { return d.spent; }), backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }]
      },
      options: {
        plugins: { legend: { position: 'right', labels: { boxWidth: 9, font: { size: 10.5 } } } },
        onClick: function (evt, elements) {
          if (!elements.length) return;
          const idx = elements[0].index;
          renderCategoryLevel(filtered[idx]);
        }
      }
    });
  }

  function renderCategoryLevel(dept) {
    currentLevel = 'category';
    const cats = Object.keys(dept.by_category).filter(function (c) { return dept.by_category[c] > 0; });
    document.getElementById('drilldownBreadcrumb').innerHTML = '<a href="#" id="drillBackLink" style="color:var(--rail);">← सगळे विभाग</a> / ' + dept.name;
    if (chartInstance) chartInstance.destroy();
    chartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: cats,
        datasets: [{ data: cats.map(function (c) { return dept.by_category[c]; }), backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }]
      },
      options: { plugins: { legend: { position: 'right', labels: { boxWidth: 9, font: { size: 10.5 } } } } }
    });
    document.getElementById('drillBackLink').addEventListener('click', function (e) { e.preventDefault(); renderDeptLevel(); });
  }

  renderDeptLevel();
})();
</script>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>विभाग</th><th>College/School</th><th>दिलेलं Budget</th><th>खर्च झालेलं</th><th>शिल्लक</th><th>वापर %</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($departments as $d): $b = $budgetRows[$d['id']]; $over = $b['remaining'] < 0; ?>
        <tr>
          <td style="font-weight:500;"><i class="<?= h($d['icon']) ?> me-2" style="color:var(--rail);"></i><?= h($d['name_local'] ?: $d['name']) ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($d['campus_name'] ?: '🏛️ Trust-स्तरीय') ?></td>
          <td class="font-mono">₹<?= number_format($b['allocated'], 0) ?></td>
          <td class="font-mono">
            ₹<?= number_format($b['spent'], 0) ?>
            <?php if (!empty($b['by_category'])): ?>
              <div style="font-size:9.5px;color:var(--text-dim);font-family:inherit;margin-top:3px;line-height:1.6;">
                <?php foreach ($b['by_category'] as $cat => $amt): if ($amt <= 0) continue; ?>
                  <?= h($cat) ?>: ₹<?= number_format($amt, 0) ?><br>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="font-mono" style="color:<?= $over ? 'var(--rose)' : 'var(--ink)' ?>;font-weight:600;">₹<?= number_format($b['remaining'], 0) ?><?= $over ? ' ⚠️' : '' ?></td>
          <td>
            <div style="background:var(--canvas);border-radius:10px;height:8px;width:80px;overflow:hidden;display:inline-block;vertical-align:middle;">
              <div style="height:100%;width:<?= $b['percent_used'] ?>%;background:<?= $over ? 'var(--rose)' : ($b['percent_used'] > 80 ? 'var(--amber)' : 'var(--rail)') ?>;"></div>
            </div>
            <span style="font-size:11px;color:var(--text-dim);margin-left:6px;"><?= $b['percent_used'] ?>%</span>
          </td>
          <td class="d-flex gap-2">
            <a href="budget-report.php?department_id=<?= $d['id'] ?>&fy=<?= $selectedFy ?>" target="_blank" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:12px;padding:5px 12px;">तपशील पहा</a>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#budgetModal<?= $d['id'] ?>">Budget सेट करा</button>
          </td>
        </tr>

        <div class="modal fade" id="budgetModal<?= $d['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST">
                <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                <input type="hidden" name="financial_year" value="<?= $selectedFy ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-bottom:6px;"><?= h($d['name_local'] ?: $d['name']) ?> — Budget</div>
                  <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">आर्थिक वर्ष <?= h(formatFinancialYearLabel($selectedFy)) ?></div>
                  <label class="form-label-custom">दिलेली रक्कम (₹)</label>
                  <input type="number" step="0.01" name="allocated_amount" class="form-control-custom" value="<?= $b['allocated'] ?: '' ?>" required>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
