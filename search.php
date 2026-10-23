<?php
$pageTitle = 'शोध';
$rootPath = '';
require_once __DIR__ . '/includes/header.php';

$q = trim($_GET['q'] ?? '');
$orgId = activeOrgId();
$like = '%' . $q . '%';

$results = ['employees' => [], 'work_records' => [], 'departments' => [], 'renewals' => [], 'tasks' => [], 'letters' => []];

if ($q !== '') {
    // Employees
    $stmt = $pdo->prepare("SELECT u.*, d.name_local AS dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ? AND (u.name LIKE ? OR u.email LIKE ? OR u.employee_code LIKE ?) LIMIT 15");
    $stmt->execute([$orgId, $like, $like, $like]);
    $results['employees'] = $stmt->fetchAll();

    // Work records (by code or title)
    $stmt = $pdo->prepare("SELECT wr.*, d.name_local AS dept_name FROM work_records wr JOIN departments d ON d.id = wr.department_id WHERE wr.organization_id = ? AND (wr.work_code LIKE ? OR wr.title LIKE ?) ORDER BY wr.work_date DESC LIMIT 15");
    $stmt->execute([$orgId, $like, $like]);
    $results['work_records'] = $stmt->fetchAll();

    // Departments
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND (name LIKE ? OR name_local LIKE ?) LIMIT 10");
    $stmt->execute([$orgId, $like, $like]);
    $results['departments'] = $stmt->fetchAll();

    // Renewals
    $stmt = $pdo->prepare("SELECT r.*, d.name_local AS dept_name FROM renewal_items r JOIN departments d ON d.id = r.department_id WHERE r.organization_id = ? AND r.status <> 'inactive' AND r.item_name LIKE ? LIMIT 10");
    $stmt->execute([$orgId, $like]);
    $results['renewals'] = $stmt->fetchAll();

    // Tasks
    $stmt = $pdo->prepare("SELECT t.*, u.name AS assignee_name FROM tasks t JOIN users u ON u.id = t.assigned_to WHERE t.organization_id = ? AND (t.title LIKE ? OR t.task_code LIKE ?) LIMIT 10");
    $stmt->execute([$orgId, $like, $like]);
    $results['tasks'] = $stmt->fetchAll();

    // Letters / Files — only ones this user can actually access
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, u.name AS holder_name FROM letters l LEFT JOIN users u ON u.id = l.current_holder_id
            WHERE l.organization_id = ? AND (l.subject LIKE ? OR l.file_number LIKE ?)
              AND (l.created_by = ? OR l.current_holder_id = ? OR ? = 1
                   OR l.id IN (SELECT letter_id FROM letter_actions WHERE from_user_id = ? OR to_user_id = ?))
            LIMIT 10
        ");
        $isMgmt = isManagementOrAbove() ? 1 : 0;
        $stmt->execute([$orgId, $like, $like, $user['id'], $user['id'], $isMgmt, $user['id'], $user['id']]);
        $results['letters'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $results['letters'] = [];
    }
}

$totalResults = array_sum(array_map('count', $results));
?>

<div class="page-eyebrow"><?= h(t('s118')) ?></div>
<div class="page-title">"<?= h($q) ?>" साठी <?= $totalResults ?> <?= h(t('s119')) ?></div>

<?php if ($q === ''): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s120')) ?></div>
<?php else: ?>

<?php if (!empty($results['employees'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h(t('s6')) ?></div></div>
    <table class="rtable">
      <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s1')) ?></th><th>Email</th></tr></thead>
      <tbody>
        <?php foreach ($results['employees'] as $e): ?>
          <tr style="cursor:pointer;" onclick="window.location='staff/profile.php?id=<?= $e['id'] ?>'"><td style="font-weight:500;"><?= h($e['name']) ?></td><td><?= h($e['dept_name'] ?? '—') ?></td><td class="font-mono" style="font-size:12px;"><?= h($e['email']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!empty($results['work_records'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h(t('s28')) ?></div></div>
    <table class="rtable">
      <thead><tr><th>Work ID</th><th><?= h(t('s50')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s13')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($results['work_records'] as $w): ?>
          <tr><td class="font-mono"><?= h($w['work_code']) ?></td><td><?= h($w['title']) ?></td><td><?= h($w['dept_name']) ?></td><td class="font-mono"><?= date('d M Y', strtotime($w['work_date'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!empty($results['departments'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h(t('s1')) ?></div></div>
    <table class="rtable">
      <tbody>
        <?php foreach ($results['departments'] as $d): ?>
          <tr><td><a href="departments/dashboard.php?id=<?= $d['id'] ?>" style="text-decoration:none;color:var(--rail);font-weight:500;"><?= h($d['name_local'] ?: $d['name']) ?></a></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!empty($results['renewals'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h(t('s29')) ?></div></div>
    <table class="rtable">
      <thead><tr><th><?= h(t('s51')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s30')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($results['renewals'] as $r): ?>
          <tr><td style="font-weight:500;"><?= h($r['item_name']) ?></td><td><?= h($r['dept_name']) ?></td><td class="font-mono"><?= date('d M Y', strtotime($r['expiry_date'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!empty($results['tasks'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h(t('s14')) ?></div></div>
    <table class="rtable">
      <thead><tr><th>Task ID</th><th><?= h(t('s50')) ?></th><th><?= h(t('s6')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($results['tasks'] as $t): ?>
          <tr><td class="font-mono"><?= h($t['task_code']) ?></td><td><?= h($t['title']) ?></td><td><?= h($t['assignee_name']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!empty($results['letters'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">पत्र / फाईल</div></div>
    <table class="rtable">
      <thead><tr><th>फाईल क्र.</th><th>विषय</th><th>सध्या कोणाकडे</th></tr></thead>
      <tbody>
        <?php foreach ($results['letters'] as $l): ?>
          <tr style="cursor:pointer;" onclick="window.location='letters/view.php?id=<?= $l['id'] ?>'"><td class="font-mono"><?= h($l['file_number']) ?></td><td><?= h($l['subject']) ?></td><td><?= h($l['holder_name'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($totalResults === 0): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s121')) ?></div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
