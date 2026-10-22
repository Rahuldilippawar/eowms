<?php
$pageTitle = 'माझ्या विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

$dbNotReady = false;
$myRequests = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, d.name_local AS dept_name, u.name AS completed_by_name
        FROM work_requests r
        JOIN departments d ON d.id = r.department_id
        LEFT JOIN users u ON u.id = r.completed_by
        WHERE r.organization_id = ? AND r.requested_by_user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$orgId, $user['id']]);
    $myRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    // work_requests चं requested_by_user_id Column अजून Database मध्ये
    // तयार झालेलं नाही — म्हणजे database/migration-internal-work-requests.sql
    // अजून चालवलेली नाही.
    $dbNotReady = true;
}

if ($dbNotReady) {
?>
  <div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
  <div class="page-title mb-3">📋 माझ्या पाठवलेल्या विनंत्या</div>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:18px 22px;">
    ⚠️ <strong>Database अजून पूर्णपणे तयार नाही.</strong><br><br>
    <strong>उपाय:</strong> phpMyAdmin मध्ये जाऊन <span class="font-mono" style="background:#fff;padding:2px 8px;border-radius:5px;">database/migration-internal-work-requests.sql</span> ही File चालवा, मग हे पान Refresh करा.
  </div>
  <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php
}


$statusLabels = ['pending' => '⏳ प्रलंबित', 'in_progress' => '🔧 सुरू आहे', 'completed' => '✅ पूर्ण'];
$statusChips = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 माझ्या पाठवलेल्या विनंत्या</div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन विनंती पाठवा
  </a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Request ID</th><th>कोणत्या विभागाला</th><th>काम</th><th>दिनांक</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php if (empty($myRequests)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">तुम्ही अजून कुठलीही विनंती पाठवलेली नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($myRequests as $r): ?>
        <tr>
          <td class="font-mono" style="font-weight:600;font-size:12px;"><?= h($r['request_code']) ?></td>
          <td><?= h($r['dept_name']) ?></td>
          <td style="max-width:260px;"><?= h($r['work_description']) ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td>
            <span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span>
            <?php if ($r['status'] === 'completed' && $r['completed_by_name']): ?>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:2px;"><?= h($r['completed_by_name']) ?> यांनी पूर्ण केलं</div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
