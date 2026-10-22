<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_documents', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '') ?: null;
    $departmentId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;

    if ($title && !empty($_FILES['document']['tmp_name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $uploadDir = __DIR__ . '/../assets/uploads/documents';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $safeFilename = 'doc-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . '/' . $safeFilename)) {
                $relPath = 'assets/uploads/documents/' . $safeFilename;
                $pdo->prepare("INSERT INTO documents (organization_id, title, description, category, file_path, original_filename, department_id, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $title, $description, $category, $relPath, $_FILES['document']['name'], $departmentId, $user['id']]);
                logActivity('दस्तऐवज Upload केला', 'document', $pdo->lastInsertId(), $title);
                $_SESSION['flash'] = "\"$title\" यशस्वीरित्या Upload झालं.";
                header('Location: index.php');
                exit;
            }
        } else {
            $_SESSION['form_error'] = 'फक्त PDF, Word, Excel, किंवा Image File चालेल.';
        }
    } else {
        $_SESSION['form_error'] = 'कृपया शीर्षक टाका आणि File निवडा.';
    }
}

$pageTitle = 'दस्तऐवज Upload';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📤 दस्तऐवज Upload करा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Repository</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:600px;">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-3" required>

    <label class="form-label-custom">वर्णन <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <textarea name="description" class="form-control-custom mb-3" rows="3"></textarea>

    <label class="form-label-custom">Category <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <input type="text" name="category" class="form-control-custom mb-3" placeholder="उदा. GR, धोरण, Format">

    <label class="form-label-custom">कोणासाठी <span style="font-weight:400;color:var(--text-dim);">(न निवडल्यास — सगळ्यांना दिसेल)</span></label>
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom mb-2" onchange="loadDeptsCascade_docUp(this.value)">
        <option value="">-- College/School (ऐच्छिक) --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom mb-3">
      <option value="">-- सगळ्यांसाठी --</option>
      <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_docUp(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true;
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<option value="">-- सगळ्यांसाठी --</option>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>

    <label class="form-label-custom">File (PDF/Word/Excel/Image)</label>
    <input type="file" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="form-control-custom mb-3" required>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">Upload करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
