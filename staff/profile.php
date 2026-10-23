<?php
$pageTitle = 'कर्मचारी प्रोफाइल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$empId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

// 5-Tier Access: स्वतःचा विभाग → अनेक विभाग → एक कॉलेज → अनेक कॉलेज →
// संपूर्ण संस्था (getVisibleDepartmentIdsForStaffTracking() मध्ये सगळे
// Tiers एकत्र तपासले जातात — null म्हणजे कुठलाही Filter नाही, सगळं दिसेल).
$visibleDeptIds = getVisibleDepartmentIdsForStaffTracking();
$hasBroadAccess = $visibleDeptIds === null;

if (!$hasBroadAccess && (int)$user['id'] !== $empId) {
    // स्वतःचं Profile नेहमीच बघता येतं — इतरांचं फक्त Visible विभागांतलं
    $empDeptCheck = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND organization_id = ?");
    $empDeptCheck->execute([$empId, $orgId]);
    $empDeptId = (int)($empDeptCheck->fetch()['department_id'] ?? 0);
    if (!in_array($empDeptId, $visibleDeptIds, true)) {
        http_response_code(403);
        die(t('s579'));
    }
}

$stmt = $pdo->prepare("
    SELECT u.*, d.name_local AS dept_name, d.name AS dept_name_en, c.name AS campus_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.id = ? AND u.organization_id = ?
");
$stmt->execute([$empId, $orgId]);
$emp = $stmt->fetch();
if (!$emp) { die(t('s436')); }

// Work history stats — counts any record this person was part of (solo or team)
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_record_team WHERE user_id = ?");
$stmt->execute([$empId]);
$totalWork = $stmt->fetch()['c'];
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_record_team wt JOIN work_records wr ON wr.id = wt.work_record_id WHERE wt.user_id = ? AND wr.status = 'completed'");
$stmt->execute([$empId]);
$completedWork = $stmt->fetch()['c'];

$empStatusCounts = [];
foreach (['pending', 'in_progress', 'completed'] as $s) {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM work_record_team wt JOIN work_records wr ON wr.id = wt.work_record_id WHERE wt.user_id = ? AND wr.status = ?");
    $st->execute([$empId, $s]);
    $empStatusCounts[$s] = (int)$st->fetch()['c'];
}

$stmt = $pdo->prepare("SELECT wr.* FROM work_records wr JOIN work_record_team wt ON wt.work_record_id = wr.id WHERE wt.user_id = ? ORDER BY wr.work_date DESC LIMIT 10");
$stmt->execute([$empId]);
$recentWork = $stmt->fetchAll();

// गेल्या 6 महिन्यांचा Attendance-Heatmap Data
$attHeatmapData = [];
try {
    $attHmStmt = $pdo->prepare("SELECT attendance_date, status FROM staff_attendance WHERE user_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)");
    $attHmStmt->execute([$empId]);
    foreach ($attHmStmt->fetchAll() as $ah) {
        $attHeatmapData[$ah['attendance_date']] = ($ah['status'] === 'present') ? 1 : (($ah['status'] === 'half_day') ? 0.5 : 0);
    }
} catch (PDOException $e) { /* table not migrated yet */ }

// Access — कोण Edit करू शकतो (Emergency Contact, Documents, Achievements —
// फक्त स्वतः किंवा Super Admin)
$isOwnProfile = (int)$user['id'] === $empId;
$canEditProfile = $isOwnProfile || hasRole('super_admin');

// 1. Profile Completeness Meter
$completenessFields = [
    'photo_path' => $emp['photo_path'], 'mobile' => $emp['mobile'], 'date_of_birth' => $emp['date_of_birth'],
    'designation' => $emp['designation'], 'emergency_contact_name' => $emp['emergency_contact_name'] ?? null,
    'emergency_contact_mobile' => $emp['emergency_contact_mobile'] ?? null, 'local_address' => $emp['local_address'] ?? null,
];
$filledCount = count(array_filter($completenessFields, fn($v) => !empty($v)));
$completenessPct = round(($filledCount / count($completenessFields)) * 100);

// 3. Document Vault
try {
    $docsStmt = $pdo->prepare("SELECT * FROM staff_documents WHERE user_id = ? ORDER BY uploaded_at DESC");
    $docsStmt->execute([$empId]);
    $myDocuments = $docsStmt->fetchAll();
} catch (PDOException $e) { $myDocuments = []; }

// 5. Personal Achievements Timeline
try {
    $achStmt = $pdo->prepare("SELECT * FROM staff_achievements WHERE user_id = ? ORDER BY achievement_date DESC");
    $achStmt->execute([$empId]);
    $achievements = $achStmt->fetchAll();
} catch (PDOException $e) { $achievements = []; }

// 8. Activity Timeline — स्वतःच्याच Profile वर दाखवतो (Privacy साठी, इतरांना नाही)
$activityTimeline = [];
if ($isOwnProfile) {
    try {
        $actStmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
        $actStmt->execute([$empId]);
        $activityTimeline = $actStmt->fetchAll();
    } catch (PDOException $e) {}
}

// 10. वाढदिवस Countdown
$birthdayCountdown = null;
if (!empty($emp['date_of_birth'])) {
    $today = new DateTime();
    $nextBday = new DateTime(date('Y') . '-' . date('m-d', strtotime($emp['date_of_birth'])));
    if ($nextBday < $today) { $nextBday->modify('+1 year'); }
    $daysLeft = (int)$today->diff($nextBday)->format('%a');
    $birthdayCountdown = $daysLeft;
}

// Tasks assigned to them
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE assigned_to = ? ORDER BY target_date DESC LIMIT 10");
$stmt->execute([$empId]);
$recentTasks = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM tasks WHERE assigned_to = ? AND status NOT IN ('completed', 'cancelled')");
$stmt->execute([$empId]);
$pendingTasks = $stmt->fetch()['c'];

$roleLabels = ['super_admin' => 'Super Admin', 'president' => 'President', 'executive_president' => 'Executive President', 'general_manager' => 'General Manager', 'hod' => 'HOD', 'staff' => 'Staff'];
$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले', 'cancelled' => 'रद्द केले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'cancelled' => 'overdue'];
?>

<div class="page-eyebrow"><?= h($emp['dept_name'] ?? '') ?></div>
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s285')) ?></div>
  <div class="d-flex gap-2">
    <a href="../letters/create.php?to_user=<?= $emp['id'] ?>" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-paper-plane me-1"></i>पत्र पाठवा</a>
    <a href="id-card.php?id=<?= $emp['id'] ?>" target="_blank" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;"><i class="fa-solid fa-id-card me-1"></i>ID Card</a>
    <?php if (hasRole('super_admin')): ?>
      <a href="edit.php?id=<?= $emp['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
      <form method="POST" action="toggle-active.php" onsubmit="return confirm('<?= $emp['is_active'] ? 'हे खाते निष्क्रिय करायचे?' : 'हे खाते सक्रिय करायचे?' ?>');">
        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
        <?php if ($emp['is_active']): ?>
          <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:9px;font-size:12.5px;padding:8px 16px;"><i class="fa-solid fa-user-slash me-1"></i>Deactivate</button>
        <?php else: ?>
          <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;"><i class="fa-solid fa-user-check me-1"></i>Activate</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mt-2">
  <div class="col-lg-4">
    <div class="panel">
      <div style="padding:24px;">
        <div class="d-flex align-items-center gap-3 mb-4">
          <div style="width:60px;height:60px;border-radius:14px;background:var(--ink-soft);color:#fff;font-family:'Space Grotesk';font-weight:700;font-size:22px;display:flex;align-items:center;justify-content:center;">
            <?= h(mb_substr($emp['name'], 0, 2)) ?>
          </div>
          <div>
            <div style="font-family:'Space Grotesk';font-weight:700;font-size:17px;color:var(--ink);"><?= h($emp['name']) ?></div>
            <div style="font-size:12.5px;color:var(--text-dim);"><?= h($roleLabels[$emp['role']] ?? $emp['role']) ?></div>
          </div>
        </div>
        <?php if ($isOwnProfile && $completenessPct < 100): ?>
        <div style="margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-dim);margin-bottom:4px;"><span>Profile Completeness</span><span style="font-weight:700;color:var(--rail);"><?= $completenessPct ?>%</span></div>
          <div style="background:var(--canvas);border-radius:20px;height:7px;overflow:hidden;"><div style="height:100%;width:<?= $completenessPct ?>%;background:var(--rail);"></div></div>
        </div>
        <?php endif; ?>
        <?php if ($birthdayCountdown !== null && $birthdayCountdown <= 30): ?>
        <div style="background:#FBEBD6;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#8A5A14;font-weight:600;">
          🎂 <?= $birthdayCountdown === 0 ? 'आज वाढदिवस आहे! शुभेच्छा!' : "वाढदिवस $birthdayCountdown दिवसांनी" ?>
        </div>
        <?php endif; ?>
        <table class="rtable" style="font-size:13px;">
          <tbody>
            <tr><td style="color:var(--text-dim);width:40%;"><?= h(t('s91')) ?></td><td class="font-mono"><?= h($emp['employee_code'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);">Email</td><td class="font-mono" style="font-size:12px;"><?= h($emp['email']) ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s92')) ?></td><td><?= h($emp['mobile'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s45')) ?></td><td><?= h($emp['designation'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s1')) ?></td><td><?= h($emp['dept_name'] ?? $emp['dept_name_en'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s8')) ?></td><td><?= h($emp['campus_name'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s2')) ?></td><td><?php if ($emp['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td></tr>
            <?php if ($emp['joining_date']): ?>
              <tr><td style="color:var(--text-dim);">रुजू दिनांक</td><td><?= h(date('d M Y', strtotime($emp['joining_date']))) ?></td></tr>
            <?php endif; ?>
            <?php if ($emp['left_date']): ?>
              <tr><td style="color:var(--text-dim);">सोडल्याचा दिनांक</td><td style="color:var(--rose);"><?= h(date('d M Y', strtotime($emp['left_date']))) ?></td></tr>
            <?php endif; ?>
            <?php if (isManagementOrAbove() || hasRole('super_admin')): ?>
              <tr><td style="color:var(--text-dim);">पगार (₹)</td><td style="font-weight:600;color:var(--rail);"><?= $emp['salary'] !== null ? '₹' . number_format($emp['salary'], 0) : '—' ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel mt-3">
      <div class="panel-head"><div class="title">🆘 Emergency Contact</div></div>
      <div style="padding:16px 20px;">
        <?php if ($emp['emergency_contact_name']): ?>
          <div style="font-size:13px;font-weight:600;"><?= h($emp['emergency_contact_name']) ?></div>
          <div style="font-size:11.5px;color:var(--text-dim);"><?= h($emp['emergency_contact_relation'] ?: '') ?><?= $emp['emergency_contact_relation'] ? ' — ' : '' ?><?= h($emp['emergency_contact_mobile'] ?: '') ?></div>
        <?php else: ?>
          <div style="font-size:12px;color:var(--text-dim);">अजून नमूद केलेलं नाही</div>
        <?php endif; ?>
        <?php if ($canEditProfile): ?>
          <button type="button" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#emergencyModal">✏️ Edit करा</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-6"><div class="kpi-mini"><div class="lbl"><?= h(t('s286')) ?></div><div class="val"><?= $totalWork ?></div></div></div>
      <div class="col-6"><div class="kpi-mini"><div class="lbl"><?= h(t('s42')) ?></div><div class="val" style="color:var(--rail);"><?= $completedWork ?></div></div></div>
    </div>

    <?php if ($totalWork > 0): ?>
    <div class="panel mt-3">
      <div class="panel-head"><div class="title">कामाची स्थिती</div></div>
      <div style="padding:16px 18px;"><canvas id="empStatusChart" height="140"></canvas></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($attHeatmapData)): ?>
    <div class="panel mt-3">
      <div class="panel-head"><div class="title">📅 Attendance Pattern (6 महिने)</div></div>
      <div style="padding:16px 18px 10px;" id="attHeatmapContainer"></div>
      <div style="padding:0 18px 14px;font-size:10.5px;color:var(--text-dim);">हलक्या रंगापासून गडद रंगापर्यंत — जास्त गडद = जास्त उपस्थिती</div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      eowmsDrawHeatmap('attHeatmapContainer', <?= json_encode($attHeatmapData) ?>, { monthsBack: 6 });
    });
    </script>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title"><?= h(t('s287')) ?></div></div>
      <table class="rtable">
        <thead><tr><th><?= h(t('s13')) ?></th><th><?= h(t('s21')) ?></th><th><?= h(t('s20')) ?></th><th><?= h(t('s2')) ?></th></tr></thead>
        <tbody>
          <?php if (empty($recentWork)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:18px;"><?= h(t('s97')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($recentWork as $w): ?>
            <tr>
              <td class="font-mono"><?= date('d M Y', strtotime($w['work_date'])) ?></td>
              <td><?= h($w['title']) ?></td>
              <td><?= h(ucfirst($w['priority'])) ?></td>
              <td><span class="status-chip <?= $statusChip[$w['status']] ?>"><?= $statusLabels[$w['status']] ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <div class="panel-head"><div class="title"><?= h(t('s288')) ?><?= $pendingTasks ?> <?= h(t('s289')) ?></div></div>
      <table class="rtable">
        <thead><tr><th><?= h(t('s14')) ?></th><th>Target Date</th><th><?= h(t('s2')) ?></th></tr></thead>
        <tbody>
          <?php if (empty($recentTasks)): ?>
            <tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:18px;"><?= h(t('s290')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($recentTasks as $t): ?>
            <tr>
              <td style="font-weight:500;"><?= h($t['title']) ?></td>
              <td class="font-mono"><?= date('d M Y', strtotime($t['target_date'])) ?></td>
              <td><span class="status-chip <?= $statusChip[$t['status']] ?>"><?= $statusLabels[$t['status']] ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-md-6">
    <div class="panel">
      <div class="panel-head"><div class="title">📁 Document Vault</div><?php if ($canEditProfile): ?><button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:11px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#uploadDocModal">+ Upload</button><?php endif; ?></div>
      <div style="padding:8px 0;">
        <?php if (empty($myDocuments)): ?><p style="font-size:12px;color:var(--text-dim);padding:10px 20px;">अजून कुठलंही Document Upload केलेलं नाही</p><?php endif; ?>
        <?php foreach ($myDocuments as $doc): ?>
          <div style="padding:10px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
            <div>
              <div style="font-size:12.5px;font-weight:600;"><?= h($doc['document_type']) ?></div>
              <div style="font-size:10.5px;color:var(--text-dim);"><?= date('d M Y', strtotime($doc['uploaded_at'])) ?></div>
            </div>
            <div class="d-flex gap-2">
              <a href="../<?= h($doc['file_path']) ?>" target="_blank" style="font-size:11px;color:var(--rail);font-weight:600;">बघा</a>
              <?php if ($canEditProfile): ?>
                <form method="POST" action="profile-actions.php"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                  <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;" onclick="return confirm('काढायचं?')">✕</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel mt-3">
      <div class="panel-head"><div class="title">🏆 Achievements</div><?php if ($canEditProfile): ?><button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:11px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#addAchModal">+ जोडा</button><?php endif; ?></div>
      <div style="padding:8px 0;">
        <?php if (empty($achievements)): ?><p style="font-size:12px;color:var(--text-dim);padding:10px 20px;">अजून काही जोडलेलं नाही</p><?php endif; ?>
        <?php foreach ($achievements as $ach): ?>
          <div style="padding:10px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:start;">
            <div>
              <div style="font-size:12.5px;font-weight:600;">🏅 <?= h($ach['title']) ?></div>
              <?php if ($ach['description']): ?><div style="font-size:11px;color:var(--text-dim);"><?= h($ach['description']) ?></div><?php endif; ?>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:2px;"><?= date('d M Y', strtotime($ach['achievement_date'])) ?></div>
            </div>
            <?php if ($canEditProfile): ?>
              <form method="POST" action="profile-actions.php"><input type="hidden" name="action" value="delete_achievement"><input type="hidden" name="ach_id" value="<?= $ach['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;" onclick="return confirm('काढायचं?')">✕</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($isOwnProfile): ?>
  <div class="col-md-6">
    <div class="panel">
      <div class="panel-head"><div class="title">🕐 माझा Activity Timeline</div><div class="sub">फक्त तुम्हालाच दिसतं</div></div>
      <div style="max-height:420px;overflow-y:auto;">
        <?php if (empty($activityTimeline)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून काही Activity नाही</p><?php endif; ?>
        <?php foreach ($activityTimeline as $act): ?>
          <div style="padding:9px 20px;border-bottom:1px solid var(--line);">
            <div style="font-size:12px;"><?= h($act['action']) ?></div>
            <div style="font-size:10.5px;color:var(--text-dim);"><?= date('d M Y, h:i A', strtotime($act['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($canEditProfile): ?>
<div class="modal fade" id="emergencyModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" action="profile-emergency-contact.php">
      <input type="hidden" name="user_id" value="<?= $empId ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Emergency Contact Edit करा</div>
        <label class="form-label-custom">नाव</label>
        <input type="text" name="emergency_contact_name" class="form-control-custom mb-2" value="<?= h($emp['emergency_contact_name'] ?? '') ?>">
        <label class="form-label-custom">नातं</label>
        <input type="text" name="emergency_contact_relation" class="form-control-custom mb-2" value="<?= h($emp['emergency_contact_relation'] ?? '') ?>" placeholder="उदा. पती, वडील">
        <label class="form-label-custom">Mobile</label>
        <input type="text" name="emergency_contact_mobile" class="form-control-custom" value="<?= h($emp['emergency_contact_mobile'] ?? '') ?>">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="uploadDocModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" action="profile-document-upload.php" enctype="multipart/form-data">
      <input type="hidden" name="user_id" value="<?= $empId ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Document Upload करा</div>
        <label class="form-label-custom">प्रकार</label>
        <input type="text" name="document_type" class="form-control-custom mb-2" placeholder="उदा. Aadhar Card, PAN, Degree Certificate" required>
        <label class="form-label-custom">File (JPG/PNG/PDF, 5MB पर्यंत)</label>
        <input type="file" name="document" class="form-control-custom" accept=".jpg,.jpeg,.png,.pdf" required>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Upload करा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="addAchModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" action="profile-actions.php">
      <input type="hidden" name="action" value="add_achievement">
      <input type="hidden" name="user_id" value="<?= $empId ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Achievement जोडा</div>
        <label class="form-label-custom">शीर्षक</label>
        <input type="text" name="title" class="form-control-custom mb-2" required>
        <label class="form-label-custom">तपशील (ऐच्छिक)</label>
        <textarea name="description" class="form-control-custom mb-2" rows="2"></textarea>
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="achievement_date" class="form-control-custom" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php if ($totalWork > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('empStatusChart'), {
  type: 'doughnut',
  data: {
    labels: ['प्रलंबित', 'सुरू आहे', 'पूर्ण झाले'],
    datasets: [{
      data: [<?= $empStatusCounts['pending'] ?>, <?= $empStatusCounts['in_progress'] ?>, <?= $empStatusCounts['completed'] ?>],
      backgroundColor: ['#C97F1D', '#3B6FC9', '#1B8A83'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10 } } },
    cutout: '60%'
  }
});
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
