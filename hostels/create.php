<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_hostels', '../');
$user = currentUser();

$orgId = activeOrgId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $campusId = (int)($_POST['campus_id'] ?? 0);
    $capacity = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;

    // Find the org's "Hostel" (code=HOS) department to link under
    $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'HOS' LIMIT 1");
    $deptStmt->execute([$orgId]);
    $hostelDept = $deptStmt->fetch();

    if (!$name || !$campusId) {
        $error = t('s475');
    } elseif (!$hostelDept) {
        $error = t('s476');
    } else {
        $insert = $pdo->prepare("INSERT INTO hostels (organization_id, campus_id, department_id, name, capacity) VALUES (?, ?, ?, ?, ?)");
        $insert->execute([$orgId, $campusId, $hostelDept['id'], $name, $capacity]);
        $_SESSION['flash'] = "'$name' वसतिगृह जोडले गेले.";
        header('Location: index.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$campuses = $stmt->fetchAll();

$pageTitle = t('s320');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s198')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s37')) ?></label>
        <input type="text" name="name" class="form-control-custom" placeholder="उदा. मुलांचे वसतिगृह — ब्लॉक अ" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s8')) ?></label>
        <select name="campus_id" class="form-select-custom" required>
          <?php foreach ($campuses as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s199')) ?></label>
        <input type="number" name="capacity" class="form-control-custom" placeholder="उदा. 50">
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s38')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
