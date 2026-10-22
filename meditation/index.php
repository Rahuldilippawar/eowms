<?php
$pageTitle = 'ध्यान व सत्संग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$canManage = isManagementOrAbove() || hasPermission('manage_meditation');

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$canMarkAttendance = hasRole('super_admin') || $userDeptCode === 'MED';

try {
    $stmt = $pdo->prepare("
        SELECT se.*, d.name_local AS dept_name,
          (SELECT COUNT(*) FROM satsang_duties sd WHERE sd.satsang_event_id = se.id) AS duty_count
        FROM satsang_events se
        LEFT JOIN departments d ON d.id = se.organizing_department_id
        WHERE se.organization_id = ?
        ORDER BY se.event_date DESC
    ");
    $stmt->execute([$orgId]);
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    $events = [];
}

$today = date('Y-m-d');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1"><?= h(t('s591')) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h(t('s592')) ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canManage): ?>
      <a href="quotes.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-quote-left me-1"></i><?= h(t('s583')) ?></a>
      <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i><?= h(t('s584')) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($events)): ?>
    <div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s593')) ?></div></div>
  <?php endif; ?>
  <?php foreach ($events as $ev): ?>
    <?php $isUpcoming = $ev['event_date'] >= $today; ?>
    <div class="col-md-6">
      <div class="panel h-100" style="<?= $isUpcoming ? 'border-color:var(--rail);' : '' ?>">
        <div style="padding:18px 20px;">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <div class="font-display" style="font-weight:700;font-size:16px;color:var(--ink);"><?= date('d M Y — l', strtotime($ev['event_date'])) ?></div>
              <?php if ($ev['theme']): ?><div style="font-size:13px;color:var(--text-dim);margin-top:2px;"><?= h($ev['theme']) ?></div><?php endif; ?>
            </div>
            <?php if ($isUpcoming): ?><span class="status-chip pending"><?= h(t('s594')) ?></span><?php else: ?><span class="status-chip done"><?= h(t('s595')) ?></span><?php endif; ?>
          </div>
          <div style="font-size:13px;margin-bottom:10px;">
            <i class="fa-solid fa-building-flag me-1" style="color:var(--rail);"></i><?= h(t('s585')) ?> <strong><?= h($ev['dept_name'] ?? 'अद्याप ठरलेले नाही') ?></strong>
          </div>
          <?php if ($ev['notes']): ?><p style="font-size:12.5px;color:var(--text-dim);"><?= h($ev['notes']) ?></p><?php endif; ?>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <span style="font-size:12px;color:var(--text-dim);"><i class="fa-solid fa-list-check me-1"></i><?= $ev['duty_count'] ?> <?= h(t('s596')) ?></span>
            <div class="d-flex gap-2">
              <?php if ($canMarkAttendance): ?>
                <a href="attendance.php?id=<?= $ev['id'] ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-clipboard-user me-1"></i>हजेरी</a>
              <?php endif; ?>
              <a href="event.php?id=<?= $ev['id'] ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><?= h(t('s597')) ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
