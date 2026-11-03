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


$stmt = $pdo->prepare("SELECT COUNT(*) c FROM renewal_items WHERE organization_id=? AND status <> 'inactive' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)$deptScopeSql");
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


// 🧠 Smart Command Center — Requests / Tasks / नूतनीकरण / विभाग-आरोग्य
require __DIR__ . '/includes/dashboard-smart.php';

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

<?php $smartPart = 'alerts'; require __DIR__ . '/includes/dashboard-smart-view.php'; $smartPart = 'main'; ?>

<?php require __DIR__ . '/includes/dashboard-smart-view.php'; ?>

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

<div class="mt-3">
<div class="panel mb-3 dash-analytics" id="dashAnalytics" data-mgmt="<?= $isMgmtView ? '1' : '0' ?>">
  <div class="panel-head dash-an-head">
    <div>
      <div class="title">📊 कामाचं विश्लेषण</div>
      <div class="sub" id="dashRangeLabel"><?= $isMgmtView ? 'संपूर्ण संस्था' : 'माझा विभाग' ?> — कालावधी निवडा</div>
    </div>
    <div class="dash-range" role="group" aria-label="कालावधी">
      <button type="button" data-range="today">आज</button>
      <button type="button" data-range="7d" class="active">७ दिवस</button>
      <button type="button" data-range="30d">३० दिवस</button>
      <button type="button" data-range="month">हा महिना</button>
      <button type="button" data-range="90d">३ महिने</button>
      <button type="button" data-range="year">हे वर्ष</button>
      <button type="button" data-range="custom"><i class="fa-solid fa-calendar-days"></i> Custom</button>
    </div>
  </div>
  <form class="dash-custom" id="dashCustom" hidden>
    <label>पासून <input type="date" id="dashFrom" required></label>
    <label>पर्यंत <input type="date" id="dashTo" required></label>
    <button type="submit" class="btn btn-sm btn-dark">दाखवा</button>
  </form>
  <div class="dash-kpis">
    <div class="dash-kpi"><span>एकूण कामं</span><b id="dkTotal">—</b></div>
    <div class="dash-kpi ok"><span>पूर्ण</span><b id="dkDone">—</b></div>
    <div class="dash-kpi warn"><span>प्रलंबित / सुरू</span><b id="dkOpen">—</b></div>
    <div class="dash-kpi"><span>पूर्णत्व दर</span><b id="dkRate">—</b><i class="dash-rate-bar"><i id="dkRateBar"></i></i></div>
  </div>
  <div class="dash-an-body" id="dashAnBody">
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="dash-card">
          <div class="dash-card-head"><span>कामाचा कल — एकूण vs पूर्ण</span>
            <span class="dash-toggle"><button type="button" data-trend="bar" class="active" title="Bar Chart"><i class="fa-solid fa-chart-column"></i></button><button type="button" data-trend="line" title="Line Chart"><i class="fa-solid fa-chart-line"></i></button></span>
          </div>
          <div class="dash-canvas tall"><canvas id="dcTrend"></canvas></div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="dash-card">
          <div class="dash-card-head"><span>कामाची स्थिती</span></div>
          <div class="dash-canvas tall"><canvas id="dcStatus"></canvas></div>
        </div>
      </div>
      <?php if ($isMgmtView): ?>
      <div class="col-lg-5">
        <div class="dash-card">
          <div class="dash-card-head"><span>विभागनिहाय वाटा (Pie)</span></div>
          <div class="dash-canvas tall"><canvas id="dcDeptPie"></canvas></div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="dash-card">
          <div class="dash-card-head"><span>विभागनिहाय — पूर्ण vs प्रलंबित</span></div>
          <div class="dash-canvas tall"><canvas id="dcDeptBar"></canvas></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-md-6">
        <div class="dash-card">
          <div class="dash-card-head"><span>कामाच्या विनंत्या (Work Requests)</span></div>
          <div class="dash-canvas"><canvas id="dcRequests"></canvas></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="dash-card">
          <div class="dash-card-head"><span>Tasks — मुदत या कालावधीत</span></div>
          <div class="dash-canvas"><canvas id="dcTasks"></canvas></div>
        </div>
      </div>
    </div>
    <div class="dash-loading" id="dashLoading"><span class="spinner-border spinner-border-sm"></span> लोड होत आहे…</div>
  </div>
</div>
</div>

<script src="assets/js/dashboard-charts.js?v=1"></script>
<script src="assets/js/dashboard-smart.js?v=1"></script>
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
    ], <?= (int)$user['id'] ?>);
  }, 500);
});
</script>
<?php endif; ?>
