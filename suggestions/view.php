<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Suggestion याच संस्थेची आहे ना — नसेल तर काहीच करायचं नाही
    $ownStmt = $pdo->prepare("SELECT id FROM software_suggestions WHERE id = ? AND organization_id = ?");
    $ownStmt->execute([$id, $orgId]);
    if (!$ownStmt->fetch()) { http_response_code(404); die('Suggestion सापडलं नाही.'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'vote_here') {
        try {
            $pdo->prepare("INSERT INTO software_suggestion_votes (suggestion_id, user_id) VALUES (?, ?)")->execute([$id, $user['id']]);
        } catch (PDOException $e) {
            $pdo->prepare("DELETE FROM software_suggestion_votes WHERE suggestion_id = ? AND user_id = ?")->execute([$id, $user['id']]);
        }
    } elseif ($action === 'comment') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment) {
            $pdo->prepare("INSERT INTO software_suggestion_comments (suggestion_id, comment, commented_by) VALUES (?, ?, ?)")->execute([$id, $comment, $user['id']]);
        }
    } elseif ($action === 'respond' && (hasRole('super_admin') || hasPermission('manage_suggestions'))) {
        $status = $_POST['status'] ?? 'submitted';
        $response = trim($_POST['admin_response'] ?? '');
        $sugStmt = $pdo->prepare("SELECT submitted_by, title FROM software_suggestions WHERE id = ?");
        $sugStmt->execute([$id]);
        $sug = $sugStmt->fetch();
        $pdo->prepare("UPDATE software_suggestions SET status = ?, admin_response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?")
            ->execute([$status, $response, $user['id'], $id]);
        if ($sug) {
            notifyUser($sug['submitted_by'], $orgId, "तुमच्या Suggestion ला उत्तर मिळालं — {$sug['title']}", $response ?: 'स्थिती अद्ययावत झाली', 'suggestions/view.php?id=' . $id);
        }
        $_SESSION['flash'] = 'Response जतन झाला.';
    }
    header('Location: view.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.*, u.name AS submitted_by_name, ru.name AS responded_by_name,
      (SELECT COUNT(*) FROM software_suggestion_votes WHERE suggestion_id = s.id) AS vote_count,
      (SELECT COUNT(*) FROM software_suggestion_votes WHERE suggestion_id = s.id AND user_id = ?) AS my_vote
    FROM software_suggestions s JOIN users u ON u.id = s.submitted_by LEFT JOIN users ru ON ru.id = s.responded_by
    WHERE s.id = ? AND s.organization_id = ?
");
$stmt->execute([$user['id'], $id, $orgId]);
$suggestion = $stmt->fetch();
if (!$suggestion) { die('Suggestion सापडली नाही.'); }

$pageTitle = $suggestion['title'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$commentsStmt = $pdo->prepare("SELECT c.*, u.name AS commenter_name FROM software_suggestion_comments c JOIN users u ON u.id = c.commented_by WHERE suggestion_id = ? ORDER BY c.created_at");
$commentsStmt->execute([$id]);
$comments = $commentsStmt->fetchAll();

$categoryLabels = ['new_feature' => '✨ नवीन Feature', 'improvement' => '🔧 सुधारणा', 'bug' => '🐛 समस्या', 'other' => '💭 इतर'];
$statusLabels = ['submitted' => '📥 नोंदवलं', 'under_review' => '🔍 पडताळणी सुरू', 'planned' => '📅 नियोजित', 'implemented' => '✅ बनवलं गेलं', 'rejected' => '❌ शक्य नाही'];
$statusChips = ['submitted' => 'pending', 'under_review' => 'pending', 'planned' => 'pending', 'implemented' => 'done', 'rejected' => 'overdue'];
$isManagement = hasRole('super_admin') || hasPermission('manage_suggestions');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💡 Suggestion तपशील</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व Suggestions</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div style="padding:24px 26px;">
    <div style="display:flex;justify-content:space-between;align-items:start;">
      <div style="font-size:19px;font-weight:700;"><?= h($suggestion['title']) ?></div>
      <span class="status-chip <?= $statusChips[$suggestion['status']] ?>"><?= $statusLabels[$suggestion['status']] ?></span>
    </div>
    <div style="font-size:11.5px;color:var(--text-dim);margin:6px 0 14px;">
      <?= $categoryLabels[$suggestion['category']] ?> <?= $suggestion['module_name'] ? '· ' . h($suggestion['module_name']) : '' ?> · <?= h($suggestion['submitted_by_name']) ?> · <?= date('d M Y', strtotime($suggestion['created_at'])) ?>
    </div>
    <p style="font-size:14px;white-space:pre-wrap;"><?= h($suggestion['description']) ?></p>

    <form method="POST" class="mt-2">
      <input type="hidden" name="action" value="vote_here">
      <button type="submit" style="border:1px solid <?= $suggestion['my_vote'] ? 'var(--rail)' : 'var(--line)' ?>;background:<?= $suggestion['my_vote'] ? 'var(--rail-soft)' : 'transparent' ?>;color:var(--rail);border-radius:9px;padding:7px 16px;font-size:13px;font-weight:600;">👍 <?= $suggestion['vote_count'] ?> Vote</button>
    </form>

    <?php if ($suggestion['admin_response']): ?>
      <div style="background:var(--rail-soft);border-radius:10px;padding:14px 18px;margin-top:16px;">
        <div style="font-size:11.5px;font-weight:700;color:var(--rail);">💬 Management चं उत्तर — <?= h($suggestion['responded_by_name']) ?> (<?= date('d M Y', strtotime($suggestion['responded_at'])) ?>)</div>
        <div style="font-size:13px;margin-top:4px;"><?= h($suggestion['admin_response']) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($isManagement): ?>
      <div style="background:var(--canvas);border-radius:10px;padding:16px 18px;margin-top:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-dim);margin-bottom:8px;">Management — स्थिती व उत्तर द्या</div>
        <form method="POST">
          <input type="hidden" name="action" value="respond">
          <select name="status" class="form-select-custom mb-2">
            <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $suggestion['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
          </select>
          <textarea name="admin_response" class="form-control-custom mb-2" rows="2" placeholder="उत्तर/स्पष्टीकरण (ऐच्छिक)"><?= h($suggestion['admin_response']) ?></textarea>
          <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 16px;">जतन करा</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">💬 चर्चा (<?= count($comments) ?>)</div></div>
  <div style="padding:16px 20px;">
    <?php foreach ($comments as $c): ?>
      <div style="border-bottom:1px solid var(--line);padding:10px 0;">
        <div style="font-size:12.5px;font-weight:600;"><?= h($c['commenter_name']) ?> <span style="font-weight:400;color:var(--text-dim);font-size:10.5px;"><?= date('d M Y, h:i A', strtotime($c['created_at'])) ?></span></div>
        <div style="font-size:13px;margin-top:2px;"><?= h($c['comment']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($comments)): ?><p style="font-size:12.5px;color:var(--text-dim);">अजून कुठलीही चर्चा नाही.</p><?php endif; ?>

    <form method="POST" class="mt-3 d-flex gap-2">
      <input type="hidden" name="action" value="comment">
      <input type="text" name="comment" class="form-control-custom" placeholder="तुमचं मत/शंका लिहा..." required>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 18px;">पाठवा</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
