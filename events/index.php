<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_events') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $eventDate = $_POST['event_date'] ?? '';
    $startTime = ($_POST['start_time'] ?? '') ?: null;
    $endTime = ($_POST['end_time'] ?? '') ?: null;
    $venue = trim($_POST['venue'] ?? '') ?: null;
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $responsibleUserId = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;
    $budgetEstimated = ($_POST['budget_estimated'] ?? '') !== '' ? (float)$_POST['budget_estimated'] : null;

    if ($name && $eventDate) {
        $pdo->prepare("
            INSERT INTO events (organization_id, campus_id, name, description, event_date, start_time, end_time, venue, responsible_user_id, budget_estimated, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$orgId, $campusId, $name, $description, $eventDate, $startTime, $endTime, $venue, $responsibleUserId, $budgetEstimated, $user['id']]);
        $newEventId = $pdo->lastInsertId();
        if ($responsibleUserId) {
            notifyUser($responsibleUserId, $orgId, '🎉 नवीन कार्यक्रमाची जबाबदारी', "$name — " . date('d M Y', strtotime($eventDate)), 'events/view.php?id=' . $newEventId);
        }
        logActivity('कार्यक्रम तयार केला', 'events', $newEventId, $name);
        $_SESSION['flash'] = 'कार्यक्रम तयार झाला.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'कार्यक्रम व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$statusFilter = $_GET['status'] ?? 'all';
$sql = "
    SELECT e.*, c.name AS campus_name, u.name AS responsible_name
    FROM events e LEFT JOIN campuses c ON c.id = e.campus_id LEFT JOIN users u ON u.id = e.responsible_user_id
    WHERE e.organization_id = ?
";
$params = [$orgId];
if ($statusFilter !== 'all') { $sql .= " AND e.status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY e.event_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campusesForEvent = $campusesStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaffForEvent = $staffStmt->fetchAll();

// 🗓️ महिन्याचं Calendar (?m=YYYY-MM)
$calMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$calStmt = $pdo->prepare("SELECT id, name, event_date, start_time, status FROM events WHERE organization_id = ? AND event_date BETWEEN ? AND LAST_DAY(?) AND status <> 'cancelled' ORDER BY start_time IS NULL, start_time");
$calStmt->execute([$orgId, $calMonth . '-01', $calMonth . '-01']);
$calByDay = [];
foreach ($calStmt->fetchAll() as $ce) $calByDay[(int)substr($ce['event_date'], 8, 2)][] = $ce;

$statusLabels = ['planned' => '📅 नियोजित', 'ongoing' => '🔴 सुरू आहे', 'completed' => '✅ पूर्ण झाला', 'cancelled' => '❌ रद्द'];
$statusChips = ['planned' => 'pending', 'ongoing' => 'pending', 'completed' => 'done', 'cancelled' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎉 कार्यक्रम व्यवस्थापन</div>
  <div class="d-flex gap-2">
    <a href="export.php?year=<?= h(substr($calMonth, 0, 4)) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 14px;">📥 Excel (<?= h(substr($calMonth, 0, 4)) ?>)</a>
  <?php if ($canManage): ?>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#createEventModal">+ नवीन कार्यक्रम</button>
  <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/insights/events.php'; ?>

<?php
$mrMonths = ['', 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
$firstTs = strtotime($calMonth . '-01'); $daysIn = (int)date('t', $firstTs); $lead = (int)date('N', $firstTs) - 1;
$prevM = date('Y-m', strtotime('-1 month', $firstTs)); $nextM = date('Y-m', strtotime('+1 month', $firstTs));
$keepQ = ['status' => $statusFilter];
?>
<div class="panel mb-3">
  <div class="panel-head d-flex justify-content-between align-items-center">
    <div class="title">🗓️ <?= $mrMonths[(int)date('n', $firstTs)] ?> <?= date('Y', $firstTs) ?></div>
    <div class="d-flex gap-1">
      <a class="ev-nav" href="?<?= h(http_build_query($keepQ + ['m' => $prevM])) ?>">‹</a>
      <?php if ($calMonth !== date('Y-m')): ?><a class="ev-nav" style="font-size:12px;" href="?<?= h(http_build_query($keepQ)) ?>">हा महिना</a><?php endif; ?>
      <a class="ev-nav" href="?<?= h(http_build_query($keepQ + ['m' => $nextM])) ?>">›</a>
    </div>
  </div>
  <div class="ev-cal">
    <?php foreach (['सोम', 'मंगळ', 'बुध', 'गुरु', 'शुक्र', 'शनि', 'रवि'] as $dn): ?><div class="ev-dh"><?= $dn ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="ev-d empty"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysIn; $d++): $isToday = $calMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT) === date('Y-m-d'); ?>
      <div class="ev-d<?= $isToday ? ' today' : '' ?><?= isset($calByDay[$d]) ? ' has' : '' ?>"><span><?= $d ?></span>
        <?php foreach (array_slice($calByDay[$d] ?? [], 0, 3) as $ce): ?><a href="view.php?id=<?= $ce['id'] ?>" class="ev-e st-<?= h($ce['status']) ?>" title="<?= h($ce['name']) ?>"><?= $ce['start_time'] ? substr($ce['start_time'], 0, 5) . ' ' : '' ?><?= h($ce['name']) ?></a><?php endforeach; ?>
        <?php if (count($calByDay[$d] ?? []) > 3): ?><small>+<?= count($calByDay[$d]) - 3 ?></small><?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<style>
.ev-nav{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--text);font-weight:700;padding:0 8px;}
.ev-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;padding:10px 14px 14px;}
.ev-dh{font-size:11px;font-weight:700;color:var(--text-dim);text-align:center;padding:4px 0;}
.ev-d{min-height:78px;border:1px solid var(--line);border-radius:8px;padding:4px;overflow:hidden;font-size:11px;}
.ev-d.empty{border:0;}
.ev-d>span{font-weight:700;font-size:11.5px;color:var(--text-dim);}
.ev-d.today{border-color:var(--amber);box-shadow:inset 0 0 0 1px var(--amber);} .ev-d.today>span{color:var(--amber);}
.ev-e{display:block;margin-top:2px;padding:2px 5px;border-radius:5px;background:color-mix(in srgb,var(--rail) 14%,transparent);color:var(--text);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:10.5px;}
.ev-e.st-completed{background:#DCFCE7;} .ev-e.st-ongoing{background:#FEE2E2;}
@media (max-width:600px){.ev-d{min-height:46px;}.ev-e{font-size:0;height:6px;padding:0;border-radius:3px;background:var(--rail);}.ev-cal{gap:2px;padding:8px;}}
</style>

<div class="d-flex gap-2 mb-4">
  <?php foreach (['all' => 'सगळे'] + $statusLabels as $val => $lbl): ?>
    <a href="?status=<?= $val ?>" class="btn" style="background:<?= $statusFilter === $val ? 'var(--rail)' : '#fff' ?>;color:<?= $statusFilter === $val ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <?php if (empty($events)): ?><div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कुठलाही कार्यक्रम नाही</div></div><?php endif; ?>
  <?php foreach ($events as $e): ?>
    <div class="col-md-4">
      <a href="view.php?id=<?= $e['id'] ?>" class="panel" style="display:block;padding:18px;text-decoration:none;color:inherit;">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <div style="font-weight:700;font-size:14px;"><?= h($e['name']) ?></div>
          <span class="status-chip <?= $statusChips[$e['status']] ?>" style="font-size:10px;"><?= $statusLabels[$e['status']] ?></span>
        </div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:8px;">📅 <?= date('d M Y', strtotime($e['event_date'])) ?><?= $e['venue'] ? ' · 📍 ' . h($e['venue']) : '' ?></div>
        <?php if ($e['responsible_name']): ?><div style="font-size:12px;">👤 <?= h($e['responsible_name']) ?></div><?php endif; ?>
        <?php if ($e['campus_name']): ?><div style="font-size:11px;color:var(--text-dim);margin-top:4px;">🏫 <?= h($e['campus_name']) ?></div><?php endif; ?>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="createEventModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन कार्यक्रम तयार करा</div>
        <label class="form-label-custom">कार्यक्रमाचं नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">वर्णन</label>
        <textarea name="description" class="form-control-custom mb-2" rows="2"></textarea>
        <div class="row g-2 mb-2">
          <div class="col-md-4"><label class="form-label-custom">दिनांक</label><input type="date" name="event_date" class="form-control-custom" required></div>
          <div class="col-md-4"><label class="form-label-custom">सुरू</label><input type="time" name="start_time" class="form-control-custom"></div>
          <div class="col-md-4"><label class="form-label-custom">संपेल</label><input type="time" name="end_time" class="form-control-custom"></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">ठिकाण</label><input type="text" name="venue" class="form-control-custom"></div>
          <div class="col-md-6">
            <label class="form-label-custom">College/School</label>
            <select name="campus_id" class="form-select-custom">
              <option value="">-- सगळ्यांसाठी --</option>
              <?php foreach ($campusesForEvent as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label-custom">जबाबदार व्यक्ती</label>
            <select name="responsible_user_id" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaffForEvent as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label-custom">अंदाजित Budget</label><input type="number" step="0.01" name="budget_estimated" class="form-control-custom"></div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">तयार करा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
