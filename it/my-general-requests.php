<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $checkStmt = $pdo->prepare("SELECT id FROM it_general_requests WHERE id = ? AND requested_by = ?");
    $checkStmt->execute([$requestId, $user['id']]);
    if ($checkStmt->fetch() && $comment) {
        $pdo->prepare("INSERT INTO it_general_request_comments (request_id, comment, commented_by) VALUES (?, ?, ?)")->execute([$requestId, $comment, $user['id']]);
        $_SESSION['flash'] = 'टिप्पणी जोडली गेली.';
    }
    header('Location: my-general-requests.php');
    exit;
}

$pageTitle = 'माझ्या IT Requests';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("
    SELECT r.*, u.name AS assigned_to_name
    FROM it_general_requests r LEFT JOIN users u ON u.id = r.assigned_to
    WHERE r.organization_id = ? AND r.requested_by = ? ORDER BY r.created_at DESC
");
$stmt->execute([$orgId, $user['id']]);
$requests = $stmt->fetchAll();

$categoryIcons = ['internet_network' => '🌐', 'software' => '💿', 'new_hardware' => '🖥️', 'account_access' => '🔑', 'printer_scanner' => '🖨️', 'other' => '📄'];
$statusLabels = ['open' => '🆕 नवीन', 'assigned' => '👤 नेमली', 'in_progress' => '⏳ सुरू आहे', 'resolved' => '✅ सुटली', 'closed' => '🔒 बंद'];
$statusChips = ['open' => 'pending', 'assigned' => 'pending', 'in_progress' => 'pending', 'resolved' => 'done', 'closed' => 'done'];
$priorityColors = ['low' => 'var(--text-dim)', 'normal' => 'var(--ink)', 'high' => 'var(--amber)', 'urgent' => 'var(--rose)'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 माझ्या IT Requests</div>
  <a href="general-request.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन तक्रार</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (empty($requests)): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">तुम्ही अजून कुठलीही IT Request नोंदवलेली नाही.</div>
<?php endif; ?>

<?php foreach ($requests as $r):
  $commentsStmt = $pdo->prepare("SELECT c.*, u.name AS commented_by_name FROM it_general_request_comments c LEFT JOIN users u ON u.id = c.commented_by WHERE c.request_id = ? ORDER BY c.created_at");
  $commentsStmt->execute([$r['id']]);
  $comments = $commentsStmt->fetchAll();
?>
  <div class="panel mb-3">
    <div style="padding:16px 20px;">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <span style="font-size:14.5px;font-weight:700;"><?= $categoryIcons[$r['category']] ?? '📄' ?> <?= h($r['title']) ?></span>
          <span class="font-mono" style="font-size:10.5px;color:var(--text-dim);margin-left:6px;"><?= h($r['request_code']) ?></span>
        </div>
        <span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span>
      </div>
      <div style="font-size:12.5px;color:var(--text-dim);margin-top:6px;"><?= h($r['description']) ?></div>
      <div style="font-size:11px;color:<?= $priorityColors[$r['priority']] ?>;font-weight:600;margin-top:8px;">प्राधान्य: <?= h(ucfirst($r['priority'])) ?><?= $r['assigned_to_name'] ? ' · नेमलं: ' . h($r['assigned_to_name']) : '' ?></div>
      <?php if ($r['resolution_notes']): ?><div style="font-size:12px;color:var(--rail);margin-top:8px;background:var(--rail-soft);padding:8px 12px;border-radius:8px;">✅ <?= h($r['resolution_notes']) ?></div><?php endif; ?>

      <?php if (!empty($comments)): ?>
        <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px;">
          <?php foreach ($comments as $c): ?>
            <div style="font-size:11.5px;padding:4px 0;"><strong><?= h($c['commented_by_name']) ?>:</strong> <?= h($c['comment']) ?> <span style="color:var(--text-dim);">(<?= date('d M, h:i A', strtotime($c['created_at'])) ?>)</span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($r['status'] !== 'closed'): ?>
        <form method="POST" class="d-flex gap-2 mt-2">
          <input type="hidden" name="action" value="comment"><input type="hidden" name="request_id" value="<?= $r['id'] ?>">
          <input type="text" name="comment" class="form-control-custom" style="font-size:12px;" placeholder="टिप्पणी जोडा...">
          <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:5px 12px;">पाठवा</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
