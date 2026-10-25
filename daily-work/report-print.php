<?php
// छापील (Print/PDF) कामाचा अहवाल — पूर्वीचं reports/view.php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/report-common.php';
$user = currentUser();

$cols = (array)($_GET['cols'] ?? ['heading', 'details', 'requester', 'priority', 'cost', 'materials', 'dates', 'status']);
$colSet = array_flip($cols);

// ---------------- Access control + कालावधी (सामायिक) ----------------
$ctx = dwReportContext($pdo, $user);
$scope = $ctx['scope'];
$period = $ctx['period'];
$start = $ctx['start'];
$end = $ctx['end'];
$periodLabels = dwReportPeriodLabels();

// ---------------- Organization / campus header info ----------------
$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->execute([activeOrgId()]);
$org = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? ORDER BY id LIMIT 1");
$stmt->execute([activeOrgId()]);
$campus = $stmt->fetch();

$addressParts = array_filter([$campus['address'] ?? null, $campus['city'] ?? null, $campus['state'] ?? null]);
$addressLine = implode(', ', $addressParts);

$scopeDept = null;
$headerDeptId = $scope === 'personal' ? (int)($user['department_id'] ?? 0) : (int)$ctx['department_id'];
if ($headerDeptId) {
    $stmt = $pdo->prepare("SELECT d.*, u.name AS hod_name FROM departments d LEFT JOIN users u ON u.id = d.hod_id WHERE d.id = ? AND d.organization_id = ?");
    $stmt->execute([$headerDeptId, activeOrgId()]);
    $scopeDept = $stmt->fetch() ?: null;
}
if ($scope === 'department' && !$scopeDept) {
    http_response_code(404);
    die(t('s553'));
}

$scopeLabels = dwReportScopeLabels();
$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];

// ---------------- Data (नेहमी याच संस्थेचा) ----------------
$personalRecords = [];
$deptRecords = [];
$deptSummary = null;
$orgSummary = [];

if ($scope === 'personal') {
    $personalRecords = dwReportRecords($pdo, $ctx, $user);
    $pStatusCounts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
    foreach ($personalRecords as $r) { $pStatusCounts[$r['status']] = ($pStatusCounts[$r['status']] ?? 0) + 1; }
}

if ($scope === 'department' && $scopeDept) {
    $deptRecords = dwReportRecords($pdo, $ctx, $user);
    $total = count($deptRecords);
    $done = count(array_filter($deptRecords, fn($r) => $r['status'] === 'completed'));
    $requested = count(array_filter($deptRecords, fn($r) => !empty($r['requested_by'])));
    $deptSummary = ['total' => $total, 'done' => $done, 'pct' => $total > 0 ? round(($done / $total) * 100) : 0, 'requested' => $requested, 'routine' => $total - $requested];

    $dStatusCounts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
    foreach ($deptRecords as $r) { $dStatusCounts[$r['status']] = ($dStatusCounts[$r['status']] ?? 0) + 1; }
}

if ($scope === 'organization') {
    $orgSummary = dwReportOrgSummary($pdo, $ctx);
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title><?= h(t('s397')) ?> <?= h($org['name'] ?? 'VJMAT') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --amber:#C97F1D; --rose:#B23A48; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .toolbar .btn.secondary{background:transparent;border:1px solid rgba(255,255,255,.25);color:#fff;}
  .sheet{max-width:850px;margin:24px auto;background:#fff;padding:40px 44px;box-shadow:0 2px 12px rgba(0,0,0,.08);}

  .doc-header{text-align:center;border-bottom:3px solid var(--ink);padding-bottom:20px;margin-bottom:22px;}
  .doc-header .logo-img{width:64px;height:64px;object-fit:contain;margin:0 auto 10px;display:block;}
  .doc-header .mark{width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,var(--rail),#0F5E58);display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Space Grotesk';font-weight:700;font-size:20px;margin:0 auto 10px;}
  .doc-header .org-name{font-family:'Space Grotesk';font-weight:700;font-size:22px;color:var(--ink);}
  .doc-header .org-address{font-size:12px;color:var(--text-dim);margin-top:4px;}
  .doc-header .org-contact{font-size:11px;color:var(--text-dim);margin-top:2px;}
  .doc-header .meta-strip{display:flex;justify-content:center;gap:18px;font-size:11px;color:var(--text-dim);margin-top:12px;padding-top:10px;border-top:1px solid var(--line);}
  .doc-header .meta-strip strong{color:var(--ink);}

  .report-title{font-family:'Space Grotesk';font-weight:700;font-size:20px;color:var(--ink);margin-bottom:2px;}
  .report-sub{font-size:12.5px;color:var(--text-dim);margin-bottom:20px;}
  .report-sub b{color:var(--rail);}

  table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:18px;}
  th{background:#F3F4F7;text-transform:uppercase;font-size:10px;letter-spacing:.04em;color:var(--text-dim);font-weight:700;padding:8px 10px;text-align:left;border-bottom:2px solid var(--line);}
  td{padding:8px 10px;border-bottom:1px solid var(--line);}
  .mono{font-family:'JetBrains Mono',monospace;}
  .chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;}
  .chip.done{background:#DCEFEC;color:var(--rail);}
  .chip.pending{background:#FBEBD6;color:var(--amber);}

  .summary-row{display:flex;gap:14px;margin-bottom:18px;}
  .summary-box{flex:1;border:1px solid var(--line);border-radius:8px;padding:12px 14px;}
  .summary-box .lbl{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;}
  .summary-box .val{font-family:'Space Grotesk';font-weight:700;font-size:20px;color:var(--ink);}

  .doc-footer{margin-top:30px;padding-top:14px;border-top:1px solid var(--line);font-size:10.5px;color:var(--text-dim);display:flex;justify-content:space-between;}

  @media print {
    .toolbar{display:none;
    #eowmsLangSelect, #google_translate_element { display: none !important; }
  }
    body{background:#fff;}
    .sheet{box-shadow:none;margin:0;padding:0;max-width:100%;}
  }
</style>
</head>
<body>
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text-dim);background:#fff;cursor:pointer;">
  <option value="">🌐 भाषा (Language)</option>
  <option value="en">English</option>
  <option value="hi">हिंदी (Hindi)</option>
  <option value="gu">ગુજરાતી (Gujarati)</option>
  <option value="mr">मराठी (मूळ)</option>
</select>
<div id="google_translate_element" style="display:none;"></div>
<style>body > .skiptranslate{display:none !important;} body{top:0 !important;}</style>
<script>
  function googleTranslateElementInit() {
    new google.translate.TranslateElement({ pageLanguage: 'mr', autoDisplay: false }, 'google_translate_element');
  }
  function eowmsChangeLanguage(lang) {
    var tries = 0;
    var trigger = function () {
      var combo = document.querySelector('.goog-te-combo');
      if (combo) {
        combo.value = lang;
        combo.dispatchEvent(new Event('change'));
      } else if (tries < 20) {
        tries++;
        setTimeout(trigger, 250);
      }
    };
    trigger();
  }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>


<div class="toolbar">
  <a href="report.php?<?= h(http_build_query(['scope' => $scope, 'period' => $period, 'department_id' => $ctx['department_id'] ?: null])) ?>" class="btn secondary" style="text-decoration:none;display:inline-block;"><?= h(t('s398')) ?></a>
  <div style="display:flex;gap:8px;">
    <a href="export-csv.php?<?= h($_SERVER['QUERY_STRING'] ?? '') ?>" class="btn secondary" style="text-decoration:none;display:inline-block;"><i class="fa-solid fa-file-excel"></i> Excel (CSV)</a>
    <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> <?= h(t('s399')) ?></button>
  </div>
</div>

<div class="sheet">
  <div class="doc-header">
    <?php if (!empty($org['logo_path'])): ?>
      <img src="../<?= h($org['logo_path']) ?>" alt="Logo" class="logo-img">
    <?php else: ?>
      <div class="mark"><?= h(mb_substr($org['name'] ?? 'EO', 0, 2)) ?></div>
    <?php endif; ?>
    <div class="org-name"><?= h($org['name'] ?? 'VJMAT') ?></div>
    <?php if ($addressLine): ?><div class="org-address"><?= h($addressLine) ?></div><?php endif; ?>
    <?php if (!empty($org['contact_phone']) || !empty($org['contact_email'])): ?>
      <div class="org-contact"><?= h($org['contact_phone'] ?? '') ?> <?= $org['contact_phone'] && $org['contact_email'] ? '·' : '' ?> <?= h($org['contact_email'] ?? '') ?></div>
    <?php endif; ?>
    <div class="meta-strip">
      <div><?= h(t('s400')) ?> <strong><?= h($user['name']) ?></strong> (<?= h($user['designation'] ?? '') ?>)</div>
      <div><?= date('d M Y, h:i A') ?></div>
    </div>
  </div>

  <div class="report-title"><?= h(t('s401')) ?> <?= h($scopeLabels[$scope]) ?></div>
  <div class="report-sub">
    कालावधी: <b><?= h($periodLabels[$period]) ?></b> (<?= date('d M Y', strtotime($start)) ?> — <?= date('d M Y', strtotime($end)) ?>)
    <?php if ($scopeDept && $scope !== 'organization'): ?> <?= t('s402') ?> <b><?= h($scopeDept['name_local'] ?: $scopeDept['name']) ?></b><?php endif; ?>
    <?php if ($scopeDept && !empty($scopeDept['hod_name'])): ?> <?= t('s403') ?> <b><?= h($scopeDept['hod_name']) ?></b><?php endif; ?>
    <?php if ($scope === 'personal'): ?> <?= t('s404') ?> <b><?= h($user['name']) ?></b><?php endif; ?>
  </div>

  <?php if ($scope === 'personal'): ?>
    <?php
      $pTotal = count($personalRecords);
      $pDone = count(array_filter($personalRecords, fn($r) => $r['status'] === 'completed'));
      $pRequested = count(array_filter($personalRecords, fn($r) => !empty($r['requested_by'])));
      $pRoutine = $pTotal - $pRequested;

      // दुसरा Chart — प्राधान्य-निहाय विभाजन (Priority Breakdown)
      $pPriorityCounts = ['low' => 0, 'normal' => 0, 'high' => 0, 'urgent' => 0];
      foreach ($personalRecords as $r) { $pPriorityCounts[$r['priority']] = ($pPriorityCounts[$r['priority']] ?? 0) + 1; }
    ?>
    <div class="summary-row">
      <div class="summary-box"><div class="lbl"><?= h(t('s405')) ?></div><div class="val"><?= $pTotal ?></div></div>
      <div class="summary-box"><div class="lbl"><?= h(t('s31')) ?></div><div class="val"><?= $pDone ?></div></div>
      <div class="summary-box"><div class="lbl"><?= h(t('s7')) ?></div><div class="val"><?= $pTotal - $pDone ?></div></div>
      <div class="summary-box"><div class="lbl">🔁 नियमित काम</div><div class="val"><?= $pRoutine ?></div></div>
      <div class="summary-box"><div class="lbl">📋 विनंतीनुसार</div><div class="val"><?= $pRequested ?></div></div>
    </div>
    <?php if ($pTotal > 0): ?>
    <div style="display:flex;gap:24px;justify-content:center;flex-wrap:wrap;margin-bottom:20px;">
      <div style="flex:1;min-width:260px;max-width:320px;">
        <div style="text-align:center;font-size:12px;font-weight:600;color:#666B7D;margin-bottom:6px;">स्थिती-निहाय विभाजन</div>
        <div style="position:relative;height:220px;"><canvas id="pStatusChart"></canvas></div>
      </div>
      <div style="flex:1;min-width:260px;max-width:320px;">
        <div style="text-align:center;font-size:12px;font-weight:600;color:#666B7D;margin-bottom:6px;">प्राधान्य-निहाय विभाजन</div>
        <div style="position:relative;height:220px;"><canvas id="pPriorityChart"></canvas></div>
      </div>
    </div>
    <?php endif; ?>
    <table>
      <thead><tr>
        <?php if (isset($colSet['dates'])): ?><th><?= h(t('s13')) ?></th><?php endif; ?>
        <th><?= h(t('s1')) ?></th>
        <?php if (isset($colSet['requester'])): ?><th>विनंती करणारा</th><?php endif; ?>
        <?php if (isset($colSet['heading'])): ?><th><?= h(t('s21')) ?></th><?php endif; ?>
        <?php if (isset($colSet['details'])): ?><th>तपशील</th><?php endif; ?>
        <?php if (isset($colSet['priority'])): ?><th><?= h(t('s20')) ?></th><?php endif; ?>
        <?php if (isset($colSet['cost'])): ?><th>खर्च</th><?php endif; ?>
        <?php if (isset($colSet['materials'])): ?><th>वापरलेलं साहित्य</th><?php endif; ?>
        <?php if (isset($colSet['status'])): ?><th><?= h(t('s2')) ?></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if (empty($personalRecords)): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:16px;"><?= h(t('s44')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($personalRecords as $r): ?>
          <tr>
            <?php if (isset($colSet['dates'])): ?><td class="mono"><?= date('d M Y', strtotime($r['work_date'])) ?></td><?php endif; ?>
            <td><?= h($r['dept_name']) ?></td>
            <?php if (isset($colSet['requester'])): ?><td><?= $r['requested_by'] ? h($r['requested_by']) : '<span style="color:var(--text-dim);font-size:11px;">🔁 नियमित</span>' ?></td><?php endif; ?>
            <?php if (isset($colSet['heading'])): ?><td><?= h($r['title']) ?></td><?php endif; ?>
            <?php if (isset($colSet['details'])): ?><td><?= h($r['description'] ?: '—') ?></td><?php endif; ?>
            <?php if (isset($colSet['priority'])): ?><td><?= h(ucfirst($r['priority'])) ?></td><?php endif; ?>
            <?php if (isset($colSet['cost'])): ?><td><?= $r['actual_cost'] ? '₹' . number_format($r['actual_cost'], 0) : ($r['estimated_cost'] ? '₹' . number_format($r['estimated_cost'], 0) . ' (अंदाजित)' : '—') ?></td><?php endif; ?>
            <?php if (isset($colSet['materials'])): ?><td style="font-size:11px;"><?= h($r['materials_used'] ?: '—') ?></td><?php endif; ?>
            <?php if (isset($colSet['status'])): ?><td><span class="chip <?= $statusChip[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($scope === 'department'): ?>
    <div class="summary-row">
      <div class="summary-box"><div class="lbl"><?= h(t('s26')) ?></div><div class="val"><?= $deptSummary['total'] ?></div></div>
      <div class="summary-box"><div class="lbl"><?= h(t('s31')) ?></div><div class="val"><?= $deptSummary['done'] ?></div></div>
      <div class="summary-box"><div class="lbl"><?= h(t('s43')) ?></div><div class="val"><?= $deptSummary['pct'] ?>%</div></div>
      <div class="summary-box"><div class="lbl">🔁 नियमित काम</div><div class="val"><?= $deptSummary['routine'] ?></div></div>
      <div class="summary-box"><div class="lbl">📋 विनंतीनुसार</div><div class="val"><?= $deptSummary['requested'] ?></div></div>
    </div>
    <?php if ($deptSummary['total'] > 0): ?>
    <div style="max-width:320px;margin:0 auto 20px;"><canvas id="dStatusChart" height="180"></canvas></div>
    <?php endif; ?>
    <table>
      <thead><tr>
        <?php if (isset($colSet['dates'])): ?><th><?= h(t('s13')) ?></th><?php endif; ?>
        <?php if (isset($colSet['requester'])): ?><th>विनंती करणारा</th><?php endif; ?>
        <?php if (isset($colSet['heading'])): ?><th><?= h(t('s21')) ?></th><?php endif; ?>
        <?php if (isset($colSet['details'])): ?><th>तपशील</th><?php endif; ?>
        <?php if (isset($colSet['priority'])): ?><th><?= h(t('s20')) ?></th><?php endif; ?>
        <?php if (isset($colSet['cost'])): ?><th>खर्च</th><?php endif; ?>
        <?php if (isset($colSet['materials'])): ?><th>वापरलेलं साहित्य</th><?php endif; ?>
        <?php if (isset($colSet['status'])): ?><th><?= h(t('s2')) ?></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if (empty($deptRecords)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:16px;"><?= h(t('s44')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($deptRecords as $r): ?>
          <tr>
            <?php if (isset($colSet['dates'])): ?><td class="mono"><?= date('d M Y', strtotime($r['work_date'])) ?></td><?php endif; ?>
            <?php if (isset($colSet['requester'])): ?><td><?= $r['requested_by'] ? h($r['requested_by']) : '<span style="color:var(--text-dim);font-size:11px;">🔁 नियमित</span>' ?><?php if ($r['emp_name']): ?><div style="font-size:10px;color:var(--text-dim);">केलं: <?= h($r['emp_name']) ?></div><?php endif; ?></td><?php endif; ?>
            <?php if (isset($colSet['heading'])): ?><td><?= h($r['title']) ?></td><?php endif; ?>
            <?php if (isset($colSet['details'])): ?><td><?= h($r['description'] ?: '—') ?></td><?php endif; ?>
            <?php if (isset($colSet['priority'])): ?><td><?= h(ucfirst($r['priority'])) ?></td><?php endif; ?>
            <?php if (isset($colSet['cost'])): ?><td><?= $r['actual_cost'] ? '₹' . number_format($r['actual_cost'], 0) : ($r['estimated_cost'] ? '₹' . number_format($r['estimated_cost'], 0) . ' (अंदाजित)' : '—') ?></td><?php endif; ?>
            <?php if (isset($colSet['materials'])): ?><td style="font-size:11px;"><?= h($r['materials_used'] ?: '—') ?></td><?php endif; ?>
            <?php if (isset($colSet['status'])): ?><td><span class="chip <?= $statusChip[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($scope === 'organization'): ?>
    <?php
      $oTotal = array_sum(array_column($orgSummary, 'total'));
      $oDone = array_sum(array_column($orgSummary, 'done'));
    ?>
    <div class="summary-row">
      <div class="summary-box"><div class="lbl"><?= h(t('s54')) ?></div><div class="val"><?= count($orgSummary) ?></div></div>
      <div class="summary-box"><div class="lbl"><?= h(t('s26')) ?></div><div class="val"><?= $oTotal ?></div></div>
      <div class="summary-box"><div class="lbl"><?= h(t('s406')) ?></div><div class="val"><?= $oDone ?></div></div>
    </div>
    <?php if (!empty($orgSummary)): ?>
    <div style="margin-bottom:20px;"><canvas id="orgCompareChart" height="90"></canvas></div>
    <?php endif; ?>
    <table>
      <thead><tr><th><?= h(t('s1')) ?></th><th>College/School</th><th><?= h(t('s27')) ?></th><th><?= h(t('s26')) ?></th><th><?= h(t('s42')) ?></th><th><?= h(t('s43')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($orgSummary as $row): ?>
          <tr>
            <td><?= h($row['name']) ?></td>
            <td style="font-size:11px;color:var(--text-dim);"><?= h($row['campus_name'] ?: '🏛️ Trust-स्तरीय') ?></td>
            <td><?= h($row['hod_name']) ?></td>
            <td class="mono"><?= $row['total'] ?></td>
            <td class="mono"><?= $row['done'] ?></td>
            <td class="mono"><?= $row['pct'] ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="doc-footer">
    <div>VJMAT — E-Admin (Smart Administration)</div>
    <div><?= h($org['name'] ?? '') ?> · Generated <?= date('d M Y, h:i A') ?></div>
  </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<?php if ($scope === 'personal' && $pTotal > 0): ?>
<script>
new Chart(document.getElementById('pStatusChart'), {
  type: 'doughnut',
  data: {
    labels: ['प्रलंबित', 'सुरू आहे', 'पूर्ण झाले'],
    datasets: [{
      data: [<?= $pStatusCounts['pending'] ?>, <?= $pStatusCounts['in_progress'] ?>, <?= $pStatusCounts['completed'] ?>],
      backgroundColor: ['#C97F1D', '#3B6FC9', '#1B8A83'], borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10 } } }, cutout: '60%' }
});
new Chart(document.getElementById('pPriorityChart'), {
  type: 'bar',
  data: {
    labels: ['कमी', 'सर्वसाधारण', 'जास्त', 'तातडीचं'],
    datasets: [{
      data: [<?= $pPriorityCounts['low'] ?>, <?= $pPriorityCounts['normal'] ?>, <?= $pPriorityCounts['high'] ?>, <?= $pPriorityCounts['urgent'] ?>],
      backgroundColor: ['#8A90AC', '#3B6FC9', '#C97F1D', '#B23A48'], borderRadius: 6, maxBarThickness: 46
    }]
  },
  options: {
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { family: "'Noto Sans Devanagari'", size: 11 } } },
      y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
    }
  }
});
</script>
<?php endif; ?>

<?php if ($scope === 'department' && $deptSummary['total'] > 0): ?>
<script>
new Chart(document.getElementById('dStatusChart'), {
  type: 'doughnut',
  data: {
    labels: ['प्रलंबित', 'सुरू आहे', 'पूर्ण झाले'],
    datasets: [{
      data: [<?= $dStatusCounts['pending'] ?>, <?= $dStatusCounts['in_progress'] ?>, <?= $dStatusCounts['completed'] ?>],
      backgroundColor: ['#C97F1D', '#3B6FC9', '#1B8A83'], borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: { plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10 } } }, cutout: '60%' }
});
</script>
<?php endif; ?>

<?php if ($scope === 'organization' && !empty($orgSummary)): ?>
<script>
new Chart(document.getElementById('orgCompareChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($orgSummary, 'name'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: 'एकूण', data: <?= json_encode(array_map('intval', array_column($orgSummary, 'total'))) ?>, backgroundColor: '#D7DAE0', borderRadius: 5, maxBarThickness: 30 },
      { label: 'पूर्ण', data: <?= json_encode(array_map('intval', array_column($orgSummary, 'done'))) ?>, backgroundColor: '#1B8A83', borderRadius: 5, maxBarThickness: 30 }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10 } } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { family: "'Noto Sans Devanagari'", size: 11 } } },
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#EEF0F4' } }
    }
  }
});
</script>
<?php endif; ?>

</body>
</html>
