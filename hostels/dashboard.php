<?php
$pageTitle = 'वसतिगृह डॅशबोर्ड';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$hostelId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

// Access: management roles, or staff/HOD who belong to the Hostel department can view
$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

if (!hasPermission('view_org_wide_dashboards') && $userDeptCode !== 'HOS') {
    http_response_code(403);
    die(t('s435'));
}

$stmt = $pdo->prepare("SELECT h.*, c.name AS campus_name FROM hostels h LEFT JOIN campuses c ON c.id = h.campus_id WHERE h.id = ? AND h.organization_id = ?");
$stmt->execute([$hostelId, $orgId]);
$hostel = $stmt->fetch();
if (!$hostel) { die(t('s430')); }

// Allocated staff
$stmt = $pdo->prepare("SELECT u.name, u.designation, u.mobile FROM hostel_staff hs JOIN users u ON u.id = hs.user_id WHERE hs.hostel_id = ?");
$stmt->execute([$hostelId]);
$allocatedStaff = $stmt->fetchAll();

// Students
$stmt = $pdo->prepare("SELECT * FROM hostel_students WHERE hostel_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$hostelId]);
$students = $stmt->fetchAll();

// District-wise student count — for the bar chart
$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(district, ''), 'अज्ञात') AS district, COUNT(*) AS cnt
    FROM hostel_students WHERE hostel_id = ? AND is_active = 1
    GROUP BY district ORDER BY cnt DESC
");
$stmt->execute([$hostelId]);
$districtCounts = $stmt->fetchAll();

// School-wise student count — for the bar chart
$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(school_name, ''), 'अज्ञात') AS school_name, COUNT(*) AS cnt
    FROM hostel_students WHERE hostel_id = ? AND is_active = 1
    GROUP BY school_name ORDER BY cnt DESC
");
$stmt->execute([$hostelId]);
$schoolCounts = $stmt->fetchAll();

// Today's attendance status for all 3 sessions — live count + missing-session detection
$todaySessions = [];
try {
    foreach (['morning' => '🌅 सकाळ', 'afternoon' => '☀️ दुपार', 'evening' => '🌆 संध्याकाळ'] as $key => $label) {
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM hostel_student_attendance WHERE hostel_id = ? AND attendance_date = CURDATE() AND session = ?");
        $stmt->execute([$hostelId, $key]);
        $marked = (int)$stmt->fetch()['c'];
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM hostel_student_attendance WHERE hostel_id = ? AND attendance_date = CURDATE() AND session = ? AND status = 'present'");
        $stmt->execute([$hostelId, $key]);
        $present = (int)$stmt->fetch()['c'];
        $todaySessions[$key] = ['label' => $label, 'marked' => $marked, 'present' => $present];
    }
} catch (PDOException $e) {
    // hostel_student_attendance table not migrated yet — skip widget
}

$canManage = hasPermission('manage_hostel_students') || $userDeptCode === 'HOS';
?>

<div class="page-eyebrow"><?= h($hostel['campus_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1"><i class="fa-solid fa-building me-2"></i><?= h($hostel['name']) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h(t('s179')) ?> <?= $hostel['capacity'] ?: '—' ?> <?= t('s180') ?> <?= count($students) ?></div>
  </div>
  <a href="../departments/dashboard.php?id=<?= $hostel['department_id'] ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-arrow-left me-1"></i><?= h(t('s181')) ?></a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['import_errors'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
    <strong>काही ओळी वगळल्या गेल्या:</strong>
    <ul class="mb-0">
      <?php foreach ($_SESSION['import_errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s182')) ?></div><div class="val"><?= count($students) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s79')) ?></div><div class="val" style="color:var(--rail);"><?= count($allocatedStaff) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s24')) ?></div><div class="val"><?= $hostel['capacity'] ?: '—' ?></div></div></div>
</div>

<?php if (!empty($todaySessions)): ?>
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title">आजची उपस्थिती (Live)</div><div class="sub"><?= date('d M Y') ?> — 3 सत्रे</div></div>
        <?php if (hasRole('super_admin') || isManagementOrAbove() || $userDeptCode === 'HOS'): ?>
          <a href="attendance.php?hostel_id=<?= $hostelId ?>" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 14px;"><i class="fa-solid fa-clipboard-user me-1"></i>हजेरी नोंदवा</a>
        <?php endif; ?>
      </div>
      <div style="padding:14px 20px;display:flex;gap:28px;flex-wrap:wrap;">
        <?php foreach ($todaySessions as $key => $s): ?>
          <div>
            <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:3px;"><?= $s['label'] ?></div>
            <?php if ($s['marked'] > 0): ?>
              <div class="font-mono" style="font-weight:700;font-size:18px;color:var(--rail);"><?= $s['present'] ?> <span style="font-size:12px;color:var(--text-dim);font-weight:400;">उपस्थित</span></div>
            <?php else: ?>
              <div style="font-size:13px;color:var(--rose);font-weight:600;"><i class="fa-solid fa-triangle-exclamation me-1"></i>नोंद झालेली नाही</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($districtCounts) || !empty($schoolCounts)): ?>
<div class="row g-3 mb-3">
  <?php if (!empty($districtCounts)): ?>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title"><?= h(t('s183')) ?></div><div class="sub"><?= h(t('s184')) ?></div></div>
      <div style="padding:18px 20px;"><canvas id="districtChart" height="110"></canvas></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($schoolCounts)): ?>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">शाळा-निहाय विद्यार्थी</div><div class="sub">कोणत्या शाळेतून किती विद्यार्थी आहेत</div></div>
      <div style="padding:18px 20px;"><canvas id="schoolChart" height="110"></canvas></div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title"><?= h(t('s185')) ?></div></div>
      <?php if (empty($allocatedStaff)): ?>
        <div style="padding:18px 20px;color:var(--text-dim);font-size:13px;"><?= h(t('s186')) ?></div>
      <?php endif; ?>
      <?php foreach ($allocatedStaff as $s): ?>
        <div style="padding:12px 20px;border-bottom:1px solid var(--line);">
          <div style="font-weight:500;font-size:13.5px;"><?= h($s['name']) ?></div>
          <div style="font-size:12px;color:var(--text-dim);"><?= h($s['designation']) ?> <?= $s['mobile'] ? '· ' . h($s['mobile']) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div class="title"><?= h(t('s187')) ?></div>
        <?php if ($canManage): ?>
          <?php if (hasRole('super_admin')): ?>
            <a href="student-import.php" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:6px 14px;margin-right:6px;"><i class="fa-solid fa-file-csv me-1"></i>CSV आयात</a>
          <?php endif; ?>
          <a href="student-create.php?hostel_id=<?= $hostelId ?>" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 14px;"><i class="fa-solid fa-plus me-1"></i><?= h(t('s78')) ?></a>
        <?php endif; ?>
      </div>
      <table class="rtable">
        <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s35')) ?></th><th><?= h(t('s188')) ?></th><th><?= h(t('s189')) ?></th><th><?= h(t('s190')) ?></th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php if (empty($students)): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:20px;"><?= h(t('s191')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($students as $st): ?>
            <tr>
              <td style="font-weight:500;"><?= h($st['name']) ?></td>
              <td><?= h($st['gender'] ?? '—') ?></td>
              <td><?= h($st['school_name'] ?? '—') ?></td>
              <td><?= h($st['class_grade'] ?? '—') ?></td>
              <td><?= h($st['district'] ?? '—') ?></td>
              <?php if ($canManage): ?>
                <td><a href="student-edit.php?id=<?= $st['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;">Edit</a></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($districtCounts)): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('districtChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($districtCounts, 'district'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      label: 'विद्यार्थी संख्या',
      data: <?= json_encode(array_map('intval', array_column($districtCounts, 'cnt'))) ?>,
      backgroundColor: '#1B8A83',
      borderRadius: 6,
      maxBarThickness: 42
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { family: "'Noto Sans Devanagari'", size: 12 } } },
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'Inter', size: 11 } }, grid: { color: '#EEF0F4' } }
    }
  }
});
});
</script>
<?php endif; ?>

<?php if (!empty($schoolCounts)): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('schoolChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($schoolCounts, 'school_name'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      label: 'विद्यार्थी संख्या',
      data: <?= json_encode(array_map('intval', array_column($schoolCounts, 'cnt'))) ?>,
      backgroundColor: '#C97F1D',
      borderRadius: 6,
      maxBarThickness: 42
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { family: "'Noto Sans Devanagari'", size: 11 }, autoSkip: false, maxRotation: 30, minRotation: 0 } },
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'Inter', size: 11 } }, grid: { color: '#EEF0F4' } }
    }
  }
});
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
