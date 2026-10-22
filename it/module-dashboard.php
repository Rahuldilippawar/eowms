<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'IT विभाग — Dashboard';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/it-helpers.php';
requireItAccess($pdo, $user);

$labCountStmt = $pdo->prepare("SELECT COUNT(*) c FROM it_labs WHERE organization_id = ?");
$labCountStmt->execute([$orgId]);
$labCount = $labCountStmt->fetch()['c'];

$assetStmt = $pdo->prepare("
    SELECT COUNT(*) AS total, SUM(power_status = 'on') AS power_on, SUM(status = 'faulty') AS faulty
    FROM it_assets ia JOIN it_labs il ON il.id = ia.lab_id WHERE il.organization_id = ?
");
$assetStmt->execute([$orgId]);
$assetStats = $assetStmt->fetch();

$openTicketsStmt = $pdo->prepare("
    SELECT COUNT(*) c FROM it_repair_tickets rt JOIN it_assets ia ON ia.id = rt.asset_id
    JOIN it_labs il ON il.id = ia.lab_id WHERE il.organization_id = ? AND rt.status != 'resolved'
");
$openTicketsStmt->execute([$orgId]);
$openTickets = $openTicketsStmt->fetch()['c'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🖥️ IT विभाग — Dashboard</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:24px;">Labs, Computers, Smart Boards, Repair Tickets — सगळं एका नजरेत</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">Labs</div><div class="val"><?= $labCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Assets</div><div class="val"><?= $assetStats['total'] ?? 0 ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🟢 सध्या चालू</div><div class="val" style="color:var(--rail);"><?= $assetStats['power_on'] ?? 0 ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔧 Pending Tickets</div><div class="val" style="color:<?= $openTickets > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $openTickets ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <a href="labs.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-desktop" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">Labs व Assets</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">PC, Smart Board, Power Status</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="smartboard-report.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-chalkboard" style="font-size:22px;color:var(--amber);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">Smart Board Report</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Warranty, तक्रारी</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="general-requests-manage.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-headset" style="font-size:22px;color:var(--rose);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">General IT Requests</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Internet/इतर Requests</div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
