<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s628'));
}
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_text'])) {
    $quoteText = trim($_POST['quote_text']);
    $author = trim($_POST['author'] ?? '') ?: null;
    if ($quoteText) {
        $insert = $pdo->prepare("INSERT INTO meditation_quotes (organization_id, quote_text, author, created_by) VALUES (?, ?, ?, ?)");
        $insert->execute([$orgId, $quoteText, $author, $user['id']]);
        logActivity('सुविचार जोडला', 'meditation_quote', $pdo->lastInsertId(), $quoteText);
        $_SESSION['flash'] = t('s630');
        header('Location: quotes.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM meditation_quotes WHERE organization_id = ? ORDER BY created_at DESC");
$stmt->execute([$orgId]);
$quotes = $stmt->fetchAll();

$pageTitle = 'सुविचार';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s622')) ?></div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;"><?= h(t('s623')) ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title"><?= h(t('s624')) ?></div></div>
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-9">
        <label class="form-label-custom"><?= h(t('s583')) ?></label>
        <textarea name="quote_text" class="form-control-custom" rows="2" required placeholder="उदा. मन शांत असेल तर आयुष्यातील प्रत्येक निर्णय स्पष्ट होतो."></textarea>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s625')) ?></label>
        <input type="text" name="author" class="form-control-custom">
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">जोडा</button>
      </div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th><?= h(t('s583')) ?></th><th><?= h(t('s626')) ?></th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($quotes)): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s627')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($quotes as $q): ?>
        <tr>
          <td style="max-width:420px;"><?= h($q['quote_text']) ?></td>
          <td><?= h($q['author'] ?? '—') ?></td>
          <td><?php if ($q['is_active']): ?><span class="status-chip done">सक्रिय</span><?php else: ?><span class="status-chip overdue">निष्क्रिय</span><?php endif; ?></td>
          <td>
            <a href="quote-edit.php?id=<?= $q['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;">Edit</a>
            <form method="POST" action="quote-delete.php" style="display:inline;" onsubmit="return confirm('<?= addslashes(t('s640')) ?>');">
              <input type="hidden" name="id" value="<?= $q['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11px;padding:4px 10px;"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
