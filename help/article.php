<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$articleId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT ha.*, hc.name AS cat_name, hc.id AS cat_id FROM help_articles ha JOIN help_categories hc ON hc.id = ha.category_id WHERE ha.id = ?");
$stmt->execute([$articleId]);
$article = $stmt->fetch();
if (!$article) { die('Article सापडला नाही.'); }

$pageTitle = $article['title'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$relatedStmt = $pdo->prepare("SELECT id, title FROM help_articles WHERE category_id = ? AND id != ? ORDER BY sort_order");
$relatedStmt->execute([$article['cat_id'], $articleId]);
$related = $relatedStmt->fetchAll();
?>

<div class="page-eyebrow"><a href="index.php" style="color:var(--text-dim);text-decoration:none;">Help Center</a> / <?= h($article['cat_name']) ?></div>
<div class="page-title mb-1"><?= h($article['title']) ?></div>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="panel" style="padding:26px;">
      <div style="font-size:14px;line-height:1.9;color:var(--text);"><?= $article['content'] ?></div>
    </div>
    <?php if (hasRole('super_admin')): ?>
      <a href="edit-article.php?id=<?= $article['id'] ?>" style="font-size:12px;color:var(--amber);font-weight:600;text-decoration:none;display:inline-block;margin-top:12px;">✏️ हा Article Edit करा</a>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">या Category मधले इतर</div></div>
      <?php foreach ($related as $r): ?>
        <a href="article.php?id=<?= $r['id'] ?>" style="display:block;padding:10px 18px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;font-size:12.5px;"><?= h($r['title']) ?></a>
      <?php endforeach; ?>
      <?php if (empty($related)): ?><p style="font-size:12px;color:var(--text-dim);padding:12px 18px;">इतर काही नाही</p><?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
