<?php
$pageTitle = 'संगीत विभाग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// संगीत विभागाचाच Staff, किंवा 'manage_music' Permission असलेला, किंवा
// Management-श्रेणीतला — यांनाच हे Overview Page सुद्धा दिसेल (बाकी सगळ्या
// Module Index Pages सारखीच, Consistent Access-Control Pattern).
$musicDeptCheckIdx = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$musicDeptCheckIdx->execute([$user['department_id'] ?? 0]);
$isMusicDeptIdx = ($musicDeptCheckIdx->fetch()['code'] ?? '') === 'MUS';
if (!$isMusicDeptIdx && !hasPermission('manage_music') && !isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला संगीत विभाग पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$orgId = activeOrgId();

$instrCount = $pdo->prepare("SELECT COUNT(*) c FROM music_instruments WHERE organization_id = ? AND is_active = 1");
$instrCount->execute([$orgId]);
$instrCount = $instrCount->fetch()['c'];

$studentCount = $pdo->prepare("SELECT COUNT(*) c FROM music_students WHERE organization_id = ? AND is_active = 1");
$studentCount->execute([$orgId]);
$studentCount = $studentCount->fetch()['c'];

$upcomingEvents = $pdo->prepare("SELECT COUNT(*) c FROM music_events WHERE organization_id = ? AND event_date >= CURDATE()");
$upcomingEvents->execute([$orgId]);
$upcomingEvents = $upcomingEvents->fetch()['c'];

$needsTuning = $pdo->prepare("SELECT COUNT(*) c FROM music_instruments WHERE organization_id = ? AND is_active = 1 AND condition_status IN ('needs_tuning','under_repair','damaged')");
$needsTuning->execute([$orgId]);
$needsTuning = $needsTuning->fetch()['c'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🎵 संगीत विभाग</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">वाद्य-साहित्य, सराव-खोली, विद्यार्थी, परीक्षा, कार्यक्रम — सगळं एका ठिकाणी.</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण वाद्यं</div><div class="val"><?= $instrCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ लक्ष द्यावं लागेल</div><div class="val" style="color:var(--amber);"><?= $needsTuning ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण विद्यार्थी</div><div class="val"><?= $studentCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">येणारे कार्यक्रम</div><div class="val" style="color:var(--rail);"><?= $upcomingEvents ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-lg-4">
    <a href="instruments.php" style="text-decoration:none;color:inherit;">
      <div class="panel" style="padding:20px;height:100%;">
        <div style="font-size:26px;">🎻</div>
        <div style="font-size:14.5px;font-weight:700;margin-top:8px;">वाद्य-साहित्य Inventory</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Instrument नोंद, Tuning/देखभाल इतिहास</div>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-lg-4">
    <a href="room-booking.php" style="text-decoration:none;color:inherit;">
      <div class="panel" style="padding:20px;height:100%;">
        <div style="font-size:26px;">📅</div>
        <div style="font-size:14.5px;font-weight:700;margin-top:8px;">सराव-खोली Booking</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">वेळ आधीच Book करा, संघर्ष टाळा</div>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-lg-4">
    <a href="students.php" style="text-decoration:none;color:inherit;">
      <div class="panel" style="padding:20px;height:100%;">
        <div style="font-size:26px;">🎓</div>
        <div style="font-size:14.5px;font-weight:700;margin-top:8px;">विद्यार्थी व हजेरी</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">नोंदणी, कोण-कोणतं वाद्य शिकतंय</div>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-lg-4">
    <a href="exams.php" style="text-decoration:none;color:inherit;">
      <div class="panel" style="padding:20px;height:100%;">
        <div style="font-size:26px;">📜</div>
        <div style="font-size:14.5px;font-weight:700;margin-top:8px;">परीक्षा/Grade Tracking</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">गांधर्व/Trinity — निकाल इतिहास</div>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-lg-4">
    <a href="events.php" style="text-decoration:none;color:inherit;">
      <div class="panel" style="padding:20px;height:100%;">
        <div style="font-size:26px;">🎤</div>
        <div style="font-size:14.5px;font-weight:700;margin-top:8px;">कार्यक्रम/स्पर्धा</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Concert, स्नेहसंमेलन, सहभागी विद्यार्थी</div>
      </div>
    </a>
  </div>
  <div class="col-md-6 col-lg-4">
    <a href="sheet-library.php" style="text-decoration:none;color:inherit;">
      <div class="panel" style="padding:20px;height:100%;">
        <div style="font-size:26px;">🎼</div>
        <div style="font-size:14.5px;font-weight:700;margin-top:8px;">Sheet Music Library</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">स्वरलिपी, रचना — शोधण्यायोग्य</div>
      </div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
