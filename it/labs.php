<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $labName = trim($_POST['lab_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $capacity = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;
    $assistantId = ($_POST['assistant_id'] ?? '') !== '' ? (int)$_POST['assistant_id'] : null;

    if ($labName) {
        $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'IT' LIMIT 1");
        $deptStmt->execute([$orgId]);
        $deptId = $deptStmt->fetch()['id'] ?? null;

        $pdo->prepare("INSERT INTO it_labs (organization_id, department_id, lab_name, location, capacity, assistant_id) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $deptId, $labName, $location, $capacity, $assistantId]);
        logActivity('IT Lab जोडली', 'it_lab', $pdo->lastInsertId(), $labName);
        $_SESSION['flash'] = "'$labName' Lab जोडली गेली.";
    }
    header('Location: labs.php');
    exit;
}

$pageTitle = 'IT Labs';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$labsStmt = $pdo->prepare("
    SELECT l.*, u.name AS assistant_name, (SELECT COUNT(*) FROM it_assets WHERE lab_id = l.id) AS asset_count,
      (SELECT COUNT(*) FROM it_assets WHERE lab_id = l.id AND power_status = 'on') AS power_on_count
    FROM it_labs l LEFT JOIN users u ON u.id = l.assistant_id
    WHERE l.organization_id = ? ORDER BY l.lab_name
");
$labsStmt->execute([$orgId]);
$labs = $labsStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$staffPool = $staffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏫 IT Labs</div>
  <div class="d-flex gap-2">
    <a href="import.php" style="font-size:12px;color:var(--amber);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-file-import me-1"></i>Lab/Computer Import करा</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>IT विभाग</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Lab जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">Lab चं नाव</label><input type="text" name="lab_name" class="form-control-custom" placeholder="उदा. Computer Lab 1" required></div>
      <div class="col-md-3"><label class="form-label-custom">ठिकाण</label><input type="text" name="location" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">क्षमता</label><input type="number" name="capacity" class="form-control-custom" placeholder="40"></div>
      <div class="col-md-4">
        <label class="form-label-custom">Assistant नेमा</label>
        <select name="assistant_id" class="form-select-custom">
          <option value="">-- नंतर नेमा --</option>
          <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Lab जोडा</button>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Lab</th><th>ठिकाण</th><th>क्षमता</th><th>Assistant</th><th>Assets</th><th>⚡ चालू</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($labs)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतीही Lab नाही</td></tr><?php endif; ?>
      <?php foreach ($labs as $l): ?>
        <tr>
          <td style="font-weight:600;"><?= h($l['lab_name']) ?></td>
          <td><?= h($l['location']) ?></td>
          <td><?= $l['capacity'] ?: '—' ?></td>
          <td><?= h($l['assistant_name'] ?? '— नेमलेला नाही —') ?></td>
          <td><?= $l['asset_count'] ?></td>
          <td><span class="status-chip <?= $l['power_on_count'] > 0 ? 'done' : 'overdue' ?>">🟢 <?= $l['power_on_count'] ?>/<?= $l['asset_count'] ?></span></td>
          <td><a href="assets.php?lab_id=<?= $l['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;">Assets बघा →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
