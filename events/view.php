<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_events') || isManagementOrAbove();

$eventId = (int)($_GET['id'] ?? 0);
$eventStmt = $pdo->prepare("SELECT e.*, u.name AS responsible_name FROM events e LEFT JOIN users u ON u.id = e.responsible_user_id WHERE e.id = ? AND e.organization_id = ?");
$eventStmt->execute([$eventId, $orgId]);
$event = $eventStmt->fetch();
if (!$event) { die('कार्यक्रम सापडला नाही.'); }

// जबाबदार व्यक्ती सुद्धा Task व्यवस्थापित करू शकते
$canManageThis = $canManage || (int)$event['responsible_user_id'] === (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageThis) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        $title = trim($_POST['title'] ?? '');
        $assignedTo = ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null;
        $dueDate = ($_POST['due_date'] ?? '') ?: null;
        if ($assignedTo) {
            $aChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND organization_id = ?");
            $aChk->execute([$assignedTo, $orgId]);
            if (!(int)$aChk->fetchColumn()) { $assignedTo = null; }
        }
        if ($title) {
            $pdo->prepare("INSERT INTO event_tasks (event_id, title, assigned_to, due_date) VALUES (?, ?, ?, ?)")->execute([$eventId, $title, $assignedTo, $dueDate]);
            if ($assignedTo) { notifyUser($assignedTo, $orgId, '🎉 कार्यक्रमाचं काम नेमून दिलं', "{$event['name']} — $title", 'events/view.php?id=' . $eventId); }
        }
    } elseif ($action === 'update_task_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        if (!in_array($status, ['pending', 'in_progress', 'done'], true)) { header('Location: view.php?id=' . $eventId); exit; }
        $pdo->prepare("UPDATE event_tasks SET status = ? WHERE id = ? AND event_id = ?")->execute([$status, $taskId, $eventId]);
        if ($status === 'done') { $_SESSION['celebrate_task'] = true; }
    } elseif ($action === 'add_budget_item') {
        $desc = trim($_POST['item_description'] ?? '');
        $est = ($_POST['estimated_amount'] ?? '') !== '' ? (float)$_POST['estimated_amount'] : null;
        $act = ($_POST['actual_amount'] ?? '') !== '' ? (float)$_POST['actual_amount'] : null;
        if ($desc) {
            $pdo->prepare("INSERT INTO event_budget_items (event_id, item_description, estimated_amount, actual_amount) VALUES (?, ?, ?, ?)")->execute([$eventId, $desc, $est, $act]);
        }
    } elseif ($action === 'upload_photo') {
        if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $uploadDir = __DIR__ . '/../assets/uploads/event-photos';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $safeName = 'evt-' . $eventId . '-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . '/' . $safeName)) {
                    $pdo->prepare("INSERT INTO event_photos (event_id, file_path, caption, uploaded_by) VALUES (?, ?, ?, ?)")
                        ->execute([$eventId, 'assets/uploads/event-photos/' . $safeName, trim($_POST['caption'] ?? '') ?: null, $user['id']]);
                }
            }
        }
    } elseif ($action === 'update_status') {
        $newStatus = in_array($_POST['new_status'] ?? '', ['planned', 'ongoing', 'completed', 'cancelled'], true) ? $_POST['new_status'] : $event['status'];
        // खर्चाचं Field रिकामं ठेवलं तर आधीची रक्कम तशीच ठेवतो (पुसत नाही)
        $actualAmount = ($_POST['budget_actual'] ?? '') !== '' ? (float)$_POST['budget_actual'] : $event['budget_actual'];
        $pdo->prepare("UPDATE events SET status = ?, budget_actual = ? WHERE id = ? AND organization_id = ?")->execute([$newStatus, $actualAmount, $eventId, $orgId]);
    } elseif ($action === 'mark_attendance') {
        $attendeeType = $_POST['attendee_type'] ?? 'staff';
        if ($attendeeType === 'staff' && !empty($_POST['user_id'])) {
            // एकाच कर्मचाऱ्याची दुहेरी नोंद नको; कर्मचारी याच संस्थेचा असावा
            $attChk = $pdo->prepare("SELECT (SELECT COUNT(*) FROM users WHERE id = ? AND organization_id = ?) AS in_org, (SELECT COUNT(*) FROM event_attendance WHERE event_id = ? AND user_id = ?) AS dup");
            $attChk->execute([(int)$_POST['user_id'], $orgId, $eventId, (int)$_POST['user_id']]);
            $attRow = $attChk->fetch();
            if ((int)$attRow['in_org'] && !(int)$attRow['dup']) {
                $pdo->prepare("INSERT IGNORE INTO event_attendance (event_id, attendee_type, user_id, marked_by) VALUES (?, 'staff', ?, ?)")->execute([$eventId, (int)$_POST['user_id'], $user['id']]);
            } elseif ((int)$attRow['dup']) {
                $_SESSION['flash'] = 'या कर्मचाऱ्याची उपस्थिती आधीच नोंदलेली आहे.';
            }
        } elseif ($attendeeType === 'guest' && !empty($_POST['guest_name'])) {
            $pdo->prepare("INSERT INTO event_attendance (event_id, attendee_type, guest_name, marked_by) VALUES (?, 'guest', ?, ?)")->execute([$eventId, trim($_POST['guest_name']), $user['id']]);
        }
    } elseif ($action === 'remove_attendance') {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $pdo->prepare("DELETE FROM event_attendance WHERE id = ? AND event_id = ?")->execute([$attId, $eventId]);
    } elseif ($action === 'set_recurring') {
        $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
        $pattern = $isRecurring ? (($_POST['recurrence_pattern'] ?? '') === 'monthly' ? 'monthly' : 'yearly') : null;
        $pdo->prepare("UPDATE events SET is_recurring = ?, recurrence_pattern = ? WHERE id = ? AND organization_id = ?")->execute([$isRecurring, $pattern, $eventId, $orgId]);
    } elseif ($action === 'edit_event' && $canManage) {
        $name = trim($_POST['name'] ?? '');
        $eventDate = $_POST['event_date'] ?? '';
        $responsibleUserId = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;
        if ($responsibleUserId) {
            $rChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND organization_id = ?");
            $rChk->execute([$responsibleUserId, $orgId]);
            if (!(int)$rChk->fetchColumn()) { $responsibleUserId = null; }
        }
        if ($name && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            $pdo->prepare("UPDATE events SET name = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, venue = ?, responsible_user_id = ?, budget_estimated = ? WHERE id = ? AND organization_id = ?")
                ->execute([$name, trim($_POST['description'] ?? ''), $eventDate, ($_POST['start_time'] ?? '') ?: null, ($_POST['end_time'] ?? '') ?: null,
                    trim($_POST['venue'] ?? '') ?: null, $responsibleUserId, ($_POST['budget_estimated'] ?? '') !== '' ? (float)$_POST['budget_estimated'] : null, $eventId, $orgId]);
            if ($responsibleUserId && $responsibleUserId !== (int)$event['responsible_user_id']) {
                notifyUser($responsibleUserId, $orgId, '🎉 नवीन कार्यक्रमाची जबाबदारी', "$name — " . date('d M Y', strtotime($eventDate)), 'events/view.php?id=' . $eventId);
            }
            logActivity('कार्यक्रम बदलला', 'events', $eventId, $name);
            $_SESSION['flash'] = 'कार्यक्रम Update झाला.';
        }
    } elseif ($action === 'delete_event' && $canManage) {
        $pdo->prepare("DELETE FROM events WHERE id = ? AND organization_id = ?")->execute([$eventId, $orgId]);
        logActivity('कार्यक्रम काढला', 'events', $eventId, $event['name']);
        $_SESSION['flash'] = 'कार्यक्रम काढला गेला.';
        header('Location: index.php');
        exit;
    }
    header('Location: view.php?id=' . $eventId);
    exit;
}

$pageTitle = $event['name'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$tasksStmt = $pdo->prepare("SELECT et.*, u.name AS assignee_name FROM event_tasks et LEFT JOIN users u ON u.id = et.assigned_to WHERE et.event_id = ? ORDER BY et.due_date");
$tasksStmt->execute([$eventId]);
$tasks = $tasksStmt->fetchAll();

$budgetStmt = $pdo->prepare("SELECT * FROM event_budget_items WHERE event_id = ?");
$budgetStmt->execute([$eventId]);
$budgetItems = $budgetStmt->fetchAll();
$totalEst = array_sum(array_column($budgetItems, 'estimated_amount'));
$totalAct = array_sum(array_column($budgetItems, 'actual_amount'));

$photosStmt = $pdo->prepare("SELECT ep.*, u.name AS uploader_name FROM event_photos ep LEFT JOIN users u ON u.id = ep.uploaded_by WHERE ep.event_id = ? ORDER BY ep.created_at DESC");
$photosStmt->execute([$eventId]);
$photos = $photosStmt->fetchAll();

$attendanceStmt = $pdo->prepare("SELECT ea.*, u.name AS staff_name FROM event_attendance ea LEFT JOIN users u ON u.id = ea.user_id WHERE ea.event_id = ? ORDER BY ea.created_at DESC");
$attendanceStmt->execute([$eventId]);
$attendanceList = $attendanceStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaffForTask = $staffStmt->fetchAll();

$statusLabels = ['planned' => '📅 नियोजित', 'ongoing' => '🔴 सुरू आहे', 'completed' => '✅ पूर्ण झाला', 'cancelled' => '❌ रद्द'];
$taskStatusLabels = ['pending' => '⏳ बाकी', 'in_progress' => '🔄 सुरू आहे', 'done' => '✅ पूर्ण'];
?>

<div class="page-eyebrow">कार्यक्रम</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎉 <?= h($event['name']) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व कार्यक्रम</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">📅 <?= date('d M Y', strtotime($event['event_date'])) ?><?= $event['venue'] ? ' · 📍 ' . h($event['venue']) : '' ?><?= $event['responsible_name'] ? ' · 👤 ' . h($event['responsible_name']) : '' ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($canManage): ?>
<details class="panel mb-4" style="padding:12px 20px;">
  <summary style="cursor:pointer;font-size:13px;font-weight:600;">✏️ कार्यक्रम बदला / काढा</summary>
  <form method="POST" class="mt-3">
    <input type="hidden" name="action" value="edit_event">
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label-custom">नाव</label><input type="text" name="name" class="form-control-custom" value="<?= h($event['name']) ?>" required></div>
      <div class="col-md-3"><label class="form-label-custom">दिनांक</label><input type="date" name="event_date" class="form-control-custom" value="<?= h($event['event_date']) ?>" required></div>
      <div class="col-md-2"><label class="form-label-custom">सुरू</label><input type="time" name="start_time" class="form-control-custom" value="<?= h($event['start_time']) ?>"></div>
      <div class="col-md-2"><label class="form-label-custom">संपेल</label><input type="time" name="end_time" class="form-control-custom" value="<?= h($event['end_time']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">ठिकाण</label><input type="text" name="venue" class="form-control-custom" value="<?= h($event['venue']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">जबाबदार व्यक्ती</label>
        <select name="responsible_user_id" class="form-select-custom">
          <option value="">-- नाही --</option>
          <?php foreach ($allStaffForTask as $st): ?><option value="<?= $st['id'] ?>" <?= (int)$event['responsible_user_id'] === (int)$st['id'] ? 'selected' : '' ?>><?= h($st['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label-custom">अंदाजित Budget</label><input type="number" step="0.01" name="budget_estimated" class="form-control-custom" value="<?= h($event['budget_estimated']) ?>"></div>
      <div class="col-12"><label class="form-label-custom">माहिती</label><textarea name="description" class="form-control-custom" rows="2"><?= h($event['description']) ?></textarea></div>
    </div>
    <div class="d-flex justify-content-between mt-2">
      <button type="submit" form="eventDeleteForm" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;">🗑️ कार्यक्रम काढा</button>
      <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 18px;">जतन करा</button>
    </div>
  </form>
  <form method="POST" id="eventDeleteForm" onsubmit="return confirm('हा कार्यक्रम व त्याची सगळी कामं, Budget, उपस्थिती, Photos काढायचे?');">
    <input type="hidden" name="action" value="delete_event">
  </form>
</details>
<?php endif; ?>

<?php if ($canManageThis): ?>
<div class="panel mb-4">
  <div style="padding:14px 20px;">
    <form method="POST" class="d-flex gap-2 align-items-end flex-wrap">
      <input type="hidden" name="action" value="update_status">
      <div>
        <label class="form-label-custom">स्थिती</label>
        <select name="new_status" class="form-select-custom">
          <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $event['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label-custom">प्रत्यक्ष खर्च झालेला (एकूण)</label>
        <input type="number" step="0.01" name="budget_actual" class="form-control-custom" value="<?= h($event['budget_actual']) ?>">
      </div>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;padding:8px 16px;">Update करा</button>
    </form>
  </div>
</div>

<div class="panel mb-4">
  <div style="padding:14px 20px;">
    <form method="POST" class="d-flex gap-3 align-items-center flex-wrap">
      <input type="hidden" name="action" value="set_recurring">
      <label style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;">
        <input type="checkbox" name="is_recurring" value="1" <?= $event['is_recurring'] ? 'checked' : '' ?> style="width:16px;height:16px;">
        🔁 हा दरवर्षी/दरमहा होणारा कार्यक्रम आहे
      </label>
      <select name="recurrence_pattern" class="form-select-custom" style="width:auto;">
        <option value="yearly" <?= $event['recurrence_pattern'] === 'yearly' ? 'selected' : '' ?>>दरवर्षी</option>
        <option value="monthly" <?= $event['recurrence_pattern'] === 'monthly' ? 'selected' : '' ?>>दरमहा</option>
      </select>
      <button type="submit" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:8px;font-size:12px;padding:7px 14px;">जतन करा</button>
    </form>
    <?php if ($event['is_recurring']): ?>
      <div style="font-size:11px;color:var(--rail);margin-top:8px;">✅ हा कार्यक्रम संपल्यावर — पुढच्या <?= $event['recurrence_pattern'] === 'yearly' ? 'वर्षीचा' : 'महिन्याचा' ?> कार्यक्रम आपोआप तयार होईल.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">✅ कामं (Tasks)</div></div>
      <?php if ($canManageThis): ?>
      <form method="POST" style="padding:14px 20px;border-bottom:1px solid var(--line);">
        <input type="hidden" name="action" value="add_task">
        <div class="row g-2">
          <div class="col-5"><input type="text" name="title" class="form-control-custom" placeholder="काम" required></div>
          <div class="col-4">
            <select name="assigned_to" class="form-select-custom">
              <option value="">-- कोणाला --</option>
              <?php foreach ($allStaffForTask as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-2"><input type="date" name="due_date" class="form-control-custom"></div>
          <div class="col-1"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:7px;">+</button></div>
        </div>
      </form>
      <?php endif; ?>
      <?php foreach ($tasks as $t): ?>
        <div style="padding:10px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:13px;font-weight:600;"><?= h($t['title']) ?></div>
            <div style="font-size:11px;color:var(--text-dim);"><?= h($t['assignee_name'] ?: 'नेमलं नाही') ?><?= $t['due_date'] ? ' · ' . date('d M', strtotime($t['due_date'])) : '' ?></div>
          </div>
          <?php if ($canManageThis): ?>
            <form method="POST"><input type="hidden" name="action" value="update_task_status"><input type="hidden" name="task_id" value="<?= $t['id'] ?>">
              <select name="status" class="form-select-custom" style="font-size:11px;padding:4px 8px;" onchange="this.form.submit()">
                <?php foreach ($taskStatusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $t['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
              </select>
            </form>
          <?php else: ?>
            <span class="status-chip <?= $t['status'] === 'done' ? 'done' : 'pending' ?>"><?= $taskStatusLabels[$t['status']] ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($tasks)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून कुठलंही काम नाही</p><?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><div class="title">💰 Budget</div><div class="sub">अंदाजित ₹<?= number_format($totalEst, 0) ?> · प्रत्यक्ष ₹<?= number_format($totalAct, 0) ?></div></div>
      <?php if ($canManageThis): ?>
      <form method="POST" style="padding:14px 20px;border-bottom:1px solid var(--line);">
        <input type="hidden" name="action" value="add_budget_item">
        <div class="row g-2">
          <div class="col-5"><input type="text" name="item_description" class="form-control-custom" placeholder="बाब" required></div>
          <div class="col-3"><input type="number" step="0.01" name="estimated_amount" class="form-control-custom" placeholder="अंदाजित"></div>
          <div class="col-3"><input type="number" step="0.01" name="actual_amount" class="form-control-custom" placeholder="प्रत्यक्ष"></div>
          <div class="col-1"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:7px;">+</button></div>
        </div>
      </form>
      <?php endif; ?>
      <table class="rtable">
        <thead><tr><th>बाब</th><th>अंदाजित</th><th>प्रत्यक्ष</th></tr></thead>
        <tbody>
          <?php foreach ($budgetItems as $b): ?>
            <tr><td><?= h($b['item_description']) ?></td><td class="font-mono">₹<?= number_format($b['estimated_amount'] ?? 0, 0) ?></td><td class="font-mono">₹<?= number_format($b['actual_amount'] ?? 0, 0) ?></td></tr>
          <?php endforeach; ?>
          <?php if (empty($budgetItems)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:14px;">अजून काही नाही</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📋 उपस्थिती (<?= count($attendanceList) ?>)</div></div>
      <?php if ($canManageThis): ?>
      <form method="POST" style="padding:14px 20px;border-bottom:1px solid var(--line);">
        <input type="hidden" name="action" value="mark_attendance">
        <div class="row g-2">
          <div class="col-4">
            <select name="attendee_type" class="form-select-custom" onchange="document.getElementById('attStaffPick').style.display = this.value === 'staff' ? 'block' : 'none'; document.getElementById('attGuestPick').style.display = this.value === 'guest' ? 'block' : 'none';">
              <option value="staff">Staff</option>
              <option value="guest">बाहेरचा (नाव फक्त)</option>
            </select>
          </div>
          <div class="col-6" id="attStaffPick">
            <select name="user_id" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaffForTask as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6" id="attGuestPick" style="display:none;"><input type="text" name="guest_name" class="form-control-custom" placeholder="नाव"></div>
          <div class="col-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:7px;">+</button></div>
        </div>
      </form>
      <?php endif; ?>
      <div style="max-height:280px;overflow-y:auto;">
        <?php foreach ($attendanceList as $a): ?>
          <div style="padding:9px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:12.5px;"><?= $a['attendee_type'] === 'staff' ? '👤 ' . h($a['staff_name']) : '🎫 ' . h($a['guest_name']) ?></span>
            <div class="d-flex gap-2 align-items-center">
              <a href="certificate.php?event_id=<?= $eventId ?>&attendance_id=<?= $a['id'] ?>" target="_blank" style="font-size:10.5px;color:var(--rail);font-weight:600;">🏆 Certificate</a>
              <?php if ($canManageThis): ?>
                <form method="POST"><input type="hidden" name="action" value="remove_attendance"><input type="hidden" name="attendance_id" value="<?= $a['id'] ?>">
                  <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($attendanceList)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून कुठलीही उपस्थिती नोंदवलेली नाही</p><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><div class="title">📸 Photos</div></div>
      <?php if ($canManageThis): ?>
      <form method="POST" enctype="multipart/form-data" style="padding:14px 20px;border-bottom:1px solid var(--line);">
        <input type="hidden" name="action" value="upload_photo">
        <input type="file" name="photo" accept="image/*" class="form-control-custom mb-2" required>
        <input type="text" name="caption" class="form-control-custom mb-2" placeholder="Caption (ऐच्छिक)">
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:9px;">Upload करा</button>
      </form>
      <?php endif; ?>
      <div style="padding:14px 20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;">
        <?php foreach ($photos as $p): ?>
          <a href="../<?= h($p['file_path']) ?>" target="_blank">
            <img src="../<?= h($p['file_path']) ?>" style="width:100%;height:100px;object-fit:cover;border-radius:8px;">
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (empty($photos)): ?><p style="font-size:12px;color:var(--text-dim);padding:0 20px 14px;">अजून कुठलाही Photo नाही</p><?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php if (!empty($_SESSION['celebrate_task'])): unset($_SESSION['celebrate_task']); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var doneChip = document.querySelector('.status-chip.done') || document.body;
  eowmsCelebrate(doneChip);
});
</script>
<?php endif; ?>
