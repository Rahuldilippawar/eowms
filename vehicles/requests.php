<?php
$pageTitle = 'सुधारणा विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$status = $_GET['status'] ?? 'pending';

$sql = "
    SELECT r.*, v.vehicle_number, u.name AS requester_name
    FROM vehicle_correction_requests r
    JOIN vehicles v ON v.id = r.vehicle_id
    JOIN users u ON u.id = r.requested_by
    WHERE v.organization_id = ?
";
$params = [$orgId];
if ($status !== 'all') { $sql .= " AND r.status = ?"; $params[] = $status; }
$sql .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$tableMap = ['trip' => 'vehicle_trips', 'fuel' => 'vehicle_fuel_log', 'maintenance' => 'vehicle_maintenance'];
$typeLabels = ['trip' => 'ट्रिप', 'fuel' => 'इंधन नोंद', 'maintenance' => 'देखभाल नोंद'];

// Fetch the actual current entry data for each request, to display inline
foreach ($requests as &$r) {
    $table = $tableMap[$r['entry_type']];
    $es = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $es->execute([$r['entry_id']]);
    $r['entry'] = $es->fetch();
}
unset($r);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">वाहन नोंद सुधारणा विनंत्या</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="period-toggle mb-3">
  <a href="?status=pending" class="period-btn <?= $status === 'pending' ? 'active' : '' ?>">प्रलंबित</a>
  <a href="?status=approved" class="period-btn <?= $status === 'approved' ? 'active' : '' ?>">मंजूर</a>
  <a href="?status=rejected" class="period-btn <?= $status === 'rejected' ? 'active' : '' ?>">नाकारलेल्या</a>
  <a href="?status=all" class="period-btn <?= $status === 'all' ? 'active' : '' ?>">सर्व</a>
</div>

<?php if (empty($requests)): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">कोणतीही विनंती सापडली नाही</div>
<?php endif; ?>

<?php foreach ($requests as $r): $entry = $r['entry']; ?>
  <div class="panel mb-3">
    <div class="panel-head d-flex justify-content-between align-items-center">
      <div>
        <div class="title"><?= h($r['vehicle_number']) ?> — <?= $typeLabels[$r['entry_type']] ?> <?= $r['request_type'] === 'delete' ? '(Delete विनंती)' : '(Edit विनंती)' ?></div>
        <div class="sub"><?= h($r['requester_name']) ?> · <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></div>
      </div>
      <?php
        $statusChip = ['pending' => 'pending', 'approved' => 'done', 'rejected' => 'overdue'];
        $statusLabel = ['pending' => 'प्रलंबित', 'approved' => 'मंजूर', 'rejected' => 'नाकारले'];
      ?>
      <span class="status-chip <?= $statusChip[$r['status']] ?>"><?= $statusLabel[$r['status']] ?></span>
    </div>

    <div style="padding:16px 20px;">
      <div style="background:var(--amber-soft);color:#8A5A14;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px;">
        <i class="fa-solid fa-comment-dots me-1"></i><?= h($r['reason']) ?>
      </div>

      <?php if (!$entry): ?>
        <p style="font-size:13px;color:var(--text-dim);">ही मूळ नोंद आधीच डिलीट झालेली दिसते.</p>
      <?php elseif ($r['status'] === 'pending'): ?>

        <?php if ($r['entry_type'] === 'trip'): ?>
          <form method="POST" action="entry-update.php" class="row g-2">
            <input type="hidden" name="entry_type" value="trip"><input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
            <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $r['vehicle_id'] ?>">
            <div class="col-md-3"><label class="form-label-custom">दिनांक</label><input type="date" name="trip_date" class="form-control-custom" value="<?= $entry['trip_date'] ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">कुठून</label><input type="text" name="from_location" class="form-control-custom" value="<?= h($entry['from_location']) ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">कुठे</label><input type="text" name="to_location" class="form-control-custom" value="<?= h($entry['to_location']) ?>"></div>
            <div class="col-md-1"><label class="form-label-custom">सुरु.</label><input type="number" step="0.1" name="start_odometer" class="form-control-custom" value="<?= $entry['start_odometer'] ?>"></div>
            <div class="col-md-2"><label class="form-label-custom">शेवट</label><input type="number" step="0.1" name="end_odometer" class="form-control-custom" value="<?= $entry['end_odometer'] ?>"></div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-2">
              <button type="submit" name="action" value="reject" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;padding:7px 16px;">नाकारा</button>
              <button type="submit" name="action" value="save" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:7px 16px;">दुरुस्त करा व मंजूर करा</button>
            </div>
          </form>
        <?php elseif ($r['entry_type'] === 'fuel'): ?>
          <form method="POST" action="entry-update.php" class="row g-2">
            <input type="hidden" name="entry_type" value="fuel"><input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
            <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $r['vehicle_id'] ?>">
            <div class="col-md-3"><label class="form-label-custom">दिनांक</label><input type="date" name="fill_date" class="form-control-custom" value="<?= $entry['fill_date'] ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">मीटर</label><input type="number" step="0.1" name="odometer_reading" class="form-control-custom" value="<?= $entry['odometer_reading'] ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">लिटर</label><input type="number" step="0.01" name="liters" class="form-control-custom" value="<?= $entry['liters'] ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">खर्च (₹)</label><input type="number" step="0.01" name="cost" class="form-control-custom" value="<?= $entry['cost'] ?>"></div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-2">
              <button type="submit" name="action" value="reject" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;padding:7px 16px;">नाकारा</button>
              <button type="submit" name="action" value="save" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:7px 16px;">दुरुस्त करा व मंजूर करा</button>
            </div>
          </form>
        <?php else: ?>
          <form method="POST" action="entry-update.php" class="row g-2">
            <input type="hidden" name="entry_type" value="maintenance"><input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
            <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $r['vehicle_id'] ?>">
            <div class="col-md-3"><label class="form-label-custom">दिनांक</label><input type="date" name="maintenance_date" class="form-control-custom" value="<?= $entry['maintenance_date'] ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">प्रकार</label><input type="text" name="type" class="form-control-custom" value="<?= h($entry['type']) ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">खर्च (₹)</label><input type="number" step="0.01" name="cost" class="form-control-custom" value="<?= $entry['cost'] ?>"></div>
            <div class="col-md-3"><label class="form-label-custom">तपशील</label><input type="text" name="description" class="form-control-custom" value="<?= h($entry['description']) ?>"></div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-2">
              <button type="submit" name="action" value="reject" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;padding:7px 16px;">नाकारा</button>
              <button type="submit" name="action" value="save" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:7px 16px;">दुरुस्त करा व मंजूर करा</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($r['request_type'] === 'delete'): ?>
          <form method="POST" action="entry-delete.php" class="d-flex justify-content-end mt-2" onsubmit="return confirm('ही नोंद कायमची डिलीट करायची?');">
            <input type="hidden" name="entry_type" value="<?= $r['entry_type'] ?>"><input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
            <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= $r['vehicle_id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;padding:7px 16px;"><i class="fa-solid fa-trash me-1"></i>विनंतीप्रमाणे नोंद डिलीट करा</button>
          </form>
        <?php endif; ?>

      <?php else: ?>
        <div style="font-size:12px;color:var(--text-dim);">
          <?= h($r['status'] === 'approved' ? 'मंजूर' : 'नाकारले') ?> — <?= $r['reviewed_at'] ? date('d M Y, h:i A', strtotime($r['reviewed_at'])) : '' ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
