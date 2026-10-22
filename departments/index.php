<?php
$pageTitle = 'विभाग व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_departments', '../');

$stmt = $pdo->prepare("
    SELECT d.*, c.name AS campus_name, u.name AS hod_name,
      (SELECT COUNT(*) FROM users s WHERE s.department_id = d.id) AS staff_count
    FROM departments d
    LEFT JOIN campuses c ON c.id = d.campus_id
    LEFT JOIN users u ON u.id = d.hod_id
    WHERE d.organization_id = ? ORDER BY d.name_local
");
$stmt->execute([activeOrgId()]);
$departments = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s323')) ?></div>
  <div class="d-flex gap-2">
    <a href="bulk-reassign.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-arrows-turn-right me-1"></i>🔀 मोठ्या प्रमाणात हलवा
    </a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i>नवीन विभाग
    </a>
  </div>
</div>

<div class="panel mt-3">
  <table class="rtable">
    <thead><tr><th><?= h(t('s1')) ?></th><th><?= h(t('s8')) ?></th><th><?= h(t('s27')) ?></th><th><?= h(t('s6')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($departments)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s324')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($departments as $dept): ?>
        <tr>
          <td style="font-weight:500;"><i class="<?= h($dept['icon']) ?> me-2"></i><?= h($dept['name_local'] ?: $dept['name']) ?> <span style="color:var(--text-dim);font-size:12px;">(<?= h($dept['name']) ?>)</span></td>
          <td><?= $dept['campus_name'] ? h($dept['campus_name']) : '<span style="color:var(--rail);font-weight:600;">🏛️ Trust (केंद्रीय)</span>' ?></td>
          <td><?= h($dept['hod_name'] ?? '—') ?></td>
          <td class="font-mono"><?= $dept['staff_count'] ?></td>
          <td><?php if ($dept['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td><a href="edit.php?id=<?= $dept['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
