<?php
// Expects $pdo, $user, $rootPath to already be available.
$rp = $rootPath ?? '';
$activeOrgId = activeOrgId();

// 🎯 Single-Purpose Access Mode — Restricted User असेल तर, इथेच एक
// पूर्णपणे वेगळा, Minimal Sidebar दाखवून थांबतो — खालचा सगळा (Normal
// System चा) Sidebar-Logic अजिबात चालत नाही.
$restrictedModulesForSidebar = getRestrictedModules();
if ($restrictedModulesForSidebar !== null) {
    $orgStmtR = $pdo->prepare("SELECT name AS org_name, logo_path FROM organizations WHERE id = ?");
    $orgStmtR->execute([$activeOrgId]);
    $orgInfoR = $orgStmtR->fetch() ?: ['org_name' => 'VJMAT', 'logo_path' => null];
    $registryR = getModuleRegistry();
    $currentDirR = basename(dirname($_SERVER['SCRIPT_NAME']));
    ?>
    <aside class="rail-sidebar">
      <div class="rail-brand">
        <?php if (!empty($orgInfoR['logo_path'])): ?>
          <img src="<?= $rp ?><?= h($orgInfoR['logo_path']) ?>" alt="Logo" style="width:34px;height:34px;object-fit:contain;border-radius:9px;background:#fff;padding:2px;">
        <?php else: ?>
          <div class="mark"><?= h(mb_substr($orgInfoR['org_name'], 0, 2)) ?></div>
        <?php endif; ?>
        <div>
          <div class="org-name"><?= h($orgInfoR['org_name']) ?></div>
          <div class="org-sub"><?= h($user['designation'] ?? '') ?></div>
        </div>
      </div>

      <div style="padding:14px 16px;background:rgba(201,127,29,.12);margin:10px 12px;border-radius:10px;border:1px solid rgba(201,127,29,.25);">
        <div style="font-size:10px;font-weight:700;letter-spacing:.5px;color:#C97F1D;text-transform:uppercase;">🎯 Single-Purpose Access</div>
        <div style="font-size:11px;color:#B7BBD6;margin-top:4px;">तुम्हाला फक्त खालील Module<?= count($restrictedModulesForSidebar) > 1 ? '्स' : '' ?> उपलब्ध आहे<?= count($restrictedModulesForSidebar) > 1 ? 'त' : '' ?>.</div>
      </div>

      <nav style="padding:6px 12px;">
        <?php foreach ($restrictedModulesForSidebar as $modKey):
          $modInfo = $registryR[$modKey] ?? null;
          if (!$modInfo) continue;
          $isActiveModule = in_array($currentDirR, $modInfo['dirs'], true);
        ?>
          <a href="<?= $rp . h($modInfo['dashboard']) ?>" class="tree-node <?= $isActiveModule ? 'active' : '' ?>" style="<?= $isActiveModule ? 'border-left:3px solid ' . h($modInfo['color']) . ';' : '' ?>">
            <i class="<?= h($modInfo['icon']) ?> fa-fw"></i><span><?= h($modInfo['label']) ?></span>
          </a>
        <?php endforeach; ?>

        <div style="margin:14px 0;border-top:1px solid rgba(255,255,255,.08);"></div>

        <a href="<?= $rp ?>staff/profile.php" class="tree-node">
          <i class="fa-solid fa-user fa-fw"></i><span>प्रोफाइल / पासवर्ड</span>
        </a>
        <a href="<?= $rp ?>logout.php" class="tree-node">
          <i class="fa-solid fa-right-from-bracket fa-fw"></i><span>Logout</span>
        </a>
      </nav>
    </aside>
    <?php
    return; // खालचा संपूर्ण Normal-Sidebar Logic इथेच थांबवतो
}


$deptStmt = $pdo->prepare("
    SELECT d.*, c.name AS campus_name, c.college_type
    FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_active = 1" . (isDeptScoped() ? " AND d.id = ?" : "") . "
    ORDER BY (c.college_type = 'Trust HQ') DESC, d.campus_id IS NULL DESC, c.name, d.name_local
");
$deptParams = isDeptScoped() ? [$activeOrgId, $user['department_id']] : [$activeOrgId];
$deptStmt->execute($deptParams);
$sidebarDepartments = $deptStmt->fetchAll();

$orgStmt = $pdo->prepare("SELECT name AS org_name, logo_path FROM organizations WHERE id = ?");
$orgStmt->execute([$activeOrgId]);
$orgInfo = $orgStmt->fetch() ?: ['org_name' => 'VJMAT', 'logo_path' => null];

// Super Admin only: list every organization for the switcher
$allOrgs = [];
if (hasRole('super_admin')) {
    $allOrgs = $pdo->query("SELECT id, name FROM organizations WHERE is_active = 1 ORDER BY name")->fetchAll();
}

$currentPage = basename($_SERVER['SCRIPT_NAME']);
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));

// या directories मध्ये असलो तर कोणता Group आपोआप उघडा राहील हे ठरवण्यासाठी
// (टीप: प्रत्येक Key इथे त्या Group च्या Collapse-Body मध्ये प्रत्यक्ष जो
// Link दिसतो, त्याच्याशी जुळलेली असावी — नाहीतर त्या पानावर असताना Group
// आपोआप उघडणार नाही.)
$dirToGroup = [
    'letters' => 'letters', 'requests' => 'letters', 'announcements' => 'letters',
    'suggestions' => 'letters', 'documents' => 'letters', 'emergency' => 'letters',
    'staff' => 'staff',
    'hostels' => 'hostel', 'residency' => 'hostel',
    'meditation' => 'activities', 'music' => 'activities', 'academics' => 'activities', 'sop' => 'activities',
    'events' => 'activities', 'guests' => 'activities',
    'allocations' => 'facilities', 'room-booking' => 'facilities',
    'messages' => 'communication', 'grievances' => 'communication',
    'vehicles' => 'assets', 'deadstock' => 'assets', 'maintenance' => 'assets',
    'cctv' => 'techdept', 'it' => 'techdept',
    'organizations' => 'admin', 'roles' => 'admin', 'custom-fields' => 'admin', 'backup' => 'admin',
    'activity-log' => 'admin', 'campuses' => 'admin', 'system-update' => 'admin',
];
// 'departments' आणि 'letters' (Admin-Group आतल्या Sub-Pages: categories,
// workflows, templates) — हे स्वतंत्रपणे, खाली नेमक्या पानानुसार ठरवतो,
// कारण या दोन्ही Directories मुख्य Tier/Group मध्येही वापरल्या जातात —
// साधं Directory-Match इथे चुकीचं ठरेल.
$activeGroup = $dirToGroup[$currentDir] ?? null;
if ($currentDir === 'departments' && $currentPage === 'index.php') { $activeGroup = 'admin'; }
if ($currentDir === 'letters' && in_array($currentPage, ['categories.php', 'workflows.php', 'workflow-create.php', 'workflow-edit.php'])) { $activeGroup = 'admin'; }
if ($currentDir === 'staff' && $currentPage === 'import.php') { $activeGroup = 'admin'; }
if ($currentDir === 'it' && in_array($currentPage, ['general-request.php', 'my-general-requests.php'])) { $activeGroup = 'letters'; }
if ($currentDir === 'about-manage' || $currentPage === 'about-manage.php') { $activeGroup = 'admin'; }

function navGroupOpen($groupKey, $activeGroup) { return $groupKey === $activeGroup ? 'show' : ''; }
function navGroupExpanded($groupKey, $activeGroup) { return $groupKey === $activeGroup ? 'true' : 'false'; }
?>
<aside class="rail-sidebar">
  <div class="rail-brand">
    <?php if (!empty($orgInfo['logo_path'])): ?>
      <img src="<?= $rp ?><?= h($orgInfo['logo_path']) ?>" alt="Logo" style="width:34px;height:34px;object-fit:contain;border-radius:9px;background:#fff;padding:2px;">
    <?php else: ?>
      <div class="mark"><?= h(mb_substr($orgInfo['org_name'], 0, 2)) ?></div>
    <?php endif; ?>
    <div>
      <div class="org-name"><?= h($orgInfo['org_name']) ?></div>
      <?php if (hasRole('super_admin')): ?>
        <div class="org-sub">Super Admin</div>
      <?php else: ?>
        <div class="org-sub"><?= h($user['designation']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (hasRole('super_admin') && count($allOrgs) > 1): ?>
    <div style="padding:0 14px 14px;">
      <form method="POST" action="<?= $rp ?>organizations/switch.php">
        <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
        <label style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#6E7396;font-weight:600;display:block;margin-bottom:5px;"><?= h(t('s292')) ?></label>
        <select name="organization_id" onchange="this.form.submit()" style="width:100%;background:#1B2340;border:1px solid rgba(255,255,255,.12);color:#fff;border-radius:8px;padding:7px 10px;font-size:12.5px;">
          <?php foreach ($allOrgs as $org): ?>
            <option value="<?= $org['id'] ?>" <?= $activeOrgId === (int)$org['id'] ? 'selected' : '' ?>><?= h($org['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  <?php endif; ?>

  <div style="padding:0 12px;">
    <input type="text" id="navQuickFind" placeholder="🔍 शोधा..." style="width:100%;background:#1B2340;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:8px;padding:8px 10px;font-size:12.5px;margin-bottom:8px;">
  </div>

  <div class="org-tree" id="navTreeRoot">

    <!-- ==================== मुख्य कामकाज (नेहमी उघडं) ==================== -->
    <div class="tier-label"><?= h(t('nav_command_center')) ?></div>
    <a href="<?= $rp ?>index.php" class="tree-node <?= $currentPage === 'index.php' && $currentDir !== 'daily-work' ? 'active' : '' ?>">
      <i class="fa-solid fa-gauge-high fa-fw"></i><span><?= h(t('nav_command_center')) ?></span>
    </a>
    <a href="<?= $rp ?>daily-work/entry.php" class="tree-node <?= $currentDir === 'daily-work' && in_array($currentPage, ['entry.php','report.php','save.php']) ? 'active' : '' ?>">
      <i class="fa-regular fa-clipboard fa-fw"></i><span><?= h(t('nav_daily_work')) ?></span>
    </a>
    <a href="<?= $rp ?>daily-work/my-work.php" class="tree-node <?= $currentDir === 'daily-work' && $currentPage === 'my-work.php' ? 'active' : '' ?>">
      <i class="fa-regular fa-square-check fa-fw"></i><span><?= h(t('nav_my_work')) ?></span>
    </a>
    <?php if (hasPermission('assign_tasks') || hasPermission('view_task_tracker')): ?>
      <a href="<?= $rp ?>tasks/index.php" class="tree-node <?= $currentDir === 'tasks' && in_array($currentPage, ['index.php','assign.php','report.php']) ? 'active' : '' ?>">
        <i class="fa-solid fa-list-check fa-fw"></i><span><?= h(t('nav_task_assignment')) ?></span>
      </a>
    <?php endif; ?>
    <?php // टास्क कोणालाही (HOD/Management सुद्धा) मिळू शकतो — म्हणून "माझे टास्क" नेहमी दिसतो ?>
    <a href="<?= $rp ?>tasks/my-tasks.php" class="tree-node <?= $currentDir === 'tasks' && $currentPage === 'my-tasks.php' ? 'active' : '' ?>">
      <i class="fa-solid fa-user-check fa-fw"></i><span><?= h(t('nav_my_tasks')) ?></span>
    </a>
    <a href="<?= $rp ?>renewals/index.php" class="tree-node <?= $currentDir === 'renewals' ? 'active' : '' ?>">
      <i class="fa-solid fa-shield-halved fa-fw"></i><span><?= h(t('nav_renewals')) ?></span>
    </a>
    <a href="<?= $rp ?>daily-work/report.php" class="tree-node <?= $currentDir === 'daily-work' && in_array($currentPage, ['report.php', 'report-print.php'], true) ? 'active' : '' ?>">
      <i class="fa-solid fa-file-pdf fa-fw"></i><span><?= h(t('nav_reports')) ?></span>
    </a>

    <!-- ==================== पत्र व विनंत्या ==================== -->
    <?php
      $pendingReqCount = 0;
      try {
          $reqCountStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_requests WHERE organization_id = ? AND status != 'completed'" . (isDeptScoped() ? " AND department_id = ?" : ""));
          $reqCountStmt->execute(isDeptScoped() ? [$activeOrgId, $user['department_id']] : [$activeOrgId]);
          $pendingReqCount = $reqCountStmt->fetch()['c'];
      } catch (PDOException $e) { /* work_requests table not migrated yet */ }

      $pendingLettersCount = 0;
      try {
          $letCountStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE current_holder_id = ? AND status IN ('in_progress', 'returned')");
          $letCountStmt->execute([$user['id']]);
          $pendingLettersCount = $letCountStmt->fetch()['c'];
      } catch (PDOException $e) { /* letters table not migrated yet */ }

      $lettersGroupBadge = $pendingReqCount + $pendingLettersCount;
    ?>
    <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupLetters" aria-expanded="<?= navGroupExpanded('letters', $activeGroup) ?>">
      <span class="label"><i class="fa-solid fa-paper-plane fa-fw"></i> पत्र व विनंत्या</span>
      <?php if ($lettersGroupBadge > 0): ?><span class="nav-group-badge"><?= $lettersGroupBadge ?></span><?php endif; ?>
      <i class="fa-solid fa-chevron-right chevron"></i>
    </div>
    <div class="collapse nav-group-body <?= navGroupOpen('letters', $activeGroup) ?>" id="navGroupLetters">
      <a href="<?= $rp ?>letters/index.php" class="tree-node <?= $currentDir === 'letters' ? 'active' : '' ?>">
        <i class="fa-solid fa-paper-plane fa-fw"></i><span>पत्र व्यवहार</span>
        <?php if ($pendingLettersCount > 0): ?><span class="count" style="color:var(--amber);"><?= $pendingLettersCount ?></span><?php endif; ?>
      </a>
      <a href="<?= $rp ?>requests/index.php" class="tree-node <?= $currentDir === 'requests' && $currentPage === 'index.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-clipboard-question fa-fw"></i><span><?= h(t('nav_requests')) ?></span>
        <?php if ($pendingReqCount > 0): ?><span class="count" style="color:var(--amber);"><?= $pendingReqCount ?></span><?php endif; ?>
      </a>
      <a href="<?= $rp ?>requests/create.php" class="tree-node <?= $currentDir === 'requests' && $currentPage === 'create.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-paper-plane fa-fw"></i><span>दुसऱ्या विभागाला विनंती पाठवा</span>
      </a>
      <a href="<?= $rp ?>requests/my-requests.php" class="tree-node <?= $currentDir === 'requests' && $currentPage === 'my-requests.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-list-check fa-fw"></i><span>माझ्या पाठवलेल्या विनंत्या</span>
      </a>
      <a href="<?= $rp ?>it/general-request.php" class="tree-node <?= $currentDir === 'it' && in_array($currentPage, ['general-request.php','my-general-requests.php']) ? 'active' : '' ?>">
        <i class="fa-solid fa-headset fa-fw"></i><span>🖥️ IT तक्रार/विनंती नोंदवा</span>
      </a>
      <a href="<?= $rp ?>staff/index.php?view=tree" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'index.php' && ($_GET['view'] ?? '') === 'tree' ? 'active' : '' ?>">
        <i class="fa-solid fa-address-book fa-fw"></i><span>📇 कर्मचारी Directory</span>
      </a>
      <a href="<?= $rp ?>announcements/index.php" class="tree-node <?= $currentDir === 'announcements' ? 'active' : '' ?>">
        <i class="fa-solid fa-bullhorn fa-fw"></i><span>📢 परिपत्रक/सूचना</span>
      </a>
      <a href="<?= $rp ?>suggestions/index.php" class="tree-node <?= $currentDir === 'suggestions' ? 'active' : '' ?>">
        <i class="fa-solid fa-lightbulb fa-fw"></i><span>💡 Software Suggestions</span>
      </a>
      <a href="<?= $rp ?>documents/index.php" class="tree-node <?= $currentDir === 'documents' ? 'active' : '' ?>">
        <i class="fa-solid fa-book fa-fw"></i><span>📚 दस्तऐवज Repository</span>
      </a>
      <?php if (hasPermission('view_org_wide_dashboards') || hasRole('super_admin')): ?>
        <a href="<?= $rp ?>emergency/index.php" class="tree-node <?= $currentDir === 'emergency' ? 'active' : '' ?>">
          <i class="fa-solid fa-triangle-exclamation fa-fw"></i><span>🆘 आणीबाणी सूचना</span>
        </a>
      <?php endif; ?>
    </div>

    <!-- ==================== कर्मचारी ==================== -->
    <?php // Attendance/रजा सगळ्यांनाच लागू — हा Group नेहमी दिसतो (आतले Staff-Directory/पगार-अहवाल Links मात्र फक्त Permission असलेल्यांनाच). ?>
      <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupStaff" aria-expanded="<?= navGroupExpanded('staff', $activeGroup) ?>">
        <span class="label"><i class="fa-solid fa-address-book fa-fw"></i> कर्मचारी</span>
        <i class="fa-solid fa-chevron-right chevron"></i>
      </div>
      <div class="collapse nav-group-body <?= navGroupOpen('staff', $activeGroup) ?>" id="navGroupStaff">
        <?php if (hasPermission('view_staff_directory') || hasCategory('hod')): ?>
          <a href="<?= $rp ?>staff/index.php" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'index.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-address-book fa-fw"></i><span><?= h(t('nav_staff_directory')) ?></span>
          </a>
        <?php endif; ?>
        <a href="<?= $rp ?>staff/attendance.php" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'attendance.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-fingerprint fa-fw"></i><span>👆 Staff Attendance</span>
        </a>
        <a href="<?= $rp ?>staff/my-leaves.php" class="tree-node <?= $currentDir === 'staff' && in_array($currentPage, ['my-leaves.php','leave-apply.php']) ? 'active' : '' ?>">
          <i class="fa-solid fa-umbrella-beach fa-fw"></i><span>🏖️ रजा व्यवस्थापन</span>
        </a>
        <?php /* staff/leave-approvals.php मधलीच अट — दोन्ही ठिकाणी सारखीच */ if (hasCategory('hod') || hasPermission('view_org_wide_dashboards') || hasRole('super_admin')): ?>
          <a href="<?= $rp ?>staff/leave-approvals.php" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'leave-approvals.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clipboard-check fa-fw"></i><span>✅ रजा-अर्ज मंजुरी</span>
          </a>
        <?php endif; ?>
        <?php if (isManagementOrAbove()): ?>
          <a href="<?= $rp ?>staff/salary-report.php" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'salary-report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-money-bill-wave fa-fw"></i><span>पगार अहवाल</span>
          </a>
          <a href="<?= $rp ?>staff/movement-report.php" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'movement-report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-people-arrows fa-fw"></i><span>कर्मचारी Movement</span>
          </a>
        <?php endif; ?>
      </div>

    <!-- ==================== वसतिगृह व रहिवासी ==================== -->
    <?php
      $resDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
      $resDeptCheck->execute([$user['department_id'] ?? 0]);
      $userDeptCodeNav = $resDeptCheck->fetch()['code'] ?? null;
      $userIsResDept = $userDeptCodeNav === 'RES';
      $showHostel = hasPermission('manage_hostels');
      // वसतिगृह विभागाचे (HOS) कर्मचारी — hostels/index.php उघडत नाही, पण Dashboard/हजेरी वापरतात
      $hosDeptHostels = [];
      if (!$showHostel && $userDeptCodeNav === 'HOS') {
          $hosStmt = $pdo->prepare("SELECT id, name FROM hostels WHERE organization_id = ? AND is_active = 1 ORDER BY name");
          $hosStmt->execute([$activeOrgId]);
          $hosDeptHostels = $hosStmt->fetchAll();
      }
      $showResidency = $userIsResDept || hasPermission('manage_residency_all_depts'); // residency/index.php ची परवानगी तीच
    ?>
    <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupHostel" aria-expanded="<?= navGroupExpanded('hostel', $activeGroup) ?>">
      <span class="label"><i class="fa-solid fa-house-user fa-fw"></i> वसतिगृह व रहिवासी</span>
      <i class="fa-solid fa-chevron-right chevron"></i>
    </div>
    <div class="collapse nav-group-body <?= navGroupOpen('hostel', $activeGroup) ?>" id="navGroupHostel">
      <a href="<?= $rp ?>residency/my-residency.php" class="tree-node <?= $currentDir === 'residency' && $currentPage === 'my-residency.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-house-user fa-fw"></i><span>🏠 माझं निवासस्थान</span>
      </a>
      <?php if ($showHostel): ?>
        <a href="<?= $rp ?>hostels/index.php" class="tree-node <?= $currentDir === 'hostels' ? 'active' : '' ?>">
          <i class="fa-solid fa-house-user fa-fw"></i><span><?= h(t('nav_hostel_management')) ?></span>
        </a>
      <?php endif; ?>
      <?php foreach ($hosDeptHostels as $hosNav): ?>
        <a href="<?= $rp ?>hostels/dashboard.php?id=<?= (int)$hosNav['id'] ?>" class="tree-node <?= $currentDir === 'hostels' && (int)($_GET['id'] ?? $_GET['hostel_id'] ?? 0) === (int)$hosNav['id'] ? 'active' : '' ?>">
          <i class="fa-solid fa-house-user fa-fw"></i><span><?= h($hosNav['name']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if ($showResidency): ?>
        <a href="<?= $rp ?>residency/index.php" class="tree-node <?= $currentDir === 'residency' && $currentPage !== 'my-residency.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-house-chimney-user fa-fw"></i><span>रहिवासी विभाग</span>
        </a>
      <?php endif; ?>
    </div>

    <!-- ==================== उपक्रम व अनुपालन ==================== -->
    <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupActivities" aria-expanded="<?= navGroupExpanded('activities', $activeGroup) ?>">
      <span class="label"><i class="fa-solid fa-om fa-fw"></i> उपक्रम व अनुपालन</span>
      <i class="fa-solid fa-chevron-right chevron"></i>
    </div>
    <div class="collapse nav-group-body <?= navGroupOpen('activities', $activeGroup) ?>" id="navGroupActivities">
      <a href="<?= $rp ?>meditation/index.php" class="tree-node <?= $currentDir === 'meditation' ? 'active' : '' ?>">
        <i class="fa-solid fa-spa fa-fw"></i><span>ध्यान व सत्संग</span>
      </a>
      <a href="<?= $rp ?>music/index.php" class="tree-node <?= $currentDir === 'music' ? 'active' : '' ?>">
        <i class="fa-solid fa-music fa-fw"></i><span>🎵 संगीत विभाग</span>
      </a>
      <a href="<?= $rp ?>events/index.php" class="tree-node <?= $currentDir === 'events' ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-days fa-fw"></i><span>🎉 कार्यक्रम व्यवस्थापन</span>
      </a>
      <?php if (hasPermission('manage_guests') || isManagementOrAbove() || hasRole('super_admin')): ?>
        <a href="<?= $rp ?>guests/index.php" class="tree-node <?= $currentDir === 'guests' ? 'active' : '' ?>">
          <i class="fa-solid fa-id-card-clip fa-fw"></i><span>🎫 पाहुणे नोंदणी</span>
        </a>
      <?php endif; ?>
      <?php if (hasCategory('hod') || isManagementOrAbove() || hasRole('super_admin') || hasPermission('manage_academics')): ?>
        <a href="<?= $rp ?>academics/students.php" class="tree-node <?= $currentDir === 'academics' ? 'active' : '' ?>">
          <i class="fa-solid fa-chalkboard-user fa-fw"></i><span>🎓 Virtual Classroom</span>
        </a>
      <?php else:
        // नेमलेले शिक्षक (HOD नसलेले) — फक्त स्वतःचे वर्ग
        $myClassNavStmt = $pdo->prepare("SELECT COUNT(*) FROM classroom_staff cs JOIN virtual_classrooms vc ON vc.id = cs.classroom_id WHERE cs.user_id = ? AND vc.organization_id = ? AND vc.is_active = 1");
        $myClassNavStmt->execute([$user['id'], $activeOrgId]);
        if ((int)$myClassNavStmt->fetchColumn() > 0): ?>
        <a href="<?= $rp ?>academics/classrooms.php" class="tree-node <?= $currentDir === 'academics' ? 'active' : '' ?>">
          <i class="fa-solid fa-chalkboard-user fa-fw"></i><span>🎓 माझे वर्ग / My Classrooms</span>
        </a>
      <?php endif; endif; ?>
      <?php if (!empty($user['department_id']) || isManagementOrAbove() || hasRole('super_admin')): ?>
        <a href="<?= $rp ?>sop/checklist.php" class="tree-node <?= $currentDir === 'sop' && $currentPage === 'checklist.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-list-check fa-fw"></i><span>SOP Checklist</span>
        </a>
      <?php endif; ?>
      <?php if (hasPermission('manage_sop')): ?>
        <a href="<?= $rp ?>sop/index.php" class="tree-node <?= $currentDir === 'sop' && $currentPage !== 'checklist.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-clipboard-check fa-fw"></i><span>SOP व्यवस्थापन</span>
        </a>
      <?php endif; ?>
    </div>

    <!-- ==================== सुविधा व्यवस्थापन ==================== -->
    <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupFacilities" aria-expanded="<?= navGroupExpanded('facilities', $activeGroup) ?>">
      <span class="label"><i class="fa-solid fa-building fa-fw"></i> सुविधा व्यवस्थापन</span>
      <i class="fa-solid fa-chevron-right chevron"></i>
    </div>
    <div class="collapse nav-group-body <?= navGroupOpen('facilities', $activeGroup) ?>" id="navGroupFacilities">
      <a href="<?= $rp ?>room-booking/index.php" class="tree-node <?= $currentDir === 'room-booking' ? 'active' : '' ?>">
        <i class="fa-solid fa-door-open fa-fw"></i><span>📐 Room/Hall Booking</span>
      </a>
      <a href="<?= $rp ?>allocations/index.php" class="tree-node <?= $currentDir === 'allocations' ? 'active' : '' ?>">
        <i class="fa-solid fa-boxes-packing fa-fw"></i><span>📦 Issue / Return Register</span>
      </a>
    </div>

    <!-- ==================== संवाद व सहभाग ==================== -->
    <?php
      $commBadge = 0;
      try {
          $commBadgeStmt = $pdo->prepare("SELECT COUNT(*) c FROM staff_messages WHERE recipient_id = ? AND is_read = 0");
          $commBadgeStmt->execute([$user['id']]);
          $commBadge = (int)($commBadgeStmt->fetch()['c'] ?? 0);
      } catch (PDOException $e) { /* table not migrated yet */ }
    ?>
    <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupCommunication" aria-expanded="<?= navGroupExpanded('communication', $activeGroup) ?>">
      <span class="label"><i class="fa-solid fa-comments fa-fw"></i> संवाद व सहभाग</span>
      <?php if ($commBadge > 0): ?><span class="nav-group-badge"><?= $commBadge ?></span><?php endif; ?>
      <i class="fa-solid fa-chevron-right chevron"></i>
    </div>
    <div class="collapse nav-group-body <?= navGroupOpen('communication', $activeGroup) ?>" id="navGroupCommunication">
      <a href="<?= $rp ?>messages/index.php" class="tree-node <?= $currentDir === 'messages' ? 'active' : '' ?>">
        <i class="fa-solid fa-comments fa-fw"></i><span>💬 Messages<?= $commBadge > 0 ? ' (' . $commBadge . ')' : '' ?></span>
      </a>
      <a href="<?= $rp ?>grievances/index.php" class="tree-node <?= $currentDir === 'grievances' ? 'active' : '' ?>">
        <i class="fa-solid fa-gavel fa-fw"></i><span>🎫 तक्रार निवारण</span>
      </a>
    </div>

    <!-- ==================== वाहन व मालमत्ता ==================== -->
    <?php
      $vehDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
      $vehDeptCheck->execute([$user['department_id'] ?? 0]);
      $vehUserDeptCode = $vehDeptCheck->fetch()['code'] ?? null;

      // वाहन यादी (vehicles/index.php) — तिथेच प्रत्येकाला परवानगी असलेली वाहनं दिसतात:
      // Super Admin/manage_vehicles/Management/वाहतूक विभाग — सगळी; चालक — स्वतःला नेमलेली
      $myVeh = null;
      if (!hasRole('super_admin') && !hasPermission('manage_vehicles') && !isManagementOrAbove() && $vehUserDeptCode !== 'TRP') {
          try {
              $myVehStmt = $pdo->prepare("SELECT id FROM vehicles WHERE organization_id = ? AND driver_id = ? LIMIT 1");
              $myVehStmt->execute([activeOrgId(), $user['id']]);
              $myVeh = $myVehStmt->fetch();
          } catch (PDOException $e) { /* vehicles table not migrated yet */ }
      }
      $showVehicles = hasRole('super_admin') || hasPermission('manage_vehicles') || isManagementOrAbove() || $vehUserDeptCode === 'TRP' || $myVeh;

      $mtnDeptCheck = $pdo->prepare("SELECT has_issue_register FROM departments WHERE id = ?");
      $mtnDeptCheck->execute([$user['department_id'] ?? 0]);
      $userHasIssueRegister = !empty($mtnDeptCheck->fetch()['has_issue_register']);
      // देखभाल — maintenance/index.php व create.php चाच नियम (Issue Register असलेला विभाग किंवा manage_maintenance_settings)
      $showMaintenance = $userHasIssueRegister || hasPermission('manage_maintenance_settings');
      $showDeadStock = !empty($user['department_id']) || isManagementOrAbove() || hasRole('super_admin');
      $showStore = ($vehUserDeptCode === 'STR') || hasPermission('manage_store') || isManagementOrAbove();
      $showCctv = ($vehUserDeptCode === 'CCTV') || hasPermission('manage_cctv') || isManagementOrAbove();
      $showIt = ($vehUserDeptCode === 'IT') || hasPermission('manage_it') || isManagementOrAbove();
      // IT Requests व्यवस्थापन — पानावरचाच नियम (IT विभाग सदस्य किंवा manage_it)
      require_once __DIR__ . '/it-helpers.php';
      $showItRequestsManage = false;
      $myItLabs = [];
      try {
          $showItRequestsManage = canManageItRequests($pdo, $user);
          // Lab Assistant — फक्त स्वतःच्या Lab(s) चे Assets
          if (!$showIt) {
              $myLabStmt = $pdo->prepare("SELECT id, lab_name FROM it_labs WHERE organization_id = ? AND assistant_id = ? AND is_active = 1 ORDER BY lab_name");
              $myLabStmt->execute([$activeOrgId, $user['id']]);
              $myItLabs = $myLabStmt->fetchAll();
          }
      } catch (PDOException $e) { /* IT tables not migrated yet */ }
    ?>
    <?php if ($showVehicles || $showMaintenance || $showDeadStock): ?>
      <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupAssets" aria-expanded="<?= navGroupExpanded('assets', $activeGroup) ?>">
        <span class="label"><i class="fa-solid fa-truck-fast fa-fw"></i> वाहन व मालमत्ता</span>
        <i class="fa-solid fa-chevron-right chevron"></i>
      </div>
      <div class="collapse nav-group-body <?= navGroupOpen('assets', $activeGroup) ?>" id="navGroupAssets">
        <?php if ($showVehicles): ?>
          <a href="<?= $rp ?>vehicles/index.php" class="tree-node <?= $currentDir === 'vehicles' ? 'active' : '' ?>">
            <i class="fa-solid fa-truck-fast fa-fw"></i><span>वाहन व्यवस्थापन</span>
          </a>
        <?php endif; ?>
        <?php if ($showDeadStock): ?>
          <a href="<?= $rp ?>deadstock/index.php" class="tree-node <?= $currentDir === 'deadstock' ? 'active' : '' ?>">
            <i class="fa-solid fa-box-archive fa-fw"></i><span>Dead Stock Register</span>
          </a>
        <?php endif; ?>
        <?php if ($showMaintenance): ?>
          <a href="<?= $rp ?>maintenance/index.php" class="tree-node <?= $currentDir === 'maintenance' ? 'active' : '' ?>">
            <i class="fa-solid fa-screwdriver-wrench fa-fw"></i><span>देखभाल नोंदवही</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($showStore): ?>
      <a href="<?= $rp ?>store/items.php" class="tree-node <?= $currentDir === 'store' ? 'active' : '' ?>">
        <i class="fa-solid fa-warehouse fa-fw"></i><span>भांडार Material-यादी</span>
      </a>
    <?php endif; ?>

    <!-- ==================== तांत्रिक विभाग ==================== -->
    <?php if ($showCctv || $showIt || !empty($myItLabs)): ?>
      <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupTechDept" aria-expanded="<?= navGroupExpanded('techdept', $activeGroup) ?>">
        <span class="label"><i class="fa-solid fa-server fa-fw"></i> तांत्रिक विभाग</span>
        <i class="fa-solid fa-chevron-right chevron"></i>
      </div>
      <div class="collapse nav-group-body <?= navGroupOpen('techdept', $activeGroup) ?>" id="navGroupTechDept">
        <?php if ($showCctv): ?>
          <a href="<?= $rp ?>cctv/index.php" class="tree-node <?= $currentDir === 'cctv' ? 'active' : '' ?>">
            <i class="fa-solid fa-video fa-fw"></i><span>CCTV विभाग</span>
          </a>
        <?php endif; ?>
        <?php if ($showIt): ?>
          <a href="<?= $rp ?>it/index.php" class="tree-node <?= $currentDir === 'it' && $currentPage === 'index.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-desktop fa-fw"></i><span>IT विभाग</span>
          </a>
        <?php endif; ?>
        <?php if ($showItRequestsManage): ?>
          <a href="<?= $rp ?>it/general-requests-manage.php" class="tree-node <?= $currentDir === 'it' && $currentPage === 'general-requests-manage.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-headset fa-fw"></i><span>🖥️ IT Requests व्यवस्थापन</span>
          </a>
        <?php endif; ?>
        <?php foreach ($myItLabs as $myLab): ?>
          <a href="<?= $rp ?>it/assets.php?lab_id=<?= (int)$myLab['id'] ?>" class="tree-node <?= $currentDir === 'it' && in_array($currentPage, ['assets.php', 'asset-view.php', 'asset-edit.php']) ? 'active' : '' ?>">
            <i class="fa-solid fa-desktop fa-fw"></i><span>माझी Lab — <?= h($myLab['lab_name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ==================== आर्थिक ==================== -->
    <?php // पूर्वीचे "विभाग Budget" + "Material Purchase व Payment" — आता एकच; दोन्हीपैकी कुठलीही परवानगी पुरेशी ?>
    <?php if (hasPermission('manage_budget') || hasPermission('manage_dept_finance') || hasCategory('hod') || isManagementOrAbove() || hasRole('super_admin')): ?>
      <a href="<?= $rp ?>dept-finance/dashboard.php" class="tree-node <?= $currentDir === 'dept-finance' ? 'active' : '' ?>">
        <i class="fa-solid fa-sack-dollar fa-fw"></i><span>💰 विभाग Finance</span>
      </a>
    <?php endif; ?>

    <!-- ==================== गौशाळा ==================== -->
    <a href="<?= $rp ?>gaushala/index.php" class="tree-node <?= $currentDir === 'gaushala' ? 'active' : '' ?>">
      <i class="fa-solid fa-cow fa-fw"></i><span>🐄 गौशाळा</span>
    </a>

    <!-- ==================== विभाग (Department Dashboards) — College/School/Trust नुसार गटवार ==================== -->
    <div class="tier-label"><?= h(t('nav_departments')) ?></div>
    <?php
      // Trust-स्तरीय (campus_id NULL, किंवा Trust HQ Campus खालचे) आणि प्रत्येक
      // College/School — असे स्वतंत्र गट तयार करतो.
      $deptGroups = []; // key: "trust" किंवा campus_name, value: ['label' => ..., 'depts' => [...]]
      foreach ($sidebarDepartments as $dept) {
          $isTrustLevel = empty($dept['campus_name']) || $dept['college_type'] === 'Trust HQ';
          $groupKey = $isTrustLevel ? 'trust' : $dept['campus_name'];
          if (!isset($deptGroups[$groupKey])) {
              $deptGroups[$groupKey] = ['label' => $isTrustLevel ? '🏛️ Trust-स्तरीय' : '🏫 ' . $dept['campus_name'], 'depts' => []];
          }
          $deptGroups[$groupKey]['depts'][] = $dept;
      }
    ?>
    <?php if (isDeptScoped()): ?>
      <?php // फक्त स्वतःचाच विभाग दिसणाऱ्यांसाठी — Group-Label ची गरजच नाही ?>
      <?php foreach ($sidebarDepartments as $dept): ?>
        <a href="<?= $rp ?>departments/dashboard.php?id=<?= $dept['id'] ?>" class="tree-node <?= $currentDir === 'departments' && $currentPage === 'dashboard.php' && (int)($_GET['id'] ?? 0) === (int)$dept['id'] ? 'active' : '' ?>">
          <i class="<?= h($dept['icon']) ?> fa-fw"></i>
          <span><?= h($dept['name_local'] ?: $dept['name']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php else: ?>
      <?php // Management/Super Admin — नेहमीच College/Trust-स्तरीय Group-Label सह
      // दाखवतो (1 च Group असला तरी) — जेणेकरून "हे कोणत्या College चे
      // विभाग आहेत" हे कायम स्पष्ट राहील, System च्या इतर सगळ्या
      // ठिकाणांप्रमाणेच Consistent Campus-Context. ?>
      <?php $activeDeptId = (int)($_GET['id'] ?? 0); ?>
      <?php foreach ($deptGroups as $groupKey => $group): $groupNodeId = 'deptGroup' . md5($groupKey);
        $groupHasActive = $currentDir === 'departments' && $currentPage === 'dashboard.php' && in_array($activeDeptId, array_column($group['depts'], 'id'));
        $onlyOneGroup = count($deptGroups) <= 1;
      ?>
        <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#<?= $groupNodeId ?>" aria-expanded="<?= ($groupHasActive || $onlyOneGroup) ? 'true' : 'false' ?>">
          <span class="label"><?= h($group['label']) ?> <span style="font-size:10px;color:var(--text-dim);font-weight:400;">(<?= count($group['depts']) ?>)</span></span>
          <i class="fa-solid fa-chevron-right chevron"></i>
        </div>
        <div class="collapse nav-group-body <?= ($groupHasActive || $onlyOneGroup) ? 'show' : '' ?>" id="<?= $groupNodeId ?>">
          <?php foreach ($group['depts'] as $dept): ?>
            <a href="<?= $rp ?>departments/dashboard.php?id=<?= $dept['id'] ?>" class="tree-node <?= $currentDir === 'departments' && $currentPage === 'dashboard.php' && (int)($_GET['id'] ?? 0) === (int)$dept['id'] ? 'active' : '' ?>">
              <i class="<?= h($dept['icon']) ?> fa-fw"></i>
              <span><?= h($dept['name_local'] ?: $dept['name']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- ==================== प्रशासन (Super Admin) ==================== -->
    <?php if (hasRole('super_admin')): ?>
      <div class="tier-label"><?= h(t('nav_administration')) ?></div>
      <div class="nav-group-header" data-bs-toggle="collapse" data-bs-target="#navGroupAdmin" aria-expanded="<?= navGroupExpanded('admin', $activeGroup) ?>">
        <span class="label"><i class="fa-solid fa-gear fa-fw"></i> संस्था व्यवस्थापन</span>
        <i class="fa-solid fa-chevron-right chevron"></i>
      </div>
      <div class="collapse nav-group-body <?= navGroupOpen('admin', $activeGroup) ?>" id="navGroupAdmin">
        <a href="<?= $rp ?>organizations/index.php" class="tree-node <?= $currentDir === 'organizations' ? 'active' : '' ?>">
          <i class="fa-solid fa-sitemap fa-fw"></i><span><?= h(t('nav_org_management')) ?></span>
        </a>
        <a href="<?= $rp ?>campuses/index.php" class="tree-node <?= $currentDir === 'campuses' ? 'active' : '' ?>">
          <i class="fa-solid fa-city fa-fw"></i><span><?= h(t('nav_campus_management')) ?></span>
        </a>
        <a href="<?= $rp ?>departments/index.php" class="tree-node <?= $currentDir === 'departments' && $currentPage !== 'dashboard.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-diagram-project fa-fw"></i><span><?= h(t('nav_department_management')) ?></span>
        </a>
        <a href="<?= $rp ?>roles/index.php" class="tree-node <?= $currentDir === 'roles' ? 'active' : '' ?>">
          <i class="fa-solid fa-user-tag fa-fw"></i><span><?= h(t('nav_roles_management')) ?></span>
        </a>
        <a href="<?= $rp ?>custom-fields/index.php" class="tree-node <?= $currentDir === 'custom-fields' ? 'active' : '' ?>">
          <i class="fa-solid fa-list-ul fa-fw"></i><span><?= h(t('nav_custom_fields')) ?></span>
        </a>
        <a href="<?= $rp ?>staff/import.php" class="tree-node <?= $currentDir === 'staff' && $currentPage === 'import.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-file-csv fa-fw"></i><span><?= h(t('nav_csv_import')) ?></span>
        </a>
        <a href="<?= $rp ?>letters/categories.php" class="tree-node <?= $currentDir === 'letters' && $currentPage === 'categories.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-tags fa-fw"></i><span>पत्र प्रकार</span>
        </a>
        <a href="<?= $rp ?>letters/workflows.php" class="tree-node <?= $currentDir === 'letters' && in_array($currentPage, ['workflows.php','workflow-create.php','workflow-edit.php']) ? 'active' : '' ?>">
          <i class="fa-solid fa-diagram-project fa-fw"></i><span>कार्यप्रवाह टेम्प्लेट</span>
        </a>
        <a href="<?= $rp ?>about-manage.php" class="tree-node <?= $currentPage === 'about-manage.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-om fa-fw"></i><span>About Page व्यवस्थापन</span>
        </a>
        <a href="<?= $rp ?>backup/index.php" class="tree-node <?= $currentDir === 'backup' ? 'active' : '' ?>">
          <i class="fa-solid fa-database fa-fw"></i><span><?= h(t('nav_backup_restore')) ?></span>
        </a>
        <a href="<?= $rp ?>activity-log/index.php" class="tree-node <?= $currentDir === 'activity-log' ? 'active' : '' ?>">
          <i class="fa-solid fa-clock-rotate-left fa-fw"></i><span><?= h(t('nav_activity_log')) ?></span>
        </a>
        <a href="<?= $rp ?>system-update/index.php" class="tree-node <?= $currentDir === 'system-update' && $currentPage === 'index.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-arrows-rotate fa-fw"></i><span>🔄 System Update</span>
        </a>
        <a href="<?= $rp ?>system-update/maintenance.php" class="tree-node <?= $currentDir === 'system-update' && $currentPage === 'maintenance.php' ? 'active' : '' ?>">
          <i class="fa-solid fa-screwdriver-wrench fa-fw"></i><span>🛠️ Site Maintenance Mode</span>
        </a>
        <a href="<?= $rp ?>tv-display.php" target="_blank" class="tree-node">
          <i class="fa-solid fa-tv fa-fw"></i><span>📺 Live TV Display</span>
        </a>
      </div>
    <?php endif; ?>
  </div>

  <div class="rail-foot">
    <a href="<?= $rp ?>staff/profile.php" style="text-decoration:none;">
      <div class="user-chip">
        <div class="avatar"><?= h(mb_substr($user['name'], 0, 2)) ?></div>
        <div>
          <div style="font-size:13px;color:#fff;font-weight:600;"><?= h($user['name']) ?></div>
          <div style="font-size:11px;color:#8A90AC;"><?= h($user['designation']) ?></div>
        </div>
      </div>
    </a>
    <a href="<?= $rp ?>staff/profile.php" style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:12px;color:#B7BBD6;text-decoration:none;">
      <i class="fa-solid fa-user fa-fw"></i><span><?= h(t('nav_profile')) ?></span>
    </a>
    <div style="display:flex;align-items:center;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);">
      <img src="<?= $rp ?>assets/brand/e-admin-mark.png" alt="" style="width:26px;height:26px;border-radius:6px;background:#fff;padding:2px;">
      <div style="line-height:1.2;"><div style="font-size:12px;color:#fff;font-weight:700;letter-spacing:.3px;">E-Admin</div><div style="font-size:10px;color:#8A90AC;">Smart Administration</div></div>
    </div>
  </div>
</aside>

<script>
window.addEventListener('DOMContentLoaded', function () {
  var input = document.getElementById('navQuickFind');
  if (!input) return;
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    var nodes = document.querySelectorAll('#navTreeRoot .tree-node');
    if (!q) {
      nodes.forEach(function (n) { n.style.display = 'flex'; });
      return;
    }
    // शोध चालू असताना — सगळे Groups उघडे ठेवा, आणि न-जुळणारे links लपवा
    if (window.bootstrap && window.bootstrap.Collapse) {
      document.querySelectorAll('#navTreeRoot .nav-group-body').forEach(function (g) {
        window.bootstrap.Collapse.getOrCreateInstance(g, { toggle: false }).show();
      });
    }
    nodes.forEach(function (n) {
      var text = n.textContent.toLowerCase();
      n.style.display = text.indexOf(q) !== -1 ? 'flex' : 'none';
    });
  });
});
</script>
