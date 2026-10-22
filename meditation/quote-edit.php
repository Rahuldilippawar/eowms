<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s633'));
}
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quoteText = trim($_POST['quote_text'] ?? '');
    $author = trim($_POST['author'] ?? '') ?: null;
    $isActive = (int)($_POST['is_active'] ?? 1);

    if ($quoteText) {
        $update = $pdo->prepare("UPDATE meditation_quotes SET quote_text=?, author=?, is_active=? WHERE id=? AND organization_id=?");
        $update->execute([$quoteText, $author, $isActive, $id, $orgId]);
        $_SESSION['flash'] = t('s634');
        header('Location: quotes.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM meditation_quotes WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$quote = $stmt->fetch();
if (!$quote) { die(t('s635')); }

$pageTitle = t('s590');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s590')) ?></div>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $quote['id'] ?>">
    <div class="row g-3">
      <div class="col-md-9">
        <label class="form-label-custom"><?= h(t('s583')) ?></label>
        <textarea name="quote_text" class="form-control-custom" rows="3" required><?= h($quote['quote_text']) ?></textarea>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s632')) ?></label>
        <input type="text" name="author" class="form-control-custom" value="<?= h($quote['author']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">स्थिती</label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $quote['is_active'] ? 'selected' : '' ?>>सक्रिय</option>
          <option value="0" <?= !$quote['is_active'] ? 'selected' : '' ?>>निष्क्रिय</option>
        </select>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="quotes.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">बदल जतन करा</button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
