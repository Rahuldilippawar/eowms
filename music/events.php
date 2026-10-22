<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// संगीत विभागाचाच Staff, किंवा 'manage_music' Permission असलेला, किंवा
// Management-श्रेणीतला — यांनाच Instruments/Students/इ. Manage करता येईल.
$musicDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$musicDeptCheck->execute([$user['department_id'] ?? 0]);
$isMusicDept = ($musicDeptCheck->fetch()['code'] ?? '') === 'MUS';
if (!$isMusicDept && !hasPermission('manage_music') && !isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला संगीत विभाग व्यवस्थापित करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $eventType = $_POST['event_type'] ?? 'other';
        $eventDate = $_POST['event_date'] ?? '';
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title && $eventDate) {
            $pdo->prepare("INSERT INTO music_events (organization_id, title, event_type, event_date, venue, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $title, $eventType, $eventDate, $venue, $description, $user['id']]);
            logActivity('संगीत कार्यक्रम जोडला', 'music_event', $pdo->lastInsertId(), $title);
            $_SESSION['flash'] = "\"$title\" यशस्वीरित्या जोडलं गेलं.";
        }
    } elseif ($action === 'add_participant') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $performanceItem = trim($_POST['performance_item'] ?? '');
        if ($eventId && $studentId) {
            $pdo->prepare("INSERT INTO music_event_participants (event_id, student_id, performance_item) VALUES (?, ?, ?)")->execute([$eventId, $studentId, $performanceItem]);
            $_SESSION['flash'] = 'सहभागी जोडला गेला.';
        }
    } elseif ($action === 'set_result') {
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $result = trim($_POST['result'] ?? '');
        $pdo->prepare("UPDATE music_event_participants SET result = ? WHERE id = ?")->execute([$result, $participantId]);
        $_SESSION['flash'] = 'निकाल जतन झाला.';
    }
    header('Location: events.php');
    exit;
}

$pageTitle = 'कार्यक्रम/स्पर्धा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$eventsStmt = $pdo->prepare("SELECT * FROM music_events WHERE organization_id = ? ORDER BY event_date DESC");
$eventsStmt->execute([$orgId]);
$events = $eventsStmt->fetchAll();

$studentsStmt = $pdo->prepare("SELECT id, name FROM music_students WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$studentsStmt->execute([$orgId]);
$students = $studentsStmt->fetchAll();

$typeIcons = ['concert' => '🎤', 'competition' => '🏆', 'annual_function' => '🎉', 'other' => '📅'];
$typeLabels = ['concert' => 'Concert', 'competition' => 'स्पर्धा', 'annual_function' => 'वार्षिक स्नेहसंमेलन', 'other' => 'इतर'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎤 कार्यक्रम/स्पर्धा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संगीत विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन कार्यक्रम/स्पर्धा जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="add_event">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label-custom">शीर्षक</label><input type="text" name="title" class="form-control-custom" required></div>
      <div class="col-md-2">
        <label class="form-label-custom">प्रकार</label>
        <select name="event_type" class="form-select-custom">
          <option value="concert">🎤 Concert</option>
          <option value="competition">🏆 स्पर्धा</option>
          <option value="annual_function">🎉 वार्षिक स्नेहसंमेलन</option>
          <option value="other">📅 इतर</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label-custom">दिनांक</label><input type="date" name="event_date" class="form-control-custom" required></div>
      <div class="col-md-4"><label class="form-label-custom">ठिकाण</label><input type="text" name="venue" class="form-control-custom"></div>
      <div class="col-12"><label class="form-label-custom">वर्णन</label><textarea name="description" class="form-control-custom" rows="2"></textarea></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">जोडा</button>
  </form>
</div>

<?php foreach ($events as $ev):
  $partStmt = $pdo->prepare("SELECT ep.*, s.name AS student_name FROM music_event_participants ep JOIN music_students s ON s.id = ep.student_id WHERE ep.event_id = ?");
  $partStmt->execute([$ev['id']]);
  $participants = $partStmt->fetchAll();
?>
  <div class="panel mb-3">
    <div class="panel-head">
      <div class="title"><?= $typeIcons[$ev['event_type']] ?> <?= h($ev['title']) ?></div>
      <div class="sub"><?= $typeLabels[$ev['event_type']] ?> · <?= date('d M Y', strtotime($ev['event_date'])) ?><?= $ev['venue'] ? ' · ' . h($ev['venue']) : '' ?></div>
    </div>
    <div style="padding:14px 20px;">
      <?php if ($ev['description']): ?><p style="font-size:12.5px;color:var(--text-dim);"><?= h($ev['description']) ?></p><?php endif; ?>

      <div style="font-size:12px;font-weight:600;color:var(--text-dim);margin-bottom:8px;">सहभागी विद्यार्थी</div>
      <?php foreach ($participants as $p): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line);font-size:13px;">
          <span><?= h($p['student_name']) ?><?= $p['performance_item'] ? ' — ' . h($p['performance_item']) : '' ?></span>
          <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="action" value="set_result"><input type="hidden" name="participant_id" value="<?= $p['id'] ?>">
            <input type="text" name="result" class="form-control-custom" style="width:140px;font-size:11.5px;padding:4px 8px;" placeholder="निकाल (उदा. प्रथम)" value="<?= h($p['result']) ?>">
            <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:6px;font-size:10.5px;padding:3px 10px;">जतन</button>
          </form>
        </div>
      <?php endforeach; ?>

      <form method="POST" class="d-flex gap-2 mt-3">
        <input type="hidden" name="action" value="add_participant"><input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
        <select name="student_id" class="form-select-custom" style="flex:2;" required>
          <option value="">-- विद्यार्थी निवडा --</option>
          <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="performance_item" class="form-control-custom" style="flex:2;" placeholder="काय सादर करणार">
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11.5px;padding:6px 14px;">+ जोडा</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($events)): ?><div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">अजून कुठलाही कार्यक्रम जोडलेला नाही</div><?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
