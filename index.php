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

<?php
// ✨ Smart Hero — Live घड्याळ, आजचं चित्र, माझी हजेरी/Tasks, सर्वात महत्त्वाची सूचना
$heroMyTasks = ['pending' => 0, 'overdue' => 0];
try {
    $__t = $pdo->prepare("SELECT COUNT(*) p, COALESCE(SUM(target_date < CURDATE()), 0) o FROM tasks WHERE assigned_to = ? AND status <> 'completed'");
    $__t->execute([$user['id']]);
    $__r = $__t->fetch();
    $heroMyTasks = ['pending' => (int)$__r['p'], 'overdue' => (int)$__r['o']];
} catch (PDOException $e) {}
$heroAtt = null;
try {
    $__a = $pdo->prepare("SELECT first_punch_time, last_punch_time, status, is_late FROM staff_attendance WHERE user_id = ? AND attendance_date = CURDATE() LIMIT 1");
    $__a->execute([$user['id']]);
    $heroAtt = $__a->fetch() ?: null;
} catch (PDOException $e) {}
$heroBad = array_values(array_filter($smart['alerts'] ?? [], fn($a) => $a['sev'] === 'bad'));
$heroTop = $smart['alerts'][0] ?? null;
$mrDays = ['रविवार', 'सोमवार', 'मंगळवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
$mrMonths = [1 => 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
$heroDate = $mrDays[(int)date('w')] . ', ' . date('j') . ' ' . $mrMonths[(int)date('n')] . ' ' . date('Y');
$heroDayPct = (int)round((time() - strtotime('today')) * 100 / 86400);
$heroRole = $headInfo['central'] ? ('🌐 ' . ($headInfo['central_title'] ?: 'केंद्रीय प्रमुख')) : ($headInfo['campuses'] ? ('🏛️ ' . (reset($headInfo['campuses'])['title'] ?: 'Campus प्रमुख')) : ($user['designation'] ?? ''));
$heroFirst = explode(' ', trim($user['name']))[0];
$heroDonePct = $todayReports ? (int)round($todayCompleted * 100 / max(1, $todayReports)) : 0;
?>
<div class="dash-hero sh">
  <div class="dash-hero-bg"></div>
  <div class="sh-in">
    <div class="sh-top">
      <div class="sh-left">
        <div class="page-eyebrow" style="color:#9FA6C0;"><?= h(activeOrgName()) ?></div>
        <div class="sh-greet"><span id="shGreetIcon"><?= mb_substr($greeting, 0, 2) ?></span> <span id="shGreetText"><?= h(trim(mb_substr($greeting, 2))) ?></span>, <?= h($heroFirst) ?>!</div>
        <div class="sh-sub">
          <?php if ($heroRole): ?><span class="sh-role"><?= h($heroRole) ?></span><?php endif; ?>
          <span><i class="fa-regular fa-calendar"></i> <?= h($heroDate) ?></span>
        </div>
      </div>
      <div class="sh-clock" title="भारतीय वेळ (IST)">
        <div class="sh-time" id="shTime" data-server="<?= time() ?>"><?= date('h:i') ?><small><?= date(':s') ?></small></div>
        <div class="sh-ampm"><span id="shAmpm"><?php $__h = (int)date('G'); echo $__h < 12 ? 'सकाळ' : ($__h < 17 ? 'दुपार' : ($__h < 20 ? 'संध्याकाळ' : 'रात्र')); ?></span> · IST</div>
        <div class="sh-daybar" title="आजचा दिवस"><i id="shDayBar" style="width:<?= $heroDayPct ?>%;"></i></div>
      </div>
    </div>

    <div class="sh-chips">
      <a class="sh-chip" href="daily-work/report.php"><i class="fa-regular fa-clipboard"></i><b><?= (int)$todayReports ?></b><span>आजच्या नोंदी</span></a>
      <a class="sh-chip" href="daily-work/report.php"><span class="sh-ring" style="--p:<?= $heroDonePct ?>;"><?= $heroDonePct ?>%</span><span>पूर्ण झालेली कामं</span></a>
      <a class="sh-chip <?= $heroMyTasks['overdue'] ? 'bad' : '' ?>" href="tasks/my-tasks.php"><i class="fa-solid fa-list-check"></i><b><?= $heroMyTasks['pending'] ?></b><span>माझे Tasks बाकी<?= $heroMyTasks['overdue'] ? ' · ' . $heroMyTasks['overdue'] . ' मुदतबाह्य' : '' ?></span></a>
      <a class="sh-chip <?= $heroAtt ? ((int)$heroAtt['is_late'] ? 'warn' : 'ok') : '' ?>" href="staff/attendance.php"><i class="fa-solid fa-fingerprint"></i>
        <?php if ($heroAtt && $heroAtt['first_punch_time']): ?><b><?= h(date('h:i', strtotime($heroAtt['first_punch_time']))) ?></b><span>माझी हजेरी<?= (int)$heroAtt['is_late'] ? ' · उशीर' : ' ✓' ?></span>
        <?php elseif ($heroAtt): ?><b><?= $heroAtt['status'] === 'absent' ? '✗' : '✓' ?></b><span>माझी हजेरी</span>
        <?php else: ?><b>—</b><span>आज Punch नाही</span><?php endif; ?></a>
      <a class="sh-chip <?= $heroBad ? 'bad' : 'ok' ?>" href="#" onclick="var x=document.querySelector('.sm-attn'); if(x){x.scrollIntoView({behavior:'smooth',block:'start'});} return false;"><i class="fa-solid fa-bell"></i><b><?= count($smart['alerts'] ?? []) ?></b><span>लक्ष द्यावं असं</span></a>
    </div>

    <div class="sh-bottom">
      <div class="sh-insight <?= $heroTop ? h($heroTop['sev']) : 'ok' ?>">
        <?php if ($heroTop): ?>
          <i class="<?= h($heroTop['icon']) ?>"></i> <span><b><?= (int)$heroTop['cnt'] ?></b> <?= h($heroTop['text']) ?></span> <a href="<?= h($heroTop['href']) ?>">पाहा →</a>
        <?php else: ?>
          <i class="fa-solid fa-circle-check"></i> <span>सर्व काही नियंत्रणात — आज कोणतीही तातडीची बाब नाही</span>
        <?php endif; ?>
      </div>
      <div class="sh-actions">
        <a href="daily-work/entry.php" class="dash-quick-btn pri"><i class="fa-solid fa-pen-to-square"></i> काम नोंदवा</a>
        <?php if (hasPermission('assign_tasks')): ?><a href="tasks/assign.php" class="dash-quick-btn"><i class="fa-solid fa-list-check"></i> Task Assign करा</a><?php endif; ?>
        <a href="tasks/my-tasks.php" class="dash-quick-btn"><i class="fa-solid fa-thumbtack"></i> माझे Tasks</a>
        <a href="renewals/index.php" class="dash-quick-btn"><i class="fa-solid fa-shield-halved"></i> नूतनीकरण</a>
        <?php if ($isMgmtView): ?><a href="reports/daily.php" class="dash-quick-btn"><i class="fa-solid fa-moon"></i> दैनिक अहवाल</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<style>
  .sh .sh-in{position:relative;z-index:1;}
  .sh-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;}
  .sh-greet{font-family:'Space Grotesk',inherit;font-weight:700;font-size:26px;color:#fff;margin-top:4px;line-height:1.25;}
  .sh-sub{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:13px;color:#B7BBD6;margin-top:6px;}
  .sh-role{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);color:#FDBA8C;font-weight:700;font-size:11.5px;padding:3px 10px;border-radius:99px;}
  .sh-clock{text-align:right;color:#fff;min-width:150px;}
  .sh-time{font-family:'Space Grotesk',monospace;font-weight:700;font-size:34px;letter-spacing:.02em;line-height:1;font-variant-numeric:tabular-nums;}
  .sh-time small{font-size:16px;color:#9FA6C0;font-weight:600;}
  .sh-ampm{font-size:11.5px;color:#9FA6C0;margin-top:4px;}
  .sh-daybar{height:4px;border-radius:99px;background:rgba(255,255,255,.12);margin-top:8px;overflow:hidden;}
  .sh-daybar i{display:block;height:100%;background:linear-gradient(90deg,#F59E0B,var(--rail,#D9530F));border-radius:99px;transition:width 1s linear;}
  .sh-chips{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:18px;}
  .sh-chip{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:10px 12px;color:#fff;text-decoration:none;transition:transform .15s ease,background .15s ease;min-width:0;}
  .sh-chip:hover{background:rgba(255,255,255,.13);color:#fff;transform:translateY(-1px);}
  .sh-chip > i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:rgba(255,255,255,.1);font-size:14px;flex-shrink:0;}
  .sh-chip b{font-size:19px;font-family:'Space Grotesk',inherit;font-variant-numeric:tabular-nums;}
  .sh-chip span{font-size:11.5px;color:#B7BBD6;line-height:1.25;}
  .sh-chip.ok > i{background:rgba(22,163,74,.25);color:#86EFAC;} .sh-chip.warn > i{background:rgba(245,158,11,.25);color:#FCD34D;} .sh-chip.bad > i{background:rgba(220,38,38,.28);color:#FCA5A5;}
  .sh-chip.bad{border-color:rgba(248,113,113,.4);}
  .sh-ring{--p:0;width:36px;height:36px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-size:10px;font-weight:800;color:#fff !important;background:radial-gradient(closest-side,#1B1E3D 74%,transparent 76% 100%),conic-gradient(#22C55E calc(var(--p)*1%),rgba(255,255,255,.15) 0);}
  .sh-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;}
  .sh-insight{display:flex;align-items:center;gap:8px;font-size:13px;color:#E6E8F5;background:rgba(255,255,255,.06);border-radius:10px;padding:8px 12px;border-left:3px solid #22C55E;min-width:0;flex:1 1 280px;}
  .sh-insight.bad{border-left-color:#F87171;} .sh-insight.warn{border-left-color:#FBBF24;} .sh-insight.info{border-left-color:#60A5FA;}
  .sh-insight i{color:#FDBA8C;} .sh-insight.ok i{color:#86EFAC;}
  .sh-insight a{color:#FDBA8C;font-weight:700;text-decoration:none;white-space:nowrap;margin-left:auto;}
  .sh-actions{display:flex;gap:8px;flex-wrap:wrap;}
  .dash-quick-btn.pri{background:var(--rail,#D9530F);border-color:var(--rail,#D9530F);}
  @media (max-width: 991px){ .sh-chips{grid-template-columns:repeat(3,minmax(0,1fr));} }
  @media (max-width: 640px){
    .sh-clock{text-align:left;min-width:0;width:100%;display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}
    .sh-time{font-size:26px;} .sh-daybar{flex-basis:100%;}
    .sh-greet{font-size:21px;}
    .sh-chips{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
    .sh-chip{padding:9px 10px;} .sh-chip b{font-size:17px;}
    .sh-chips .sh-chip:last-child{grid-column:1 / -1;}
    .sh-bottom{flex-direction:column !important;align-items:stretch !important;}
    .sh-insight{flex:0 0 auto;width:100%;}
    .sh-actions{flex-wrap:nowrap;overflow-x:auto;width:100%;scrollbar-width:none;-webkit-mask-image:linear-gradient(90deg,#000 88%,transparent);mask-image:linear-gradient(90deg,#000 88%,transparent);}
    .sh-actions .dash-quick-btn{flex-shrink:0;white-space:nowrap;}
  }
</style>
<script>
(function () {
  // ⏰ Live घड्याळ — Server च्या IST वेळेशी जुळवलेलं (Phone ची वेळ चुकीची असली तरी)
  var el = document.getElementById('shTime'); if (!el) return;
  var skew = parseInt(el.dataset.server, 10) * 1000 - Date.now();
  var ampm = document.getElementById('shAmpm'), bar = document.getElementById('shDayBar');
  var gi = document.getElementById('shGreetIcon'), gt = document.getElementById('shGreetText');
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    var d = new Date(Date.now() + skew + (330 + new Date().getTimezoneOffset()) * 60000); // IST
    var h = d.getHours(), h12 = h % 12 || 12;
    el.innerHTML = pad(h12) + ':' + pad(d.getMinutes()) + '<small>:' + pad(d.getSeconds()) + '</small>';
    var part = h < 12 ? 'सकाळ' : (h < 17 ? 'दुपार' : (h < 20 ? 'संध्याकाळ' : 'रात्र'));
    if (ampm) ampm.textContent = (window.eowmsT ? eowmsT(part) : part);
    if (bar) bar.style.width = Math.round((h * 3600 + d.getMinutes() * 60 + d.getSeconds()) / 864) + '%';
    if (gi && gt) {
      var g = h < 12 ? ['☀️', 'शुभ सकाळ'] : (h < 17 ? ['🌤️', 'नमस्कार'] : ['🌙', 'शुभ संध्याकाळ']);
      if (gi.textContent !== g[0]) { gi.textContent = g[0]; gt.textContent = window.eowmsT ? eowmsT(g[1]) : g[1]; }
    }
  }
  tick(); setInterval(tick, 1000);
})();
</script>

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
