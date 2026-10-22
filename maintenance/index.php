<?php
$pageTitle = 'देखभाल नोंदवही';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code, has_issue_register FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptRow = $deptCodeCheck->fetch();
$userDeptCode = $userDeptRow['code'] ?? null;
$userDeptHasIssueRegister = !empty($userDeptRow['has_issue_register']);
$isMgmt = hasPermission('manage_maintenance_settings');

if (!$isMgmt && !$userDeptHasIssueRegister) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला देखभाल नोंदवही पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}
$canLog = hasRole('super_admin') || $userDeptHasIssueRegister || $isMgmt;

$stmt = $pdo->prepare("
    SELECT ml.*, c.name AS campus_name,
      (SELECT COUNT(*) FROM maintenance_logs WHERE location_id = ml.id) AS total_logs,
      (SELECT MAX(work_date) FROM maintenance_logs WHERE location_id = ml.id) AS last_work_date,
      (SELECT COUNT(*) FROM maintenance_logs WHERE location_id = ml.id AND work_type IN ('दुरुस्त केले','बदलले') AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS recent_issue_count
    FROM maintenance_locations ml LEFT JOIN campuses c ON c.id = ml.campus_id
    WHERE ml.organization_id = ? AND ml.is_active = 1
    ORDER BY recent_issue_count DESC, ml.name
");
$stmt->execute([$orgId]);
$locations = $stmt->fetchAll();

$flaggedCount = count(array_filter($locations, fn($l) => $l['recent_issue_count'] >= 2));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">🔧 देखभाल नोंदवही (Maintenance Log)</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">जागा-निहाय — कधी काय बसवलं/दुरुस्त केलं याचा संपूर्ण इतिहास</div>
  </div>
  <div class="d-flex gap-2">
    <?php if (hasRole('super_admin')): ?>
      <a href="settings.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-sliders me-1"></i>विभाग सेटिंग्ज</a>
      <a href="locations.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-map-location-dot me-1"></i>इमारत/खोली यादी</a>
    <?php endif; ?>
    <?php if ($canLog): ?>
      <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन नोंद</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($flaggedCount > 0): ?>
  <div style="background:var(--rose-soft);color:var(--rose);border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:16px;">
    ⚠️ <strong><?= $flaggedCount ?> जागां</strong>मध्ये गेल्या ३० दिवसांत वारंवार (2+ वेळा) दुरुस्ती/बदल झाला आहे — मूळ कारण तपासणे आवश्यक असू शकते.
  </div>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($locations)): ?>
    <div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कोणतीही जागा जोडलेली नाही<?php if (hasRole('super_admin')): ?> — <a href="locations.php">इथून जोडा</a><?php endif; ?></div></div>
  <?php endif; ?>
  <?php foreach ($locations as $loc): $flagged = $loc['recent_issue_count'] >= 2; ?>
    <div class="col-md-6 col-lg-4">
      <a href="location-history.php?id=<?= $loc['id'] ?>" style="text-decoration:none;">
        <div class="panel" style="padding:18px 20px;<?= $flagged ? 'border:1.5px solid var(--rose);' : '' ?>">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div style="font-weight:600;color:var(--ink);font-size:14.5px;"><?= h($loc['name']) ?></div>
              <div style="font-size:11px;color:var(--text-dim);"><?= h($loc['location_code']) ?><?= $loc['campus_name'] ? ' · ' . h($loc['campus_name']) : '' ?></div>
            </div>
            <?php if ($flagged): ?><span style="font-size:16px;">⚠️</span><?php endif; ?>
          </div>
          <div style="display:flex;gap:16px;margin-top:12px;">
            <div><div style="font-size:10.5px;color:var(--text-dim);">एकूण नोंदी</div><div class="font-mono" style="font-weight:700;font-size:15px;"><?= $loc['total_logs'] ?></div></div>
            <div><div style="font-size:10.5px;color:var(--text-dim);">शेवटचं काम</div><div style="font-size:12px;font-weight:600;"><?= $loc['last_work_date'] ? date('d M Y', strtotime($loc['last_work_date'])) : '—' ?></div></div>
          </div>
          <?php if ($flagged): ?>
            <div style="font-size:11px;color:var(--rose);font-weight:600;margin-top:8px;">गेल्या ३० दिवसांत <?= $loc['recent_issue_count'] ?> वेळा दुरुस्ती/बदल</div>
          <?php endif; ?>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
