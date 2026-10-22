<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
    try {
        $pdo->prepare("INSERT INTO software_suggestion_votes (suggestion_id, user_id) VALUES (?, ?)")->execute([$suggestionId, $user['id']]);
    } catch (PDOException $e) {
        // आधीच Vote दिलेला आहे — Toggle करून काढून टाकतो
        $pdo->prepare("DELETE FROM software_suggestion_votes WHERE suggestion_id = ? AND user_id = ?")->execute([$suggestionId, $user['id']]);
    }
    header('Location: index.php?sort=' . ($_GET['sort'] ?? 'votes'));
    exit;
}

$pageTitle = 'Software Suggestions';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sort = $_GET['sort'] ?? 'votes';
$filterStatus = $_GET['status'] ?? 'all';
$filterCategory = $_GET['category'] ?? '';

$sql = "
    SELECT s.*, u.name AS submitted_by_name,
      (SELECT COUNT(*) FROM software_suggestion_votes WHERE suggestion_id = s.id) AS vote_count,
      (SELECT COUNT(*) FROM software_suggestion_votes WHERE suggestion_id = s.id AND user_id = ?) AS my_vote
    FROM software_suggestions s JOIN users u ON u.id = s.submitted_by
    WHERE s.organization_id = ?
";
$params = [$user['id'], $orgId];
if ($filterStatus !== 'all') { $sql .= " AND s.status = ?"; $params[] = $filterStatus; }
if ($filterCategory) { $sql .= " AND s.category = ?"; $params[] = $filterCategory; }
$sql .= $sort === 'newest' ? " ORDER BY s.created_at DESC" : " ORDER BY vote_count DESC, s.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suggestions = $stmt->fetchAll();

$categoryLabels = ['new_feature' => '✨ नवीन Feature', 'improvement' => '🔧 सुधारणा', 'bug' => '🐛 समस्या', 'other' => '💭 इतर'];
$statusLabels = ['submitted' => '📥 नोंदवलं', 'under_review' => '🔍 पडताळणी सुरू', 'planned' => '📅 नियोजित', 'implemented' => '✅ बनवलं गेलं', 'rejected' => '❌ शक्य नाही'];
$statusChips = ['submitted' => 'pending', 'under_review' => 'pending', 'planned' => 'pending', 'implemented' => 'done', 'rejected' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💡 Software Suggestions</div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
    <i class="fa-solid fa-lightbulb me-1"></i>नवीन कल्पना मांडा
  </a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Software मध्ये काय सुधारायला हवं, कोणतं नवीन Feature हवं — तुमचं मत मांडा. जास्त Vote मिळालेल्या कल्पनांना प्राधान्य दिलं जाईल.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex gap-2 mb-3 flex-wrap justify-content-between">
  <div class="d-flex gap-2 flex-wrap">
    <a href="?sort=votes&status=<?= h($filterStatus) ?>" class="period-btn <?= $sort === 'votes' ? 'active' : '' ?>">👍 सर्वाधिक Vote</a>
    <a href="?sort=newest&status=<?= h($filterStatus) ?>" class="period-btn <?= $sort === 'newest' ? 'active' : '' ?>">🆕 नवीन</a>
  </div>
  <select onchange="window.location.href='?sort=<?= h($sort) ?>&status='+this.value" class="form-select-custom" style="width:auto;">
    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>सगळ्या स्थिती</option>
    <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
  </select>
</div>

<?php if (empty($suggestions)): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">अजून कुठलीही Suggestion नाही — पहिली तुम्हीच मांडा!</div>
<?php endif; ?>

<?php foreach ($suggestions as $s): ?>
  <div class="panel mb-3" style="padding:0;">
    <div style="display:flex;">
      <form method="POST" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px 18px;border-right:1px solid var(--line);min-width:70px;">
        <input type="hidden" name="action" value="vote"><input type="hidden" name="suggestion_id" value="<?= $s['id'] ?>">
        <button type="submit" style="border:none;background:none;font-size:22px;color:<?= $s['my_vote'] ? 'var(--rail)' : 'var(--text-dim)' ?>;cursor:pointer;">👍</button>
        <div style="font-size:15px;font-weight:700;color:<?= $s['my_vote'] ? 'var(--rail)' : 'var(--ink)' ?>;"><?= $s['vote_count'] ?></div>
      </form>
      <a href="view.php?id=<?= $s['id'] ?>" style="flex:1;padding:16px 20px;text-decoration:none;color:inherit;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="font-size:14.5px;font-weight:700;color:var(--ink);"><?= h($s['title']) ?></span>
          <span class="status-chip <?= $statusChips[$s['status']] ?>" style="font-size:10px;"><?= $statusLabels[$s['status']] ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-dim);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:700px;"><?= h(mb_substr($s['description'], 0, 130)) ?></div>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:8px;">
          <?= $categoryLabels[$s['category']] ?> <?= $s['module_name'] ? '· ' . h($s['module_name']) : '' ?> · <?= h($s['submitted_by_name']) ?> · <?= date('d M Y', strtotime($s['created_at'])) ?>
        </div>
      </a>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
