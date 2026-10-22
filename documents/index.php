<?php
$pageTitle = 'दस्तऐवज Repository';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$search = trim($_GET['q'] ?? '');
$filterCategory = $_GET['category'] ?? '';

$sql = "
    SELECT d.*, dep.name_local AS dept_name, u.name AS uploaded_by_name
    FROM documents d LEFT JOIN departments dep ON dep.id = d.department_id LEFT JOIN users u ON u.id = d.uploaded_by
    WHERE d.organization_id = ? AND (d.department_id IS NULL OR d.department_id = ? OR ? = 1)
";
$params = [$orgId, $user['department_id'] ?? 0, (hasPermission('view_org_wide_dashboards') || hasRole('super_admin')) ? 1 : 0];
if ($search) { $sql .= " AND (d.title LIKE ? OR d.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filterCategory) { $sql .= " AND d.category = ?"; $params[] = $filterCategory; }
$sql .= " ORDER BY d.uploaded_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$catStmt = $pdo->prepare("SELECT DISTINCT category FROM documents WHERE organization_id = ? AND category IS NOT NULL ORDER BY category");
$catStmt->execute([$orgId]);
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$fileIcons = ['pdf' => '📕', 'doc' => '📘', 'docx' => '📘', 'xls' => '📗', 'xlsx' => '📗', 'jpg' => '🖼️', 'png' => '🖼️', 'jpeg' => '🖼️'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📚 दस्तऐवज Repository</div>
  <?php if (hasPermission('view_org_wide_dashboards') || hasRole('super_admin')): ?>
    <a href="upload.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
      <i class="fa-solid fa-upload me-1"></i>दस्तऐवज Upload करा
    </a>
  <?php endif; ?>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">शासकीय GR, संस्थेची धोरणं, Formats — सगळं एकाच ठिकाणी.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="d-flex gap-2 mb-4 flex-wrap">
  <input type="text" name="q" class="form-control-custom" style="max-width:280px;" placeholder="🔍 नाव/वर्णनाने शोधा..." value="<?= h($search) ?>">
  <select name="category" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">-- सगळ्या Categories --</option>
    <?php foreach ($categories as $c): ?><option value="<?= h($c) ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;">शोधा</button>
</form>

<div class="row g-3">
  <?php if (empty($documents)): ?>
    <div class="col-12"><div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">कुठलेही दस्तऐवज सापडले नाहीत.</div></div>
  <?php endif; ?>
  <?php foreach ($documents as $d): $ext = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION)); ?>
    <div class="col-md-6 col-lg-4">
      <div class="panel" style="padding:16px 18px;height:100%;">
        <div style="font-size:26px;margin-bottom:8px;"><?= $fileIcons[$ext] ?? '📄' ?></div>
        <div style="font-size:13.5px;font-weight:600;color:var(--ink);"><?= h($d['title']) ?></div>
        <?php if ($d['description']): ?><div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;"><?= h($d['description']) ?></div><?php endif; ?>
        <div style="font-size:10px;color:var(--text-dim);margin-top:10px;">
          <?php if ($d['category']): ?><span class="status-chip pending" style="font-size:9.5px;"><?= h($d['category']) ?></span><?php endif; ?>
          <?php if ($d['dept_name']): ?><span class="status-chip done" style="font-size:9.5px;"><?= h($d['dept_name']) ?></span><?php endif; ?>
        </div>
        <div style="font-size:10px;color:var(--text-dim);margin-top:6px;"><?= h($d['uploaded_by_name']) ?> · <?= date('d M Y', strtotime($d['uploaded_at'])) ?></div>
        <a href="download.php?id=<?= $d['id'] ?>" class="btn btn-sm mt-2" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11.5px;padding:6px 14px;width:100%;text-align:center;"><i class="fa-solid fa-download me-1"></i>Download करा</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
