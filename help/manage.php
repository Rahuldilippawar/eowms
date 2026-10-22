<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_article') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM help_articles WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = 'Article काढला गेला.';
    } elseif ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-solid fa-circle-question';
        if ($name) {
            $maxOrderStmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM help_categories");
            $nextOrder = $maxOrderStmt->fetch()['next_order'];
            $pdo->prepare("INSERT INTO help_categories (name, icon, sort_order) VALUES (?, ?, ?)")->execute([$name, $icon, $nextOrder]);
            $_SESSION['flash'] = 'Category जोडली गेली.';
        }
    } elseif ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM help_categories WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = 'Category (व त्यातले सगळे Articles) काढले गेले.';
    }
    header('Location: manage.php');
    exit;
}

$pageTitle = 'Help Content व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$categoriesStmt = $pdo->query("SELECT * FROM help_categories ORDER BY sort_order");
$categories = $categoriesStmt->fetchAll();

$allArticlesStmt = $pdo->query("SELECT * FROM help_articles ORDER BY category_id, sort_order");
$allArticles = [];
foreach ($allArticlesStmt->fetchAll() as $a) { $allArticles[$a['category_id']][] = $a; }
?>

<div class="page-eyebrow">Help Center</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">✏️ Help Content व्यवस्थापन</div>
  <div class="d-flex gap-2">
    <button type="button" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;" data-bs-toggle="modal" data-bs-target="#addCatModal">+ नवीन Category</button>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Help Center</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($categories as $cat): ?>
    <div class="col-md-6">
      <div class="panel">
        <div class="panel-head">
          <div class="title"><i class="<?= h($cat['icon']) ?> me-2" style="color:var(--rail);"></i><?= h($cat['name']) ?></div>
          <form method="POST" onsubmit="return confirm('या Category सोबत त्यातले सगळे Articles सुद्धा जातील — काढायचं?')">
            <input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= $cat['id'] ?>">
            <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">🗑️</button>
          </form>
        </div>
        <?php foreach ($allArticles[$cat['id']] ?? [] as $art): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 20px;border-bottom:1px solid var(--line);">
            <span style="font-size:12.5px;"><?= h($art['title']) ?></span>
            <div class="d-flex gap-2">
              <a href="edit-article.php?id=<?= $art['id'] ?>" style="font-size:11px;color:var(--amber);font-weight:600;">Edit</a>
              <form method="POST" onsubmit="return confirm('काढायचं?')"><input type="hidden" name="action" value="delete_article"><input type="hidden" name="id" value="<?= $art['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        <div style="padding:10px 20px;">
          <a href="edit-article.php?category_id=<?= $cat['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;">+ या Category मध्ये नवीन Article जोडा</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="addCatModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_category">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Category जोडा</div>
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">Icon (FontAwesome Class, ऐच्छिक)</label>
        <input type="text" name="icon" class="form-control-custom" placeholder="fa-solid fa-circle-question">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
