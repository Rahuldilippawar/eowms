<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();
$isTeacher = ($user['teaching_mode'] ?? '') === 'Teaching';

// शैक्षणिक वर्ष जूनपासून सुरू — जानेवारी-मे मध्ये मागच्या वर्षाचंच Academic Year
$fyStartYear = (int)date('Y') - ((int)date('n') < 6 ? 1 : 0);
$fy = $fyStartYear . '-' . substr((string)($fyStartYear + 1), 2);
$viewTeacherId = $canManage && !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $canEditThis = $canManage || (int)($_POST['teacher_id'] ?? 0) === (int)$user['id'];
    if ($canEditThis) {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $subject = trim($_POST['subject_name'] ?? '');
            $classSection = trim($_POST['class_section'] ?? '');
            $totalChapters = (int)($_POST['total_chapters'] ?? 0);
            $teacherCampusStmt = $pdo->prepare("SELECT campus_id FROM users WHERE id = ?");
            $teacherCampusStmt->execute([$teacherId]);
            $teacherCampusId = $teacherCampusStmt->fetch()['campus_id'] ?? null;
            if ($teacherId && $subject && $classSection && $totalChapters > 0) {
                try {
                    $pdo->prepare("
                        INSERT INTO syllabus_trackers (organization_id, campus_id, teacher_id, subject_name, class_section, academic_year, total_chapters, chapters_completed)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                    ")->execute([$orgId, $teacherCampusId, $teacherId, $subject, $classSection, $fy, $totalChapters]);
                } catch (PDOException $e) { $_SESSION['flash'] = '⚠️ हा विषय/वर्ग आधीच जोडलेला आहे.'; }
            }
        } elseif ($action === 'update_progress') {
            $id = (int)($_POST['id'] ?? 0);
            $completed = (int)($_POST['chapters_completed'] ?? 0);
            $pdo->prepare("UPDATE syllabus_trackers SET chapters_completed = ? WHERE id = ? AND organization_id = ?")->execute([$completed, $id, $orgId]);
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM syllabus_trackers WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        }
    }
    header('Location: syllabus.php' . ($canManage ? '?teacher_id=' . $viewTeacherId : ''));
    exit;
}

$pageTitle = 'Syllabus Tracker';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$viewTeacherStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND organization_id = ?");
$viewTeacherStmt->execute([$viewTeacherId, $orgId]);
$viewTeacher = $viewTeacherStmt->fetch();
if (!$viewTeacher) { die('सापडलं नाही.'); }

$syllabusStmt = $pdo->prepare("SELECT * FROM syllabus_trackers WHERE teacher_id = ? AND academic_year = ? ORDER BY subject_name");
$syllabusStmt->execute([$viewTeacherId, $fy]);
$syllabusList = $syllabusStmt->fetchAll();

if ($canManage) {
    $teachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
    $teachersStmt->execute([$orgId]);
    $allTeachers = $teachersStmt->fetchAll();
}
$canEditThisView = $canManage || $viewTeacherId === (int)$user['id'];
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📖 <?= h($viewTeacher['name']) ?> — Syllabus Tracker</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
  <?php if ($canManage): ?>
  <form method="GET">
    <select name="teacher_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <?php foreach ($allTeachers as $t): ?><option value="<?= $t['id'] ?>" <?= $viewTeacherId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <?php if ($canEditThisView): ?>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addSyllabusModal">+ नवीन विषय जोडा</button>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php if (empty($syllabusList)): ?><div class="col-12"><div class="panel"><div class="empty-state"><div class="icon"><i class="fa-solid fa-book-open"></i></div><div class="title">अजून कुठलाही विषय जोडलेला नाही</div></div></div></div><?php endif; ?>
  <?php foreach ($syllabusList as $s): $pct = $s['total_chapters'] > 0 ? round(($s['chapters_completed'] / $s['total_chapters']) * 100) : 0; ?>
    <div class="col-md-4">
      <div class="panel" style="padding:18px;">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div style="font-weight:700;font-size:14px;"><?= h($s['subject_name']) ?></div>
            <div style="font-size:11.5px;color:var(--text-dim);"><?= h($s['class_section']) ?></div>
          </div>
          <?php if ($canEditThisView): ?>
            <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;" onclick="return confirm('काढायचं?')">✕</button>
            </form>
          <?php endif; ?>
        </div>
        <div style="background:var(--canvas);border-radius:20px;height:10px;overflow:hidden;margin:12px 0 6px;">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 80 ? 'var(--rail)' : ($pct >= 40 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
        </div>
        <div style="font-size:12px;color:var(--text-dim);"><?= $s['chapters_completed'] ?>/<?= $s['total_chapters'] ?> Chapters — <strong><?= $pct ?>%</strong></div>
        <?php if ($canEditThisView): ?>
        <form method="POST" class="d-flex gap-2 mt-2">
          <input type="hidden" name="action" value="update_progress">
          <input type="hidden" name="id" value="<?= $s['id'] ?>">
          <input type="number" name="chapters_completed" class="form-control-custom" style="font-size:11px;padding:5px 8px;" value="<?= $s['chapters_completed'] ?>" min="0" max="<?= $s['total_chapters'] ?>">
          <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:6px;font-size:10.5px;padding:5px 12px;">Update</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($canEditThisView): ?>
<div class="modal fade" id="addSyllabusModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="teacher_id" value="<?= $viewTeacherId ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन विषय जोडा</div>
        <label class="form-label-custom">विषय</label>
        <input type="text" name="subject_name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">वर्ग/Section</label>
        <input type="text" name="class_section" class="form-control-custom mb-2" placeholder="उदा. 10th A" required>
        <label class="form-label-custom">एकूण Chapters</label>
        <input type="number" name="total_chapters" class="form-control-custom" min="1" required>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
