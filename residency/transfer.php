<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $occupantId = (int)($_POST['occupant_id'] ?? 0);
    $toLocationId = (int)($_POST['to_location_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    $occStmt = $pdo->prepare("SELECT * FROM residency_occupants WHERE id = ? AND organization_id = ? AND status = 'active'");
    $occStmt->execute([$occupantId, $orgId]);
    $occupant = $occStmt->fetch();

    $toLocStmt = $pdo->prepare("SELECT id FROM maintenance_locations WHERE id = ? AND organization_id = ?");
    $toLocStmt->execute([$toLocationId, $orgId]);

    if ($occupant && $toLocationId && $toLocationId != $occupant['location_id'] && $toLocStmt->fetch()) {
        $pdo->prepare("INSERT INTO residency_room_transfers (occupant_id, from_location_id, to_location_id, transfer_date, reason, transferred_by) VALUES (?, ?, ?, CURDATE(), ?, ?)")
            ->execute([$occupantId, $occupant['location_id'], $toLocationId, $reason, $user['id']]);
        $pdo->prepare("UPDATE residency_occupants SET location_id = ? WHERE id = ?")->execute([$toLocationId, $occupantId]);
        logActivity('निवास-खोली बदलली', 'residency_occupant', $occupantId);
        $_SESSION['flash'] = 'खोली यशस्वीरित्या बदलली गेली.';
    } else {
        $_SESSION['form_error'] = 'कृपया वेगळी Room निवडा.';
    }
    header('Location: transfer.php');
    exit;
}

$pageTitle = 'खोली-बदल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$occStmt = $pdo->prepare("
    SELECT o.*, ml.name AS room_name, b.name AS building_name, u.name AS user_name
    FROM residency_occupants o JOIN maintenance_locations ml ON ml.id = o.location_id LEFT JOIN buildings b ON b.id = ml.building_id LEFT JOIN users u ON u.id = o.user_id
    WHERE o.organization_id = ? AND o.status = 'active' ORDER BY o.name
");
$occStmt->execute([$orgId]);
$occupants = $occStmt->fetchAll();

$locStmt = $pdo->prepare("SELECT ml.*, b.name AS building_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.organization_id = ? AND ml.is_active = 1 ORDER BY b.name, ml.name");
$locStmt->execute([$orgId]);
$locations = $locStmt->fetchAll();

$histStmt = $pdo->prepare("
    SELECT t.*, o.name AS occ_name, fl.name AS from_room, tl.name AS to_room, u.name AS transferred_by_name
    FROM residency_room_transfers t
    JOIN residency_occupants o ON o.id = t.occupant_id
    LEFT JOIN maintenance_locations fl ON fl.id = t.from_location_id
    JOIN maintenance_locations tl ON tl.id = t.to_location_id
    LEFT JOIN users u ON u.id = t.transferred_by
    WHERE tl.organization_id = ? ORDER BY t.transfer_date DESC LIMIT 30
");
$histStmt->execute([$orgId]);
$history = $histStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🔄 खोली-बदल (Room Transfer)</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">व्यक्तीला दुसऱ्या Room मध्ये हलवा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label-custom">व्यक्ती (सध्याची Room)</label>
        <select name="occupant_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($occupants as $o): ?>
            <option value="<?= $o['id'] ?>"><?= h($o['user_name'] ?? $o['name']) ?> — सध्या: <?= h($o['building_name'] ? $o['building_name'] . ' — ' : '') ?><?= h($o['room_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">नवीन Room</label>
        <select name="to_location_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>"><?= h($loc['building_name'] ? $loc['building_name'] . ' — ' : '') ?><?= h($loc['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label-custom">कारण</label><input type="text" name="reason" class="form-control-custom"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">खोली बदला</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📜 खोली-बदलांचा इतिहास</div></div>
  <table class="rtable">
    <thead><tr><th>व्यक्ती</th><th>आधीची Room</th><th>नवीन Room</th><th>कारण</th><th>दिनांक</th></tr></thead>
    <tbody>
      <?php if (empty($history)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही खोली-बदल झालेला नाही</td></tr><?php endif; ?>
      <?php foreach ($history as $h): ?>
        <tr>
          <td style="font-weight:600;"><?= h($h['occ_name']) ?></td>
          <td><?= h($h['from_room'] ?: '—') ?></td>
          <td><?= h($h['to_room']) ?></td>
          <td><?= h($h['reason'] ?: '—') ?></td>
          <td><?= date('d M Y', strtotime($h['transfer_date'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
