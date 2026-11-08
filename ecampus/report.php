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
    foreach ($rows as $i => $r) {
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
<p style="font-size:13px;color:var(--text-dim);">एकूण प्रगती <b><?= (int)$S['overall'] ?>%</b> · <?= (int)$S['done'] ?> पूर्ण · <?= (int)$S['progress'] ?> सुरू · <?= (int)$S['pending'] ?> सुरू नाही</p>
<div class="panel">
  <div style="overflow-x:auto;">
  <table class="rtable ecr">
    <thead><tr><th>Sr.</th><th>Wing / Category</th><th>Section / Department</th><th>Staff Name</th><th>Designation</th><th>Assigned Task</th><th>Progress Notes</th><th>% Complete</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= h($r['wing']) ?></td><td><?= h($r['section']) ?></td><td><b><?= h($r['staff_name']) ?></b></td><td><?= h($r['designation_show']) ?></td>
        <td class="t"><?= h($r['task_description']) ?></td><td class="n"><?= h((string)$r['notes']) ?></td>
        <td><b><?= $r['percent'] ?>%</b></td><td><span class="ec-st" style="background:<?= $r['status_color'] ?>;"><?= h($r['status_label']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;">या महिन्यासाठी नोंदी नाहीत.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if (!empty($_GET['print'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
