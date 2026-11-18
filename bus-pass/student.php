<?php
// 🚌 एक बस विद्यार्थी — नवीन / बदल / फोटो (Crop) / कुठून-कुठपर्यंत / पासचा इतिहास (बस पास विभाग; विद्यार्थी विभागापासून वेगळं)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';
$user = currentUser();
$orgId = activeOrgId();
if (!canViewBusPass()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बस पास पाहण्याची परवानगी नाही.</div>'); }
if (!busStudentsReady($pdo)) { header('Location: index.php'); exit; }
$canManage = canBusPass();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$campusRows = $pdo->prepare("SELECT id, name, city FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusRows->execute([$orgId]);
$campusRows = $campusRows->fetchAll(PDO::FETCH_UNIQUE);

$stu = null;
if ($id) {
    $st = $pdo->prepare("SELECT s.*, c.name campus_name, sx.photo_path stu_photo FROM bus_students s LEFT JOIN campuses c ON c.id = s.campus_id LEFT JOIN students sx ON sx.id = s.student_id WHERE s.organization_id = ? AND s.id = ?");
    $st->execute([$orgId, $id]);
    $stu = $st->fetch();
    if (!$stu) { $_SESSION['flash'] = '⚠️ विद्यार्थी सापडला नाही.'; header('Location: students.php'); exit; }
} elseif (!$canManage) { header('Location: students.php'); exit; }

// 🎓 विद्यार्थी विभागातून भरून (?from_student=ID) — त्या विभागाची परवानगी असेल तर
$fromStudent = (int)($_GET['from_student'] ?? 0);
if (!$id && $fromStudent && studentsReady($pdo) && ($scope = studentVisibleScope($pdo)) !== []) {
    $p = [$orgId, $fromStudent];
    $q = $pdo->prepare("SELECT s.* FROM students s WHERE s.organization_id = ? AND s.id = ?" . studentScopeSql($scope, $p));
    $q->execute($p);
    if ($src = $q->fetch()) {
        if ($ex = busStudentFind($pdo, $orgId, ['student_id' => $src['id'], 'name' => $src['name']])) { header('Location: student.php?id=' . $ex); exit; }
        $stu = ['student_id' => $src['id']] + array_intersect_key($src, array_flip(['campus_id', 'name', 'admission_no', 'roll_no', 'class_name', 'division', 'course', 'academic_year', 'gender', 'date_of_birth', 'mobile', 'parent_mobile', 'address', 'blood_group', 'photo_path']));
        $stu['stu_photo'] = $src['photo_path']; $stu['photo_path'] = null;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'set_status' && $stu && $id) {
        $active = !empty($_POST['active']) ? 1 : 0;
        $pdo->prepare("UPDATE bus_students SET is_active = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$active, $id, $orgId]);
        if (!$active) $pdo->prepare("UPDATE student_bus_passes SET status = 'cancelled', cancelled_at = NOW() WHERE organization_id = ? AND bus_student_id = ? AND status = 'active' AND valid_till >= CURDATE()")->execute([$orgId, $id]);
        logActivity($active ? 'बस विद्यार्थी पुन्हा सक्रिय' : 'बस विद्यार्थी — बस सोडली', 'bus_student', $id, $stu['name']);
        $_SESSION['flash'] = $active ? '✓ ' . $stu['name'] . ' पुन्हा सक्रिय.' : '✓ ' . $stu['name'] . ' — बस सोडली म्हणून नोंद; चालू पास रद्द केला.';
        header('Location: student.php?id=' . $id); exit;
    }
    $in = [];
    foreach (BUS_STUDENT_COLS as $c) { if ($c !== 'student_id') $in[$c] = trim((string)($_POST[$c] ?? '')); }
    $in['name'] = $in['name'] !== '' ? nameCase($in['name']) : '';
    $in['campus_id'] = isset($campusRows[(int)$in['campus_id']]) ? (int)$in['campus_id'] : null;
    $in['gender'] = in_array($in['gender'], ['M', 'F', 'O'], true) ? $in['gender'] : null;
    $in['date_of_birth'] = studentParseDate($in['date_of_birth']);
    $in['mobile'] = $in['mobile'] !== '' ? studentNormMobile($in['mobile']) : null;
    $in['parent_mobile'] = $in['parent_mobile'] !== '' ? studentNormMobile($in['parent_mobile']) : null;
    foreach (['from_place', 'to_place'] as $k) { if ($in[$k] !== '') $in[$k] = nameCase($in[$k]); }
    if (!$id && (int)($_POST['student_id'] ?? 0)) $in['student_id'] = (int)$_POST['student_id'];
    if (mb_strlen($in['name']) < 2) $error = 'विद्यार्थ्याचे नाव भरा.';
    elseif (($_POST['mobile'] ?? '') !== '' && !$in['mobile']) $error = 'मोबाईल क्रमांक 10 अंकी हवा.';
    elseif (($_POST['parent_mobile'] ?? '') !== '' && !$in['parent_mobile']) $error = 'पालकांचा मोबाईल 10 अंकी हवा.';
    elseif ($in['admission_no'] !== '') {
        $dup = $pdo->prepare("SELECT id FROM bus_students WHERE organization_id = ? AND admission_no = ? AND id <> ? AND is_active = 1");
        $dup->execute([$orgId, $in['admission_no'], $id]);
        if ($dup->fetchColumn()) $error = 'हा Gr.No. / ID आधीच दुसऱ्या बस विद्यार्थ्याला आहे.';
    }
    $photo = null;
    if (!$error) { [$photo, $perr] = eowmsSavePhotoUpload($_FILES['photo'] ?? null, 'students', 'bus', 700); if ($perr) $error = $perr; }
    if (!$error) {
        $newId = busStudentSave($pdo, $orgId, $id, $in, $user['id']);
        if ($photo) $pdo->prepare("UPDATE bus_students SET photo_path = ? WHERE id = ? AND organization_id = ?")->execute([$photo, $newId, $orgId]);
        logActivity($id ? 'बस विद्यार्थी माहिती बदलली' : 'नवीन बस विद्यार्थी', 'bus_student', $newId, $in['name']);
        $_SESSION['flash'] = '✓ ' . $in['name'] . ' — जतन झालं.';
        $next = $_POST['next'] ?? '';
        if ($next === 'another') { header('Location: student.php?' . http_build_query(array_filter(['campus' => $in['campus_id'], 'class' => $in['class_name'], 'div' => $in['division'], 'year' => $in['academic_year'], 'to' => $in['to_place'], 'route' => $in['route_name']]))); exit; }
        if ($next === 'pass') { header('Location: index.php?' . http_build_query(array_filter(['campus' => $in['campus_id'], 'year' => $in['academic_year'], 'class' => $in['class_name'], 'div' => $in['division']], fn($v) => $v !== null && $v !== '')) . '#issue'); exit; }
        header('Location: student.php?id=' . $newId); exit;
    }
    $stu = array_merge($stu ?: [], $in);
}

$v = function ($k, $d = '') use ($stu) { return $stu && isset($stu[$k]) && $stu[$k] !== null ? $stu[$k] : $d; };
$defCampus = (int)$v('campus_id', (int)($_GET['campus'] ?? 0) ?: (count($campusRows) === 1 ? array_key_first($campusRows) : 0));
$defTo = $v('to_place', (string)($_GET['to'] ?? '')) ?: busDefaultTo($pdo, $orgId, $defCampus);
$photoNow = '';
foreach ([$v('photo_path'), $v('stu_photo')] as $pp) { if ($pp && eowmsPhotoExists($pp)) { $photoNow = $pp; break; } }
$passes = [];
if ($id) {
    $st = $pdo->prepare("SELECT * FROM student_bus_passes WHERE organization_id = ? AND bus_student_id = ? ORDER BY valid_till DESC, id DESC LIMIT 20");
    $st->execute([$orgId, $id]);
    $passes = $st->fetchAll();
}
$places = $pdo->prepare("SELECT from_place FROM bus_students WHERE organization_id = ? AND from_place IS NOT NULL AND from_place <> '' GROUP BY from_place ORDER BY COUNT(*) DESC LIMIT 80");
$places->execute([$orgId]);
$places = $places->fetchAll(PDO::FETCH_COLUMN);
$edit = $canManage;

$pageTitle = $id ? $v('name') : 'नवीन बस विद्यार्थी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .sf-sec{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--rail);font-weight:700;margin:16px 0 6px;}
  .sf-card{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px;}
  .sf-av{width:56px;height:66px;border-radius:14px;background:var(--canvas);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:var(--rail);overflow:hidden;}
  .sf-av img{width:100%;height:100%;object-fit:cover;}
  .sf-chip{display:inline-block;font-size:11.5px;border-radius:999px;padding:3px 10px;background:var(--canvas);margin:2px 4px 0 0;}
  .sf-bar{display:flex;justify-content:flex-end;gap:8px;padding-top:12px;flex-wrap:wrap;}
  .bp-st{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;}
  .bp-st.ok{background:#DCFCE7;color:#166534;} .bp-st.exp{background:#FEE2E2;color:#991B1B;} .bp-st.none{background:#E5E7EB;color:#374151;}
  @media (max-width:767px){ .sf-bar{position:sticky;bottom:70px;background:var(--surface);margin:0 -12px;padding:10px 12px;border-top:1px solid var(--line);z-index:5;} .sf-bar .btn{flex:1;} }
</style>

<div class="page-eyebrow"><a href="students.php" style="color:inherit;text-decoration:none;">🚌 बस विद्यार्थी</a></div>
<?php if ($id): ?>
  <div class="sf-card">
    <div class="sf-av"><?php if ($photoNow): ?><img src="../<?= h($photoNow) ?>" alt=""><?php else: ?><?= h(mb_strtoupper(mb_substr($v('name'), 0, 1))) ?><?php endif; ?></div>
    <div>
      <div class="page-title mb-0"><?= h($v('name')) ?><?= !$v('is_active', 1) ? ' <span class="status-chip overdue" style="font-size:11px;">बस सोडली</span>' : '' ?></div>
      <div>
        <?php if ($v('admission_no')): ?><span class="sf-chip">Gr. <?= h($v('admission_no')) ?></span><?php endif; ?>
        <?php if ($v('class_name')): ?><span class="sf-chip"><?= h($v('class_name') . ($v('division') ? ' - ' . $v('division') : '')) ?></span><?php endif; ?>
        <?php if ($v('from_place')): ?><span class="sf-chip">🚏 <?= h($v('from_place')) ?> → <?= h($v('to_place') ?: '—') ?></span><?php endif; ?>
        <?php if ($v('student_id')): ?><span class="sf-chip">🎓 विद्यार्थी विभागाशी जोडलेले</span><?php endif; ?>
      </div>
      <?php if ($canManage && $v('is_active', 1)): ?><a href="index.php?<?= h(http_build_query(array_filter(['campus' => $v('campus_id'), 'year' => $v('academic_year'), 'class' => (string)$v('class_name'), 'div' => (string)$v('division')], fn($x) => $x !== null && $x !== ''))) ?>#issue" class="btn btn-sm mt-2" style="background:var(--rail);color:#fff;border-radius:8px;font-weight:600;">🚌 पास द्या / नूतनीकरण</a><?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="page-title">＋ नवीन बस विद्यार्थी<?= $v('student_id') ? ' <span style="font-size:13px;font-weight:600;color:var(--text-dim);">— 🎓 विद्यार्थी विभागातून भरलं</span>' : '' ?></div>
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;">
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php if (!$id && $v('student_id')): ?><input type="hidden" name="student_id" value="<?= (int)$v('student_id') ?>"><?php endif; ?>
    <fieldset <?= $edit ? '' : 'disabled' ?>>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">विद्यार्थ्याचे पूर्ण नाव *</label><input type="text" name="name" value="<?= h($v('name')) ?>" class="form-control-custom" required autocapitalize="words" placeholder="आडनाव नाव वडिलांचे नाव"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">Gr.No. / ID</label><input type="text" name="admission_no" value="<?= h($v('admission_no')) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">Roll No</label><input type="text" name="roll_no" value="<?= h($v('roll_no')) ?>" class="form-control-custom"></div>
    </div>
    <div class="sf-sec">🚌 बस</div>
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label-custom">🚏 कुठून (गाव / थांबा)</label><input type="text" name="from_place" value="<?= h($v('from_place')) ?>" class="form-control-custom" list="dlPlaces" maxlength="120"></div>
      <div class="col-md-4"><label class="form-label-custom">🏫 कुठपर्यंत</label><input type="text" name="to_place" value="<?= h($defTo) ?>" class="form-control-custom" maxlength="120"></div>
      <div class="col-md-4"><label class="form-label-custom">🛣️ मार्ग / बस</label><input type="text" name="route_name" value="<?= h($v('route_name', (string)($_GET['route'] ?? ''))) ?>" class="form-control-custom" maxlength="120" placeholder="उदा. Route 3"></div>
    </div>
    <datalist id="dlPlaces"><?php foreach ($places as $pl): ?><option value="<?= h($pl) ?>"><?php endforeach; ?></datalist>
    <div class="sf-sec">🏫 वर्ग</div>
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label-custom">College / School</label>
        <select name="campus_id" class="form-select-custom"><option value="">—</option>
          <?php foreach ($campusRows as $cid => $c): ?><option value="<?= (int)$cid ?>" <?= $defCampus === (int)$cid ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">शैक्षणिक वर्ष</label><input type="text" name="academic_year" value="<?= h($v('academic_year', (string)($_GET['year'] ?? studentCurrentAcademicYear()))) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">इयत्ता / वर्ग</label><input type="text" name="class_name" value="<?= h($v('class_name', (string)($_GET['class'] ?? ''))) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">तुकडी</label><input type="text" name="division" value="<?= h($v('division', (string)($_GET['div'] ?? ''))) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">शाखा</label><input type="text" name="course" value="<?= h($v('course')) ?>" class="form-control-custom" placeholder="उदा. AIML"></div>
    </div>
    <div class="sf-sec">👤 माहिती</div>
    <div class="row g-2">
      <div class="col-6 col-md-2"><label class="form-label-custom">लिंग</label>
        <select name="gender" class="form-select-custom"><option value="">—</option><?php foreach (['M' => 'मुलगा', 'F' => 'मुलगी', 'O' => 'इतर'] as $gk => $gl): ?><option value="<?= $gk ?>" <?= $v('gender') === $gk ? 'selected' : '' ?>><?= $gl ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">जन्मतारीख</label><input type="date" name="date_of_birth" value="<?= h($v('date_of_birth')) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">रक्तगट</label><input type="text" name="blood_group" value="<?= h($v('blood_group')) ?>" class="form-control-custom" maxlength="6"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">पालक मोबाईल</label><input type="tel" name="parent_mobile" value="<?= h($v('parent_mobile')) ?>" class="form-control-custom" inputmode="numeric"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">मोबाईल</label><input type="tel" name="mobile" value="<?= h($v('mobile')) ?>" class="form-control-custom" inputmode="numeric"></div>
      <div class="col-md-8"><label class="form-label-custom">पत्ता</label><input type="text" name="address" value="<?= h($v('address')) ?>" class="form-control-custom" maxlength="500"></div>
      <div class="col-md-4"><label class="form-label-custom">शेरा</label><input type="text" name="remarks" value="<?= h($v('remarks')) ?>" class="form-control-custom" maxlength="255"></div>
      <?php if ($edit): ?><div class="col-md-6"><label class="form-label-custom">📷 फोटो (पाससाठी)<?= $photoNow ? ' ✓' : '' ?></label><input type="file" name="photo" accept="image/png,image/jpeg,image/webp" class="form-control-custom" data-crop></div><?php endif; ?>
    </div>
    </fieldset>
    <?php if ($edit): ?>
    <div class="sf-bar">
      <a href="students.php" class="btn" style="border:1px solid var(--line);border-radius:10px;">रद्द</a>
      <?php if (!$id): ?><button type="submit" name="next" value="another" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:10px;font-weight:600;">जतन + पुढचा</button><?php endif; ?>
      <button type="submit" name="next" value="pass" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:10px;font-weight:600;">जतन + 🚌 पास द्या</button>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:700;padding:8px 22px;">✓ जतन करा</button>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($id): ?>
<div class="panel mt-3">
  <div class="panel-head"><div class="title">🎫 पासचा इतिहास (<?= count($passes) ?>)</div></div>
  <?php if (!$passes): ?><div style="padding:14px 20px;font-size:13px;color:var(--text-dim);">अजून पास दिलेला नाही.</div><?php endif; ?>
  <?php foreach ($passes as $p): $stt = busPassState($p); ?>
    <div class="d-flex align-items-center gap-2 flex-wrap" style="padding:9px 20px;border-top:1px solid var(--line);font-size:13px;">
      <b><?= h($p['pass_no']) ?></b>
      <span><?= h($p['from_place']) ?> → <?= h($p['to_place']) ?></span>
      <span style="color:var(--text-dim);"><?= h(date('d/m/Y', strtotime($p['valid_from']))) ?> → <?= h(date('d/m/Y', strtotime($p['valid_till']))) ?></span>
      <span class="bp-st <?= in_array($stt, ['active', 'expiring', 'upcoming'], true) ? 'ok' : ($stt === 'expired' ? 'exp' : 'none') ?>"><?= h(busPassStateLabel($stt)) ?></span>
      <a href="index.php?print=1&amp;ids=<?= (int)$p['id'] ?>" target="_blank" class="ms-auto btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:3px 9px;">🖨️ Print</a>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($edit): ?>
<div class="panel mt-3">
  <form method="POST" class="d-flex align-items-center gap-2 flex-wrap" style="padding:14px 20px;" onsubmit="return confirm('<?= $v('is_active', 1) ? 'या विद्यार्थ्याने बस सोडली म्हणून नोंदवायचं? (चालू पास रद्द होईल)' : 'पुन्हा सक्रिय करायचं?' ?>');">
    <input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php if ($v('is_active', 1)): ?>
      <span style="font-size:13px;">🚪 बस सोडली?</span>
      <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;">बस सोडली म्हणून नोंदवा</button>
    <?php else: ?>
      <input type="hidden" name="active" value="1">
      <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">↺ पुन्हा सक्रिय करा</button>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="../assets/js/photo-crop.js?v=1"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
