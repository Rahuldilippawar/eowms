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
// व्याप: Management / केंद्रीय प्रमुख → संपूर्ण संस्था; 🏛️ Campus प्रमुख (Principal/Manager) → त्याचा Campus;
// विभाग-प्रमुख/कर्मचारी → स्वतःचा विभाग. सगळ्या Queries खाली $scopeDepts वरून Scope केल्या आहेत.
require_once __DIR__ . '/includes/campus-heads.php';
$scopeDepts = dashboardScopeDeptIds($pdo);
$isMgmtView = $scopeDepts === null;
$headInfo = campusHeadInfo($pdo, $user['id']);
$isCampusHeadView = !$isMgmtView && !empty($headInfo['campuses']);
$myDeptId = $user['department_id'] ?? 0;
[$deptScopeSql, $deptScopeParams] = scopeInSql('department_id', $scopeDepts);

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id=? AND work_date=CURDATE()$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
$todayReports = $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id=? AND is_active=1$deptScopeSql");
$stmt->execute(array_merge([$orgId], $deptScopeParams));
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
        WHERE d.organization_id=? AND d.is_active=1" . scopeInSql('d.id', $scopeDepts)[0] . " ORDER BY c.name, d.name_local
    ");
    $stmt->execute(array_merge([$orgId], scopeInSql('d.id', $scopeDepts)[1]));
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
    if (!$isMgmtView) { [$__s, $__p] = scopeInSql('u.department_id', $scopeDepts); $todayAttSql .= $__s; $taParams = array_merge($taParams, $__p); }
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
// Access नुसार: Management / केंद्रीय प्रमुख / Super Admin → सर्व Campus; 🏛️ Campus प्रमुख → फक्त त्याचं College
$showCampusBreakdown = $isMgmtView || hasPermission('manage_campuses') || $isCampusHeadView;
$campusOnlyMine = $isCampusHeadView && !hasPermission('manage_campuses');
if ($showCampusBreakdown) {
    require_once __DIR__ . '/includes/dashboard-helpers.php';

    if ($campusOnlyMine) {
        $__ids = array_keys($headInfo['campuses']);
        $campusStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 AND id IN (" . implode(',', array_fill(0, count($__ids), '?')) . ") ORDER BY name");
        $campusStmt->execute(array_merge([$orgId], $__ids));
    } else {
        $campusStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 AND (college_type IS NULL OR college_type != 'Trust HQ') ORDER BY name");
        $campusStmt->execute([$orgId]);
    }
    $allCampuses = $campusStmt->fetchAll();
    $campusHeadNames = [];
    if (campusHeadsReady($pdo)) {
        $__hs = $pdo->prepare("SELECT h.campus_id, u.name, h.title FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.organization_id = ? AND h.campus_id IS NOT NULL ORDER BY u.name");
        $__hs->execute([$orgId]);
        foreach ($__hs->fetchAll() as $__r) { $campusHeadNames[(int)$__r['campus_id']][] = $__r['name'] . ($__r['title'] ? ' (' . $__r['title'] . ')' : ''); }
    }

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

<div class="dash-hero ah">
  <div class="dash-hero-bg"></div>
  <span class="ah-orb ah-orb1"></span><span class="ah-orb ah-orb2"></span><span class="ah-grid"></span>
  <div class="ah-in">
    <div class="page-eyebrow ah-eyebrow"><?= h(activeOrgName()) ?></div>
    <div class="ah-row">
      <div class="ah-hello">
        <div class="ah-emoji"><?= h(trim(mb_substr($greeting, 0, 2))) ?></div>
        <div>
          <div class="ah-greet"><?= h(trim(mb_substr($greeting, 2))) ?>, <span><?= h(explode(' ', $user['name'])[0]) ?>!</span></div>
          <div class="ah-date"><i class="fa-regular fa-calendar"></i> <?= date('l, d F Y') ?> · <?= h(t('s122')) ?></div>
        </div>
      </div>
      <div class="ah-btns">
        <a href="daily-work/entry.php" class="dash-quick-btn ah-btn ah-pri"><i class="fa-solid fa-pen-to-square"></i><span>काम नोंदवा</span></a>
        <?php if (hasPermission('assign_tasks')): ?>
          <a href="tasks/assign.php" class="dash-quick-btn ah-btn"><i class="fa-solid fa-list-check"></i><span>Task Assign करा</span></a>
        <?php endif; ?>
        <a href="renewals/index.php" class="dash-quick-btn ah-btn"><i class="fa-solid fa-shield-halved"></i><span>नूतनीकरण</span></a>
      </div>
    </div>
  </div>
</div>
<style>
  .ah{background:linear-gradient(125deg,#1A1D45 0%,#141633 45%,#2A1630 80%,#3A1A24 100%);border:1px solid rgba(255,255,255,.08);box-shadow:0 18px 40px -22px rgba(20,16,50,.75),inset 0 1px 0 rgba(255,255,255,.06);}
  .ah-orb{position:absolute;border-radius:50%;filter:blur(40px);pointer-events:none;opacity:.55;animation:ahFloat 12s ease-in-out infinite alternate;}
  .ah-orb1{width:260px;height:260px;right:-60px;top:-110px;background:radial-gradient(circle,#F26B2A,transparent 70%);}
  .ah-orb2{width:220px;height:220px;left:-70px;bottom:-120px;background:radial-gradient(circle,#6D5BF2,transparent 70%);animation-delay:-6s;}
  .ah-grid{position:absolute;inset:0;pointer-events:none;opacity:.07;background-image:radial-gradient(rgba(255,255,255,.9) 1px,transparent 1px);background-size:18px 18px;-webkit-mask-image:linear-gradient(90deg,transparent,#000 60%);mask-image:linear-gradient(90deg,transparent,#000 60%);}
  @keyframes ahFloat{from{transform:translate(0,0) scale(1);}to{transform:translate(-30px,20px) scale(1.12);}}
  .ah-in{position:relative;z-index:1;}
  .ah-eyebrow{color:#A9AFD0 !important;letter-spacing:.14em;}
  .ah-row{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-top:8px;}
  .ah-hello{display:flex;align-items:center;gap:14px;min-width:0;}
  .ah-emoji{width:54px;height:54px;flex-shrink:0;border-radius:16px;display:grid;place-items:center;font-size:28px;background:linear-gradient(145deg,rgba(255,255,255,.16),rgba(255,255,255,.04));border:1px solid rgba(255,255,255,.16);box-shadow:0 8px 22px -10px rgba(242,107,42,.8);animation:ahPop .6s cubic-bezier(.2,.9,.3,1.4) both;}
  @keyframes ahPop{from{transform:scale(.6) rotate(-12deg);opacity:0;}to{transform:none;opacity:1;}}
  .ah-greet{font-family:'Space Grotesk',inherit;font-weight:700;font-size:27px;line-height:1.2;color:#fff;}
  .ah-greet span{background:linear-gradient(90deg,#FFD7B5,#F59E6B 55%,#F26B2A);-webkit-background-clip:text;background-clip:text;color:transparent;}
  .ah-date{font-size:13px;color:#B7BBD6;margin-top:5px;display:flex;align-items:center;gap:6px;}
  .ah-date i{color:#F59E6B;}
  .ah-btns{display:flex;gap:10px;flex-wrap:wrap;}
  .ah-btn{gap:9px !important;padding:10px 16px !important;border-radius:12px !important;background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.14) !important;backdrop-filter:blur(6px);transition:transform .18s ease,background .18s ease,box-shadow .18s ease !important;}
  .ah-btn i{width:26px;height:26px;border-radius:8px;display:grid;place-items:center;background:rgba(255,255,255,.12);font-size:12.5px;}
  .ah-btn:hover{transform:translateY(-2px);background:rgba(255,255,255,.14) !important;box-shadow:0 10px 22px -12px rgba(0,0,0,.6);}
  .ah-pri{background:linear-gradient(135deg,#F26B2A,#D9530F) !important;border-color:transparent !important;box-shadow:0 10px 24px -12px rgba(242,107,42,.9);}
  .ah-pri i{background:rgba(255,255,255,.22);}
  .ah-pri:hover{background:linear-gradient(135deg,#F57A3D,#E05A14) !important;}
  @media (prefers-reduced-motion: reduce){ .ah-orb,.ah-emoji{animation:none;} }
  @media (max-width: 640px){
    .dash-hero.ah .ah-in > .ah-row{flex-direction:column !important;align-items:stretch !important;}
    .dash-hero.ah .ah-btns{width:100%;}
    .ah-emoji{width:46px;height:46px;font-size:24px;border-radius:14px;}
    .ah-greet{font-size:21px;}
    .ah-btns{display:grid !important;grid-template-columns:repeat(<?= hasPermission('assign_tasks') ? 3 : 2 ?>,minmax(0,1fr));gap:8px;flex-wrap:nowrap !important;overflow:visible !important;}
    .ah-btn{flex-direction:column;justify-content:center;text-align:center;padding:10px 6px !important;font-size:11.5px !important;white-space:normal !important;line-height:1.2;}
  }
</style>

<?php if ($headInfo['central'] || $headInfo['campuses']): ?>
<!-- 🏛️ Campus प्रमुख / केंद्रीय प्रमुख -->
<div class="panel mt-3" style="padding:14px 18px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;border-left:4px solid var(--rail);">
  <div style="font-size:30px;line-height:1;">🏛️</div>
  <div style="flex:1;min-width:220px;">
    <?php if ($headInfo['central']): ?>
      <div style="font-weight:800;font-size:15px;">सर्व Campus चे केंद्रीय प्रमुख<?= $headInfo['central_title'] ? ' — ' . h($headInfo['central_title']) : '' ?></div>
      <div style="font-size:12.5px;color:var(--text-dim);">या Dashboard वर संपूर्ण संस्थेचे आकडे; प्रत्येक Campus चं वेगळं चित्र "सर्व Campus Dashboard" मध्ये.</div>
    <?php else: ?>
      <div style="font-weight:800;font-size:15px;"><?= h(implode(' · ', array_map(fn($c) => ($c['title'] ? $c['title'] . ' — ' : 'Campus प्रमुख — ') . $c['name'], $headInfo['campuses']))) ?></div>
      <div style="font-size:12.5px;color:var(--text-dim);">या Dashboard वर तुमच्या Campus चे सगळे विभाग, कर्मचारी, कामं आणि नूतनीकरण.</div>
    <?php endif; ?>
  </div>
  <button type="button" class="btn" onclick="var b=document.getElementById('dashTabCampusBtn'); if(b){ b.click(); }" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:12.5px;padding:7px 14px;font-weight:600;"><i class="fa-solid fa-gauge-high me-1"></i><?= $headInfo['central'] ? 'सर्व Campus Dashboard' : 'माझा College Dashboard' ?></button>
</div>
<?php endif; ?>

<?php if ($showCampusBreakdown): ?>
<div class="dash-tabs" role="tablist">
  <button type="button" class="dash-tab active" onclick="eowmsSwitchDashTab('overview', this)"><?= $campusOnlyMine ? '📋 माझ्या Campus चं दृश्य' : '📋 माझं/संस्थेचं दृश्य' ?></button>
  <button type="button" class="dash-tab" id="dashTabCampusBtn" onclick="eowmsSwitchDashTab('campus', this)"><?= $campusOnlyMine ? '🏛️ माझा College Dashboard' : '🏛️ सर्व Campus Dashboard' ?></button>
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
        <span class="kpi-label"><?= $isMgmtView ? h(t('s54')) : ($isCampusHeadView ? 'Campus चे विभाग' : 'माझा विभाग') ?></span>
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

    <?php if ($latestSatsang && isManagementOrAbove() && moduleAllowed('meditation')): ?>
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
      <div class="sub" id="dashRangeLabel"><?= $isMgmtView ? 'संपूर्ण संस्था' : ($isCampusHeadView ? '🏛️ ' . h(implode(', ', array_column($headInfo['campuses'], 'name'))) : 'माझा विभाग') ?> — कालावधी निवडा</div>
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
<script src="assets/js/dashboard-smart.js?v=3"></script>
</div>

<?php if ($showCampusBreakdown): ?>
<div id="dashTabCampus" style="display:none;">
  <?php if ($campusOnlyMine): ?>
  <div class="page-title mb-1">🏛️ माझा College Dashboard</div>
  <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">तुम्ही प्रमुख असलेल्या Campus/College चे सगळे विभाग, कर्मचारी, Tasks, नूतनीकरण आणि पत्रव्यवहार.</p>
  <?php else: ?>
  <div class="page-title mb-1">🏛️ सर्व Campus Dashboard</div>
  <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">संपूर्ण संस्थेचं एकत्रित चित्र, आणि प्रत्येक Trust-कार्यालय व College/School साठी स्वतंत्रपणे.</p>

  <?php renderDashboardEntityCard('📊 संपूर्ण Trust — Overall', h(activeOrgName()) . ' अंतर्गत सर्व School/College + Trust-स्तरीय एकत्रित', $overallMetrics, 'overallChart', 'var(--ink)'); ?>
  <?php renderDashboardEntityCard('🏛️ Trust-स्तरीय (मुख्य कार्यालय)', 'Trust Office व केंद्रीय विभाग', $trustMetrics, 'trustChart', 'linear-gradient(135deg, #10162B, #1B3A5C)'); ?>
  <?php endif; ?>
  <?php foreach ($collegeRows as $i => $row): $c = $row['campus']; ?>
    <?php $__hn = $campusHeadNames[(int)$c['id']] ?? []; renderDashboardEntityCard('🏫 ' . h($c['name']), h($collegeTypeLabels[$c['college_type']] ?? '') . ($__hn ? ' · 🏛️ प्रमुख: ' . h(implode(', ', $__hn)) : ($c['principal_name'] ? ' · प्राचार्य: ' . h($c['principal_name']) : '')), $row, 'college' . $i . 'Chart', 'linear-gradient(135deg, #10162B, #0F5E58)'); ?>
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
