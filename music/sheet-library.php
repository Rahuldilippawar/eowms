<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// संगीत विभागाचाच Staff, किंवा 'manage_music' Permission असलेला, किंवा
// Management-श्रेणीतला — यांनाच Instruments/Students/इ. Manage करता येईल.
$musicDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$musicDeptCheck->execute([$user['department_id'] ?? 0]);
$isMusicDept = ($musicDeptCheck->fetch()['code'] ?? '') === 'MUS';
if (!$isMusicDept && !hasPermission('manage_music') && !isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला संगीत विभाग व्यवस्थापित करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $ragaOrType = trim($_POST['raga_or_type'] ?? '');
    $instrument = trim($_POST['instrument'] ?? '');
    $composer = trim($_POST['composer'] ?? '');

    if ($title && !empty($_FILES['sheet_file']['tmp_name']) && $_FILES['sheet_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['sheet_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $uploadDir = __DIR__ . '/../assets/uploads/sheet-music';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $safeFilename = 'sheet-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['sheet_file']['tmp_name'], $uploadDir . '/' . $safeFilename)) {
                $relPath = 'assets/uploads/sheet-music/' . $safeFilename;
                $pdo->prepare("INSERT INTO music_sheet_library (organization_id, title, raga_or_type, instrument, composer, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $title, $ragaOrType, $instrument, $composer, $relPath, $user['id']]);
                logActivity('Sheet Music Upload केलं', 'music_sheet', $pdo->lastInsertId(), $title);
                $_SESSION['flash'] = "\"$title\" यशस्वीरित्या Upload झालं.";
            }
        } else {
            $_SESSION['form_error'] = 'फक्त PDF किंवा Image File चालेल.';
        }
    } else {
        $_SESSION['form_error'] = 'कृपया शीर्षक टाका आणि File निवडा.';
    }
    header('Location: sheet-library.php');
    exit;
}

$pageTitle = 'Sheet Music Library';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT sl.*, u.name AS uploaded_by_name FROM music_sheet_library sl LEFT JOIN users u ON u.id = sl.uploaded_by WHERE sl.organization_id = ?";
$params = [$orgId];
if ($search) { $sql .= " AND (sl.title LIKE ? OR sl.raga_or_type LIKE ? OR sl.composer LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }
$sql .= " ORDER BY sl.uploaded_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sheets = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎼 Sheet Music Library</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संगीत विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Sheet Music Upload करा</div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">शीर्षक</label><input type="text" name="title" class="form-control-custom" required></div>
      <div class="col-md-3"><label class="form-label-custom">राग/प्रकार</label><input type="text" name="raga_or_type" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">वाद्य</label><input type="text" name="instrument" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">रचनाकार</label><input type="text" name="composer" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">File</label><input type="file" name="sheet_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control-custom" required></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Upload करा</button>
  </form>
</div>

<form method="GET" class="mb-3">
  <input type="text" name="q" class="form-control-custom" style="max-width:320px;" placeholder="🔍 शीर्षक/राग/रचनाकाराने शोधा..." value="<?= h($search) ?>">
</form>

<div class="row g-3">
  <?php if (empty($sheets)): ?><div class="col-12"><div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">कुठलंही Sheet Music सापडलं नाही</div></div><?php endif; ?>
  <?php foreach ($sheets as $s): ?>
    <div class="col-md-6 col-lg-4">
      <div class="panel" style="padding:16px 18px;">
        <div style="font-size:24px;">🎼</div>
        <div style="font-size:13.5px;font-weight:600;margin-top:6px;"><?= h($s['title']) ?></div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">
          <?php if ($s['raga_or_type']): ?><?= h($s['raga_or_type']) ?><?php endif; ?>
          <?php if ($s['instrument']): ?> · <?= h($s['instrument']) ?><?php endif; ?>
          <?php if ($s['composer']): ?><br>रचनाकार: <?= h($s['composer']) ?><?php endif; ?>
        </div>
        <a href="../<?= h($s['file_path']) ?>" target="_blank" class="btn btn-sm mt-2" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11.5px;padding:6px 14px;width:100%;text-align:center;">बघा/Download करा</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
