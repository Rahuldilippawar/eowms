<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
require_once __DIR__ . '/../includes/hostel-helpers.php';
requireHostelStaffAccess($pdo, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireHostelPostIdsInOrg($pdo, $orgId);
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_out') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $leaveDate = ($_POST['leave_date'] ?? '') ?: date('Y-m-d');
        $expectedReturn = ($_POST['expected_return_date'] ?? '') ?: null;
        $reason = trim($_POST['reason'] ?? '');
        if ($studentId) {
            $pdo->prepare("INSERT INTO hostel_leave_records (student_id, leave_date, expected_return_date, reason, approved_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$studentId, $leaveDate, $expectedReturn, $reason, $user['id']]);
            logActivity('विद्यार्थी सुट्टीवर गेला', 'hostel_leave', $pdo->lastInsertId());
            $_SESSION['flash'] = 'नोंदवलं गेलं.';
        }
    } elseif ($action === 'mark_returned') {
        $leaveId = (int)($_POST['leave_id'] ?? 0);
        $pdo->prepare("UPDATE hostel_leave_records SET status = 'returned', actual_return_date = CURDATE() WHERE id = ?")->execute([$leaveId]);
        $_SESSION['flash'] = 'परत आल्याची नोंद झाली.';
    }
    header('Location: leave.php');
    exit;
}

$pageTitle = 'सुट्टी/बाहेर जाण्याची नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$studentsStmt = $pdo->prepare("
    SELECT hs.id, hs.name, h.name AS hostel_name FROM hostel_students hs JOIN hostels h ON h.id = hs.hostel_id
    WHERE h.organization_id = ? AND hs.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM hostel_leave_records WHERE student_id = hs.id AND status = 'out')
    ORDER BY hs.name
");
$studentsStmt->execute([$orgId]);
$students = $studentsStmt->fetchAll();

$recordsStmt = $pdo->prepare("
    SELECT l.*, hs.name AS student_name, h.name AS hostel_name
    FROM hostel_leave_records l JOIN hostel_students hs ON hs.id = l.student_id JOIN hostels h ON h.id = hs.hostel_id
    WHERE h.organization_id = ? ORDER BY l.status = 'out' DESC, l.leave_date DESC LIMIT 100
");
$recordsStmt->execute([$orgId]);
$records = $recordsStmt->fetchAll();

$countOutStmt = $pdo->prepare("SELECT COUNT(*) c FROM hostel_leave_records l JOIN hostel_students hs ON hs.id = l.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ? AND l.status = 'out'");
$countOutStmt->execute([$orgId]);
$countOut = $countOutStmt->fetch()['c'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🚪 सुट्टी/बाहेर जाण्याची नोंद</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वसतिगृह</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🚪 सध्या बाहेर</div><div class="val" style="color:var(--amber);"><?= $countOut ?></div></div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">विद्यार्थी बाहेर जातो आहे — नोंदवा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="mark_out">
    <div class="row g-2">
      <div class="col-md-3">
        <select name="student_id" class="form-select-custom" required>
          <option value="">-- विद्यार्थी --</option>
          <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['hostel_name']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><input type="date" name="leave_date" class="form-control-custom" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-2"><input type="date" name="expected_return_date" class="form-control-custom" placeholder="परत येण्याचा अंदाजित दिनांक"></div>
      <div class="col-md-4"><input type="text" name="reason" class="form-control-custom" placeholder="कारण (उदा. घरी सुट्टीसाठी)"></div>
      <div class="col-md-1"><button type="submit" class="btn w-100" style="background:var(--amber);color:#fff;border-radius:8px;font-size:12px;">नोंदवा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>विद्यार्थी</th><th>गेला</th><th>अंदाजित परत</th><th>कारण</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($records)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($records as $r): ?>
        <tr style="<?= $r['status'] === 'out' ? 'background:#FBEBD6;' : '' ?>">
          <td style="font-weight:600;"><?= h($r['student_name']) ?><div style="font-size:10px;color:var(--text-dim);"><?= h($r['hostel_name']) ?></div></td>
          <td><?= date('d M Y', strtotime($r['leave_date'])) ?></td>
          <td><?= $r['expected_return_date'] ? date('d M Y', strtotime($r['expected_return_date'])) : '—' ?></td>
          <td><?= h($r['reason'] ?: '—') ?></td>
          <td>
            <?php if ($r['status'] === 'out'): ?><span class="status-chip pending">🚪 बाहेर</span>
            <?php else: ?><span class="status-chip done">✅ परत आला (<?= date('d M', strtotime($r['actual_return_date'])) ?>)</span><?php endif; ?>
          </td>
          <td>
            <?php if ($r['status'] === 'out'): ?>
              <form method="POST"><input type="hidden" name="action" value="mark_returned"><input type="hidden" name="leave_id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:4px 10px;">परत आला</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
