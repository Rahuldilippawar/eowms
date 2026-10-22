<?php
$pageTitle = 'सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    $notifications = [];
}

// Mark all as read once they view this page
try {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
} catch (PDOException $e) {}
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title"><?= h(t('s93')) ?></div>

<div class="panel">
  <?php if (empty($notifications)): ?>
    <div style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s94')) ?></div>
  <?php endif; ?>
  <?php foreach ($notifications as $n): ?>
    <a href="<?= $rootPath ?><?= h($n['link'] ?: 'index.php') ?>" style="display:block;padding:14px 20px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;">
      <div style="font-size:13.5px;font-weight:600;color:var(--ink);"><?= h($n['title']) ?></div>
      <?php if ($n['message']): ?><div style="font-size:12.5px;color:var(--text-dim);margin-top:2px;"><?= h($n['message']) ?></div><?php endif; ?>
      <div style="font-size:11px;color:var(--text-dim);margin-top:4px;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
