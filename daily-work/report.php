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
$stats = dwReportStats($pdo, $ctx, $user);
$K = $stats['k'];
$kTotal = (int)($K['total'] ?? 0); $kDone = (int)($K['done'] ?? 0);
$kPct = $kTotal ? round($kDone * 100 / $kTotal) : 0;
$summaryShown = $summary;
usort($summaryShown, function ($a, $b) { return $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']); });
$summaryActive = array_values(array_filter($summaryShown, function ($r) { return $r['total'] > 0; }));
$summaryZero = array_values(array_filter($summaryShown, function ($r) { return $r['total'] == 0; }));
$sumMax = $summaryActive ? max(array_column($summaryActive, 'total')) : 1;
$trendMax = max(1, max(array_column($stats['trend'] ?: [['c' => 0]], 'c')));
$priLabels = ['urgent' => 'तातडीचे', 'high' => 'जास्त'];
$rangeLbl = $start === $end ? date('d M Y', strtotime($start)) : date('d M', strtotime($start)) . ' — ' . date('d M Y', strtotime($end));
if ($period === 'all') { $rangeLbl = 'सुरुवातीपासून — ' . date('d M Y', strtotime($end)); }
$byDate = [];
foreach ($entries as $e) { $byDate[$e['work_date']][] = $e; }
$cnt = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
foreach ($entries as $e) { if (isset($cnt[$e['status']])) $cnt[$e['status']]++; }
$dayLbl = function ($d) {
    if ($d === date('Y-m-d')) return 'आज';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'काल';
    return date('d M Y', strtotime($d));
};
?>
<style>
  .dr-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
  .dr-act{display:flex;gap:6px;flex-wrap:wrap;}
  .dr-act a,.dr-act button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;white-space:nowrap;}
  .dr-act .pri{background:var(--rail);color:#fff;border-color:var(--rail);}
  .dr-f{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:10px;margin-bottom:14px;display:flex;flex-direction:column;gap:8px;}
  .dr-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .dr-seg{display:flex;width:fit-content;background:var(--canvas);border-radius:10px;padding:3px;gap:3px;}
  .dr-seg a{padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--text-dim);text-decoration:none;white-space:nowrap;}
  .dr-seg a.on{background:var(--surface);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12);}
  .dr-per{display:flex;gap:5px;overflow-x:auto;scrollbar-width:none;}
  .dr-per::-webkit-scrollbar{display:none;}
  .dr-per a{flex:0 0 auto;padding:6px 12px;border:1px solid var(--line);border-radius:999px;font-size:12px;font-weight:600;color:var(--text-dim);text-decoration:none;}
  .dr-per a.on{background:var(--ink);color:#fff;border-color:var(--ink);}
  .dr-range{font-size:11.5px;color:var(--text-dim);margin-left:auto;white-space:nowrap;}
  .dr-f form{display:flex;gap:6px;flex-wrap:wrap;}
  .dr-f form select{width:auto;max-width:100%;font-size:12.5px !important;padding:7px 30px 7px 10px !important;}
  .dr-k{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:14px;}
  .dr-k > div{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 12px;min-width:0;}
  .dr-k b{display:block;font-size:20px;line-height:1.15;font-weight:800;}
  .dr-k span{font-size:11px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;}
  .dr-k .pbar{height:4px;border-radius:4px;background:var(--canvas);margin-top:6px;overflow:hidden;}
  .dr-k .pbar i{display:block;height:100%;background:#16A34A;}
  .dr-hot{display:flex;align-items:center;gap:8px;background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:10px;padding:8px 12px;font-size:12.5px;font-weight:600;margin-bottom:14px;}
  .dr-trend{display:flex;align-items:flex-end;gap:3px;height:110px;padding:14px 16px 0;}
  .dr-trend div{flex:1;min-width:3px;display:flex;flex-direction:column;justify-content:flex-end;height:100%;}
  .dr-trend i{display:block;border-radius:3px 3px 0 0;background:#93C5FD;}
  .dr-trend i.d{background:#16A34A;border-radius:0;}
  .dr-trend div.today i{outline:2px solid var(--ink);}
  .dr-tl{display:flex;justify-content:space-between;font-size:10.5px;color:var(--text-dim);padding:4px 16px 12px;}
  .dr-lg{display:flex;gap:12px;font-size:11px;color:var(--text-dim);}
  .dr-lg i{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:4px;vertical-align:-1px;}
  .dr-top{padding:8px 14px 12px;}
  .dr-top .r{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:12.5px;border-bottom:1px dashed var(--line);}
  .dr-top .r:last-child{border-bottom:0;}
  .dr-top .r .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .dr-top .r .m{width:22px;height:22px;border-radius:50%;background:var(--canvas);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex:0 0 22px;}
  .dr-d{display:block;padding:9px 16px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;}
  .dr-d:hover{background:var(--canvas);}
  .dr-d .t{display:flex;align-items:center;gap:8px;font-size:13px;}
  .dr-d .t .n{flex:1;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .dr-d .t .n small{font-weight:400;color:var(--text-dim);font-size:11px;margin-left:4px;}
  .dr-d .bar{height:6px;border-radius:6px;background:var(--canvas);margin-top:6px;overflow:hidden;display:flex;}
  .dr-d .bar i{display:block;height:100%;}
  .dr-pc{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;}
  .dr-pc.g{background:#DCFCE7;color:#15803D;} .dr-pc.a{background:#FEF3C7;color:#B45309;} .dr-pc.r{background:#FEE2E2;color:#B91C1C;}
  .dr-zero summary{padding:10px 16px;font-size:12px;color:var(--text-dim);cursor:pointer;}
  .dr-zero div{padding:0 16px 12px;font-size:12px;color:var(--text-dim);line-height:1.8;}
  .dr-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--line);}
  .dr-tools input{flex:1 1 200px;min-width:0;font-size:13px !important;padding:8px 12px !important;}
  .dr-sf{display:flex;gap:5px;overflow-x:auto;scrollbar-width:none;}
  .dr-sf button{flex:0 0 auto;border:1px solid var(--line);background:var(--surface);border-radius:999px;font-size:12px;font-weight:600;padding:6px 11px;color:var(--text-dim);}
  .dr-sf button.on{background:var(--ink);color:#fff;border-color:var(--ink);}
  .dr-sf button em{font-style:normal;opacity:.7;margin-left:3px;}
  .dr-day{position:sticky;top:0;z-index:1;background:var(--canvas);padding:6px 16px;font-size:11.5px;font-weight:700;color:var(--text-dim);display:flex;justify-content:space-between;border-bottom:1px solid var(--line);}
  .dr-e{display:flex;gap:10px;padding:10px 16px;border-bottom:1px solid var(--line);}
  .dr-e .dot{width:4px;border-radius:4px;flex:0 0 4px;}
  .dr-e.completed .dot{background:#16A34A;} .dr-e.in_progress .dot{background:#2563EB;} .dr-e.pending .dot{background:#F59E0B;}
  .dr-e .b{flex:1;min-width:0;}
  .dr-e .tt{font-size:13.5px;font-weight:600;line-height:1.35;}
  .dr-e .mt{font-size:11.5px;color:var(--text-dim);margin-top:3px;display:flex;flex-wrap:wrap;gap:4px 12px;}
  .dr-e .ex{font-size:11px;color:var(--text-dim);margin-top:4px;}
  .dr-e .ex span{display:inline-block;background:var(--canvas);border-radius:6px;padding:2px 7px;margin:2px 4px 0 0;}
  .dr-e .sd{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-end;gap:4px;}
  .dr-pr{font-size:10.5px;font-weight:700;color:#DC2626;}
  .dr-pr.high{color:#EA580C;}
  .dr-empty{text-align:center;color:var(--text-dim);padding:28px 16px;font-size:13px;}
  .dr-k.k5{grid-template-columns:repeat(5,1fr);}
  @media (max-width:991px){ .dr-k,.dr-k.k5{grid-template-columns:repeat(3,1fr);} }
  @media (max-width:767px){ .dr-k.k5{grid-template-columns:repeat(6,1fr);} .dr-k.k5 > div{grid-column:span 2;} .dr-k.k5 > div:nth-child(n+4){grid-column:span 3;} }
  @media (max-width:767px){
    .dr-head .page-title{font-size:20px;}
    .dr-act{width:100%;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;}
    .dr-act a,.dr-act button{flex:0 0 auto;}
    .dr-range{margin-left:0;}
    .dr-k{gap:6px;}
    .dr-k > div{padding:8px 10px;}
    .dr-k b{font-size:17px;}
    .dr-seg{width:100%;} .dr-seg a{flex:1;text-align:center;padding:7px 4px;font-size:12px;}
    .dr-f form{width:100%;} .dr-f form select{flex:1;min-width:0;}
    .dr-e{padding:10px 12px;} .dr-tools{padding:10px 12px;} .dr-day{padding:6px 12px;}
  }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="dr-head">
  <div class="page-title mb-0"><?= h(t('s231')) ?></div>
  <div class="dr-act">
    <button type="button" class="pri" onclick="var p=document.getElementById('printOpts');p.hidden=!p.hidden;">🖨️ Print / PDF</button>
    <a href="export-csv.php?<?= h($qs([])) ?>">📊 Excel</a>
    <a href="material-usage-report.php">📦 साहित्य वापर</a>
  </div>
</div>

<div class="mode-tabs mb-3" style="display:flex;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:5px;width:fit-content;">
  <a href="entry.php" class="mode-tab" style="padding:8px 18px;border-radius:9px;font-size:13px;font-weight:600;color:var(--text-dim);text-decoration:none;"><?= h(t('s89')) ?></a>
  <a href="report.php" class="mode-tab active" style="padding:8px 18px;border-radius:9px;font-size:13px;font-weight:600;background:var(--ink);color:#fff;text-decoration:none;"><?= h(t('s90')) ?></a>
</div>

<div class="dr-f">
  <?php if (count($ctx['allowed_scopes']) > 1): ?>
  <div class="dr-seg">
    <?php foreach ($ctx['allowed_scopes'] as $s): ?>
      <a href="?<?= h($qs(['scope' => $s])) ?>" class="<?= $scope === $s ? 'on' : '' ?>"><?= $scopeLabels[$s] ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="dr-row">
    <div class="dr-per">
      <?php foreach (dwReportPeriodLabels() as $p => $lbl): ?>
        <a href="?<?= h($qs(['period' => $p])) ?>" class="<?= $period === $p ? 'on' : '' ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <span class="dr-range">📅 <?= h($rangeLbl) ?></span>
  </div>
  <?php if ($scope !== 'personal' && !$ctx['dept_locked']): ?>
  <form method="GET" data-no-smart-filter>
    <input type="hidden" name="period" value="<?= h($period) ?>">
    <input type="hidden" name="scope" value="<?= h($scope) ?>">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_dwRep(this.value)" aria-label="College/School">
        <option value="">🏫 College/School</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" onchange="this.form.submit()" aria-label="<?= h(t('s1')) ?>">
      <?php if ($scope === 'organization'): ?><option value="">🏢 <?= h(t('s18')) ?></option><?php endif; ?>
      <?php foreach ($departments as $dept): ?>
        <option value="<?= $dept['id'] ?>" <?= (int)$filterDeptId === (int)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($scope === 'organization' && $filterDeptId): ?>
      <a href="?<?= h($qs(['department_id' => null])) ?>" style="align-self:center;font-size:12px;font-weight:600;color:var(--rail);text-decoration:none;">✕ सर्व विभाग</a>
    <?php endif; ?>
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
</div>

<div id="printOpts" class="panel mb-3" hidden>
  <form method="GET" action="report-print.php" target="_blank" style="padding:14px 16px;">
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

<div class="dr-k<?= ($K['cost'] ?? 0) > 0 ? '' : ' k5' ?>">
  <div><b><?= $kTotal ?></b><span>एकूण नोंदी</span></div>
  <div><b style="color:#16A34A;"><?= $kPct ?>%</b><span>पूर्ण (<?= $kDone ?>)</span><div class="pbar"><i style="width:<?= $kPct ?>%"></i></div></div>
  <div><b style="color:#2563EB;"><?= (int)($K['prog'] ?? 0) ?></b><span>🔧 सुरू आहे</span></div>
  <div><b style="color:#D97706;"><?= (int)($K['pend'] ?? 0) ?></b><span>⏳ प्रलंबित</span></div>
  <div><b><?= (int)$K['staff'] ?></b><span>👥 कर्मचारी</span></div>
  <?php if (($K['cost'] ?? 0) > 0): ?><div><b>₹<?= number_format((float)$K['cost']) ?></b><span>💰 खर्च</span></div><?php endif; ?>
</div>

<?php if (($K['hot'] ?? 0) > 0): ?>
  <div class="dr-hot">🔥 <?= (int)$K['hot'] ?> तातडीची / जास्त प्राधान्याची कामं अजून पूर्ण नाहीत</div>
<?php endif; ?>

<?php $showTrend = count($stats['trend']) > 1 && $kTotal > 0; $showTop = !empty($stats['top']); ?>
<?php if ($showTrend || $showTop): ?>
<div class="row g-3 mb-3">
  <?php if ($showTrend): ?>
  <div class="<?= $showTop ? 'col-lg-8' : 'col-12' ?>">
    <div class="panel h-100 mb-0">
      <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="title">📈 <?= $stats['monthly'] ? 'महिन्यागणिक नोंदी' : 'दिवसागणिक नोंदी' ?></div>
        <div class="dr-lg"><span><i style="background:#16A34A"></i>पूर्ण</span><span><i style="background:#93C5FD"></i>बाकी</span></div>
      </div>
      <div class="dr-trend">
        <?php foreach ($stats['trend'] as $tr): $hTot = $tr['c'] / $trendMax * 100; $hD = $tr['c'] ? $hTot * $tr['d'] / $tr['c'] : 0; ?>
          <div class="<?= $tr['k'] === date('Y-m-d') ? 'today' : '' ?>" title="<?= h($tr['l']) ?>: <?= $tr['c'] ?> (पूर्ण <?= $tr['d'] ?>)">
            <i style="height:<?= round($hTot - $hD, 1) ?>%"></i><i class="d" style="height:<?= round($hD, 1) ?>%"></i>
          </div>
        <?php endforeach; ?>
      </div>
      <?php $tf = $stats['trend']; $tc = count($tf); ?>
      <div class="dr-tl"><span><?= h($tf[0]['l']) ?></span><?php if ($tc > 2): ?><span><?= h($tf[intdiv($tc, 2)]['l']) ?></span><?php endif; ?><span><?= h($tf[$tc - 1]['l']) ?></span></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($showTop): ?>
  <div class="<?= $showTrend ? 'col-lg-4' : 'col-12' ?>">
    <div class="panel h-100 mb-0">
      <div class="panel-head"><div class="title">🏅 सर्वाधिक काम</div></div>
      <div class="dr-top">
        <?php foreach ($stats['top'] as $i => $tp): ?>
          <div class="r"><span class="m"><?= $i + 1 ?></span><span class="n"><?= h($tp['name']) ?></span><b><?= (int)$tp['c'] ?></b><span style="font-size:11px;color:var(--text-dim);">✓<?= (int)$tp['d'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($scope !== 'personal' && count($summary) > 1): ?>
<div class="panel mb-3">
  <div class="panel-head d-flex justify-content-between align-items-center"><div class="title">🏢 <?= h(t('s235')) ?></div><div class="sub"><?= count($summaryActive) ?> विभागांत नोंदी</div></div>
  <?php foreach ($summaryActive as $row):
    $pct = $row['pct'];
    $pc = $pct >= 85 ? 'g' : ($pct >= 65 ? 'a' : 'r');
  ?>
    <a class="dr-d" href="?<?= h($qs(['scope' => 'organization', 'department_id' => $row['id']])) ?>">
      <div class="t"><span class="n"><?= h($row['name']) ?><?php if (!empty($row['campus_name'])): ?><small><?= h($row['campus_name']) ?></small><?php endif; ?></span>
        <span style="font-size:12px;color:var(--text-dim);"><?= $row['done'] ?>/<?= $row['total'] ?></span><span class="dr-pc <?= $pc ?>"><?= $pct ?>%</span></div>
      <div class="bar" style="width:<?= max(6, round($row['total'] / $sumMax * 100)) ?>%"><i style="width:<?= $pct ?>%;background:#16A34A;"></i><i style="flex:1;background:#BFDBFE;"></i></div>
    </a>
  <?php endforeach; ?>
  <?php if (!$summaryActive): ?><div class="dr-empty"><?= h(t('s44')) ?></div><?php endif; ?>
  <?php if ($summaryZero): ?>
    <details class="dr-zero"><summary>💤 <?= count($summaryZero) ?> विभागांत या कालावधीत एकही नोंद नाही</summary>
      <div><?= implode(' · ', array_map(function ($r) { return h($r['name']); }, $summaryZero)) ?></div></details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" id="drList">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-1">
    <div class="title">📋 <?= h(t('s236')) ?></div>
    <div class="sub"><?= count($entries) < $kTotal ? 'अलीकडच्या ' . count($entries) . ' / ' . $kTotal . ' — पूर्ण यादी Excel/PDF मध्ये' : count($entries) . ' नोंदी' ?></div>
  </div>
  <?php if ($entries): ?>
  <div class="dr-tools">
    <input type="search" id="drQ" class="form-control-custom" placeholder="🔍 काम, कर्मचारी, विभाग शोधा…" autocomplete="off">
    <div class="dr-sf" id="drSf">
      <button type="button" data-s="" class="on">सर्व<em><?= count($entries) ?></em></button>
      <button type="button" data-s="pending">⏳ प्रलंबित<em><?= $cnt['pending'] ?></em></button>
      <button type="button" data-s="in_progress">🔧 सुरू<em><?= $cnt['in_progress'] ?></em></button>
      <button type="button" data-s="completed">✅ पूर्ण<em><?= $cnt['completed'] ?></em></button>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!$entries): ?>
    <div class="dr-empty">📭 <?= h(t('s44')) ?><div style="margin-top:10px;"><a href="entry.php" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">＋ नवीन नोंद</a></div></div>
  <?php endif; ?>
  <?php foreach ($byDate as $d => $list): ?>
    <div class="dr-grp">
      <div class="dr-day"><span><?= h($dayLbl($d)) ?></span><span><?= count($list) ?></span></div>
      <?php foreach ($list as $entry):
        $deptN = $entry['dept_name'] ?: $entry['dept_name_en'];
        $hay = mb_strtolower($entry['title'] . ' ' . $entry['emp_name'] . ' ' . $deptN . ' ' . $entry['requested_by'] . ' ' . $entry['description']);
      ?>
        <div class="dr-e <?= h($entry['status']) ?>" data-s="<?= h($entry['status']) ?>" data-q="<?= h($hay) ?>">
          <span class="dot"></span>
          <div class="b">
            <div class="tt"><?= h($entry['title']) ?></div>
            <div class="mt">
              <?php if ($scope !== 'department' || !$filterDeptId): ?><span>🏢 <?= h($deptN) ?></span><?php endif; ?>
              <?php if ($entry['emp_name']): ?><span>👥 <?= h($entry['emp_name']) ?></span><?php endif; ?>
              <span><?= $entry['requested_by'] ? '🙋 ' . h($entry['requested_by']) : '🔁 नियमित' ?></span>
              <?php if ((float)$entry['actual_cost'] > 0): ?><span>💰 ₹<?= number_format((float)$entry['actual_cost']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($entry['materials_used']) || !empty($entry['custom_fields_display'])): ?>
              <div class="ex">
                <?php if (!empty($entry['materials_used'])): ?><span>📦 <?= h($entry['materials_used']) ?></span><?php endif; ?>
                <?php if (!empty($entry['custom_fields_display'])): ?><span><?= h($entry['custom_fields_display']) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="sd">
            <span class="status-chip <?= $statusChip[$entry['status']] ?? 'pending' ?>"><?= $statusLabels[$entry['status']] ?? h($entry['status']) ?></span>
            <?php if (isset($priLabels[$entry['priority']]) && $entry['status'] !== 'completed'): ?><span class="dr-pr <?= h($entry['priority']) ?>">● <?= $priLabels[$entry['priority']] ?></span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <div class="dr-empty" id="drNone" hidden>कोणतीही नोंद जुळली नाही.</div>
  <div id="drMoreWrap" style="text-align:center;padding:12px;" hidden><button type="button" id="drMore" class="btn" style="border:1px solid var(--line);border-radius:10px;font-size:13px;font-weight:600;padding:8px 22px;">आणखी नोंदी दाखवा ▾</button></div>
</div>

<script>
(function () {
  var q = document.getElementById('drQ'), sf = document.getElementById('drSf'); if (!q) return;
  var st = '', limit = 25, more = document.getElementById('drMoreWrap');
  function run() {
    var term = q.value.trim().toLowerCase(), shown = 0, hiddenByLimit = 0;
    document.querySelectorAll('#drList .dr-grp').forEach(function (g) {
      var vis = 0;
      g.querySelectorAll('.dr-e').forEach(function (e) {
        var ok = (!st || e.getAttribute('data-s') === st) && (!term || e.getAttribute('data-q').indexOf(term) !== -1);
        if (ok && shown >= limit) { ok = false; hiddenByLimit++; }
        e.style.display = ok ? '' : 'none'; if (ok) { vis++; shown++; }
      });
      g.style.display = vis ? '' : 'none';
    });
    document.getElementById('drNone').hidden = shown > 0 || hiddenByLimit > 0;
    more.hidden = !hiddenByLimit;
  }
  document.getElementById('drMore').addEventListener('click', function () { limit += 50; run(); });
  run();
  q.addEventListener('input', run);
  sf.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    st = b.getAttribute('data-s');
    sf.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
    run();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
