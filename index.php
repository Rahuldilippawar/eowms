<?php
require_once __DIR__ . '/includes/auth.php';

// If not logged in, show the public Welcome/About landing page instead of
// forcing a redirect to login.php — visitors see this first, with a
// "Login" button and a "काम विनंती करा" (submit a work request) button.
if (!currentUser()) {
    require __DIR__ . '/welcome.php';
    exit;
}

$pageTitle = 'Command Center';
$rootPath = '';
require_once __DIR__ . '/includes/header.php';

$orgId = activeOrgId();
$isMgmtView = isManagementOrAbove();

// विभाग-प्रमुख/कर्मचारी यांना फक्त त्यांच्याच विभागाचे आकडे दिसावेत —
// Management ला मात्र संपूर्ण संस्थेचे. myDeptId वापरून सगळ्या Queries
// खाली त्यानुसार Scope केल्या आहेत.
$myDeptId = $user['department_id'] ?? 0;
$deptScopeSql = $isMgmtView ? "" : " AND department_id = ?";
$deptScopeParams = $isMgmtView ? [] : [$myDeptId];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=CURDATE()$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$todayReports = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id=? AND is_active=1" . ($isMgmtView ? "" : " AND department_id = ?"));
$stmt->execute($isMgmtView ? [$orgId] : [$orgId, $myDeptId]);
$staffCount = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=CURDATE() AND status='completed'$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$todayCompleted = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=CURDATE() AND status!='completed'$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$todayPending = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM renewal_items WHERE organization_id=? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$renewalsDue = $stmt->fetch()['c'];

// काल (Yesterday) चे आकडे — KPI Trend Indicator साठी तुलना
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY)$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$yesterdayReports = (int)$stmt->fetch()['c'];
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status='completed'$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$yesterdayCompleted = (int)$stmt->fetch()['c'];

function kpiTrend($today, $yesterday) {
    if ($yesterday == 0) { return $today > 0 ? ['dir' => 'up', 'text' => 'नवीन'] : null; }
    $diff = $today - $yesterday;
    if ($diff == 0) { return ['dir' => 'flat', 'text' => 'कालच्याइतकंच']; }
    $pct = round((abs($diff) / $yesterday) * 100);
    return ['dir' => $diff > 0 ? 'up' : 'down', 'text' => ($diff > 0 ? '+' : '−') . $pct . '% कालच्या तुलनेत'];
}
$trendReports = kpiTrend($todayReports, $yesterdayReports);
$trendCompleted = kpiTrend($todayCompleted, $yesterdayCompleted);

$greetingHour = (int)date('G');
$greeting = $greetingHour < 12 ? '☀️ शुभ सकाळ' : ($greetingHour < 17 ? '🌤️ नमस्कार' : '🌙 शुभ संध्याकाळ');

if ($isMgmtView) {
    $stmt = $pdo->prepare("
        SELECT d.*, u.name AS hod_name, c.name AS campus_name, c.college_type
        FROM departments d LEFT JOIN users u ON u.id = d.hod_id LEFT JOIN campuses c ON c.id = d.campus_id
        WHERE d.organization_id=? AND d.is_active=1 ORDER BY (c.college_type = 'Trust HQ') DESC, d.campus_id IS NULL DESC, c.name, d.name_local
    ");
    $stmt->execute([$orgId]);
} else {
    $stmt = $pdo->prepare("
        SELECT d.*, u.name AS hod_name, c.name AS campus_name, c.college_type
        FROM departments d LEFT JOIN users u ON u.id = d.hod_id LEFT JOIN campuses c ON c.id = d.campus_id
        WHERE d.organization_id=? AND d.is_active=1 AND d.id = ?
    ");
    $stmt->execute([$orgId, $user['department_id'] ?? 0]);
}
$departments = $stmt->fetchAll();

// Trust-स्तरीय / College/School नुसार गटवार
$deptGroupsForDash = [];
foreach ($departments as $dept) {
    $isTrustLevel = empty($dept['campus_name']) || $dept['college_type'] === 'Trust HQ';
    $groupKey = $isTrustLevel ? 'trust' : $dept['campus_name'];
    if (!isset($deptGroupsForDash[$groupKey])) {
        $deptGroupsForDash[$groupKey] = ['label' => $isTrustLevel ? '🏛️ Trust-स्तरीय' : '🏫 ' . $dept['campus_name'], 'depts' => []];
    }
    $deptGroupsForDash[$groupKey]['depts'][] = $dept;
}

// Last 7 days trend — total vs completed work, for the line chart
// (HOD/Staff साठी फक्त त्यांच्याच विभागाचा कल — Management ला संपूर्ण संस्थेचा)
$trendLabels = [];
$trendTotal = [];
$trendCompleted = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $t = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=?$deptScopeSql");
    $t->execute(array_merge([$orgId, $d], $deptScopeParams));
    $trendTotal[] = (int)$t->fetch()['c'];
    $c = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=? AND status='completed'$deptScopeSql");
    $c->execute(array_merge([$orgId, $d], $deptScopeParams));
    $trendCompleted[] = (int)$c->fetch()['c'];
}

// Department-wise work distribution (last 30 days) — for the doughnut chart
// Only meaningful (and only shown) for Management — comparing across
// departments would otherwise reveal other departments' names to HOD/Staff.
$deptDistribution = [];
if ($isMgmtView) {
    $stmt = $pdo->prepare("
        SELECT d.name_local AS name, COUNT(wr.id) AS cnt
        FROM departments d
        LEFT JOIN work_records wr ON wr.department_id = d.id AND wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE d.organization_id = ? AND d.is_active = 1
        GROUP BY d.id, d.name_local
        HAVING cnt > 0
        ORDER BY cnt DESC
    ");
    $stmt->execute([$orgId]);
    $deptDistribution = $stmt->fetchAll();
}

// Latest satsang attendance summary — management dashboard widget
$latestSatsang = null;
try {
    $stmt = $pdo->prepare("
        SELECT se.*, d.name_local AS organizer_dept
        FROM satsang_events se LEFT JOIN departments d ON d.id = se.organizing_department_id
        WHERE se.organization_id = ? AND se.event_date <= CURDATE()
        ORDER BY se.event_date DESC LIMIT 1
    ");
    $stmt->execute([$orgId]);
    $latestSatsang = $stmt->fetch();
    if ($latestSatsang) {
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM satsang_attendance WHERE satsang_event_id = ? AND status = 'present'");
        $stmt->execute([$latestSatsang['id']]);
        $latestSatsang['present'] = (int)$stmt->fetch()['c'];
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM satsang_attendance WHERE satsang_event_id = ? AND status = 'absent'");
        $stmt->execute([$latestSatsang['id']]);
        $latestSatsang['absent'] = (int)$stmt->fetch()['c'];
    }
} catch (PDOException $e) {
    // satsang tables not migrated yet — skip widget silently
}

$upcomingRenewalsSql = "
    SELECT r.*, d.name_local AS dept_name, DATEDIFF(r.expiry_date, CURDATE()) AS days_left
    FROM renewal_items r JOIN departments d ON d.id = r.department_id
    WHERE r.organization_id=? AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . ($isMgmtView ? "" : " AND r.department_id = ?") . "
    ORDER BY r.expiry_date LIMIT 6
";
$stmt = $pdo->prepare($upcomingRenewalsSql);
$stmt->execute($isMgmtView ? [$orgId] : [$orgId, $myDeptId]);
$upcomingRenewals = $stmt->fetchAll();

// आज कोण गैरहजर/उशीर — Command Center Widget साठी
$todayAttendanceStmt = null;
try {
    $todayAttSql = "
        SELECT sa.status, sa.is_late, u.name, u.designation, d.name_local AS dept_name
        FROM staff_attendance sa JOIN users u ON u.id = sa.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE sa.organization_id = ? AND sa.attendance_date = CURDATE() AND (sa.status = 'absent' OR sa.is_late = 1)
    ";
    $taParams = [$orgId];
    if (!$isMgmtView) { $todayAttSql .= " AND u.department_id = ?"; $taParams[] = $myDeptId; }
    $todayAttSql .= " ORDER BY sa.status = 'absent' DESC, u.name LIMIT 10";
    $todayAttendanceStmt = $pdo->prepare($todayAttSql);
    $todayAttendanceStmt->execute($taParams);
    $todayAttendanceIssues = $todayAttendanceStmt->fetchAll();
} catch (PDOException $e) {
    $todayAttendanceIssues = [];
}

// Task tracking summary — visible to management roles only
$taskSummary = null;
$recentTasks = [];
if (hasPermission('view_task_tracker')) {
    $taskCounts = [];
    foreach (['pending','in_progress','completed'] as $s) {
        $st = $pdo->prepare("SELECT COUNT(*) c FROM tasks WHERE organization_id = ? AND status = ?");
        $st->execute([$orgId, $s]);
        $taskCounts[$s] = $st->fetch()['c'];
    }
    $taskSummary = $taskCounts;

    $stmt = $pdo->prepare("
        SELECT t.*, d.name_local AS dept_name, u.name AS assignee_name
        FROM tasks t JOIN departments d ON d.id = t.department_id JOIN users u ON u.id = t.assigned_to
        WHERE t.organization_id = ? ORDER BY t.target_date LIMIT 6
    ");
    $stmt->execute([$orgId]);
    $recentTasks = $stmt->fetchAll();
}

$statusLabelsMap = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChipMap = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];

// Campus-निहाय संपूर्ण विभाजन — पूर्वी "केंद्रीकृत Dashboard" या वेगळ्या
// पानावर होतं, आता Command Center चाच एक Section (फक्त manage_campuses
// Access असलेल्यांनाच खाली दिसेल).
$showCampusBreakdown = hasPermission('manage_campuses');
if ($showCampusBreakdown) {
    require_once __DIR__ . '/includes/dashboard-helpers.php';

    $campusStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 AND (college_type IS NULL OR college_type != 'Trust HQ') ORDER BY name");
    $campusStmt->execute([$orgId]);
    $allCampuses = $campusStmt->fetchAll();

    $collegeTypeLabels = ['Primary School' => 'प्राथमिक शाळा', 'Secondary School' => 'माध्यमिक शाळा', 'Junior College' => 'कनिष्ठ महाविद्यालय', 'Arts' => 'कला महाविद्यालय', 'Science' => 'विज्ञान महाविद्यालय', 'Commerce' => 'वाणिज्य महाविद्यालय', 'Engineering' => 'अभियांत्रिकी महाविद्यालय', 'Pharmacy' => 'औषधनिर्माणशास्त्र महाविद्यालय', 'Education' => 'शिक्षणशास्त्र महाविद्यालय', 'Law' => 'विधी महाविद्यालय', 'Medical' => 'वैद्यकीय महाविद्यालय', 'Polytechnic' => 'Polytechnic', 'Other' => ''];

    $trustMetrics = getDashboardRowMetrics($pdo, $orgId, null, true);
    $collegeRows = [];
    foreach ($allCampuses as $c) { $collegeRows[] = array_merge(['campus' => $c], getDashboardRowMetrics($pdo, $orgId, $c['id'], false)); }

    $overallMetrics = $trustMetrics;
    foreach ($collegeRows as $row) {
        foreach (['dept_count','staff_count','pending_tasks','completed_tasks','renewals_due','renewals_expired','pending_letters','completed_letters'] as $k) { $overallMetrics[$k] += $row[$k]; }
        foreach ([0,1,2,3,4,5,6] as $i) {
            $overallMetrics['trend_total'][$i] = ($overallMetrics['trend_total'][$i] ?? 0) + ($row['trend_total'][$i] ?? 0);
            $overallMetrics['trend_completed'][$i] = ($overallMetrics['trend_completed'][$i] ?? 0) + ($row['trend_completed'][$i] ?? 0);
        }
    }
}
?>

<div class="dash-hero">
  <div class="dash-hero-bg"></div>
  <div style="position:relative;z-index:1;">
    <div class="page-eyebrow" style="color:#9FA6C0;"><?= h(activeOrgName()) ?></div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;margin-top:4px;">
      <div>
        <div style="font-family:'Space Grotesk';font-weight:700;font-size:26px;color:#fff;"><?= $greeting ?>, <?= h(explode(' ', $user['name'])[0]) ?>!</div>
        <div style="font-size:13px;color:#B7BBD6;margin-top:4px;"><?= date('l, d F Y') ?> · <?= h(t('s122')) ?></div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="daily-work/entry.php" class="dash-quick-btn"><i class="fa-solid fa-pen-to-square"></i> काम नोंदवा</a>
        <?php if (hasPermission('assign_tasks')): ?>
          <a href="tasks/assign.php" class="dash-quick-btn"><i class="fa-solid fa-list-check"></i> Task Assign करा</a>
        <?php endif; ?>
        <a href="renewals/index.php" class="dash-quick-btn"><i class="fa-solid fa-shield-halved"></i> नूतनीकरण</a>
      </div>
    </div>
  </div>
</div>

<?php if ($showCampusBreakdown): ?>
<div class="dash-tabs" role="tablist">
  <button type="button" class="dash-tab active" onclick="eowmsSwitchDashTab('overview', this)">📋 माझं/संस्थेचं दृश्य</button>
  <button type="button" class="dash-tab" onclick="eowmsSwitchDashTab('campus', this)">🏛️ Campus-निहाय विभाजन</button>
</div>
<style>
  .dash-tabs{display:flex;gap:6px;margin:18px 0 22px;border-bottom:1px solid var(--line);}
  .dash-tab{background:none;border:none;padding:11px 18px;font-size:13.5px;font-weight:600;color:var(--text-dim);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s ease;}
  .dash-tab:hover{color:var(--ink);}
  .dash-tab.active{color:var(--rail);border-bottom-color:var(--rail);}
</style>
<script>
function eowmsSwitchDashTab(which, btn) {
  document.getElementById('dashTabOverview').style.display = which === 'overview' ? '' : 'none';
  document.getElementById('dashTabCampus').style.display = which === 'campus' ? '' : 'none';
  document.querySelectorAll('.dash-tab').forEach(function (b) { b.classList.remove('active'); });
  btn.classList.add('active');
  if (which === 'campus') { window.scrollTo({ top: document.querySelector('.dash-tabs').offsetTop - 20, behavior: 'smooth' }); }
}
</script>
<?php endif; ?>

<div id="dashTabOverview">
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s123')) ?></span>
        <div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-regular fa-clipboard"></i></div>
      </div>
      <div class="kpi-value"><?= $todayReports ?><span style="font-size:15px;color:var(--text-dim);font-weight:500;">/<?= $staffCount ?></span></div>
      <?php if ($trendReports): ?>
        <div class="kpi-trend kpi-trend-<?= $trendReports['dir'] ?>"><?= $trendReports['dir'] === 'up' ? '↑' : ($trendReports['dir'] === 'down' ? '↓' : '→') ?> <?= h($trendReports['text']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s52')) ?></span>
        <div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-check"></i></div>
      </div>
      <div class="kpi-value"><?= $todayCompleted ?></div>
      <?php if ($trendCompleted): ?>
        <div class="kpi-trend kpi-trend-<?= $trendCompleted['dir'] ?>"><?= $trendCompleted['dir'] === 'up' ? '↑' : ($trendCompleted['dir'] === 'down' ? '↓' : '→') ?> <?= h($trendCompleted['text']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= h(t('s53')) ?></span>
        <div class="kpi-icon" style="background:var(--rose-soft);color:var(--rose);"><i class="fa-solid fa-shield-halved"></i></div>
      </div>
      <div class="kpi-value"><?= $renewalsDue ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="d-flex justify-content-between align-items-start">
        <span class="kpi-label"><?= $isMgmtView ? h(t('s54')) : 'माझा विभाग' ?></span>
        <div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-building"></i></div>
      </div>
      <div class="kpi-value"><?= count($departments) ?></div>
    </div>
  </div>
</div>

<div class="panel mb-4">
  <div class="panel-head">
    <div class="title"><?= h(t('s124')) ?></div>
    <div class="sub"><?= h(t('s125')) ?></div>
  </div>
  <div style="padding:18px 20px;display:flex;gap:28px;flex-wrap:wrap;">
    <div class="d-flex align-items-center gap-2">
      <span style="width:10px;height:10px;border-radius:50%;background:var(--amber);"></span>
      <span style="font-size:13px;"><?= h(t('s7')) ?></span>
      <span class="font-mono" style="font-weight:700;font-size:16px;"><?= $todayPending ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span style="width:10px;height:10px;border-radius:50%;background:var(--rail);"></span>
      <span style="font-size:13px;"><?= h(t('s31')) ?></span>
      <span class="font-mono" style="font-weight:700;font-size:16px;"><?= $todayCompleted ?></span>
    </div>
  </div>
</div>

<?php if (!empty($deptDistribution) || array_sum($trendTotal) > 0): ?>
<div class="row g-3 mb-3">
  <?php if (array_sum($trendTotal) > 0): ?>
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title">गेल्या ७ दिवसांचा कल</div><div class="sub">रोजची कामं — एकूण vs पूर्ण</div></div>
      <div style="padding:18px 20px;"><canvas id="trendChart" height="90"></canvas></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($deptDistribution)): ?>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">विभागनिहाय काम वितरण</div><div class="sub">मागील ३० दिवस</div></div>
      <div style="padding:18px 20px;"><canvas id="deptDistChart" height="180"></canvas></div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-head"><div class="title"><?= $isMgmtView ? h(t('s1')) : 'माझा विभाग' ?></div><div class="sub"><?= h(t('s126')) ?></div></div>
      <?php if ($isMgmtView && count($deptGroupsForDash) > 1): ?>
        <?php foreach ($deptGroupsForDash as $group): ?>
          <div style="padding:10px 20px 4px;font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;background:var(--canvas);"><?= h($group['label']) ?> (<?= count($group['depts']) ?>)</div>
          <table class="wtable">
            <tbody>
              <?php foreach ($group['depts'] as $dept): ?>
                <tr style="cursor:pointer;" onclick="window.location='departments/dashboard.php?id=<?= $dept['id'] ?>'">
                  <td style="width:70%;"><i class="<?= h($dept['icon']) ?> me-2"></i><?= h($dept['name_local'] ?: $dept['name']) ?></td>
                  <td><?= h($dept['hod_name'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endforeach; ?>
      <?php else: ?>
        <table class="wtable">
          <thead><tr><th><?= h(t('s1')) ?></th><th><?= h(t('s127')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($departments as $dept): ?>
              <tr style="cursor:pointer;" onclick="window.location='departments/dashboard.php?id=<?= $dept['id'] ?>'">
                <td><i class="<?= h($dept['icon']) ?> me-2"></i><?= h($dept['name_local'] ?: $dept['name']) ?></td>
                <td><?= h($dept['hod_name'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title"><?= h(t('s128')) ?></div><div class="sub"><?= h(t('s55')) ?></div></div>
        <a href="renewals/index.php" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;"><?= h(t('s56')) ?></a>
      </div>
      <?php if (empty($upcomingRenewals)): ?>
        <div style="padding:20px;color:var(--text-dim);font-size:13px;"><?= h(t('s57')) ?></div>
      <?php endif; ?>
      <?php foreach ($upcomingRenewals as $item):
        $days = (int)$item['days_left'];
        $color = $days < 0 ? 'rose' : ($days <= 7 ? 'rose' : ($days <= 15 ? 'amber' : 'rail'));
      ?>
        <div style="padding:13px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;">
          <span style="width:8px;height:8px;border-radius:50%;background:var(--<?= $color ?>);flex-shrink:0;"></span>
          <div><div style="font-size:13px;font-weight:500;"><?= h($item['item_name']) ?></div><div style="font-size:11.5px;color:var(--text-dim);"><?= h($item['dept_name']) ?></div></div>
          <span class="ms-auto font-mono" style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:6px;background:var(--<?= $color ?>-soft);color:var(--<?= $color ?>);">
            <?= $days < 0 ? 'Expired' : $days . ' दिवस' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($todayAttendanceIssues)): ?>
      <div class="panel mt-3">
        <div class="panel-head d-flex justify-content-between align-items-center">
          <div><div class="title">👆 आज गैरहजर/उशीर</div><div class="sub"><?= date('d M Y') ?></div></div>
          <a href="staff/attendance.php" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;">अहवाल →</a>
        </div>
        <?php foreach ($todayAttendanceIssues as $ai): ?>
          <div style="padding:11px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $ai['status'] === 'absent' ? 'var(--rose)' : 'var(--amber)' ?>;flex-shrink:0;"></span>
            <div><div style="font-size:13px;font-weight:500;"><?= h($ai['name']) ?></div><div style="font-size:11px;color:var(--text-dim);"><?= h($ai['dept_name'] ?: '') ?></div></div>
            <span class="ms-auto" style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:6px;background:<?= $ai['status'] === 'absent' ? 'var(--rose-soft)' : '#FBEBD6' ?>;color:<?= $ai['status'] === 'absent' ? 'var(--rose)' : 'var(--amber)' ?>;">
              <?= $ai['status'] === 'absent' ? '❌ गैरहजर' : '⏰ उशीर' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($latestSatsang && isManagementOrAbove()): ?>
      <div class="panel mt-3">
        <div class="panel-head d-flex justify-content-between align-items-center">
          <div><div class="title">🧘 शेवटच्या सत्संगाची हजेरी</div><div class="sub"><?= date('d M Y', strtotime($latestSatsang['event_date'])) ?><?= $latestSatsang['organizer_dept'] ? ' · ' . h($latestSatsang['organizer_dept']) : '' ?></div></div>
          <a href="meditation/attendance-report.php?id=<?= $latestSatsang['id'] ?>" target="_blank" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;">अहवाल →</a>
        </div>
        <div style="padding:14px 20px;display:flex;gap:24px;">
          <div><div style="font-size:11px;color:var(--text-dim);">उपस्थित</div><div class="font-mono" style="font-weight:700;font-size:18px;color:var(--rail);"><?= $latestSatsang['present'] ?></div></div>
          <div><div style="font-size:11px;color:var(--text-dim);">अनुपस्थित</div><div class="font-mono" style="font-weight:700;font-size:18px;color:var(--rose);"><?= $latestSatsang['absent'] ?></div></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($taskSummary !== null): ?>
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title"><?= h(t('s129')) ?></div><div class="sub"><?= h(t('s7')) ?> <?= $taskSummary['pending'] ?> <?= h(t('s58')) ?> <?= $taskSummary['in_progress'] ?> <?= h(t('s59')) ?> <?= $taskSummary['completed'] ?></div></div>
        <a href="tasks/index.php" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;"><?= h(t('s130')) ?></a>
      </div>
      <table class="rtable">
        <thead><tr><th><?= h(t('s14')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s6')) ?></th><th>Target Date</th><th><?= h(t('s2')) ?></th></tr></thead>
        <tbody>
          <?php if (empty($recentTasks)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:20px;"><?= h(t('s131')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($recentTasks as $task):
            $isOverdue = strtotime($task['target_date']) < strtotime(date('Y-m-d')) && $task['status'] !== 'completed';
            $chip = $isOverdue ? 'overdue' : $statusChipMap[$task['status']];
            $chipText = $isOverdue ? 'मुदत संपली' : $statusLabelsMap[$task['status']];
          ?>
            <tr>
              <td style="font-weight:500;"><?= h($task['title']) ?></td>
              <td><?= h($task['dept_name']) ?></td>
              <td><?= h($task['assignee_name']) ?></td>
              <td class="font-mono"><?= date('d M Y', strtotime($task['target_date'])) ?></td>
              <td><span class="status-chip <?= $chip ?>"><?= $chipText ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (array_sum($trendTotal) > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [
      { label: 'एकूण', data: <?= json_encode($trendTotal) ?>, borderColor: '#6B7080', backgroundColor: 'rgba(107,112,128,.08)', tension: .35, fill: true },
      { label: 'पूर्ण', data: <?= json_encode($trendCompleted) ?>, borderColor: '#1B8A83', backgroundColor: 'rgba(27,138,131,.12)', tension: .35, fill: true }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 12 }, boxWidth: 12 } } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 } } },
      y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#EEF0F4' } }
    }
  }
});
});
</script>
<?php endif; ?>

<?php if (!empty($deptDistribution)): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('deptDistChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($deptDistribution, 'name'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      data: <?= json_encode(array_map('intval', array_column($deptDistribution, 'cnt'))) ?>,
      backgroundColor: ['#1B8A83','#C97F1D','#B23A48','#6B7080','#3B6FC9','#8A5CD6','#2FA37A','#D4A017','#5D8AA8','#A0522D'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10, padding: 8 } } },
    cutout: '60%'
  }
});
});
</script>
<?php endif; ?>
</div>

<?php if ($showCampusBreakdown): ?>
<div id="dashTabCampus" style="display:none;">
  <div class="page-title mb-1">🏛️ Campus-निहाय संपूर्ण विभाजन</div>
  <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">वरच्या Tab मधलं चित्र संपूर्ण संस्थेचं होतं — इथे तेच, प्रत्येक Trust-कार्यालय आणि College/School साठी स्वतंत्रपणे.</p>

  <?php renderDashboardEntityCard('📊 संपूर्ण Trust — Overall', h(activeOrgName()) . ' अंतर्गत सर्व School/College + Trust-स्तरीय एकत्रित', $overallMetrics, 'overallChart', 'var(--ink)'); ?>
  <?php renderDashboardEntityCard('🏛️ Trust-स्तरीय (मुख्य कार्यालय)', 'Trust Office व केंद्रीय विभाग', $trustMetrics, 'trustChart', 'linear-gradient(135deg, #10162B, #1B3A5C)'); ?>
  <?php foreach ($collegeRows as $i => $row): $c = $row['campus']; ?>
    <?php renderDashboardEntityCard('🏫 ' . h($c['name']), h($collegeTypeLabels[$c['college_type']] ?? '') . ($c['principal_name'] ? ' · प्राचार्य: ' . h($c['principal_name']) : ''), $row, 'college' . $i . 'Chart', 'linear-gradient(135deg, #10162B, #0F5E58)'); ?>
  <?php endforeach; ?>
  <?php if (empty($collegeRows)): ?>
    <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कोणतंही College/School जोडलेलं नाही</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php if (empty($user['onboarding_seen'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  setTimeout(function () {
    eowmsStartTour([
      { selector: '.rail-brand', title: '👋 स्वागत आहे!', text: 'हे तुमच्या संस्थेचं Command Center आहे — डावीकडे सगळे Modules दिसतील.' },
      { selector: '.global-search', title: '⌨️ कुठेही पटकन जा', text: 'इथे Click करा, किंवा Ctrl+K दाबा — कुठलंही Page, Module, किंवा व्यक्ती लगेच शोधता येईल.' },
      { selector: '.rail-sidebar', title: '📂 Sidebar', text: 'तुम्हाला दिलेले सगळे Modules इथे दिसतील — Click करून आत जा.' },
      { selector: '.theme-toggle-btn', title: '🌙 Dark Mode', text: 'रात्री काम करताना डोळ्यांना आराम — इथून Light/Dark Mode बदलता येईल.' },
    ]);
  }, 500);
});
</script>
<?php endif; ?>
