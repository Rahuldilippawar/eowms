<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$pageTitle = 'Help Center — मदत';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$searchQ = trim($_GET['q'] ?? '');

$categoriesStmt = $pdo->query("SELECT * FROM help_categories ORDER BY sort_order");
$categories = $categoriesStmt->fetchAll();

if ($searchQ) {
    $articlesStmt = $pdo->prepare("SELECT ha.*, hc.name AS cat_name, hc.icon AS cat_icon FROM help_articles ha JOIN help_categories hc ON hc.id = ha.category_id WHERE ha.title LIKE ? OR ha.keywords LIKE ? OR ha.content LIKE ? ORDER BY hc.sort_order, ha.sort_order");
    $like = '%' . $searchQ . '%';
    $articlesStmt->execute([$like, $like, $like]);
    $searchResults = $articlesStmt->fetchAll();
} else {
    $allArticlesStmt = $pdo->query("SELECT * FROM help_articles ORDER BY category_id, sort_order");
    $allArticles = [];
    foreach ($allArticlesStmt->fetchAll() as $a) { $allArticles[$a['category_id']][] = $a; }
}
?>

<div class="page-eyebrow">Help Center</div>
<div class="page-title mb-1">❓ System कसं वापरायचं — मदत</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:24px;">कुठलाही Module समजत नसेल — इथे शोधा, किंवा खाली Categories मधून बघा</p>

<form method="GET" class="mb-4">
  <div style="position:relative;max-width:500px;">
    <input type="text" name="q" class="form-control-custom" style="padding-left:38px;" placeholder="उदा. रजा कशी घ्यायची, पत्र कसं पाठवायचं..." value="<?= h($searchQ) ?>">
    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-dim);"></i>
  </div>
</form>

<?php if ($searchQ): ?>
  <div class="panel">
    <div class="panel-head"><div class="title">"<?= h($searchQ) ?>" साठी <?= count($searchResults) ?> निकाल</div></div>
    <?php if (empty($searchResults)): ?>
      <div class="empty-state"><div class="icon"><i class="fa-solid fa-magnifying-glass"></i></div><div class="title">काही सापडलं नाही</div><div class="sub">वेगळे शब्द वापरून बघा, किंवा खालच्या Categories मध्ये शोधा</div></div>
    <?php endif; ?>
    <?php foreach ($searchResults as $r): ?>
      <a href="article.php?id=<?= $r['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;">
        <i class="<?= h($r['cat_icon']) ?>" style="color:var(--rail);width:24px;"></i>
        <div><div style="font-weight:600;font-size:13.5px;"><?= h($r['title']) ?></div><div style="font-size:11px;color:var(--text-dim);"><?= h($r['cat_name']) ?></div></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($categories as $cat): ?>
      <div class="col-md-6">
        <div class="panel">
          <div class="panel-head"><div class="title"><i class="<?= h($cat['icon']) ?> me-2" style="color:var(--rail);"></i><?= h($cat['name']) ?></div></div>
          <?php foreach ($allArticles[$cat['id']] ?? [] as $art): ?>
            <a href="article.php?id=<?= $art['id']?>" style="display:block;padding:11px 20px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;font-size:13px;">
              <?= h($art['title']) ?> <i class="fa-solid fa-chevron-right" style="font-size:9px;color:var(--text-dim);float:right;margin-top:4px;"></i>
            </a>
          <?php endforeach; ?>
          <?php if (empty($allArticles[$cat['id']])): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून कुठलाही Article नाही</p><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (hasRole('super_admin')): ?>
  <a href="manage.php" class="btn mt-4" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:12.5px;padding:8px 16px;">✏️ Help Content Edit करा</a>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
