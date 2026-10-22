<?php
$pageTitle = 'जागेचा इतिहास';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$locationId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code, has_issue_register FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptRow = $deptCodeCheck->fetch();
$userDeptCode = $userDeptRow['code'] ?? null;
$userDeptHasIssueRegister = !empty($userDeptRow['has_issue_register']);
$isMgmt = hasPermission('manage_maintenance_settings');
if (!$isMgmt && !$userDeptHasIssueRegister) {
    http_response_code(403);
    die('तुम्हाला ही माहिती पाहण्याची परवानगी नाही.');
}
$canLog = hasRole('super_admin') || $userDeptHasIssueRegister || $isMgmt;

$stmt = $pdo->prepare("SELECT ml.*, c.name AS campus_name FROM maintenance_locations ml LEFT JOIN campuses c ON c.id = ml.campus_id WHERE ml.id = ? AND ml.organization_id = ?");
$stmt->execute([$locationId, $orgId]);
$location = $stmt->fetch();
if (!$location) { die('जागा सापडली नाही.'); }

$stmt = $pdo->prepare("
    SELECT mgl.*, d.name_local AS dept_name, u.name AS performed_by_name
    FROM maintenance_logs mgl
    LEFT JOIN departments d ON d.id = mgl.department_id
    LEFT JOIN users u ON u.id = mgl.performed_by
    WHERE mgl.location_id = ? ORDER BY mgl.work_date DESC, mgl.id DESC
");
$stmt->execute([$locationId]);
$logs = $stmt->fetchAll();

// Compute "days lasted" between consecutive entries (oldest-to-newest) and flag repeats
$chronological = array_reverse($logs);
$daysLastedMap = [];
for ($i = 1; $i < count($chronological); $i++) {
    $prev = strtotime($chronological[$i - 1]['work_date']);
    $curr = strtotime($chronological[$i]['work_date']);
    $daysLastedMap[$chronological[$i]['id']] = round(($curr - $prev) / 86400);
}

$recentIssueCount = 0;
foreach ($logs as $l) {
    if (in_array($l['work_type'], ['दुरुस्त केले', 'बदलले'], true) && strtotime($l['work_date']) >= strtotime('-30 days')) {
        $recentIssueCount++;
    }
}

$workTypeChip = ['बसवले' => 'done', 'दुरुस्त केले' => 'pending', 'बदलले' => 'pending', 'तपासणी' => 'done'];
?>

<div class="page-eyebrow"><?= h($location['campus_name'] ?? '') ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">📍 <?= h($location['name']) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= h($location['location_code']) ?> · <?= count($logs) ?> एकूण नोंदी</div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canLog): ?>
      <a href="create.php?location_id=<?= $locationId ?>" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन नोंद</a>
    <?php endif; ?>
    <a href="report.php?location_id=<?= $locationId ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व जागा</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($recentIssueCount >= 2): ?>
  <div style="background:var(--rose-soft);color:var(--rose);border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:16px;">
    ⚠️ <strong>वारंवार समस्या!</strong> गेल्या ३० दिवसांत या जागेत <strong><?= $recentIssueCount ?> वेळा</strong> दुरुस्ती/बदल झाला आहे — मूळ कारण (उदा. वायरिंग समस्या) तपासणे आवश्यक आहे.
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title">संपूर्ण इतिहास (Timeline)</div></div>
  <div style="padding:16px 20px;">
    <?php if (empty($logs)): ?>
      <p style="font-size:13px;color:var(--text-dim);text-align:center;padding:20px;">या जागेसाठी अजून कोणतीही नोंद नाही.</p>
    <?php endif; ?>
    <?php foreach ($logs as $l): $daysLasted = $daysLastedMap[$l['id']] ?? null; ?>
      <div style="display:flex;gap:14px;padding:14px 0;border-bottom:1px solid var(--line);">
        <div style="width:36px;height:36px;border-radius:50%;background:var(--rail-soft);color:var(--rail);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;"><i class="fa-solid fa-screwdriver-wrench"></i></div>
        <div style="flex:1;">
          <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
            <div>
              <span class="status-chip <?= $workTypeChip[$l['work_type']] ?? 'pending' ?>"><?= h($l['work_type']) ?></span>
              <strong style="margin-left:8px;font-size:13.5px;"><?= h($l['item_description']) ?></strong>
            </div>
            <div style="font-size:12px;color:var(--text-dim);"><?= date('d M Y', strtotime($l['work_date'])) ?></div>
          </div>
          <div style="font-size:12px;color:var(--text-dim);margin-top:4px;">
            <?= h($l['performed_by_name'] ?? '—') ?> · <?= h($l['dept_name'] ?? '') ?>
            <?= $l['cost'] ? ' · ₹' . number_format($l['cost'], 0) : '' ?>
          </div>
          <?php if ($l['remarks']): ?><div style="font-size:12px;color:var(--text-dim);margin-top:3px;font-style:italic;"><?= h($l['remarks']) ?></div><?php endif; ?>
          <?php if ($daysLasted !== null): ?>
            <div style="font-size:11px;color:var(--amber);margin-top:4px;">⏱ मागील नोंदीपासून <?= $daysLasted ?> दिवसांनी</div>
          <?php endif; ?>
          <?php if ($l['cost'] > 0): ?>
            <a href="payment.php?id=<?= $l['id'] ?>" style="font-size:11px;color:var(--rail);font-weight:600;text-decoration:none;display:inline-block;margin-top:4px;">💰 Payment Track करा</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
