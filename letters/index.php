<?php
$pageTitle = 'पत्र व्यवहार';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$view = $_GET['view'] ?? 'inbox';
$searchQ = trim($_GET['q'] ?? '');
$searchCondition = '';
$searchParam = [];
if ($searchQ) {
    $searchCondition = " AND (l.subject LIKE ? OR l.file_number LIKE ? OR l.outward_no LIKE ? OR l.reference_no LIKE ? OR l.description LIKE ?)";
    $like = '%' . $searchQ . '%';
    $searchParam = [$like, $like, $like, $like, $like];
}

$priorityLabels = ['low' => 'कमी', 'normal' => 'सामान्य', 'high' => 'जास्त', 'urgent' => 'तातडीचे'];
$statusLabels = ['draft' => 'Draft', 'in_progress' => 'प्रगतीत', 'completed' => 'पूर्ण/दाखल', 'returned' => 'परत आले'];
$statusChip = ['draft' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'returned' => 'overdue'];

if ($view === 'sent') {
    $stmt = $pdo->prepare("
        SELECT l.*, lc.name_local AS category_name, u.name AS holder_name
        FROM letters l LEFT JOIN letter_categories lc ON lc.id = l.category_id
        LEFT JOIN users u ON u.id = l.current_holder_id
        WHERE l.organization_id = ? AND l.created_by = ?$searchCondition ORDER BY l.created_at DESC
    ");
    $stmt->execute(array_merge([$orgId, $user['id']], $searchParam));
} elseif ($view === 'all' && hasPermission('manage_letters_org_wide')) {
    $stmt = $pdo->prepare("
        SELECT l.*, lc.name_local AS category_name, u.name AS holder_name
        FROM letters l LEFT JOIN letter_categories lc ON lc.id = l.category_id
        LEFT JOIN users u ON u.id = l.current_holder_id
        WHERE l.organization_id = ?$searchCondition ORDER BY l.created_at DESC LIMIT 200
    ");
    $stmt->execute(array_merge([$orgId], $searchParam));
} else {
    $view = 'inbox';
    $stmt = $pdo->prepare("
        SELECT l.*, lc.name_local AS category_name, u.name AS holder_name
        FROM letters l LEFT JOIN letter_categories lc ON lc.id = l.category_id
        LEFT JOIN users u ON u.id = l.current_holder_id
        WHERE l.current_holder_id = ? AND l.status = 'in_progress'$searchCondition
        ORDER BY FIELD(l.priority,'urgent','high','normal','low'), l.updated_at DESC
    ");
    $stmt->execute(array_merge([$user['id']], $searchParam));
}
$letters = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📨 पत्र व्यवहार</div>
  <div class="d-flex gap-2">
    <?php if (!empty($user['department_id']) || hasPermission('manage_letters_org_wide')): ?>
      <a href="register.php" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-book me-1"></i>आवक/जावक नोंदवही</a>
    <?php endif; ?>
    <?php if (hasPermission('manage_letters_org_wide')): ?>
      <a href="annual-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-chart-column me-1"></i>वार्षिक अहवाल</a>
    <?php endif; ?>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन पत्र / फाईल</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="period-toggle mb-3">
  <a href="?view=inbox" class="period-btn <?= $view === 'inbox' ? 'active' : '' ?>">माझ्याकडे (Action Needed)</a>
  <a href="?view=sent" class="period-btn <?= $view === 'sent' ? 'active' : '' ?>">मी तयार केलेल्या</a>
  <?php if (hasPermission('manage_letters_org_wide')): ?>
    <a href="?view=all" class="period-btn <?= $view === 'all' ? 'active' : '' ?>">संस्थेतील सर्व</a>
  <?php endif; ?>
</div>

<form method="GET" class="mb-3">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div style="position:relative;max-width:420px;">
    <input type="text" name="q" class="form-control-custom" style="padding-left:36px;" placeholder="Subject, फाईल क्र., जावक क्र. ने शोधा..." value="<?= h($searchQ) ?>">
    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:12px;"></i>
  </div>
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>फाईल क्र.</th><th>विषय</th><th>प्रकार</th><th>प्राधान्य</th><th>सध्या कोणाकडे</th><th>किती दिवस</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php if (empty($letters)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">कोणतीही फाईल सापडली नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($letters as $l):
        $daysPending = in_array($l['status'], ['draft', 'in_progress'], true) ? (int)((time() - strtotime($l['updated_at'])) / 86400) : null;
        $isStuck = $daysPending !== null && $daysPending >= 3;
      ?>
        <tr style="cursor:pointer;<?= ($view === 'inbox' && empty($l['current_holder_seen_at'])) ? 'font-weight:600;background:var(--rail-soft);' : '' ?>" onclick="window.location='view.php?id=<?= $l['id'] ?>'">
          <td class="font-mono"><?= h($l['file_number']) ?></td>
          <td style="font-weight:500;">
            <?php if ($view === 'inbox' && empty($l['current_holder_seen_at'])): ?><span style="width:7px;height:7px;border-radius:50%;background:var(--rose);display:inline-block;margin-right:6px;"></span><?php endif; ?>
            <?= h($l['subject']) ?>
          </td>
          <td><?= h($l['category_name'] ?? '—') ?></td>
          <td><?= $priorityLabels[$l['priority']] ?></td>
          <td><?= h($l['holder_name'] ?? '—') ?></td>
          <td><?php if ($daysPending !== null): ?><span style="<?= $isStuck ? 'color:var(--rose);font-weight:700;' : 'color:var(--text-dim);' ?>"><?= $isStuck ? '⚠️ ' : '' ?><?= $daysPending ?> दिवस</span><?php else: ?>—<?php endif; ?></td>
          <td><span class="status-chip <?= $statusChip[$l['status']] ?>"><?= $statusLabels[$l['status']] ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
