<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = $_POST['role'] ?? 'staff';
    $departmentId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
    $designation = trim($_POST['designation'] ?? '');
    $teachingMode = in_array($_POST['teaching_mode'] ?? '', ['Teaching', 'Non Teaching', 'Other'], true) ? $_POST['teaching_mode'] : 'Non Teaching';
    $employeeCode = trim($_POST['employee_code'] ?? '') ?: null;
    $mobile = trim($_POST['mobile'] ?? '') ?: null;
    $salary = trim($_POST['salary'] ?? '') !== '' ? (float)$_POST['salary'] : null;
    $joiningDate = ($_POST['joining_date'] ?? '') ?: date('Y-m-d');
    $dateOfBirth = ($_POST['date_of_birth'] ?? '') ?: null;

    $orgId = activeOrgId();

    if (!$name || !$email) {
        $error = t('s573');
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = "'$email' आधीच अस्तित्वात आहे.";
        } else {
            $campusId = null;
            if ($departmentId) {
                $d = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ? AND organization_id = ?");
                $d->execute([$departmentId, $orgId]);
                $campusId = $d->fetch()['campus_id'] ?? null;
            }
            if (!$campusId) {
                // Management roles (president/exec president/GM/super_admin) may have no department —
                // fall back to the organization's first campus.
                $c = $pdo->prepare("SELECT id FROM campuses WHERE organization_id = ? ORDER BY id LIMIT 1");
                $c->execute([$orgId]);
                $campusId = $c->fetch()['id'] ?? null;
            }

            // Secure default: auto-generate a strong password if left blank
            $generatedPassword = null;
            if ($password === '') {
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                $password = '';
                for ($i = 0; $i < 10; $i++) { $password .= $chars[random_int(0, strlen($chars) - 1)]; }
                $generatedPassword = $password;
            }

            // Photo upload (optional)
            $photoPath = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../assets/uploads/staff/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $safeName = uniqid('staff_') . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $safeName)) {
                        $photoPath = 'assets/uploads/staff/' . $safeName;
                    }
                }
            }

            $insert = $pdo->prepare("
                INSERT INTO users (organization_id, campus_id, department_id, employee_code, name, email, mobile, designation, teaching_mode, salary, joining_date, date_of_birth, photo_path, password, must_change_password, role, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1)
            ");
            $insert->execute([$orgId, $campusId, $departmentId, $employeeCode, $name, $email, $mobile, $designation, $teachingMode, $salary, $joiningDate, $dateOfBirth, $photoPath, password_hash($password, PASSWORD_DEFAULT), $role]);

            // Role-wise welcome email with login credentials — silently skipped if
            // the hosting's mail server isn't configured (e.g. local XAMPP testing).
            $catStmt = $pdo->prepare("SELECT category FROM role_definitions WHERE role_key = ?");
            $catStmt->execute([$role]);
            $category = $catStmt->fetch()['category'] ?? 'staff';
            $loginUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/login.php';
            sendWelcomeEmail($email, $name, $email, $password, activeOrgName(), $category, $loginUrl);

            $_SESSION['flash'] = $generatedPassword
                ? "'$name' जोडले गेले. Auto-generated password: $generatedPassword (हा आत्ताच नोंद करा — पुन्हा दिसणार नाही). कर्मचाऱ्याच्या ईमेलवर login माहिती पाठवण्याचा प्रयत्न झाला आहे."
                : "'$name' जोडले गेले. कर्मचाऱ्याच्या ईमेलवर login माहिती पाठवण्याचा प्रयत्न झाला आहे.";
            header('Location: index.php');
            exit;
        }
    }
}

$orgId = activeOrgId();
$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

try {
    $allRoles = $pdo->query("SELECT * FROM role_definitions ORDER BY FIELD(category,'super_admin','management','hod','staff'), label")->fetchAll();
} catch (PDOException $e) {
    $allRoles = [
        ['role_key' => 'staff', 'label' => 'Staff'], ['role_key' => 'hod', 'label' => 'HOD'],
        ['role_key' => 'general_manager', 'label' => 'General Manager'],
        ['role_key' => 'executive_president', 'label' => 'Executive President'],
        ['role_key' => 'president', 'label' => 'President'], ['role_key' => 'super_admin', 'label' => 'Super Admin'],
    ];
}

$pageTitle = t('s574');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s274')) ?></div>
  <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">
    <i class="fa-solid fa-file-csv me-1"></i>CSV Bulk Import
  </a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title"><?= h(t('s275')) ?></div><div class="sub"><?= h(t('s276')) ?></div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s60')) ?></label>
        <input type="text" name="name" class="form-control-custom" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s61')) ?></label>
        <input type="email" name="email" class="form-control-custom" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">जन्मतारीख (वाढदिवस शुभेच्छांसाठी)</label>
        <input type="date" name="date_of_birth" class="form-control-custom">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">फोटो (ऐच्छिक)</label>
        <input type="file" name="photo" accept="image/*" class="form-control-custom">
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s32')) ?></label>
        <select name="role" class="form-select-custom" required>
          <?php foreach ($allRoles as $r): ?>
            <option value="<?= htmlspecialchars($r['role_key']) ?>" <?= $r['role_key'] === 'staff' ? 'selected' : '' ?>><?= htmlspecialchars($r['label_local'] ?? $r['label']) ?><?= !empty($r['label_local']) ? ' (' . htmlspecialchars($r['label']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;">Peon/Technician/Clerk → Staff · HOD/Warden → HOD · GM/AGM → General Manager · Exec.President/Secretary → Executive President · Chairman/Trustee → President</div>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s33')) ?></label>
        <input type="text" name="designation" list="designationList" class="form-control-custom">
        <datalist id="designationList">
          <option value="Peon"><option value="Watchman"><option value="Sweeper"><option value="Mali (Gardener)">
          <option value="Attendant"><option value="Cook"><option value="Cleaner"><option value="Driver">
          <option value="Technician"><option value="Electrician"><option value="Plumber"><option value="Mechanic">
          <option value="Operator"><option value="Clerk"><option value="Store Keeper"><option value="Receptionist">
          <option value="Data Entry Operator"><option value="Supervisor"><option value="Section In-charge">
          <option value="Senior Clerk"><option value="Foreman"><option value="HOD"><option value="Department Head">
          <option value="Warden"><option value="Registrar"><option value="Assistant General Manager">
          <option value="Deputy General Manager"><option value="General Manager"><option value="Administrative Officer">
          <option value="Executive President"><option value="Vice President"><option value="Secretary">
          <option value="Joint Secretary"><option value="Treasurer"><option value="President"><option value="Chairman">
          <option value="Founder President"><option value="Working President"><option value="Trustee">
          <option value="Super Admin"><option value="System Administrator">
        </datalist>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">Teaching / Non-Teaching</label>
        <select name="teaching_mode" class="form-select-custom">
          <option value="Non Teaching" selected>🧰 Non-Teaching</option>
          <option value="Teaching">👨‍🏫 Teaching</option>
          <option value="Other">❔ इतर</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" class="form-select-custom">
          <option value=""><?= h(t('s277')) ?></option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s64')) ?></label>
        <input type="text" name="employee_code" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s278')) ?></label>
        <input type="text" name="mobile" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s279')) ?></label>
        <input type="text" name="password" class="form-control-custom" placeholder="रिकामे ठेवणे सुरक्षित">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">पगार (₹) — फक्त Management ला दिसेल</label>
        <input type="number" step="0.01" name="salary" class="form-control-custom" placeholder="ऐच्छिक">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">रुजू दिनांक (Joining Date)</label>
        <input type="date" name="joining_date" class="form-control-custom" value="<?= date('Y-m-d') ?>">
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s280')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
