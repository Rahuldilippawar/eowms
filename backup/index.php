<?php
$pageTitle = 'बॅकअप व रिस्टोर';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$orgName = activeOrgName();

// Quick counts to show what's currently in this organization —
// संपूर्ण Project च्या सगळ्या मुख्य Modules साठी.
$counts = [];
foreach ([
    'campuses' => "SELECT COUNT(*) c FROM campuses WHERE organization_id = ?",
    'departments' => "SELECT COUNT(*) c FROM departments WHERE organization_id = ?",
    'users' => "SELECT COUNT(*) c FROM users WHERE organization_id = ?",
    'work_records' => "SELECT COUNT(*) c FROM work_records WHERE organization_id = ?",
    'tasks' => "SELECT COUNT(*) c FROM tasks WHERE organization_id = ?",
    'work_requests' => "SELECT COUNT(*) c FROM work_requests WHERE organization_id = ?",
    'renewal_items' => "SELECT COUNT(*) c FROM renewal_items WHERE organization_id = ?",
    'letters' => "SELECT COUNT(*) c FROM letters WHERE organization_id = ?",
    'announcements' => "SELECT COUNT(*) c FROM announcements WHERE organization_id = ?",
    'documents' => "SELECT COUNT(*) c FROM documents WHERE organization_id = ?",
    'emergency_alerts' => "SELECT COUNT(*) c FROM emergency_alerts WHERE organization_id = ?",
    'vehicles' => "SELECT COUNT(*) c FROM vehicles WHERE organization_id = ?",
    'it_assets' => "SELECT COUNT(*) c FROM it_assets WHERE organization_id = ?",
    'dead_stock_items' => "SELECT COUNT(*) c FROM dead_stock_items WHERE organization_id = ?",
    'cctv_dvrs' => "SELECT COUNT(*) c FROM cctv_dvrs WHERE organization_id = ?",
    'residency_occupants' => "SELECT COUNT(*) c FROM residency_occupants WHERE organization_id = ? AND status = 'active'",
    'residency_bills' => "SELECT COUNT(*) c FROM residency_bills WHERE organization_id = ?",
    'music_students' => "SELECT COUNT(*) c FROM music_students WHERE organization_id = ? AND is_active = 1",
    'music_instruments' => "SELECT COUNT(*) c FROM music_instruments WHERE organization_id = ? AND is_active = 1",
    'hostels' => "SELECT COUNT(*) c FROM hostels WHERE organization_id = ?",
    'sops' => "SELECT COUNT(*) c FROM sops WHERE organization_id = ?",
] as $key => $q) {
    try {
        $stmt = $pdo->prepare($q);
        $stmt->execute([$orgId]);
        $counts[$key] = $stmt->fetch()['c'];
    } catch (PDOException $e) {
        $counts[$key] = '—';
    }
}
?>

<div class="page-eyebrow"><?= h($orgName) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s365')) ?></div>
  <a href="scheduled.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-clock-rotate-left me-1"></i>⏰ Automated Backup</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h(t('s366')) ?><b><?= h($orgName) ?></b><?= h(t('s367')) ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-4">
  <div class="panel-head"><div class="title">📊 सध्या या Organization मध्ये किती डेटा आहे</div><div class="sub">Backup घेण्याआधी एक झलक — सगळे मुख्य Modules</div></div>
  <div style="padding:18px 22px;">

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">🏢 मूलभूत रचना</div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl"><?= h(t('s8')) ?></div><div class="val"><?= $counts['campuses'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl"><?= h(t('s1')) ?></div><div class="val"><?= $counts['departments'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl"><?= h(t('s6')) ?></div><div class="val"><?= $counts['users'] ?></div></div></div>
    </div>

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">📋 दैनंदिन काम व कामकाज</div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl"><?= h(t('s28')) ?></div><div class="val"><?= $counts['work_records'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl"><?= h(t('s14')) ?></div><div class="val"><?= $counts['tasks'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">कामाच्या विनंत्या</div><div class="val"><?= $counts['work_requests'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">पत्र व्यवहार</div><div class="val"><?= $counts['letters'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">SOP</div><div class="val"><?= $counts['sops'] ?></div></div></div>
    </div>

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">🛡️ अनुपालन</div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl"><?= h(t('s29')) ?></div><div class="val"><?= $counts['renewal_items'] ?></div></div></div>
    </div>

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">📢 संवाद व सुरक्षा</div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">परिपत्रकं</div><div class="val"><?= $counts['announcements'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">दस्तऐवज</div><div class="val"><?= $counts['documents'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">आणीबाणी सूचना</div><div class="val"><?= $counts['emergency_alerts'] ?></div></div></div>
    </div>

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">🏢 मालमत्ता</div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">वाहनं</div><div class="val"><?= $counts['vehicles'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">IT Assets</div><div class="val"><?= $counts['it_assets'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">Dead Stock</div><div class="val"><?= $counts['dead_stock_items'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">CCTV DVR</div><div class="val"><?= $counts['cctv_dvrs'] ?></div></div></div>
    </div>

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">🏠 रहिवासी विभाग</div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">सध्याचे रहिवासी</div><div class="val"><?= $counts['residency_occupants'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">निवास-बिलं</div><div class="val"><?= $counts['residency_bills'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">वसतिगृहं</div><div class="val"><?= $counts['hostels'] ?></div></div></div>
    </div>

    <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">🎵 संगीत विभाग</div>
    <div class="row g-3">
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">विद्यार्थी</div><div class="val"><?= $counts['music_students'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">वाद्यं</div><div class="val"><?= $counts['music_instruments'] ?></div></div></div>
    </div>

  </div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="panel h-100">
      <div style="padding:24px;">
        <i class="fa-solid fa-download" style="font-size:24px;color:var(--rail);margin-bottom:12px;display:block;"></i>
        <div class="font-display" style="font-weight:600;font-size:16px;margin-bottom:6px;"><?= h(t('s368')) ?></div>
        <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= h(t('s369')) ?></p>
        <a href="export.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;display:inline-block;"><?= h(t('s370')) ?></a>
        <a href="export-full.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:12px;padding:8px 16px;font-weight:600;display:inline-block;margin-top:8px;">📎 Full Backup (Files सकट)</a>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:6px;">Logo, पत्रांची Attachments, Classroom Material, Photos — सगळं प्रत्यक्ष File सकट (मोठी File, वेळ लागू शकतो)</div>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="panel h-100">
      <div style="padding:24px;">
        <i class="fa-solid fa-upload" style="font-size:24px;color:var(--rail);margin-bottom:12px;display:block;"></i>
        <div class="font-display" style="font-weight:600;font-size:16px;margin-bottom:6px;"><?= h(t('s371')) ?></div>
        <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= h(t('s372')) ?></p>
        <a href="restore.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;display:inline-block;"><?= h(t('s373')) ?></a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="panel h-100" style="border-color:var(--rose);">
      <div style="padding:24px;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:24px;color:var(--rose);margin-bottom:12px;display:block;"></i>
        <div class="font-display" style="font-weight:600;font-size:16px;margin-bottom:6px;color:var(--rose);"><?= h(t('s374')) ?></div>
        <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= h(t('s375')) ?> <b><?= h(t('s376')) ?></b> <?= h(t('s377')) ?></p>
        <a href="clear.php" class="btn" style="background:var(--rose);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;display:inline-block;"><?= h(t('s378')) ?></a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
