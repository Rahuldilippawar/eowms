<?php
$pageTitle = 'माझ्या विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';

$orgId = activeOrgId();
$category = $_GET['category'] ?? '';
if ($category !== '' && !isset(TICKET_CATEGORIES[$category])) { $category = ''; }

$dbNotReady = false;
$myRequests = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, d.name_local AS dept_name, u.name AS completed_by_name
        FROM work_requests r
        LEFT JOIN departments d ON d.id = r.department_id
        LEFT JOIN users u ON u.id = r.completed_by
        WHERE r.organization_id = ? AND r.requested_by_user_id = ?" . ($category !== '' ? " AND r.category = ?" : "") . "
        ORDER BY (r.status = 'completed') DESC, r.created_at DESC
    ");
    $stmt->execute($category !== '' ? [$orgId, $user['id'], $category] : [$orgId, $user['id']]);
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


$statusLabels = TICKET_STATUS_LABELS;
$statusChips = TICKET_STATUS_CHIPS;
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 माझ्या पाठवलेल्या विनंत्या</div>
  <div class="d-flex gap-2">
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i>नवीन विनंती पाठवा
    </a>
    <a href="create.php?category=it" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">🖥️ IT विनंती</a>
  </div>
</div>
<div class="period-toggle mb-3">
  <a href="my-requests.php" class="period-btn <?= $category === '' ? 'active' : '' ?>">सगळ्या</a>
  <?php foreach (TICKET_CATEGORIES as $k => $lbl): ?><a href="?category=<?= $k ?>" class="period-btn <?= $category === $k ? 'active' : '' ?>"><?= h($lbl) ?></a><?php endforeach; ?>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Request ID</th><th>कोणत्या विभागाला</th><th>काम</th><th>दिनांक / वय</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php if (empty($myRequests)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">तुम्ही अजून कुठलीही विनंती पाठवलेली नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($myRequests as $r): ?>
        <tr>
          <td class="font-mono" style="font-weight:600;font-size:12px;"><a href="view.php?id=<?= $r['id'] ?>" style="color:var(--rail);"><?= h($r['request_code']) ?></a></td>
          <td><?= h($r['dept_name'] ?: ($r['category'] === 'it' ? 'IT' : '—')) ?><div style="font-size:10.5px;color:var(--text-dim);"><?= h(TICKET_CATEGORIES[$r['category']] ?? $r['category']) ?></div></td>
          <td style="max-width:260px;"><?php if ($r['title']): ?><strong><?= h($r['title']) ?></strong><br><?php endif; ?><?= h(mb_strimwidth($r['work_description'], 0, 160, '…')) ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y', strtotime($r['created_at'])) ?><div style="color:var(--text-dim);"><?= h(ticketAgeLabel($r['created_at'])) ?></div><?= ticketSlaBadge($r)['html'] ?></td>
          <td>
            <span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span>
            <?php if ($r['status'] === 'completed' && $r['completed_by_name']): ?>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:2px;"><?= h($r['completed_by_name']) ?> यांनी पूर्ण केलं</div>
              <a href="view.php?id=<?= $r['id'] ?>" style="font-size:11px;color:var(--rail);font-weight:600;">⭐ Rating देऊन बंद करा</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
