<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId = (int)($_POST['location_id'] ?? 0);
    $inspectionDate = ($_POST['inspection_date'] ?? '') ?: date('Y-m-d');
    $conditionRating = $_POST['condition_rating'] ?? 'good';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($locationId) {
        $pdo->prepare("INSERT INTO residency_inspections (organization_id, location_id, inspection_date, condition_rating, remarks, inspected_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $locationId, $inspectionDate, $conditionRating, $remarks, $user['id']]);
        logActivity('खोली-तपासणी नोंदवली', 'residency_inspection', $pdo->lastInsertId());
        $_SESSION['flash'] = 'तपासणी-नोंद जतन झाली.';
    }
    header('Location: inspections.php');
    exit;
}

$pageTitle = 'खोली-तपासणी अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$locStmt = $pdo->prepare("SELECT ml.*, b.name AS building_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.organization_id = ? AND ml.is_active = 1 AND ml.is_residential = 1 ORDER BY b.name, ml.name");
$locStmt->execute([$orgId]);
$locations = $locStmt->fetchAll();

$inspStmt = $pdo->prepare("
    SELECT i.*, ml.name AS room_name, b.name AS building_name, u.name AS inspected_by_name
    FROM residency_inspections i JOIN maintenance_locations ml ON ml.id = i.location_id LEFT JOIN buildings b ON b.id = ml.building_id LEFT JOIN users u ON u.id = i.inspected_by
    WHERE i.organization_id = ? ORDER BY i.inspection_date DESC LIMIT 50
");
$inspStmt->execute([$orgId]);
$inspections = $inspStmt->fetchAll();

$ratingLabels = ['excellent' => '🟢 उत्तम', 'good' => '🟢 चांगली', 'fair' => '🟡 ठीक', 'poor' => '🔴 वाईट'];
$ratingChips = ['excellent' => 'done', 'good' => 'done', 'fair' => 'pending', 'poor' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🔍 खोली-तपासणी अहवाल</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन तपासणी नोंदवा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">Room</label>
        <select name="location_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>"><?= h($loc['building_name'] ? $loc['building_name'] . ' — ' : '') ?><?= h($loc['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label-custom">तपासणी दिनांक</label><input type="date" name="inspection_date" class="form-control-custom" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-3">
        <label class="form-label-custom">स्थिती</label>
        <select name="condition_rating" class="form-select-custom">
          <option value="excellent">🟢 उत्तम</option><option value="good" selected>🟢 चांगली</option><option value="fair">🟡 ठीक</option><option value="poor">🔴 वाईट</option>
        </select>
      </div>
    </div>
    <label class="form-label-custom mt-2">टिप्पणी</label>
    <textarea name="remarks" class="form-control-custom" rows="2" placeholder="उदा. भिंतीला ओल आली आहे, दुरुस्ती आवश्यक"></textarea>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">तपासणी नोंदवा</button>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Room</th><th>दिनांक</th><th>स्थिती</th><th>टिप्पणी</th><th>कोणी तपासलं</th></tr></thead>
    <tbody>
      <?php if (empty($inspections)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही तपासणी नोंदवलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($inspections as $i): ?>
        <tr>
          <td style="font-weight:600;"><?= h($i['building_name'] ? $i['building_name'] . ' — ' : '') ?><?= h($i['room_name']) ?></td>
          <td><?= date('d M Y', strtotime($i['inspection_date'])) ?></td>
          <td><span class="status-chip <?= $ratingChips[$i['condition_rating']] ?>"><?= $ratingLabels[$i['condition_rating']] ?></span></td>
          <td style="max-width:220px;"><?= h($i['remarks'] ?: '—') ?></td>
          <td><?= h($i['inspected_by_name']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
