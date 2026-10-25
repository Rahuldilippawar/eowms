<?php
$pageTitle = 'सत्संग Duty Chart';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$eventId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();
$canManage = isManagementOrAbove() || hasPermission('manage_meditation');

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$canMarkAttendance = hasRole('super_admin') || $userDeptCode === 'MED' || $canManage;

$stmt = $pdo->prepare("
    SELECT se.*, d.name_local AS dept_name
    FROM satsang_events se LEFT JOIN departments d ON d.id = se.organizing_department_id
    WHERE se.id = ? AND se.organization_id = ?
");
$stmt->execute([$eventId, $orgId]);
$event = $stmt->fetch();
if (!$event) { die(t('s616')); }

$stmt = $pdo->prepare("
    SELECT sd.*, u.name AS staff_name, u.designation
    FROM satsang_duties sd JOIN users u ON u.id = sd.assigned_to
    WHERE sd.satsang_event_id = ?
    ORDER BY sd.id
");
$stmt->execute([$eventId]);
$duties = $stmt->fetchAll();

// Staff pool for assigning duties — whole organization
$stmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

$deptStmt = $pdo->prepare("SELECT id, name, name_local FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptStmt->execute([$orgId]);
$departments = $deptStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1"><?= date('d M Y — l', strtotime($event['event_date'])) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h(t('s585')) ?> <strong><?= h($event['dept_name'] ?? 'अद्याप ठरलेले नाही') ?></strong> <?= $event['theme'] ? ' · ' . h($event['theme']) : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canMarkAttendance): ?>
      <a href="attendance.php?id=<?= $eventId ?>" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-clipboard-user me-1"></i>हजेरी नोंदवा</a>
    <?php endif; ?>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i><?= h(t('s611')) ?></a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($canManage): ?>
  <details class="panel mb-3" style="padding:12px 20px;">
    <summary style="cursor:pointer;font-size:13px;font-weight:600;">✏️ कार्यक्रम बदला / काढा</summary>
    <form method="POST" action="event-save.php" class="mt-3">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <input type="hidden" name="action" value="update">
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label-custom">दिनांक</label><input type="date" name="event_date" class="form-control-custom" value="<?= h($event['event_date']) ?>" required></div>
        <div class="col-md-4"><label class="form-label-custom"><?= h(t('s601')) ?></label>
          <select name="organizing_department_id" class="form-select-custom">
            <option value=""><?= h(t('s602')) ?></option>
            <?php foreach ($departments as $dept): ?><option value="<?= $dept['id'] ?>" <?= (int)$event['organizing_department_id'] === (int)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5"><label class="form-label-custom"><?= h(t('s603')) ?></label><input type="text" name="theme" class="form-control-custom" value="<?= h($event['theme']) ?>"></div>
        <div class="col-12"><label class="form-label-custom"><?= h(t('s604')) ?></label><textarea name="notes" class="form-control-custom" rows="2"><?= h($event['notes']) ?></textarea></div>
      </div>
      <div class="d-flex justify-content-between mt-2">
        <button type="submit" form="satsangDeleteForm" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;">🗑️ कार्यक्रम काढा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 18px;">जतन करा</button>
      </div>
    </form>
    <form method="POST" action="event-save.php" id="satsangDeleteForm" onsubmit="return confirm('हा सत्संग कार्यक्रम, त्याच्या जबाबदाऱ्या व हजेरी काढायची?');">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <input type="hidden" name="action" value="delete">
    </form>
  </details>
<?php endif; ?>

<?php if ($event['notes']): ?>
  <div class="panel mb-3" style="padding:16px 20px;font-size:13px;color:var(--text-dim);"><?= nl2br(h($event['notes'])) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head d-flex justify-content-between align-items-center">
    <div><div class="title"><?= h(t('s612')) ?></div><div class="sub"><?= h(t('s613')) ?></div></div>
  </div>
  <table class="rtable">
    <thead><tr><th>कर्मचारी</th><th>पदनाम</th><th><?= h(t('s588')) ?></th><th>स्थिती</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($duties)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:20px;"><?= h(t('s614')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($duties as $d): ?>
        <tr>
          <td style="font-weight:500;"><?= h($d['staff_name']) ?></td>
          <td><?= h($d['designation'] ?? '—') ?></td>
          <td><?= h($d['duty_description']) ?></td>
          <td>
            <?php if ($d['status'] === 'completed'): ?><span class="status-chip done">पूर्ण</span><?php else: ?><span class="status-chip pending">प्रलंबित</span><?php endif; ?>
            <?php if ($canManage || (int)$d['assigned_to'] === (int)$user['id']): ?>
              <form method="POST" action="duty-save.php" style="display:inline;">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <input type="hidden" name="status" value="<?= $d['status'] === 'completed' ? 'pending' : 'completed' ?>">
                <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 9px;"><?= $d['status'] === 'completed' ? '↺ प्रलंबित' : '✓ पूर्ण झाली' ?></button>
              </form>
            <?php endif; ?>
          </td>
          <?php if ($canManage): ?>
            <td>
              <form method="POST" action="duty-delete.php" style="display:inline;" onsubmit="return confirm('<?= addslashes(t('s639')) ?>');">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11px;padding:4px 10px;"><i class="fa-solid fa-trash"></i></button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($canManage): ?>
    <form method="POST" action="duty-save.php" style="padding:18px 20px;border-top:1px solid var(--line);">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label-custom">कर्मचारी</label>
          <select name="assigned_to" class="form-select-custom" required>
            <?php foreach ($staffPool as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label-custom"><?= h(t('s588')) ?></label>
          <input type="text" name="duty_description" class="form-control-custom" placeholder="उदा. साउंड सिस्टीम व्यवस्था, आसन व्यवस्था, स्वागत" required>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">जोडा</button>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
