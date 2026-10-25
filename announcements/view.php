<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT a.*, u.name AS created_by_name FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ? AND a.organization_id = ?");
$stmt->execute([$id, $orgId]);
$announcement = $stmt->fetch();
if (!$announcement) { http_response_code(404); die('सूचना सापडली नाही.'); }

$isCreator = (int)$announcement['created_by'] === (int)$user['id'];
$canManageAnn = hasPermission('manage_announcements') || $isCreator;

// फक्त ज्यांच्यासाठी पाठवली (सगळे / त्यांचा विभाग) ते, किंवा व्यवस्थापक
$isRecipient = $announcement['target_scope'] === 'all';
if (!$isRecipient && !empty($user['department_id'])) {
    $rcStmt = $pdo->prepare("SELECT 1 FROM announcement_departments WHERE announcement_id = ? AND department_id = ?");
    $rcStmt->execute([$id, $user['department_id']]);
    $isRecipient = (bool)$rcStmt->fetchColumn();
}
$isWithdrawn = !empty($announcement['withdrawn_at']);
if (!$canManageAnn && (!$isRecipient || $isWithdrawn)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">ही सूचना तुमच्यासाठी नाही (किंवा मागे घेतली आहे). <a href="index.php">परत</a></div>');
}

// मागे घेणे / पुन्हा सुरू करणे / Pin — व्यवस्थापक किंवा पाठवणारा
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageAnn) {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'withdraw') {
            $pdo->prepare("UPDATE announcements SET withdrawn_at = NOW(), withdrawn_by = ? WHERE id = ? AND organization_id = ?")->execute([$user['id'], $id, $orgId]);
            logActivity('परिपत्रक/सूचना मागे घेतली', 'announcement', $id, $announcement['title']);
            $_SESSION['flash'] = 'सूचना मागे घेतली — आता कर्मचाऱ्यांना दिसणार नाही.';
        } elseif ($act === 'restore') {
            $pdo->prepare("UPDATE announcements SET withdrawn_at = NULL, withdrawn_by = NULL WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
            $_SESSION['flash'] = 'सूचना पुन्हा सुरू केली.';
        } elseif ($act === 'toggle_pin') {
            $pdo->prepare("UPDATE announcements SET is_pinned = 1 - is_pinned WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        }
    } catch (PDOException $e) { /* columns not migrated yet */ }
    header('Location: view.php?id=' . $id);
    exit;
}

// Read म्हणून नोंदवतो (पहिल्यांदाच बघितलं असेल तरच)
try {
    $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)")->execute([$id, $user['id']]);
} catch (PDOException $e) {}

$readReceipts = [];
$totalTargeted = 0;
if ($canManageAnn) {
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
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <?php if ($canManageAnn): ?>
      <a href="create.php?id=<?= $id ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;">✏️ बदला</a>
      <form method="POST" style="margin:0;"><input type="hidden" name="action" value="toggle_pin">
        <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;"><?= !empty($announcement['is_pinned']) ? '📌 Unpin' : '📌 Pin' ?></button></form>
      <form method="POST" style="margin:0;" <?= $isWithdrawn ? '' : 'onsubmit="return confirm(\'ही सूचना मागे घ्यायची?\');"' ?>><input type="hidden" name="action" value="<?= $isWithdrawn ? 'restore' : 'withdraw' ?>">
        <button type="submit" class="btn btn-sm" style="border:1px solid <?= $isWithdrawn ? 'var(--rail)' : 'var(--rose)' ?>;color:<?= $isWithdrawn ? 'var(--rail)' : 'var(--rose)' ?>;border-radius:7px;font-size:12px;"><?= $isWithdrawn ? '↩️ पुन्हा सुरू करा' : '🚫 मागे घ्या' ?></button></form>
    <?php endif; ?>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व सूचना</a>
  </div>
</div>
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if ($isWithdrawn): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">🚫 ही सूचना <?= date('d M Y, h:i A', strtotime($announcement['withdrawn_at'])) ?> रोजी मागे घेतली आहे — कर्मचाऱ्यांना दिसत नाही.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-<?= ($canManageAnn) ? '7' : '12' ?>">
    <div class="panel">
      <div style="padding:24px 26px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
          <span style="font-size:19px;font-weight:700;color:var(--ink);"><?= h($announcement['title']) ?></span>
          <span class="status-chip <?= $priorityChips[$announcement['priority']] ?>"><?= $priorityLabels[$announcement['priority']] ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:18px;"><?= h($announcement['created_by_name']) ?> · <?= date('d M Y, h:i A', strtotime($announcement['created_at'])) ?><?= !empty($announcement['updated_at']) ? ' · बदल: ' . date('d M, h:i A', strtotime($announcement['updated_at'])) : '' ?><?= !empty($announcement['expires_at']) ? ' · मुदत: ' . date('d M Y', strtotime($announcement['expires_at'])) : '' ?></div>
        <div style="font-size:14px;line-height:1.8;white-space:pre-wrap;"><?= h($announcement['content']) ?></div>
      </div>
    </div>
  </div>

  <?php if ($canManageAnn): ?>
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
