<?php
// 🎓 मासिक E-Campus अहवाल — तक्ता (Print) आणि PDF (Landscape) — "MONTHLY E-CAMPUS REPORT" प्रमाणे
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'ecampus_progress', '../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
require_once __DIR__ . '/../includes/daily-report.php';
if (!ecampusCanViewAll()) { header('Location: my.php'); exit; }
$orgId = activeOrgId();
$period = ecampusPeriod($_GET['month'] ?? null);
$orgName = activeOrgName();

// 📚 अनेक महिन्यांचा / वार्षिक अहवाल — ?preset=fy|lastfy|year|last6|last12 किंवा ?from=YYYY-MM&to=YYYY-MM
$presets = ecampusRangePresets();
$rPreset = $_GET['preset'] ?? '';
if (isset($presets[$rPreset])) { [, $rFrom, $rTo] = $presets[$rPreset]; }
else { $rFrom = (string)($_GET['from'] ?? $presets['fy'][1]); $rTo = (string)($_GET['to'] ?? $presets['fy'][2]); }
$rangeMonths = ecampusRangeMonths($rFrom, $rTo);
if (isset($_GET['range_pdf']) || isset($_GET['range_csv'])) {
    if (!$rangeMonths) { http_response_code(400); die('अवैध कालावधी — "पासून" व "पर्यंत" महिना निवडा (जास्तीत जास्त ' . ECAMPUS_RANGE_MAX . ' महिने).'); }
    @set_time_limit(180);
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    if (isset($_GET['range_csv'])) {
        $D = ecampusRangeData($pdo, $orgId, $rangeMonths);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="e-campus-' . $rangeMonths[0] . '-to-' . end($rangeMonths) . '.csv"');
        header('Cache-Control: no-store');
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");
        $cellX = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
        $mLabels = array_map('ecampusPeriodLabel', $rangeMonths);
        fputcsv($f, array_merge(['महिना'], $mLabels));
        fputcsv($f, array_merge(['एकूण प्रगती %'], array_map(fn($p) => $D['sum'][$p]['overall'] ?? '', $rangeMonths)));
        fputcsv($f, []);
        fputcsv($f, array_merge(['#', 'Wing', 'Section', 'कर्मचारी', 'पद', 'काम'], $mLabels, ['सरासरी']));
        $sr = 0;
        foreach ($D['tasks'] as $t) {
            $r = $t['row'];
            $vals = array_values(array_filter($t['m'], fn($v) => $v !== null));
            fputcsv($f, array_merge([++$sr, $cellX($r['wing']), $cellX($r['section']), $cellX($r['staff_name']), $cellX($r['designation_show']), $cellX($r['task_description'])],
                array_map(fn($p) => $t['m'][$p] ?? '', $rangeMonths), [$vals ? (int)round(array_sum($vals) / count($vals)) : '']));
        }
        fclose($f);
        exit;
    }
    $pdf = ecampusRangeReportPdf($pdo, $orgId, $rangeMonths, $orgName, !empty($_GET['detail']));
    header('Content-Type: application/pdf');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Content-Disposition: ' . (($_GET['range_pdf'] ?? '') === 'dl' ? 'attachment' : 'inline') . '; filename="' . ecampusRangeFileName($rangeMonths) . '"');
    echo $pdf;
    exit;
}

if (isset($_GET['pdf'])) {
    @set_time_limit(120);
    $pdf = ecampusReportPdf($pdo, $orgId, $period, $orgName);
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    // नेहमी ताजा अहवाल — Mobile वर आधी Download केलेली जुनी File उघडू नये म्हणून नाव वेळेसह, आणि Cache बंद
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Content-Disposition: ' . (($_GET['pdf'] ?? '') === 'dl' ? 'attachment' : 'inline') . '; filename="' . ecampusReportFileName($period) . '"');
    echo $pdf;
    exit;
}

$rows = ecampusRows($pdo, $orgId, $period);

// 📊 Excel (CSV — UTF-8, मराठी Excel मध्ये बरोबर दिसावं म्हणून BOM सह)
if (isset($_GET['csv'])) {
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="e-campus-' . $period . '.csv"');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Sr. No', 'Wing / Category', 'Section / Department', 'Staff Name', 'Designation', 'Assigned Task', 'Progress Notes', '% Complete', 'Status', 'Last Updated']);
    $cell = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;   // Excel formula injection टाळा
    $ordC = array_keys($rows);
    usort($ordC, fn($x, $y) => [$rows[$x]['wing'], $rows[$x]['section'], $x] <=> [$rows[$y]['wing'], $rows[$y]['section'], $y]);   // कामनिहाय — Wing → Section
    foreach ($ordC as $i => $k) {
        $r = $rows[$k];
        fputcsv($f, [$i + 1, $cell($r['wing']), $cell($r['section']), $cell($r['staff_name']), $cell($r['designation_show']), $cell($r['task_description']), $cell((string)$r['notes']),
            $r['percent'], $r['status_label'], $r['updated_at'] && !$r['carried'] ? date('d-m-Y h:i A', strtotime($r['updated_at'])) : '']);
    }
    $S = ecampusSummary($rows, $period);
    fputcsv($f, []);
    fputcsv($f, ['', '', '', '', '', '', 'एकूण प्रगती', (int)$S['overall'], $S['done'] . '/' . $S['work'] . ' पूर्ण']);
    fclose($f);
    exit;
}
$S = ecampusSummary($rows, $period);
$months = [];
for ($i = 0; $i < 12; $i++) { $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); }

$pageTitle = 'E-Campus मासिक अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .ecr td,.ecr th{font-size:12.5px;vertical-align:top;} .ecr td.t{white-space:pre-line;max-width:320px;} .ecr td.n{max-width:260px;white-space:normal;}
  .ec-st{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;color:#fff;white-space:nowrap;}
  .ecr tr.ecr-g td{background:#191C3A;color:#fff;font-size:12.5px;} .ecr tr.ecr-g td span{color:#FDBA8C;}
  @media print{ @page{size:A4 landscape;margin:10mm;} .no-print,.rail-sidebar,.topbar,.mb-nav,footer,#idleModal,.sos-fab{display:none !important;} .content{margin:0 !important;padding:0 !important;} }
</style>
<div class="page-eyebrow"><?= h($orgName) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <div class="page-title mb-0">📋 MONTHLY E-CAMPUS REPORT — <?= h(ecampusPeriodLabel($period)) ?></div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <form method="GET"><select name="month" class="form-select-custom" onchange="this.form.submit()"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $period ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></form>
    <button type="button" onclick="window.print()" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-print me-1"></i>Print</button>
    <a href="?month=<?= h($period) ?>&pdf=1&v=<?= time() ?>" target="_blank" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
    <a href="?month=<?= h($period) ?>&pdf=dl&v=<?= time() ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-download me-1"></i>Download</a>
    <a href="index.php?month=<?= h($period) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-chart-pie me-1"></i>Dashboard</a>
  </div>
</div>
<?php $allMonths = []; for ($i = 0; $i < 36; $i++) { $allMonths[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); } ?>
<div class="panel mb-3 no-print" id="range">
  <div class="panel-head"><div class="title">📚 अनेक महिन्यांचा / वार्षिक अहवाल</div><div class="sub">एका PDF मध्ये — महिनावार एकूण प्रगती आणि कामनिहाय महिनावार % (जास्तीत जास्त <?= ECAMPUS_RANGE_MAX ?> महिने)</div></div>
  <div style="padding:12px 18px 16px;">
    <div class="ecr-pre">
      <?php foreach ($presets as $k => [$lbl, $pf, $pt]): ?><button type="button" data-from="<?= h($pf) ?>" data-to="<?= h($pt) ?>" class="<?= $pf === $rFrom && $pt === $rTo ? 'on' : '' ?>"><?= h($lbl) ?></button><?php endforeach; ?>
    </div>
    <form method="GET" id="rangeForm" target="_blank" class="d-flex gap-2 flex-wrap align-items-end">
      <div><label class="form-label-custom">पासून</label><select name="from" class="form-select-custom"><?php foreach (array_reverse($allMonths) as $m): ?><option value="<?= $m ?>" <?= $m === $rFrom ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label-custom">पर्यंत</label><select name="to" class="form-select-custom"><?php foreach ($allMonths as $m): ?><option value="<?= $m ?>" <?= $m === $rTo ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></div>
      <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;margin:0 0 9px;"><input type="checkbox" name="detail" value="1"> प्रत्येक महिन्याचा पूर्ण तपशीलही जोडा</label>
      <button type="submit" name="range_pdf" value="1" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF पाहा</button>
      <button type="submit" name="range_pdf" value="dl" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-download me-1"></i>Download</button>
      <button type="submit" name="range_csv" value="1" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</button>
    </form>
    <div id="rangeInfo" style="font-size:12px;color:var(--text-dim);margin-top:6px;"></div>
  </div>
</div>
<style>.ecr-pre{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}.ecr-pre button{border:1px solid var(--line);background:var(--canvas);border-radius:999px;padding:5px 12px;font-size:12.5px;cursor:pointer;}.ecr-pre button.on,.ecr-pre button:hover{border-color:var(--rail);color:var(--rail);}</style>
<script>
(function () {
  var f = document.getElementById('rangeForm'), info = document.getElementById('rangeInfo'), max = <?= ECAMPUS_RANGE_MAX ?>;
  function upd() {
    var a = f.from.value, b = f.to.value; if (a > b) { var t = a; a = b; b = t; }
    var n = (+b.slice(0, 4) - +a.slice(0, 4)) * 12 + (+b.slice(5) - +a.slice(5)) + 1;
    info.textContent = n > max ? '⚠ ' + n + ' महिने निवडले — पहिले ' + max + ' महिनेच अहवालात येतील.' : n + ' महिन्यांचा अहवाल';
  }
  document.querySelectorAll('.ecr-pre button').forEach(function (b) { b.addEventListener('click', function () {
    f.from.value = b.dataset.from; f.to.value = b.dataset.to;
    document.querySelectorAll('.ecr-pre button').forEach(function (x) { x.classList.toggle('on', x === b); }); upd();
  }); });
  f.from.addEventListener('change', upd); f.to.addEventListener('change', upd); upd();
  if (location.hash === '#range') { f.scrollIntoView({ block: 'center' }); }
})();
</script>
<p style="font-size:13px;color:var(--text-dim);">एकूण प्रगती <b><?= (int)$S['overall'] ?>%</b> · <?= (int)$S['done'] ?> पूर्ण · <?= (int)$S['progress'] ?> सुरू · <?= (int)$S['pending'] ?> सुरू नाही</p>
<div class="panel">
  <div style="overflow-x:auto;">
  <table class="rtable ecr">
    <thead><tr><th>Sr.</th><th>Staff Name</th><th>Designation</th><th>Assigned Task</th><th>Progress Notes</th><th>% Complete</th><th>Status</th></tr></thead>
    <tbody>
    <?php
      // कामनिहाय — Wing → Section गटांत
      $ord = array_keys($rows);
      usort($ord, fn($x, $y) => [$rows[$x]['wing'], $rows[$x]['section'], $x] <=> [$rows[$y]['wing'], $rows[$y]['section'], $y]);
      $grp = null; $sr = 0;
      foreach ($ord as $k): $r = $rows[$k]; $g = $r['wing'] . '|' . $r['section'];
        if ($g !== $grp): $grp = $g; ?>
        <tr class="ecr-g"><td colspan="7"><b><?= h($r['wing']) ?></b> <span>· <?= h($r['section']) ?></span></td></tr>
      <?php endif; ?>
      <tr>
        <td><?= ++$sr ?></td><td><b><?= h($r['staff_name']) ?></b></td><td><?= h($r['designation_show']) ?></td>
        <td class="t"><?= h($r['task_description']) ?></td><td class="n"><?= h((string)$r['notes']) ?></td>
        <td><b><?= $r['percent'] ?>%</b></td><td><span class="ec-st" style="background:<?= $r['status_color'] ?>;"><?= h($r['status_label']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">या महिन्यासाठी नोंदी नाहीत.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if (!empty($_GET['print'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
