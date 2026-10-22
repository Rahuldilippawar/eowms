<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$hostelId = (int)($_GET['hostel_id'] ?? $_POST['hostel_id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

if (!hasPermission('manage_hostel_students') && $userDeptCode !== 'HOS') {
    http_response_code(403);
    die(t('s462'));
}

$stmt = $pdo->prepare("SELECT * FROM hostels WHERE id = ? AND organization_id = ?");
$stmt->execute([$hostelId, $orgId]);
$hostel = $stmt->fetch();
if (!$hostel) { die(t('s430')); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $school = trim($_POST['school_name'] ?? '') ?: null;
    $classGrade = trim($_POST['class_grade'] ?? '') ?: null;
    $district = trim($_POST['district'] ?? '') ?: null;
    $gender = $_POST['gender'] ?? null;
    $admissionDate = ($_POST['admission_date'] ?? '') ?: null;
    $dateOfBirth = ($_POST['date_of_birth'] ?? '') ?: null;

    $photoPath = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../assets/uploads/students/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $safeName = uniqid('student_') . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $safeName)) {
                $photoPath = 'assets/uploads/students/' . $safeName;
            }
        }
    }

    if (!$name) {
        $error = t('s463');
    } else {
        $insert = $pdo->prepare("INSERT INTO hostel_students (hostel_id, name, school_name, class_grade, district, gender, admission_date, date_of_birth, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$hostelId, $name, $school, $classGrade, $district, $gender, $admissionDate, $dateOfBirth, $photoPath]);
        $_SESSION['flash'] = "'$name' विद्यार्थी जोडला गेला.";
        header('Location: dashboard.php?id=' . $hostelId);
        exit;
    }
}

$pageTitle = t('s178');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($hostel['name']) ?></div>
<div class="page-title"><?= h(t('s178')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <input type="hidden" name="hostel_id" value="<?= $hostelId ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s71')) ?></label>
        <input type="text" name="name" class="form-control-custom" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s35')) ?></label>
        <select name="gender" class="form-select-custom">
          <option value=""><?= h(t('s36')) ?></option>
          <option value="मुलगा"><?= h(t('s72')) ?></option>
          <option value="मुलगी"><?= h(t('s73')) ?></option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s74')) ?></label>
        <input type="date" name="admission_date" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">जन्मतारीख (वाढदिवस शुभेच्छांसाठी)</label>
        <input type="date" name="date_of_birth" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">फोटो (ऐच्छिक)</label>
        <input type="file" name="photo" accept="image/*" class="form-control-custom">
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s75')) ?></label>
        <input type="text" name="school_name" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s76')) ?></label>
        <input type="text" name="class_grade" class="form-control-custom" placeholder="उदा. ७ वी">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s77')) ?></label>
        <input type="text" name="district" class="form-control-custom" placeholder="उदा. नाशिक">
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="dashboard.php?id=<?= $hostelId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s78')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
