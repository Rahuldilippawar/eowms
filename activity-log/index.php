<?php
$pageTitle = 'अ‍ॅक्टिव्हिटी लॉग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
try {
    $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE organization_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$orgId]);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $logs = [];
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s407')) ?></div>
  <a href="login-activity.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-right-to-bracket me-1"></i>🔐 फक्त Login/Logout बघा</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;"><?= h(t('s408')) ?></p>

<div class="panel">
  <table class="rtable">
    <thead><tr><th><?= h(t('s409')) ?></th><th><?= h(t('s410')) ?></th><th><?= h(t('s411')) ?></th><th><?= h(t('s88')) ?></th></tr></thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s34')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td class="font-mono" style="font-size:12px;white-space:nowrap;"><?= date('d M Y, h:i A', strtotime($log['created_at'])) ?></td>
          <td style="font-weight:500;"><?= h($log['actor_name'] ?? '—') ?></td>
          <td><?= h($log['action']) ?></td>
          <td style="font-size:12.5px;color:var(--text-dim);"><?= h($log['details'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
