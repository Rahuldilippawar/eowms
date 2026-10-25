<?php
$pageTitle = 'नूतनीकरण व अनुपालन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/renewal-helpers.php';

$orgId = activeOrgId();
$filter = $_GET['filter'] ?? 'all';
// लवचिक Department-Visibility — फक्त परवानगी असलेले विभाग दिसतील
$visibleDeptIds = getVisibleDepartmentIds('view_org_wide_dashboards');
$requestedDeptId = $_GET['department_id'] ?? '';
if ($visibleDeptIds === null) {
    $filterDeptId = $requestedDeptId;
} else {
    $filterDeptId = ($requestedDeptId !== '' && in_array((int)$requestedDeptId, $visibleDeptIds, true)) ? $requestedDeptId : ($user['department_id'] ?? '');
}
$filterType = $_GET['item_type'] ?? '';
$filterRegBody = $_GET['regulatory_body'] ?? '';
$focusItemId = (int)($_GET['item'] ?? 0); // Notification मधून आलेली विशिष्ट नोंद
$activeSql = renewalActiveSql('r');
$activeSqlPlain = renewalActiveSql('');

$sql = "SELECT r.*, d.name_local AS dept_name, d.name AS dept_name_en, DATEDIFF(r.expiry_date, CURDATE()) AS days_left
        FROM renewal_items r JOIN departments d ON d.id = r.department_id
        WHERE r.organization_id = ? AND $activeSql";
$params = [$orgId];

// स्थिती नेहमी expiry_date वरून — DB मधल्या status वरून नाही
if (in_array($filter, ['expired', 'due_soon', 'active'], true)) {
    $sql .= " AND " . renewalStatusSql($filter, 'r');
}
if ($focusItemId) {
    $sql .= " AND r.id = ?";
    $params[] = $focusItemId;
}
if ($filterDeptId) {
    $sql .= " AND r.department_id = ?";
    $params[] = $filterDeptId;
} elseif ($visibleDeptIds !== null) {
    $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $sql .= " AND r.department_id IN ($ph)";
    $params = array_merge($params, $visibleDeptIds);
}
if ($filterType) {
    $sql .= " AND r.item_type = ?";
    $params[] = $filterType;
}
if ($filterRegBody) {
    $sql .= " AND r.regulatory_body = ?";
    $params[] = $filterRegBody;
}
$sql .= " ORDER BY r.expiry_date";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Chips/Chart चे आकडे — तेच विभाग-Filter लावून, स्थिती expiry_date वरून
$countScopeSql = "organization_id = ? AND $activeSqlPlain";
$countScopeParams = [$orgId];
if ($filterDeptId) {
    $countScopeSql .= " AND department_id = ?";
    $countScopeParams[] = $filterDeptId;
} elseif ($visibleDeptIds !== null) {
    $countScopeSql .= empty($visibleDeptIds) ? " AND 0" : " AND department_id IN (" . implode(',', array_fill(0, count($visibleDeptIds), '?')) . ")";
    $countScopeParams = array_merge($countScopeParams, $visibleDeptIds);
}
$countStmt = $pdo->prepare("SELECT COUNT(*) c,
    SUM(CASE WHEN " . renewalStatusSql('due_soon', '') . " THEN 1 ELSE 0 END) due,
    SUM(CASE WHEN " . renewalStatusSql('expired', '') . " THEN 1 ELSE 0 END) expired
    FROM renewal_items WHERE $countScopeSql");
$countStmt->execute($countScopeParams);
$countRow = $countStmt->fetch();
$countAll = (int)$countRow['c'];
$countDue = (int)$countRow['due'];
$countExpired = (int)$countRow['expired'];

$countHealthy = max(0, $countAll - $countDue - $countExpired);

// Government Compliance Overview — Regulatory Body-निहाय सारांश
$regBodyStmt = $pdo->prepare("
    SELECT regulatory_body,
      COUNT(*) AS total,
      SUM(CASE WHEN " . renewalStatusSql('expired', '') . " THEN 1 ELSE 0 END) AS expired,
      SUM(CASE WHEN " . renewalStatusSql('due_soon', '') . " THEN 1 ELSE 0 END) AS due_soon
    FROM renewal_items WHERE $countScopeSql AND regulatory_body IS NOT NULL
    GROUP BY regulatory_body ORDER BY expired DESC, due_soon DESC
");
$regBodyStmt->execute($countScopeParams);
$regBodyOverview = $regBodyStmt->fetchAll();


$deptSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
$deptParams = [$orgId];
if ($visibleDeptIds !== null && !empty($visibleDeptIds)) {
    $ph3 = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $deptSql .= " AND id IN ($ph3)";
    $deptParams = array_merge($deptParams, $visibleDeptIds);
}
$deptSql .= " ORDER BY name_local";
$deptStmt = $pdo->prepare($deptSql);
$deptStmt->execute($deptParams);
$departments = $deptStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();

$typeStmt = $pdo->prepare("SELECT DISTINCT item_type FROM renewal_items WHERE organization_id = ? AND $activeSqlPlain ORDER BY item_type");
$typeStmt->execute([$orgId]);
$allTypes = array_column($typeStmt->fetchAll(), 'item_type');

function renewalTypeIcon($type) { return renewalTypeIconFor($type); }

// Executive President, General Manager, इतर Management-tier — आणि कुठलाही
// विशिष्ट विभाग Filter केलेला नसेल — तर एकत्रित (Centralized), विभाग-निहाय
// Grouped दृश्य दाखवतो. Staff/HOD ला (जे आपोआप त्यांच्याच विभागापुरते मर्यादित
// असतात) नेहमीचंच साधं यादी-स्वरूप दिसत राहतं.
$showGrouped = isManagementOrAbove() && !$filterDeptId;
$groupedItems = [];
if ($showGrouped) {
    foreach ($items as $item) {
        $groupedItems[$item['department_id']]['dept_name'] = $item['dept_name'] ?: $item['dept_name_en'];
        $groupedItems[$item['department_id']]['items'][] = $item;
    }
    uasort($groupedItems, fn($a, $b) => strcmp($a['dept_name'], $b['dept_name']));
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s67')) ?></div>
  <div class="d-flex gap-2">
    <a href="calendar.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-calendar-days me-1"></i>Calendar
    </a>
    <a href="cost-analysis.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-money-bill-trend-up me-1"></i>खर्च-कल
    </a>
    <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-file-import me-1"></i>Import
    </a>
    <a href="report.php<?= $filterDeptId ? '?department_id=' . h($filterDeptId) : '' ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-chart-column me-1"></i>अहवाल
    </a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i>नवीन नोंद
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>
<?php if ($focusItemId): ?>
  <div class="alert alert-info" style="border-radius:10px;font-size:12.5px;">🔔 सूचनेतील नोंद दाखवत आहे — <a href="index.php">सगळ्या नोंदी बघा</a></div>
<?php endif; ?>

<?php if (!empty($regBodyOverview)): ?>
<div class="panel mb-3" style="border-color:var(--rail);">
  <div class="panel-head"><div class="title">🏛️ Government Compliance Overview</div><div class="sub">CBSE, State Board, AICTE, DTE, MSBTE, इ. — शासकीय Compliance चा सारांश</div></div>
  <div style="padding:14px 20px;display:flex;gap:14px;flex-wrap:wrap;">
    <?php foreach ($regBodyOverview as $rb): ?>
      <a href="?regulatory_body=<?= urlencode($rb['regulatory_body']) ?>&department_id=<?= h($filterDeptId) ?>" style="text-decoration:none;color:inherit;background:var(--canvas);border-radius:10px;padding:10px 16px;min-width:130px;">
        <div style="font-size:11.5px;font-weight:700;color:var(--ink);">🏛️ <?= h($rb['regulatory_body']) ?></div>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;">एकूण: <?= $rb['total'] ?></div>
        <?php if ($rb['expired'] > 0): ?><div style="font-size:10.5px;color:var(--rose);font-weight:600;">⚠️ <?= $rb['expired'] ?> मुदत संपली</div><?php endif; ?>
        <?php if ($rb['due_soon'] > 0): ?><div style="font-size:10.5px;color:var(--amber);font-weight:600;">🟡 <?= $rb['due_soon'] ?> लवकरच</div><?php endif; ?>
        <?php if ($rb['expired'] == 0 && $rb['due_soon'] == 0): ?><div style="font-size:10.5px;color:var(--rail);font-weight:600;">✅ सर्व ठीक</div><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($countAll > 0): ?>
<div class="row g-3 mt-2 mb-1">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">नूतनीकरण स्थिती</div></div>
      <div style="padding:16px 18px;"><canvas id="renewalStatusChart" height="130"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($showGrouped): ?>
  <div style="background:var(--rail-soft,#DCEFEC);color:var(--rail);border-radius:9px;padding:8px 14px;font-size:12px;font-weight:600;margin-top:12px;display:inline-flex;align-items:center;gap:6px;">
    🏢 Centralized दृश्य — सर्व विभाग एकत्रित दाखवले आहेत
  </div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between mt-3 mb-3 gap-2">
  <div class="period-toggle">
    <a href="?filter=all&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $filter === 'all' ? 'active' : '' ?>"><?= h(t('s160')) ?><?= $countAll ?>)</a>
    <a href="?filter=due_soon&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $filter === 'due_soon' ? 'active' : '' ?>"><?= h(t('s161')) ?><?= $countDue ?>)</a>
    <a href="?filter=expired&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $filter === 'expired' ? 'active' : '' ?>"><?= h(t('s162')) ?><?= $countExpired ?>)</a>
  </div>
  <form method="GET" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_renIdx(this.value)">
        <option value="">-- College/School --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value=""><?= h(t('s18')) ?></option>
      <?php foreach ($departments as $dept): ?>
        <option value="<?= $dept['id'] ?>" <?= (string)$filterDeptId === (string)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_renIdx(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true; sel.innerHTML = '<option value="">लोड होत आहे...</option>';
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<option value=""><?= h(t('s18')) ?></option>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>
    <select name="item_type" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">-- सगळे प्रकार --</option>
      <?php foreach ($allTypes as $t): ?>
        <option value="<?= h($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= renewalTypeIcon($t) ?> <?= h($t) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="regulatory_body" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">-- सगळे Regulatory Body --</option>
      <?php foreach (['CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','RTO/Transport','Other Government'] as $rb): ?>
        <option value="<?= h($rb) ?>" <?= $filterRegBody === $rb ? 'selected' : '' ?>>🏛️ <?= h($rb) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php
  // Grouped असेल तर प्रत्येक विभाग वेगळ्या Section मध्ये, नाहीतर एकच सरळ यादी
  $sections = $showGrouped
      ? array_map(fn($g) => ['title' => $g['dept_name'], 'items' => $g['items']], $groupedItems)
      : [['title' => null, 'items' => $items]];
?>

<?php if ($showGrouped && empty($groupedItems)): ?>
  <div class="panel" style="padding:24px;text-align:center;color:var(--text-dim);"><?= h(t('s34')) ?></div>
<?php endif; ?>

<?php foreach ($sections as $section): if ($showGrouped && empty($section['items'])) continue; ?>
<div class="panel <?= $showGrouped ? 'mb-3' : '' ?>">
  <?php if ($section['title']): ?>
    <div class="panel-head"><div class="title">🏢 <?= h($section['title']) ?></div><div class="sub"><?= count($section['items']) ?> नोंदी</div></div>
  <?php endif; ?>
  <table class="rtable">
    <thead>
      <tr><th><?= h(t('s51')) ?></th><th>प्रकार</th><?php if (!$showGrouped): ?><th><?= h(t('s1')) ?></th><?php endif; ?><th><?= h(t('s163')) ?></th><th><?= h(t('s30')) ?></th><th><?= h(t('s164')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr>
    </thead>
    <tbody>
      <?php if (empty($section['items']) && !$showGrouped): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s34')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($section['items'] as $item):
        $days = (int)$item['days_left'];
        $statusInfo = renewalStatusInfo($days);
        $color = $statusInfo['color'];
        $label = $days < 0 ? abs($days) . ' दिवस उलटले' : $days . ' दिवस';
        $chip = $statusInfo['chip'];
      ?>
        <tr>
          <td style="font-weight:500;">
            <?= h($item['item_name']) ?>
            <?php if ($item['regulatory_body']): ?>
              <span style="font-size:9.5px;background:var(--rail-soft);color:var(--rail);padding:1px 7px;border-radius:6px;font-weight:700;margin-left:4px;">🏛️ <?= h($item['regulatory_body']) ?></span>
            <?php endif; ?>
            <?php if ($item['vendor_name']): ?>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:2px;">
                <?= h($item['vendor_name']) ?>
                <?php if ($item['vendor_contact']): ?>
                  · <?php if (str_contains($item['vendor_contact'], '@')): ?>
                    <a href="mailto:<?= h($item['vendor_contact']) ?>" style="color:var(--rail);"><i class="fa-regular fa-envelope"></i> <?= h($item['vendor_contact']) ?></a>
                  <?php else: ?>
                    <a href="tel:<?= h($item['vendor_contact']) ?>" style="color:var(--rail);"><i class="fa-solid fa-phone"></i> <?= h($item['vendor_contact']) ?></a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td><span style="background:var(--canvas);border-radius:7px;padding:3px 10px;font-size:11.5px;font-weight:600;white-space:nowrap;"><?= renewalTypeIcon($item['item_type']) ?> <?= h($item['item_type']) ?></span></td>
          <?php if (!$showGrouped): ?><td><?= h($item['dept_name'] ?: $item['dept_name_en']) ?></td><?php endif; ?>
          <td class="font-mono"><?= h($item['reference_no'] ?: '—') ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($item['expiry_date'])) ?></td>
          <td><span class="font-mono" style="font-weight:600;color:var(--<?= $color ?>);"><?= h($label) ?></span></td>
          <td><span class="status-chip <?= $chip ?>"><?= h($statusInfo['label']) ?></span></td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"
              data-bs-toggle="modal" data-bs-target="#renewModal<?= $item['id'] ?>"><?= h(t('s165')) ?></button>
            <a href="edit.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--text-dim);margin-left:4px;" title="संपादित करा / प्रमाणपत्र"><i class="fa-solid fa-pen"></i></a>
            <a href="history.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--text-dim);margin-left:4px;" title="इतिहास बघा"><i class="fa-solid fa-clock-rotate-left"></i></a>
            <a href="payment.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--rail);margin-left:6px;" title="Payment Track करा">💰</a>
          </td>
        </tr>

        <div class="modal fade" id="renewModal<?= $item['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="renew.php" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-bottom:14px;"><?= h($item['item_name']) ?> <?= h(t('s166')) ?></div>
                  <label class="form-label-custom"><?= h(t('s167')) ?></label>
                  <input type="date" name="new_expiry_date" class="form-control-custom mb-3" required>
                  <label class="form-label-custom"><?= h(t('s70')) ?></label>
                  <input type="number" step="0.01" name="renewal_cost" class="form-control-custom mb-3">
                  <label class="form-label-custom">Vendor (बदलायचं असल्यास)</label>
                  <input type="text" name="vendor_name" class="form-control-custom mb-3" placeholder="<?= h($item['vendor_name'] ?: '') ?>">
                  <label class="form-label-custom">नवीन प्रमाणपत्र (PDF/प्रतिमा, ऐच्छिक)</label>
                  <input type="file" name="certificate" class="form-control-custom" accept=".pdf,.jpg,.jpeg,.png,.webp">
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;"><?= h(t('s3')) ?></button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;"><?= h(t('s168')) ?></button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php if ($countAll > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('renewalStatusChart'), {
  type: 'doughnut',
  data: {
    labels: ['सुस्थितीत', 'लवकरच', 'मुदत संपली'],
    datasets: [{
      data: [<?= $countHealthy ?>, <?= $countDue ?>, <?= $countExpired ?>],
      backgroundColor: ['#1B8A83', '#C97F1D', '#B23A48'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10 } } },
    cutout: '60%'
  }
});
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
