<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

if (!hasPermission('manage_hostel_students') && $userDeptCode !== 'HOS') {
    http_response_code(403);
    die(t('s471'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $school = trim($_POST['school_name'] ?? '') ?: null;
    $classGrade = trim($_POST['class_grade'] ?? '') ?: null;
    $district = trim($_POST['district'] ?? '') ?: null;
    $gender = $_POST['gender'] ?? null;
    $admissionDate = ($_POST['admission_date'] ?? '') ?: null;
    $isActive = (int)($_POST['is_active'] ?? 1);
    $hostelId = (int)($_POST['hostel_id'] ?? 0);
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

    $stmt = $pdo->prepare("UPDATE hostel_students SET name=?, school_name=?, class_grade=?, district=?, gender=?, admission_date=?, is_active=?, date_of_birth=?, photo_path=COALESCE(?, photo_path) WHERE id=?");
    $stmt->execute([$name, $school, $classGrade, $district, $gender, $admissionDate, $isActive, $dateOfBirth, $photoPath, $id]);

    $_SESSION['flash'] = t('s472');
    header('Location: dashboard.php?id=' . $hostelId);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM hostel_students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) { die(t('s473')); }

$stmt = $pdo->prepare("SELECT * FROM hostels WHERE id = ?");
$stmt->execute([$student['hostel_id']]);
$hostel = $stmt->fetch();

$pageTitle = t('s474');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($hostel['name'] ?? '') ?></div>
<div class="page-title"><?= h($student['name']) ?> <?= h(t('s11')) ?></div>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $student['id'] ?>">
    <input type="hidden" name="hostel_id" value="<?= $student['hostel_id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s71')) ?></label>
        <input type="text" name="name" class="form-control-custom" value="<?= h($student['name']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s35')) ?></label>
        <select name="gender" class="form-select-custom">
          <option value=""><?= h(t('s36')) ?></option>
          <option value="मुलगा" <?= $student['gender'] === 'मुलगा' ? 'selected' : '' ?>><?= h(t('s72')) ?></option>
          <option value="मुलगी" <?= $student['gender'] === 'मुलगी' ? 'selected' : '' ?>><?= h(t('s73')) ?></option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s74')) ?></label>
        <input type="date" name="admission_date" class="form-control-custom" value="<?= h($student['admission_date']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">जन्मतारीख (वाढदिवस शुभेच्छांसाठी)</label>
        <input type="date" name="date_of_birth" class="form-control-custom" value="<?= h($student['date_of_birth'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">फोटो</label>
        <?php if (!empty($student['photo_path'])): ?>
          <div style="margin-bottom:6px;"><img src="../<?= h($student['photo_path']) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;"></div>
        <?php endif; ?>
        <input type="file" name="photo" accept="image/*" class="form-control-custom">
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s75')) ?></label>
        <input type="text" name="school_name" class="form-control-custom" value="<?= h($student['school_name']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s76')) ?></label>
        <input type="text" name="class_grade" class="form-control-custom" value="<?= h($student['class_grade']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s77')) ?></label>
        <input type="text" name="district" class="form-control-custom" value="<?= h($student['district']) ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $student['is_active'] ? 'selected' : '' ?>><?= h(t('s196')) ?></option>
          <option value="0" <?= !$student['is_active'] ? 'selected' : '' ?>><?= h(t('s197')) ?></option>
        </select>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="dashboard.php?id=<?= $student['hostel_id'] ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
