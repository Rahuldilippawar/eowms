<?php
$pageTitle = 'माझे टास्क';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$status = $_GET['status'] ?? 'all';

$sql = "SELECT t.*, u.name AS assigner_name FROM tasks t JOIN users u ON u.id = t.assigned_by WHERE t.assigned_to = ?";
$params = [$user['id']];
if ($status !== 'all') { $sql .= " AND t.status = ?"; $params[] = $status; }
$sql .= " ORDER BY t.target_date";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];
?>

<div class="page-eyebrow"><?= h($user['designation']) ?></div>
<div class="page-title"><?= h(t('s206')) ?></div>

<div class="period-toggle mb-3">
  <a href="?status=all" class="period-btn <?= $status === 'all' ? 'active' : '' ?>"><?= h(t('s19')) ?></a>
  <a href="?status=pending" class="period-btn <?= $status === 'pending' ? 'active' : '' ?>"><?= h(t('s7')) ?></a>
  <a href="?status=in_progress" class="period-btn <?= $status === 'in_progress' ? 'active' : '' ?>"><?= h(t('s9')) ?></a>
  <a href="?status=completed" class="period-btn <?= $status === 'completed' ? 'active' : '' ?>"><?= h(t('s10')) ?></a>
</div>

<div class="row g-3">
  <?php if (empty($tasks)): ?>
    <div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s83')) ?></div></div>
  <?php endif; ?>
  <?php foreach ($tasks as $task):
    $isOverdue = strtotime($task['target_date']) < strtotime(date('Y-m-d')) && $task['status'] !== 'completed';
    $chip = $isOverdue && $task['status'] !== 'completed' ? 'overdue' : $statusChip[$task['status']];
    $chipText = $isOverdue && $task['status'] !== 'completed' ? 'मुदत संपली' : $statusLabels[$task['status']];
  ?>
    <div class="col-md-6">
      <div class="panel h-100">
        <div style="padding:18px 20px;">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="font-display" style="font-weight:600;font-size:15px;"><?= h($task['title']) ?></div>
            <span class="status-chip <?= $chip ?>"><?= $chipText ?></span>
          </div>
          <div style="font-size:11px;color:var(--text-dim);" class="font-mono mb-2">
            <?= h($task['task_code']) ?>
            <?php $priorityLabelsMy = ['low' => '⚪ कमी', 'normal' => '🔵 सामान्य', 'high' => '🟠 जास्त', 'urgent' => '🔴 तातडीचे']; ?>
            <?php if (!empty($task['priority']) && $task['priority'] !== 'normal'): ?>&nbsp;·&nbsp;<?= $priorityLabelsMy[$task['priority']] ?? '' ?><?php endif; ?>
          </div>
          <?php if ($task['description']): ?>
            <p style="font-size:13px;color:var(--text-dim);"><?= h($task['description']) ?></p>
          <?php endif; ?>
          <div style="font-size:12.5px;color:var(--text-dim);" class="mb-3">
            <i class="fa-regular fa-calendar me-1"></i>Target: <span class="font-mono"><?= date('d M Y', strtotime($task['target_date'])) ?></span>
            &nbsp;·&nbsp; असाइन केले: <?= h($task['assigner_name']) ?>
          </div>

          <?php if ($task['status'] !== 'completed'): ?>
            <form method="POST" action="update-status.php">
              <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
              <div class="d-flex gap-2 mb-2">
                <select name="status" class="form-select-custom" style="width:auto;">
                  <option value="pending" <?= $task['status'] === 'pending' ? 'selected' : '' ?>><?= h(t('s7')) ?></option>
                  <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>><?= h(t('s9')) ?></option>
                  <option value="completed"><?= h(t('s10')) ?></option>
                </select>
                <input type="number" step="0.01" name="cost_incurred" class="form-control-custom" placeholder="झालेला खर्च (₹, असल्यास)" style="max-width:200px;">
              </div>
              <textarea name="completion_remarks" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी/तपशील (उदा. काय केलं, काय अडचण आली)"></textarea>
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:6px 16px;"><?= h(t('s39')) ?></button>
            </form>
          <?php else: ?>
            <div style="font-size:12px;color:var(--rail);font-weight:600;">
              <i class="fa-solid fa-circle-check me-1"></i><?= $task['completed_at'] ? date('d M Y, h:i A', strtotime($task['completed_at'])) : '' ?> रोजी पूर्ण झाले
            </div>
            <?php if ($task['completion_remarks']): ?><div style="font-size:12px;color:var(--text-dim);margin-top:4px;">📝 <?= h($task['completion_remarks']) ?></div><?php endif; ?>
            <?php if ($task['cost_incurred']): ?><div style="font-size:12px;color:var(--amber);margin-top:2px;">💰 झालेला खर्च: ₹<?= number_format($task['cost_incurred'], 0) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
