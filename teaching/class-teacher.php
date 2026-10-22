<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

$fy = date('Y') . '-' . substr(date('Y') + 1, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'assign') {
        $classSection = trim($_POST['class_section'] ?? '');
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $campusId = (int)($_POST['campus_id'] ?? 0);
        if ($classSection && $teacherId && $campusId) {
            $pdo->prepare("
                INSERT INTO class_teacher_assignments (organization_id, campus_id, class_section, teacher_id, academic_year)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)
            ")->execute([$orgId, $campusId, $classSection, $teacherId, $fy]);
            $_SESSION['flash'] = 'Class Teacher नेमला गेला.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM class_teacher_assignments WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
    }
    header('Location: class-teacher.php');
    exit;
}

$pageTitle = 'Class Teacher नेमणूक';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$assignmentsStmt = $pdo->prepare("
    SELECT cta.*, u.name AS teacher_name, c.name AS campus_name
    FROM class_teacher_assignments cta JOIN users u ON u.id = cta.teacher_id JOIN campuses c ON c.id = cta.campus_id
    WHERE cta.organization_id = ? AND cta.academic_year = ? ORDER BY c.name, cta.class_section
");
$assignmentsStmt->execute([$orgId, $fy]);
$assignments = $assignmentsStmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();

$teachersStmt = $pdo->prepare("SELECT id, name, campus_id FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
$teachersStmt->execute([$orgId]);
$allTeachers = $teachersStmt->fetchAll();
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">👨‍🏫 Class Teacher नेमणूक — FY <?= h($fy) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<button type="button" class="btn mb-4" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#assignModal">+ नवीन नेमणूक</button>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Class/Section</th><th>College/School</th><th>Class Teacher</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($assignments)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही नेमणूक नाही</td></tr><?php endif; ?>
      <?php foreach ($assignments as $a): ?>
        <tr>
          <td style="font-weight:600;"><?= h($a['class_section']) ?></td>
          <td><?= h($a['campus_name']) ?></td>
          <td><?= h($a['teacher_name']) ?></td>
          <td><form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;" onclick="return confirm('काढायचं?')">✕</button>
          </form></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="assign">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Class Teacher नेमा</div>
        <label class="form-label-custom">College/School</label>
        <select name="campus_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">Class/Section</label>
        <input type="text" name="class_section" class="form-control-custom mb-2" placeholder="उदा. 10th A" required>
        <label class="form-label-custom">शिक्षक</label>
        <select name="teacher_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($allTeachers as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">नेमा</button>
      </div>
    </form>
  </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
