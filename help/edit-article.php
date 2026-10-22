<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$articleId = (int)($_GET['id'] ?? 0);
$article = null;
if ($articleId) {
    $stmt = $pdo->prepare("SELECT * FROM help_articles WHERE id = ?");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch();
    if (!$article) { die('Article सापडला नाही.'); }
}
$prefilledCategoryId = (int)($_GET['category_id'] ?? ($article['category_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');

    if ($categoryId && $title && $content) {
        if ($articleId) {
            $pdo->prepare("UPDATE help_articles SET category_id=?, title=?, content=?, keywords=?, updated_by=? WHERE id=?")
                ->execute([$categoryId, $title, $content, $keywords ?: null, $user['id'], $articleId]);
            $_SESSION['flash'] = 'Article Update झाला.';
        } else {
            $maxOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM help_articles WHERE category_id = ?");
            $maxOrderStmt->execute([$categoryId]);
            $nextOrder = $maxOrderStmt->fetch()['next_order'];
            $pdo->prepare("INSERT INTO help_articles (category_id, title, content, keywords, sort_order, updated_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$categoryId, $title, $content, $keywords ?: null, $nextOrder, $user['id']]);
            $_SESSION['flash'] = 'नवीन Article जोडला गेला.';
        }
    }
    header('Location: manage.php');
    exit;
}

$pageTitle = $article ? 'Article Edit करा' : 'नवीन Article';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$categoriesStmt = $pdo->query("SELECT * FROM help_categories ORDER BY sort_order");
$categories = $categoriesStmt->fetchAll();
?>

<div class="page-eyebrow">Help Center</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= $article ? '✏️ Article Edit करा' : '+ नवीन Article' ?></div>
  <a href="manage.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<div class="panel" style="max-width:800px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">Category</label>
    <select name="category_id" class="form-select-custom mb-2" required>
      <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $prefilledCategoryId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-2" value="<?= h($article['title'] ?? '') ?>" required>
    <label class="form-label-custom">तपशील <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(साधं HTML चालेल — &lt;p&gt;, &lt;ol&gt;&lt;li&gt;, &lt;b&gt; इ.)</span></label>
    <textarea name="content" class="form-control-custom mb-2" rows="12" style="font-family:monospace;font-size:12.5px;" required><?= h($article['content'] ?? '') ?></textarea>
    <label class="form-label-custom">Keywords <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(Search साठी, Comma ने वेगळे करा)</span></label>
    <input type="text" name="keywords" class="form-control-custom mb-3" value="<?= h($article['keywords'] ?? '') ?>">
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 22px;font-weight:600;">जतन करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
