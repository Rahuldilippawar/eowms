<?php
$pageTitle = 'विभाग डॅशबोर्ड';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/budget-helpers.php';

$deptId = (int)($_GET['id'] ?? 0);

// Access control: management roles can view any department's dashboard;
// staff/HOD can only view their own department's dashboard.
if (isDeptScoped() && (int)$user['department_id'] !== $deptId) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s307')) . ' <a href="../index.php">' . h(t('s16')) . '</a></div>');
}
if (!hasPermission('view_org_wide_dashboards') && !isDeptScoped()) {
    http_response_code(403);
    die(t('s435'));
}

$budgetStatus = getBudgetStatus($pdo, activeOrgId(), $deptId);

$stmt = $pdo->prepare("SELECT d.*, c.name AS campus_name, u.name AS hod_name FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id LEFT JOIN users u ON u.id = d.hod_id WHERE d.id = ? AND d.organization_id = ?");
$stmt->execute([$deptId, activeOrgId()]);
$department = $stmt->fetch();

if (!$department) {
    die(t('s432'));
}

// Staff counts
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE department_id = ?");
$stmt->execute([$deptId]);
$totalStaff = $stmt->fetch()['c'];
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE department_id = ? AND is_active = 1");
$stmt->execute([$deptId]);
$activeStaff = $stmt->fetch()['c'];

// Full list of this department's employees (names) — shown in its own panel
$stmt = $pdo->prepare("SELECT id, name, designation, is_active FROM users WHERE department_id = ? ORDER BY name");
$stmt->execute([$deptId]);
$deptStaffList = $stmt->fetchAll();

// Today's work status
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id = ? AND work_date = CURDATE() AND status = 'completed'");
$stmt->execute([$deptId]);
$todayCompleted = $stmt->fetch()['c'];
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id = ? AND work_date = CURDATE() AND status != 'completed'");
$stmt->execute([$deptId]);
$todayPending = $stmt->fetch()['c'];

// Department renewals (due soon / expired)
$stmt = $pdo->prepare("SELECT *, DATEDIFF(expiry_date, CURDATE()) AS days_left FROM renewal_items WHERE department_id = ? AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY expiry_date LIMIT 6");
$stmt->execute([$deptId]);
$renewals = $stmt->fetchAll();

// Tasks assigned within this department — the "assigned kamancha track"
$stmt = $pdo->prepare("
    SELECT t.*, u1.name AS assignee_name, u2.name AS assigner_name
    FROM tasks t JOIN users u1 ON u1.id = t.assigned_to JOIN users u2 ON u2.id = t.assigned_by
    WHERE t.department_id = ? ORDER BY t.target_date LIMIT 10
");
$stmt->execute([$deptId]);
$tasks = $stmt->fetchAll();

$taskCounts = [];
foreach (['pending','in_progress','completed'] as $s) {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM tasks WHERE department_id = ? AND status = ?");
    $st->execute([$deptId, $s]);
    $taskCounts[$s] = $st->fetch()['c'];
}

// Recent work entries
$stmt = $pdo->prepare("SELECT wr.*, (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS emp_name FROM work_records wr WHERE wr.department_id = ? ORDER BY wr.work_date DESC LIMIT 8");
$stmt->execute([$deptId]);
$recentWork = $stmt->fetchAll();

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];

// This month's status + priority breakdown — for the pie/bar charts
$statusCounts = [];
foreach (['pending', 'in_progress', 'completed'] as $s) {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id = ? AND status = ? AND work_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $st->execute([$deptId, $s]);
    $statusCounts[$s] = (int)$st->fetch()['c'];
}
$priorityCounts = [];
foreach (['urgent', 'high', 'normal', 'low'] as $p) {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id = ? AND priority = ? AND work_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $st->execute([$deptId, $p]);
    $priorityCounts[$p] = (int)$st->fetch()['c'];
}
?>

<div class="page-eyebrow"><?= $department['campus_name'] ? h($department['campus_name']) : '🏛️ Trust (केंद्रीय)' ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1"><i class="<?= h($department['icon']) ?> me-2"></i><?= h($department['name_local'] ?: $department['name']) ?> <?= h(t('s308')) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h(t('s309')) ?> <?= h($department['hod_name'] ?? '—') ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="../letters/create.php?to_dept=<?= $department['id'] ?>" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;align-self:center;"><i class="fa-solid fa-paper-plane me-1"></i>पत्र पाठवा</a>
    <a href="../index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i><?= h(t('s310')) ?></a>
  </div>
</div>

<?php $isHostel = ($department['code'] === 'HOS'); ?>
<?php if ($isHostel): ?>
  <div class="mode-tabs mb-4" style="display:flex;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:5px;width:fit-content;">
    <div class="mode-tab active" data-view="staffView" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;background:var(--ink);color:#fff;cursor:pointer;">Staff</div>
    <div class="mode-tab" data-view="studentView" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--text-dim);cursor:pointer;">Student</div>
  </div>
<?php endif; ?>

<div id="staffView">

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s95')) ?></span>
        <div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-users"></i></div>
      </div>
      <div class="kpi-value"><?= $totalStaff ?><span style="font-size:15px;color:var(--text-dim);font-weight:500;">/<?= $activeStaff ?> <?= h(t('s4')) ?></span></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s52')) ?></span>
        <div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-check"></i></div>
      </div>
      <div class="kpi-value"><?= $todayCompleted ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s311')) ?></span>
        <div class="kpi-icon" style="background:var(--amber-soft);color:var(--amber);"><i class="fa-solid fa-hourglass-half"></i></div>
      </div>
      <div class="kpi-value"><?= $todayPending ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s53')) ?></span>
        <div class="kpi-icon" style="background:var(--rose-soft);color:var(--rose);"><i class="fa-solid fa-shield-halved"></i></div>
      </div>
      <div class="kpi-value"><?= count($renewals) ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="panel">
      <div class="panel-head"><div class="title">💰 या विभागाचं Budget</div><div class="sub"><?= h(formatFinancialYearLabel($budgetStatus['financial_year'])) ?></div></div>
      <?php if (!$budgetStatus['has_budget']): ?>
        <div style="padding:16px 20px;font-size:12.5px;color:var(--text-dim);">या विभागासाठी अजून Budget दिलेला नाही.</div>
      <?php else: ?>
        <div style="padding:16px 20px;">
          <div class="row g-3 mb-2">
            <div class="col-4"><div style="font-size:10.5px;color:var(--text-dim);">दिलेलं</div><div style="font-weight:700;font-size:16px;">₹<?= number_format($budgetStatus['allocated'], 0) ?></div></div>
            <div class="col-4"><div style="font-size:10.5px;color:var(--text-dim);">खर्च झालेलं</div><div style="font-weight:700;font-size:16px;color:var(--amber);">₹<?= number_format($budgetStatus['spent'], 0) ?></div></div>
            <div class="col-4"><div style="font-size:10.5px;color:var(--text-dim);">शिल्लक</div><div style="font-weight:700;font-size:16px;color:<?= $budgetStatus['remaining'] < 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($budgetStatus['remaining'], 0) ?></div></div>
          </div>
          <div style="background:var(--canvas);border-radius:10px;height:10px;overflow:hidden;">
            <div style="height:100%;width:<?= $budgetStatus['percent_used'] ?>%;background:<?= $budgetStatus['remaining'] < 0 ? 'var(--rose)' : ($budgetStatus['percent_used'] > 80 ? 'var(--amber)' : 'var(--rail)') ?>;"></div>
          </div>
          <?php if ($budgetStatus['remaining'] < 0): ?>
            <div style="font-size:11.5px;color:var(--rose);font-weight:600;margin-top:8px;">⚠️ Budget पेक्षा जास्त खर्च झाला आहे.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="panel">
      <div class="panel-head"><div class="title">👥 या विभागातील कर्मचारी</div><div class="sub"><?= count($deptStaffList) ?> कर्मचारी</div></div>
      <div style="padding:16px 20px;">
        <?php if (empty($deptStaffList)): ?>
          <p style="font-size:12.5px;color:var(--text-dim);">या विभागात अजून कोणीही कर्मचारी नेमलेला नाही.</p>
        <?php endif; ?>
        <?php foreach ($deptStaffList as $ds): ?>
          <a href="../staff/profile.php?id=<?= $ds['id'] ?>" style="display:inline-flex;align-items:center;gap:8px;background:var(--canvas);border-radius:20px;padding:7px 16px 7px 8px;font-size:12.5px;margin:0 8px 8px 0;text-decoration:none;color:var(--ink);<?= !$ds['is_active'] ? 'opacity:.5;' : '' ?>">
            <span class="font-mono" style="width:24px;height:24px;border-radius:50%;background:var(--rail);color:#fff;font-size:10px;display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0;"><?= h(mb_substr($ds['name'], 0, 2)) ?></span>
            <span style="font-weight:500;"><?= h($ds['name']) ?></span>
            <?php if ($ds['designation']): ?><span style="color:var(--text-dim);">— <?= h($ds['designation']) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php if (array_sum($statusCounts) > 0): ?>
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">या महिन्याची स्थिती</div><div class="sub">प्रलंबित / सुरू आहे / पूर्ण</div></div>
      <div style="padding:18px 20px;"><canvas id="deptStatusChart" height="140"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">प्राधान्य-निहाय कामे</div><div class="sub">या महिन्यातील</div></div>
      <div style="padding:18px 20px;"><canvas id="deptPriorityChart" height="140"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title"><?= h(t('s312')) ?></div><div class="sub"><?= h(t('s7')) ?> <?= $taskCounts['pending'] ?> <?= h(t('s58')) ?> <?= $taskCounts['in_progress'] ?> <?= h(t('s59')) ?> <?= $taskCounts['completed'] ?></div></div>
        <?php if (hasPermission('assign_tasks')): ?>
          <a href="../tasks/assign.php" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;"><?= h(t('s313')) ?></a>
        <?php endif; ?>
      </div>
      <table class="rtable">
        <thead><tr><th><?= h(t('s14')) ?></th><th><?= h(t('s6')) ?></th><th>Target Date</th><th><?= h(t('s2')) ?></th></tr></thead>
        <tbody>
          <?php if (empty($tasks)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:20px;"><?= h(t('s314')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($tasks as $task):
            $isOverdue = strtotime($task['target_date']) < strtotime(date('Y-m-d')) && $task['status'] !== 'completed';
            $chip = $isOverdue ? 'overdue' : $statusChip[$task['status']];
            $chipText = $isOverdue ? 'मुदत संपली' : $statusLabels[$task['status']];
          ?>
            <tr>
              <td style="font-weight:500;"><?= h($task['title']) ?><div class="font-mono" style="font-size:11px;color:var(--text-dim);"><?= h($task['task_code']) ?></div></td>
              <td><?= h($task['assignee_name']) ?></td>
              <td class="font-mono"><?= date('d M Y', strtotime($task['target_date'])) ?></td>
              <td><span class="status-chip <?= $chip ?>"><?= $chipText ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title"><?= h(t('s29')) ?></div><div class="sub"><?= h(t('s55')) ?></div></div>
        <a href="../renewals/index.php?department_id=<?= $deptId ?>" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;"><?= h(t('s56')) ?></a>
      </div>
      <?php if (empty($renewals)): ?>
        <div style="padding:20px;color:var(--text-dim);font-size:13px;"><?= h(t('s57')) ?></div>
      <?php endif; ?>
      <?php foreach ($renewals as $item):
        $days = (int)$item['days_left'];
        $color = $days < 0 ? 'rose' : ($days <= 7 ? 'rose' : ($days <= 15 ? 'amber' : 'rail'));
      ?>
        <div style="padding:13px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;">
          <span style="width:8px;height:8px;border-radius:50%;background:var(--<?= $color ?>);flex-shrink:0;"></span>
          <div style="font-size:13px;font-weight:500;"><?= h($item['item_name']) ?></div>
          <span class="ms-auto font-mono" style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:6px;background:var(--<?= $color ?>-soft);color:var(--<?= $color ?>);">
            <?= $days < 0 ? 'Expired' : $days . ' दिवस' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head d-flex justify-content-between align-items-center">
    <div><div class="title"><?= h(t('s315')) ?></div><div class="sub"><?= h(t('s316')) ?></div></div>
    <a href="../daily-work/report.php?department_id=<?= $deptId ?>" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;"><?= h(t('s317')) ?></a>
  </div>
  <table class="rtable">
    <thead><tr><th><?= h(t('s13')) ?></th><th><?= h(t('s6')) ?></th><th><?= h(t('s21')) ?></th><th><?= h(t('s2')) ?></th></tr></thead>
    <tbody>
      <?php if (empty($recentWork)): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:20px;"><?= h(t('s97')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($recentWork as $w): ?>
        <tr>
          <td class="font-mono"><?= date('d M', strtotime($w['work_date'])) ?></td>
          <td><?= h($w['emp_name']) ?></td>
          <td><?= h($w['title']) ?></td>
          <td><span class="status-chip <?= $statusChip[$w['status']] ?>"><?= $statusLabels[$w['status']] ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

</div><!-- /#staffView -->

<?php if ($isHostel): ?>
  <div id="studentView" style="display:none;">
    <?php
      $hostelsList = [];
      try {
          $hStmt = $pdo->prepare("
              SELECT h.*,
                (SELECT COUNT(*) FROM hostel_students s WHERE s.hostel_id = h.id AND s.is_active = 1) AS student_count,
                (SELECT COUNT(*) FROM hostel_staff hs WHERE hs.hostel_id = h.id) AS staff_count
              FROM hostels h WHERE h.department_id = ? AND h.is_active = 1 ORDER BY h.name
          ");
          $hStmt->execute([$deptId]);
          $hostelsList = $hStmt->fetchAll();
      } catch (PDOException $e) {
          // hostels tables not migrated yet — show empty list below instead of crashing
      }
    ?>
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title"><?= h(t('s318')) ?></div><div class="sub"><?= h(t('s319')) ?></div></div>
        <?php if (hasPermission('manage_hostels')): ?>
          <a href="../hostels/create.php" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 14px;"><i class="fa-solid fa-plus me-1"></i><?= h(t('s320')) ?></a>
        <?php endif; ?>
      </div>
      <table class="rtable">
        <thead><tr><th><?= h(t('s321')) ?></th><th><?= h(t('s24')) ?></th><th><?= h(t('s25')) ?></th><th><?= h(t('s80')) ?></th><th></th></tr></thead>
        <tbody>
          <?php if (empty($hostelsList)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:20px;"><?= h(t('s81')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($hostelsList as $h): ?>
            <tr style="cursor:pointer;" onclick="window.location='../hostels/dashboard.php?id=<?= $h['id'] ?>'">
              <td style="font-weight:500;"><?= h($h['name']) ?></td>
              <td class="font-mono"><?= $h['capacity'] ?: '—' ?></td>
              <td class="font-mono"><?= $h['student_count'] ?></td>
              <td class="font-mono"><?= $h['staff_count'] ?></td>
              <td><a href="../hostels/dashboard.php?id=<?= $h['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;"><?= h(t('s322')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    document.querySelectorAll('.mode-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.mode-tab').forEach(function (t) {
          t.classList.remove('active');
          t.style.background = 'transparent';
          t.style.color = 'var(--text-dim)';
        });
        this.classList.add('active');
        this.style.background = 'var(--ink)';
        this.style.color = '#fff';

        document.getElementById('staffView').style.display = this.dataset.view === 'staffView' ? 'block' : 'none';
        document.getElementById('studentView').style.display = this.dataset.view === 'studentView' ? 'block' : 'none';
      });
    });
  </script>
<?php endif; ?>

<?php if (array_sum($statusCounts) > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('deptStatusChart'), {
  type: 'doughnut',
  data: {
    labels: ['प्रलंबित', 'सुरू आहे', 'पूर्ण झाले'],
    datasets: [{
      data: [<?= $statusCounts['pending'] ?>, <?= $statusCounts['in_progress'] ?>, <?= $statusCounts['completed'] ?>],
      backgroundColor: ['#C97F1D', '#3B6FC9', '#1B8A83'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 12 }, boxWidth: 12 } } },
    cutout: '62%'
  }
});
new Chart(document.getElementById('deptPriorityChart'), {
  type: 'bar',
  data: {
    labels: ['तातडीचे', 'जास्त', 'सामान्य', 'कमी'],
    datasets: [{
      data: [<?= $priorityCounts['urgent'] ?>, <?= $priorityCounts['high'] ?>, <?= $priorityCounts['normal'] ?>, <?= $priorityCounts['low'] ?>],
      backgroundColor: ['#B23A48', '#C97F1D', '#3B6FC9', '#6B7080'],
      borderRadius: 6, maxBarThickness: 46
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { family: "'Noto Sans Devanagari'", size: 12 } } },
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#EEF0F4' } }
    }
  }
});
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
