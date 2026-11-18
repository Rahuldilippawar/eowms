<?php
// 🎓 एक विद्यार्थी — पाहणे (सगळ्या दृश्यमर्यादेतल्यांना), नवीन / बदल / सोडले-सक्रिय (manage_students / Super Admin)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
if (!studentsReady($pdo)) { header('Location: index.php'); exit; }
$scope = studentVisibleScope($pdo);
$canManage = canManageStudents();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stu = null;
if ($id) {
    $p = [$orgId, $id];
    $st = $pdo->prepare("SELECT s.*, c.name campus_name, d.name_local dept_local, d.name dept_name FROM students s LEFT JOIN campuses c ON c.id = s.campus_id LEFT JOIN departments d ON d.id = s.department_id
        WHERE s.organization_id = ? AND s.id = ?" . studentScopeSql($scope, $p));
    $st->execute($p);
    $stu = $st->fetch();
    if (!$stu) { $_SESSION['flash'] = '⚠️ विद्यार्थी सापडला नाही.'; header('Location: index.php'); exit; }
} elseif (!$canManage) { header('Location: index.php'); exit; }

$campuses = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll(PDO::FETCH_KEY_PAIR);
$depts = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local,''), name) n, campus_id FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY n");
$depts->execute([$orgId]);
$depts = $depts->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'set_status' && $stu) {
        $active = !empty($_POST['active']) ? 1 : 0;
        $pdo->prepare("UPDATE students SET is_active = ?, left_date = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
            ->execute([$active, $active ? null : (studentParseDate($_POST['left_date'] ?? '') ?: date('Y-m-d')), $id, $orgId]);
        logActivity($active ? 'विद्यार्थी पुन्हा सक्रिय' : 'विद्यार्थी सोडले म्हणून नोंद', 'student', $id, $stu['name']);
        $_SESSION['flash'] = $active ? '✓ ' . $stu['name'] . ' पुन्हा सक्रिय.' : '✓ ' . $stu['name'] . ' — शाळा/कॉलेज सोडले म्हणून नोंद.';
        header('Location: student.php?id=' . $id); exit;
    }
    // जतन
    $in = [];
    foreach (STUDENT_SAVE_COLS as $c) { $in[$c] = trim((string)($_POST[$c] ?? '')); }
    $in['name'] = $in['name'] !== '' ? nameCase($in['name']) : '';
    $in['campus_id'] = (int)$in['campus_id'];
    $in['department_id'] = (int)$in['department_id'] ?: null;
    $in['year_of_study'] = (int)$in['year_of_study'] ?: null;
    $in['gender'] = in_array($in['gender'], ['M', 'F', 'O'], true) ? $in['gender'] : null;
    $in['date_of_birth'] = studentParseDate($in['date_of_birth']);
    $in['admission_date'] = studentParseDate($in['admission_date']);
    $in['mobile'] = $in['mobile'] !== '' ? studentNormMobile($in['mobile']) : null;
    $in['parent_mobile'] = $in['parent_mobile'] !== '' ? studentNormMobile($in['parent_mobile']) : null;
    $in['aadhaar'] = preg_replace('/\D/', '', $in['aadhaar']) ?: null;
    if ($in['academic_year'] === '') $in['academic_year'] = studentCurrentAcademicYear();
    $dup = $pdo->prepare("SELECT id FROM students WHERE organization_id = ? AND admission_no = ? AND id <> ?");
    if ($in['admission_no'] !== '') $dup->execute([$orgId, $in['admission_no'], $id]);
    if (mb_strlen($in['name']) < 2) $error = 'विद्यार्थ्याचे नाव भरा.';
    elseif (!isset($campuses[$in['campus_id']])) $error = 'College / School निवडा.';
    elseif ($in['department_id'] && !in_array($in['department_id'], array_map('intval', array_column($depts, 'id')), true)) $error = 'विभाग सापडला नाही.';
    elseif (($_POST['mobile'] ?? '') !== '' && !$in['mobile']) $error = 'मोबाईल क्रमांक 10 अंकी हवा.';
    elseif (($_POST['parent_mobile'] ?? '') !== '' && !$in['parent_mobile']) $error = 'पालकांचा मोबाईल 10 अंकी हवा.';
    elseif ($in['aadhaar'] && strlen($in['aadhaar']) !== 12) $error = 'आधार क्रमांक 12 अंकी हवा.';
    elseif ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $error = 'Email बरोबर नाही.';
    elseif ($in['admission_no'] !== '' && $dup->fetchColumn()) $error = 'हा प्रवेश क्रमांक आधीच दुसऱ्या विद्यार्थ्याला आहे.';
    $photo = null;
    if (!$error) { [$photo, $perr] = eowmsSavePhotoUpload($_FILES['photo'] ?? null, 'students', 'stu', 700); if ($perr) $error = $perr; }
    if (!$error) {
        $newId = studentSave($pdo, $orgId, $id, $in, $user['id']);
        if ($photo) $pdo->prepare("UPDATE students SET photo_path = ? WHERE id = ? AND organization_id = ?")->execute([$photo, $newId, $orgId]);
        logActivity($id ? 'विद्यार्थी माहिती बदलली' : 'नवीन विद्यार्थी जोडला', 'student', $newId, $in['name']);
        $_SESSION['flash'] = '✓ ' . $in['name'] . ' — जतन झालं.';
        header('Location: ' . (!empty($_POST['add_another']) ? 'student.php?' . http_build_query(['campus' => $in['campus_id'], 'class' => $in['class_name'], 'div' => $in['division'], 'year' => $in['academic_year']]) : 'student.php?id=' . $newId)); exit;
    }
    $stu = array_merge($stu ?: [], $in);
}

$v = function ($k, $d = '') use ($stu) { return $stu && isset($stu[$k]) && $stu[$k] !== null ? $stu[$k] : $d; };
$defCampus = $v('campus_id', (int)($_GET['campus'] ?? 0) ?: (count($campuses) === 1 ? array_key_first($campuses) : 0));
$edit = $canManage;
$pageTitle = $stu && $id ? $stu['name'] : 'नवीन विद्यार्थी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .sf-sec{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--rail);font-weight:700;margin:16px 0 6px;}
  .sf-card{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px;}
  .sf-av{width:56px;height:56px;border-radius:16px;background:var(--canvas);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:var(--rail);}
  .sf-chip{display:inline-block;font-size:11.5px;border-radius:999px;padding:3px 10px;background:var(--canvas);margin:2px 4px 0 0;}
  .sf-bar{display:flex;justify-content:flex-end;gap:8px;padding-top:12px;flex-wrap:wrap;}
  @media (max-width:767px){ .sf-bar{position:sticky;bottom:70px;background:var(--surface);margin:0 -12px;padding:10px 12px;border-top:1px solid var(--line);z-index:5;} .sf-bar .btn{flex:1;} }
</style>

<div class="page-eyebrow"><a href="index.php" style="color:inherit;text-decoration:none;">🎓 विद्यार्थी</a></div>
<?php if ($stu && $id): ?>
  <div class="sf-card">
    <?php $hasPh = !empty($stu['photo_path']) && eowmsPhotoExists($stu['photo_path']); ?>
    <div class="sf-av" style="<?= $hasPh ? 'overflow:hidden;' : '' ?>"><?php if ($hasPh): ?><img src="../<?= h($stu['photo_path']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?= h(mb_strtoupper(mb_substr($stu['name'], 0, 1))) ?><?php endif; ?></div>
    <div>
      <div class="page-title mb-0"><?= h($stu['name']) ?><?= !$stu['is_active'] ? ' <span class="status-chip overdue" style="font-size:11px;">सोडले' . ($stu['left_date'] ? ' · ' . h(date('d/m/Y', strtotime($stu['left_date']))) : '') . '</span>' : '' ?></div>
      <div>
        <span class="sf-chip">प्रवेश क्र. <?= h($stu['admission_no']) ?></span>
        <?php if ($stu['roll_no']): ?><span class="sf-chip">Roll <?= h($stu['roll_no']) ?></span><?php endif; ?>
        <?php if ($stu['class_name']): ?><span class="sf-chip"><?= h($stu['class_name'] . ($stu['division'] ? ' - ' . $stu['division'] : '')) ?></span><?php endif; ?>
        <span class="sf-chip"><?= h($stu['academic_year']) ?></span>
        <span class="sf-chip">🏫 <?= h($stu['campus_name'] ?? '') ?></span>
      </div>
      <a href="id-cards.php?id=<?= (int)$id ?>" target="_blank" class="btn btn-sm mt-2" style="background:var(--rail);color:#fff;border-radius:8px;font-weight:600;">🪪 ID Card</a>
    </div>
  </div>
<?php else: ?>
  <div class="page-title">＋ नवीन विद्यार्थी</div>
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;">
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <fieldset <?= $edit ? '' : 'disabled' ?>>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">विद्यार्थ्याचे पूर्ण नाव *</label><input type="text" name="name" value="<?= h($v('name')) ?>" class="form-control-custom" required autocapitalize="words" placeholder="आडनाव नाव वडिलांचे नाव"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">प्रवेश / GR क्र.</label><input type="text" name="admission_no" value="<?= h($v('admission_no')) ?>" class="form-control-custom" placeholder="रिकामे = आपोआप"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">Roll No</label><input type="text" name="roll_no" value="<?= h($v('roll_no')) ?>" class="form-control-custom"></div>
      <?php if ($edit): ?><div class="col-md-6"><label class="form-label-custom">📷 फोटो (ID Card साठी)</label><input type="file" name="photo" accept="image/png,image/jpeg,image/webp" class="form-control-custom"></div><?php endif; ?>
    </div>
    <div class="sf-sec">🏫 वर्ग</div>
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label-custom">College / School *</label>
        <select name="campus_id" class="form-select-custom" required><option value="">-- निवडा --</option>
          <?php foreach ($campuses as $cid => $cn): ?><option value="<?= $cid ?>" <?= (int)$defCampus === (int)$cid ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">शैक्षणिक वर्ष</label><input type="text" name="academic_year" value="<?= h($v('academic_year', $_GET['year'] ?? studentCurrentAcademicYear())) ?>" class="form-control-custom" placeholder="2026-27"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">इयत्ता / वर्ग</label><input type="text" name="class_name" value="<?= h($v('class_name', $_GET['class'] ?? '')) ?>" class="form-control-custom" placeholder="उदा. 10 वी / FY BSc"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">तुकडी</label><input type="text" name="division" value="<?= h($v('division', $_GET['div'] ?? '')) ?>" class="form-control-custom" placeholder="A"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">वर्ष (1/2/3…)</label><input type="number" min="1" max="12" name="year_of_study" value="<?= h($v('year_of_study')) ?>" class="form-control-custom"></div>
      <div class="col-md-4"><label class="form-label-custom">शाखा / अभ्यासक्रम</label><input type="text" name="course" value="<?= h($v('course')) ?>" class="form-control-custom" placeholder="उदा. Science / B.Com / Computer"></div>
      <div class="col-md-4"><label class="form-label-custom">विभाग (ऐच्छिक)</label>
        <select name="department_id" class="form-select-custom"><option value="">—</option>
          <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$v('department_id') === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['n']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label-custom">प्रवेश दिनांक</label><input type="date" name="admission_date" value="<?= h($v('admission_date')) ?>" class="form-control-custom"></div>
    </div>
    <div class="sf-sec">👤 वैयक्तिक</div>
    <div class="row g-2">
      <div class="col-6 col-md-3"><label class="form-label-custom">लिंग</label>
        <select name="gender" class="form-select-custom"><option value="">—</option><option value="M" <?= $v('gender') === 'M' ? 'selected' : '' ?>>मुलगा</option><option value="F" <?= $v('gender') === 'F' ? 'selected' : '' ?>>मुलगी</option><option value="O" <?= $v('gender') === 'O' ? 'selected' : '' ?>>इतर</option></select></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">जन्मतारीख</label><input type="date" name="date_of_birth" value="<?= h($v('date_of_birth')) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">प्रवर्ग</label><input type="text" name="category" value="<?= h($v('category')) ?>" class="form-control-custom" placeholder="Open / OBC / SC …"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">रक्तगट</label><input type="text" name="blood_group" value="<?= h($v('blood_group')) ?>" class="form-control-custom" placeholder="B+"></div>
      <div class="col-md-4"><label class="form-label-custom">आधार क्रमांक</label><input type="text" name="aadhaar" inputmode="numeric" maxlength="14" value="<?= h($v('aadhaar')) ?>" class="form-control-custom"></div>
      <div class="col-md-8"><label class="form-label-custom">पत्ता</label><input type="text" name="address" value="<?= h($v('address')) ?>" class="form-control-custom"></div>
    </div>
    <div class="sf-sec">👪 पालक व संपर्क</div>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">वडिलांचे नाव</label><input type="text" name="father_name" value="<?= h($v('father_name')) ?>" class="form-control-custom"></div>
      <div class="col-md-6"><label class="form-label-custom">आईचे नाव</label><input type="text" name="mother_name" value="<?= h($v('mother_name')) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-4"><label class="form-label-custom">पालक मोबाईल</label><input type="tel" name="parent_mobile" inputmode="numeric" value="<?= h($v('parent_mobile')) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-4"><label class="form-label-custom">विद्यार्थी मोबाईल</label><input type="tel" name="mobile" inputmode="numeric" value="<?= h($v('mobile')) ?>" class="form-control-custom"></div>
      <div class="col-md-4"><label class="form-label-custom">Email</label><input type="email" name="email" value="<?= h($v('email')) ?>" class="form-control-custom"></div>
      <div class="col-12"><label class="form-label-custom">शेरा</label><input type="text" name="remarks" value="<?= h($v('remarks')) ?>" class="form-control-custom"></div>
    </div>
    </fieldset>
    <?php if ($edit): ?>
    <div class="sf-bar">
      <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:10px;">रद्द</a>
      <?php if (!$id): ?><button type="submit" name="add_another" value="1" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:10px;font-weight:600;">जतन + पुढचा</button><?php endif; ?>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:700;padding:8px 22px;">✓ जतन करा</button>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($edit && $stu && $id): ?>
<div class="panel mt-3">
  <form method="POST" class="d-flex align-items-center gap-2 flex-wrap" style="padding:14px 20px;" onsubmit="return confirm('<?= $stu['is_active'] ? 'हा विद्यार्थी शाळा/कॉलेज सोडला म्हणून नोंदवायचा?' : 'हा विद्यार्थी पुन्हा सक्रिय करायचा?' ?>');">
    <input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php if ($stu['is_active']): ?>
      <span style="font-size:13px;">🚪 शाळा/कॉलेज सोडले?</span>
      <input type="date" name="left_date" value="<?= date('Y-m-d') ?>" class="form-control-custom" style="width:auto;">
      <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;">सोडले म्हणून नोंदवा</button>
    <?php else: ?>
      <input type="hidden" name="active" value="1">
      <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">↺ पुन्हा सक्रिय करा</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
