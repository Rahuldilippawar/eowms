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
    $css = '<style>body{font-family:freesans;color:#1A1D29;font-size:8.6pt;}
      .hdr{background:#191C3A;color:#fff;padding:10px 16px;border-bottom:4px solid #D9530F;} .hdr .o{font-size:14pt;font-weight:bold;} .hdr .t{font-size:10.5pt;color:#FDBA8C;}
      table.kp{width:100%;border-collapse:separate;border-spacing:5px;margin:0 -5px;} td.kpi{background:#F7F5F2;border:1px solid #EAE5DF;padding:6px 8px;vertical-align:top;}
      .kl{font-size:7.6pt;color:#64708A;} .kv{font-size:14pt;font-weight:bold;} .ks{font-size:7.2pt;color:#64708A;}
      table.g{width:100%;border-collapse:collapse;font-size:8pt;} table.g th{background:#F3F1EE;color:#475069;text-align:left;padding:4px 5px;border-bottom:1px solid #DDD6CE;font-size:7.6pt;}
      table.g td{padding:4px 5px;border-bottom:1px solid #EEE9E3;vertical-align:top;} .num-r{text-align:right;} .muted{color:#8A90AC;font-size:7.6pt;}
      table.split{width:100%;border-collapse:collapse;} table.split td{vertical-align:top;padding:0;}</style>';
    $html = $css . '<div class="hdr"><div class="o">' . htmlspecialchars($orgName) . '</div><div class="t">MONTHLY E-CAMPUS REPORT — ' . htmlspecialchars(ecampusPeriodLabel($period)) . ' (' . date('m/Y', strtotime($period . '-01')) . ')</div></div>'
        . ecampusPdfSection($pdo, $orgId, $period, 'full')
        . '<div class="muted" style="margin-top:10px;text-align:center;">!! आत्मा मालिक !! — E-Admin · तयार: ' . date('d-m-Y, h:i A') . '</div>';
    $pdf = mgmtReportPdf($html, 'E-Campus Report — ' . $period, $orgName, 'A4-L');
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (($_GET['pdf'] ?? '') === 'dl' ? 'attachment' : 'inline') . '; filename="e-campus-report-' . $period . '.pdf"');
    echo $pdf;
    exit;
}

$rows = ecampusRows($pdo, $orgId, $period);
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
  @media print{ .no-print,.rail-sidebar,.topbar,.mb-nav,footer{display:none !important;} .content{margin:0 !important;padding:0 !important;} }
</style>
<div class="page-eyebrow"><?= h($orgName) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <div class="page-title mb-0">📋 MONTHLY E-CAMPUS REPORT — <?= h(ecampusPeriodLabel($period)) ?></div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <form method="GET"><select name="month" class="form-select-custom" onchange="this.form.submit()"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $period ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></form>
    <a href="?month=<?= h($period) ?>&pdf=1" target="_blank" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
    <a href="?month=<?= h($period) ?>&pdf=dl" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-download me-1"></i>Download</a>
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
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
