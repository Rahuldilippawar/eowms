<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s605'));
}
$user = currentUser();

$orgId = activeOrgId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventDate = $_POST['event_date'] ?? null;
    $deptId = ($_POST['organizing_department_id'] ?? '') !== '' ? (int)$_POST['organizing_department_id'] : null;
    $theme = trim($_POST['theme'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;

    if (!$eventDate) {
        $error = t('s606');
    } else {
        $insert = $pdo->prepare("
            INSERT INTO satsang_events (organization_id, event_date, organizing_department_id, theme, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$orgId, $eventDate, $deptId, $theme, $notes, $user['id']]);
        $newId = $pdo->lastInsertId();

        if ($deptId) {
            notifyDepartment($deptId, $orgId, 'सत्संग आयोजनाची जबाबदारी', date('d M Y', strtotime($eventDate)) . " रोजीच्या सत्संगाचं आयोजन तुमच्या विभागाकडे आहे.", 'meditation/event.php?id=' . $newId);
        }
        logActivity('सत्संग कार्यक्रम तयार केला', 'satsang_event', $newId, $eventDate);

        $_SESSION['flash'] = t('s609');
        header('Location: event.php?id=' . $newId);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

// Suggest the next upcoming Thursday as a default
$nextThursday = date('Y-m-d', strtotime('next thursday'));
if (date('N') == 4) { $nextThursday = date('Y-m-d'); } // today if today is already Thursday

$pageTitle = t('s584');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s598')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title"><?= h(t('s599')) ?></div><div class="sub"><?= h(t('s600')) ?></div></div>
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="event_date" class="form-control-custom" value="<?= h($nextThursday) ?>" required>
      </div>
      <div class="col-md-8">
        <label class="form-label-custom"><?= h(t('s601')) ?></label>
        <select name="organizing_department_id" class="form-select-custom">
          <option value=""><?= h(t('s602')) ?></option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s603')) ?></label>
        <input type="text" name="theme" class="form-control-custom" placeholder="उदा. ध्यानाचे महत्त्व, गुरुकृपा">
      </div>
      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s604')) ?></label>
        <textarea name="notes" class="form-control-custom" rows="2"></textarea>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">तयार करा</button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
