<?php
$pageTitle = 'परिपत्रक/सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

// या व्यक्तीला लागू असलेली परिपत्रकं — "सगळ्यांसाठी" किंवा तिच्याच
// विभागासाठी खास पाठवलेली.
$sql = "
    SELECT a.*, u.name AS created_by_name,
      (SELECT COUNT(*) FROM announcement_reads WHERE announcement_id = a.id AND user_id = ?) AS is_read
    FROM announcements a
    LEFT JOIN users u ON u.id = a.created_by
    WHERE a.organization_id = ?
      AND (a.target_scope = 'all' OR EXISTS (
        SELECT 1 FROM announcement_departments ad WHERE ad.announcement_id = a.id AND ad.department_id = ?
      ))
    ORDER BY a.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user['id'], $orgId, $user['department_id'] ?? 0]);
$announcements = $stmt->fetchAll();

$priorityChips = ['normal' => 'done', 'important' => 'pending', 'urgent' => 'overdue'];
$priorityLabels = ['normal' => 'सर्वसाधारण', 'important' => '⚠️ महत्त्वाचं', 'urgent' => '🚨 तातडीचं'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📢 परिपत्रक/सूचना</div>
  <?php if (hasPermission('view_org_wide_dashboards') || hasRole('super_admin')): ?>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
      <i class="fa-solid fa-bullhorn me-1"></i>नवीन सूचना पाठवा
    </a>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (empty($announcements)): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">अजून कुठलीही सूचना नाही.</div>
<?php endif; ?>

<?php foreach ($announcements as $a): ?>
  <a href="view.php?id=<?= $a['id'] ?>" style="text-decoration:none;color:inherit;">
    <div class="panel mb-3" style="padding:18px 22px;<?= !$a['is_read'] ? 'border-left:4px solid var(--rail);' : '' ?>">
      <div class="d-flex justify-content-between align-items-start">
        <div style="flex:1;">
          <div style="display:flex;align-items:center;gap:8px;">
            <?php if (!$a['is_read']): ?><span style="width:8px;height:8px;border-radius:50%;background:var(--rail);display:inline-block;"></span><?php endif; ?>
            <span style="font-size:15px;font-weight:700;color:var(--ink);"><?= h($a['title']) ?></span>
            <span class="status-chip <?= $priorityChips[$a['priority']] ?>" style="font-size:10px;"><?= $priorityLabels[$a['priority']] ?></span>
          </div>
          <div style="font-size:12.5px;color:var(--text-dim);margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:700px;"><?= h(mb_substr(strip_tags($a['content']), 0, 120)) ?></div>
          <div style="font-size:10.5px;color:var(--text-dim);margin-top:8px;"><?= h($a['created_by_name']) ?> · <?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></div>
        </div>
      </div>
    </div>
  </a>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
