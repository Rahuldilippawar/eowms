<?php
// कामाचा अहवाल — व्यक्तिगत / विभाग / संपूर्ण संस्था (पूर्वीचं "अहवाल" (reports/) Module इथेच)
$pageTitle = 'कामाचा अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/report-common.php';

$ctx = dwReportContext($pdo, $user);
$orgId = $ctx['org_id'];
$scope = $ctx['scope'];
$period = $ctx['period'];
$start = $ctx['start'];
$end = $ctx['end'];
$filterDeptId = $ctx['department_id'];

$departments = [];
$campusesForCascade = [];
if (!$ctx['dept_locked']) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

// विभाग-निहाय सारांश (संस्था/विभाग Scope)
$summary = $scope === 'personal' ? [] : dwReportOrgSummary($pdo, $ctx);
// तपशीलवार नोंदी — नेहमी याच संस्थेच्या
$entries = dwReportRecords($pdo, $ctx, $user, 100);

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'overdue', 'in_progress' => 'pending', 'completed' => 'done'];
$scopeLabels = ['personal' => '👤 व्यक्तिगत', 'department' => '🏢 विभाग', 'organization' => '🏛️ संपूर्ण संस्था'];
$qs = function ($over) use ($scope, $period, $filterDeptId) {
    return http_build_query(array_merge(['scope' => $scope, 'period' => $period, 'department_id' => $filterDeptId ?: null], $over));
};
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0"><?= h(t('s231')) ?></div>
  <a href="material-usage-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-boxes-stacked me-1"></i>📦 साहित्य वापर-अहवाल</a>
</div>

<div class="mode-tabs mb-4" style="display:flex;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:5px;width:fit-content;">
  <a href="entry.php" class="mode-tab" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--text-dim);text-decoration:none;"><?= h(t('s89')) ?></a>
  <a href="report.php" class="mode-tab active" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;background:var(--ink);color:#fff;text-decoration:none;"><?= h(t('s90')) ?></a>
</div>

<div class="period-toggle mb-3" style="width:fit-content;">
  <?php foreach ($ctx['allowed_scopes'] as $s): ?>
    <a href="?<?= h($qs(['scope' => $s])) ?>" class="period-btn <?= $scope === $s ? 'active' : '' ?>"><?= $scopeLabels[$s] ?></a>
  <?php endforeach; ?>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <?php foreach (dwReportPeriodLabels() as $p => $lbl): ?>
      <a href="?<?= h($qs(['period' => $p])) ?>" class="period-btn <?= $period === $p ? 'active' : '' ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($scope !== 'personal' && !$ctx['dept_locked']): ?>
  <form method="GET" class="d-flex gap-2">
    <input type="hidden" name="period" value="<?= h($period) ?>">
    <input type="hidden" name="scope" value="<?= h($scope) ?>">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_dwRep(this.value)">
        <option value="">-- College/School --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <?php if ($scope === 'organization'): ?><option value=""><?= h(t('s18')) ?></option><?php endif; ?>
      <?php foreach ($departments as $dept): ?>
        <option value="<?= $dept['id'] ?>" <?= (int)$filterDeptId === (int)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_dwRep(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true;
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<?= $scope === 'organization' ? '<option value="">' . h(t('s18')) . '</option>' : '' ?>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <div class="d-flex gap-2">
    <a href="export-csv.php?<?= h($qs([])) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;" onclick="var p=document.getElementById('printOpts');p.style.display=p.style.display==='none'?'block':'none';"><i class="fa-solid fa-print me-1"></i>Print / PDF</button>
  </div>
</div>

<div id="printOpts" class="panel mb-3" style="display:none;">
  <form method="GET" action="report-print.php" target="_blank" style="padding:16px 20px;">
    <input type="hidden" name="scope" value="<?= h($scope) ?>">
    <input type="hidden" name="period" value="<?= h($period) ?>">
    <?php if ($filterDeptId): ?><input type="hidden" name="department_id" value="<?= (int)$filterDeptId ?>"><?php endif; ?>
    <div style="font-weight:700;font-size:13px;margin-bottom:8px;">📋 छापील अहवालात कोणते Columns हवेत</div>
    <div class="d-flex flex-wrap gap-3" style="font-size:12.5px;">
      <?php foreach (['heading' => 'कामाचं शीर्षक', 'details' => 'तपशील/वर्णन', 'requester' => 'विनंती करणारा', 'priority' => 'प्राधान्य', 'cost' => 'खर्च', 'materials' => 'वापरलेलं साहित्य', 'dates' => 'दिनांक', 'status' => 'स्थिती'] as $k => $lbl): ?>
        <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="cols[]" value="<?= $k ?>" checked> <?= h($lbl) ?></label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 20px;font-weight:600;"><i class="fa-solid fa-file-pdf me-1"></i>अहवाल तयार करा</button>
  </form>
</div>

<?php if ($scope !== 'personal'): ?>
<div class="panel">
  <div class="panel-head"><div class="title"><?= h(t('s235')) ?></div><div class="sub"><?= h($start) ?> — <?= h($end) ?></div></div>
  <table class="rtable">
    <thead><tr><th><?= h(t('s1')) ?></th><th><?= h(t('s26')) ?></th><th><?= h(t('s42')) ?></th><th><?= h(t('s43')) ?></th><th><?= h(t('s2')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($summary as $row):
        $pct = $row['pct'];
        $statusClass = $pct >= 85 ? 'done' : ($pct >= 65 ? 'pending' : 'overdue');
        $statusText = $pct >= 85 ? 'चांगले' : ($pct >= 65 ? 'सुधारणा आवश्यक' : 'विलंबित');
        $barColor = $pct >= 85 ? 'var(--rail)' : ($pct >= 65 ? 'var(--amber)' : 'var(--rose)');
      ?>
        <tr>
          <td style="font-weight:500;"><?= h($row['name']) ?></td>
          <td class="font-mono"><?= $row['total'] ?></td>
          <td class="font-mono"><?= $row['done'] ?></td>
          <td><span class="bar-track"><span class="bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></span></span><?= $pct ?>%</td>
          <td><span class="status-chip <?= $statusClass ?>"><?= $statusText ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel mt-3">
  <div class="panel-head"><div class="title"><?= h(t('s236')) ?></div><div class="sub"><?= h(t('s237')) ?> · <?= h($start) ?> — <?= h($end) ?></div></div>
  <div style="overflow-x:auto;">
  <table class="rtable">
    <thead><tr><th><?= h(t('s13')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s238')) ?></th><th><?= h(t('s6')) ?></th><th><?= h(t('s21')) ?></th><th>साहित्य</th><th><?= h(t('s2')) ?></th></tr></thead>
    <tbody>
      <?php if (empty($entries)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s44')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($entries as $entry): ?>
        <tr>
          <td class="font-mono"><?= date('d M', strtotime($entry['work_date'])) ?></td>
          <td><?= h($entry['dept_name'] ?: $entry['dept_name_en']) ?></td>
          <td style="color:var(--text-dim);"><?= $entry['requested_by'] ? h($entry['requested_by']) : '<span style="font-size:11px;">🔁 नियमित</span>' ?></td>
          <td><?= h($entry['emp_name']) ?></td>
          <td>
            <?= h($entry['title']) ?>
            <?php if (!empty($entry['custom_fields_display'])): ?>
              <div style="font-size:11px;color:var(--text-dim);margin-top:2px;"><?= h($entry['custom_fields_display']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--text-dim);"><?= h($entry['materials_used'] ?: '—') ?></td>
          <td><span class="status-chip <?= $statusChip[$entry['status']] ?? 'pending' ?>"><?= $statusLabels[$entry['status']] ?? h($entry['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
