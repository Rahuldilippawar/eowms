<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT a.*, u.name AS created_by_name FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ? AND a.organization_id = ?");
$stmt->execute([$id, $orgId]);
$announcement = $stmt->fetch();
if (!$announcement) { die('सूचना सापडली नाही.'); }

// Read म्हणून नोंदवतो (पहिल्यांदाच बघितलं असेल तरच)
try {
    $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)")->execute([$id, $user['id']]);
} catch (PDOException $e) {}

$isCreator = (int)$announcement['created_by'] === (int)$user['id'];
$readReceipts = [];
$totalTargeted = 0;
if ($isCreator || hasRole('super_admin')) {
    if ($announcement['target_scope'] === 'specific') {
        $ph = "(SELECT department_id FROM announcement_departments WHERE announcement_id = ?)";
        $tStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 AND department_id IN $ph");
        $tStmt->execute([$orgId, $id]);
    } else {
        $tStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1");
        $tStmt->execute([$orgId]);
    }
    $targetedUsers = $tStmt->fetchAll();
    $totalTargeted = count($targetedUsers);

    $rStmt = $pdo->prepare("SELECT user_id, read_at FROM announcement_reads WHERE announcement_id = ?");
    $rStmt->execute([$id]);
    $readMap = [];
    foreach ($rStmt->fetchAll() as $r) { $readMap[$r['user_id']] = $r['read_at']; }

    foreach ($targetedUsers as $tu) {
        $readReceipts[] = ['name' => $tu['name'], 'read_at' => $readMap[$tu['id']] ?? null];
    }
    usort($readReceipts, fn($a, $b) => ($a['read_at'] ? 0 : 1) <=> ($b['read_at'] ? 0 : 1));
}

$pageTitle = $announcement['title'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$priorityChips = ['normal' => 'done', 'important' => 'pending', 'urgent' => 'overdue'];
$priorityLabels = ['normal' => 'सर्वसाधारण', 'important' => '⚠️ महत्त्वाचं', 'urgent' => '🚨 तातडीचं'];
$readCount = count(array_filter($readReceipts, fn($r) => $r['read_at']));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📢 सूचना</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व सूचना</a>
</div>

<div class="row g-3">
  <div class="col-lg-<?= ($isCreator || hasRole('super_admin')) ? '7' : '12' ?>">
    <div class="panel">
      <div style="padding:24px 26px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
          <span style="font-size:19px;font-weight:700;color:var(--ink);"><?= h($announcement['title']) ?></span>
          <span class="status-chip <?= $priorityChips[$announcement['priority']] ?>"><?= $priorityLabels[$announcement['priority']] ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:18px;"><?= h($announcement['created_by_name']) ?> · <?= date('d M Y, h:i A', strtotime($announcement['created_at'])) ?></div>
        <div style="font-size:14px;line-height:1.8;white-space:pre-wrap;"><?= h($announcement['content']) ?></div>
      </div>
    </div>
  </div>

  <?php if ($isCreator || hasRole('super_admin')): ?>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">👁️ कोणी वाचलं</div><div class="sub"><?= $readCount ?> / <?= $totalTargeted ?> जणांनी वाचलं</div></div>
      <div style="max-height:400px;overflow-y:auto;">
        <?php foreach ($readReceipts as $r): ?>
          <div style="display:flex;justify-content:space-between;padding:9px 20px;border-bottom:1px solid var(--line);font-size:12.5px;">
            <span><?= h($r['name']) ?></span>
            <?php if ($r['read_at']): ?>
              <span style="color:var(--rail);">✅ <?= date('d M, h:i A', strtotime($r['read_at'])) ?></span>
            <?php else: ?>
              <span style="color:var(--text-dim);">⏳ अजून वाचलेलं नाही</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
