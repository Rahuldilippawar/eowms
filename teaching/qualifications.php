<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();

$viewTeacherId = $canManage && !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : $user['id'];
$canEditThis = $canManage || $viewTeacherId === (int)$user['id'];
if (!$canEditThis && $viewTeacherId !== (int)$user['id']) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEditThis) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        if ($teacherId === $viewTeacherId) {
            $pdo->prepare("
                INSERT INTO teacher_qualifications (teacher_id, qualification_type, specialization, institution, year_completed, net_set_status, research_papers_count, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $teacherId, trim($_POST['qualification_type'] ?? ''), trim($_POST['specialization'] ?? '') ?: null,
                trim($_POST['institution'] ?? '') ?: null, ($_POST['year_completed'] ?? '') ?: null,
                $_POST['net_set_status'] ?? 'None', (int)($_POST['research_papers_count'] ?? 0), trim($_POST['remarks'] ?? '') ?: null,
            ]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM teacher_qualifications WHERE id = ? AND teacher_id = ?")->execute([$id, $viewTeacherId]);
    }
    header('Location: qualifications.php' . ($canManage ? '?teacher_id=' . $viewTeacherId : ''));
    exit;
}

$pageTitle = 'शैक्षणिक पात्रता';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$viewTeacherStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND organization_id = ?");
$viewTeacherStmt->execute([$viewTeacherId, $orgId]);
$viewTeacher = $viewTeacherStmt->fetch();
if (!$viewTeacher) { die('सापडलं नाही.'); }

$qualStmt = $pdo->prepare("SELECT * FROM teacher_qualifications WHERE teacher_id = ? ORDER BY year_completed DESC");
$qualStmt->execute([$viewTeacherId]);
$qualifications = $qualStmt->fetchAll();

if ($canManage) {
    $teachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
    $teachersStmt->execute([$orgId]);
    $allTeachers = $teachersStmt->fetchAll();
}
$totalPapers = array_sum(array_column($qualifications, 'research_papers_count'));
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎓 <?= h($viewTeacher['name']) ?> — शैक्षणिक पात्रता</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>
<p style="font-size:11.5px;color:var(--text-dim);margin-bottom:20px;">💡 हा Data NAAC/AICTE Documentation साठीही थेट वापरता येईल</p>

<div class="d-flex gap-2 mb-4">
  <?php if ($canManage): ?>
  <form method="GET">
    <select name="teacher_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <?php foreach ($allTeachers as $t): ?><option value="<?= $t['id'] ?>" <?= $viewTeacherId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <?php if ($canEditThis): ?>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addQualModal">+ नवीन जोडा</button>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण पात्रता</div><div class="val"><?= count($qualifications) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">Research Papers</div><div class="val" style="color:var(--rail);"><?= $totalPapers ?></div></div></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>पात्रता</th><th>Specialization</th><th>संस्था</th><th>वर्ष</th><th>NET/SET</th><th>Papers</th><?php if ($canEditThis): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($qualifications)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून काही जोडलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($qualifications as $q): ?>
        <tr>
          <td style="font-weight:600;"><?= h($q['qualification_type']) ?></td>
          <td><?= h($q['specialization'] ?: '—') ?></td>
          <td><?= h($q['institution'] ?: '—') ?></td>
          <td class="font-mono"><?= h($q['year_completed'] ?: '—') ?></td>
          <td><?= $q['net_set_status'] !== 'None' ? '✅ ' . h($q['net_set_status']) : '—' ?></td>
          <td class="font-mono"><?= $q['research_papers_count'] ?></td>
          <?php if ($canEditThis): ?>
            <td><form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $q['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;" onclick="return confirm('काढायचं?')">✕</button>
            </form></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canEditThis): ?>
<div class="modal fade" id="addQualModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="teacher_id" value="<?= $viewTeacherId ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन पात्रता जोडा</div>
        <label class="form-label-custom">पात्रता (उदा. PhD, M.Ed, B.Ed)</label>
        <input type="text" name="qualification_type" class="form-control-custom mb-2" required>
        <label class="form-label-custom">विषय/Specialization</label>
        <input type="text" name="specialization" class="form-control-custom mb-2">
        <label class="form-label-custom">संस्था/University</label>
        <input type="text" name="institution" class="form-control-custom mb-2">
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label-custom">वर्ष</label><input type="number" name="year_completed" class="form-control-custom" min="1960" max="<?= date('Y') ?>"></div>
          <div class="col-6">
            <label class="form-label-custom">NET/SET</label>
            <select name="net_set_status" class="form-select-custom">
              <option value="None">लागू नाही</option><option value="NET">NET</option><option value="SET">SET</option><option value="Both">दोन्ही</option>
            </select>
          </div>
        </div>
        <label class="form-label-custom">Research Papers Published</label>
        <input type="number" name="research_papers_count" class="form-control-custom mb-2" min="0" value="0">
        <label class="form-label-custom">टिप्पणी</label>
        <textarea name="remarks" class="form-control-custom" rows="2"></textarea>
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
