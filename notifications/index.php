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
$hasUnread = (bool)array_filter($notifications, fn($n) => !$n['is_read']);
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s93')) ?></div>
  <?php if ($hasUnread): ?>
    <form method="POST" action="mark-all-read.php">
      <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;"><?= h(t('s293')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="panel mt-2">
  <?php if (empty($notifications)): ?>
    <div style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s94')) ?></div>
  <?php endif; ?>
  <?php foreach ($notifications as $n): ?>
    <a href="open.php?id=<?= (int)$n['id'] ?>" style="display:block;padding:14px 20px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;<?= $n['is_read'] ? '' : 'background:var(--rail-soft);' ?>">
      <div style="font-size:13.5px;font-weight:600;color:var(--ink);"><?= h($n['title']) ?></div>
      <?php if ($n['message']): ?><div style="font-size:12.5px;color:var(--text-dim);margin-top:2px;"><?= h($n['message']) ?></div><?php endif; ?>
      <div style="font-size:11px;color:var(--text-dim);margin-top:4px;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
