<?php
$pageTitle = 'वाहन व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT v.*, u.name AS driver_name, c.name AS campus_name,
      (SELECT COALESCE(SUM(distance_km),0) FROM vehicle_trips WHERE vehicle_id = v.id) AS total_km,
      (SELECT COALESCE(SUM(liters),0) FROM vehicle_fuel_log WHERE vehicle_id = v.id) AS total_liters
    FROM vehicles v
    LEFT JOIN users u ON u.id = v.driver_id
    LEFT JOIN campuses c ON c.id = v.campus_id
    WHERE v.organization_id = ?
    ORDER BY v.vehicle_number
");
$stmt->execute([$orgId]);
$vehicles = $stmt->fetchAll();

$fuelTypeLabels = ['petrol' => 'पेट्रोल', 'diesel' => 'डिझेल', 'cng' => 'सीएनजी', 'electric' => 'इलेक्ट्रिक', 'other' => 'इतर'];

$pendingReqCount = 0;
try {
    $prStmt = $pdo->prepare("SELECT COUNT(*) c FROM vehicle_correction_requests r JOIN vehicles v ON v.id = r.vehicle_id WHERE v.organization_id = ? AND r.status = 'pending'");
    $prStmt->execute([$orgId]);
    $pendingReqCount = (int)$prStmt->fetch()['c'];
} catch (PDOException $e) {}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🚐 वाहन व्यवस्थापन</div>
  <div class="d-flex gap-2">
    <a href="requests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;position:relative;">
      <i class="fa-solid fa-flag me-1"></i>सुधारणा विनंत्या
      <?php if ($pendingReqCount > 0): ?><span style="background:var(--rose);color:#fff;border-radius:20px;font-size:10.5px;padding:1px 7px;margin-left:4px;"><?= $pendingReqCount ?></span><?php endif; ?>
    </a>
    <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-import me-1"></i>Import</a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन वाहन जोडा</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>वाहन क्रमांक</th><th>प्रकार</th><th>चालक</th><th>इंधन</th><th>सध्याचे मीटर</th><th>सरासरी (km/l)</th><th>स्थिती</th><th>नूतनीकरण</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($vehicles)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;">कोणतेही वाहन अजून जोडलेले नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($vehicles as $v):
        $avg = $v['total_liters'] > 0 ? round($v['total_km'] / $v['total_liters'], 1) : null;
        $renewalCols = ['permit_validity', 'puc_validity', 'insurance_validity', 'fitness_validity', 'road_tax_validity', 'green_tax_validity', 'mv_tax_validity'];
        $expiredCount = 0; $dueSoonCount = 0;
        foreach ($renewalCols as $rc) {
            if (empty($v[$rc])) continue;
            $daysLeft = (int)((strtotime($v[$rc]) - time()) / 86400);
            if ($daysLeft < 0) { $expiredCount++; }
            elseif ($daysLeft <= 30) { $dueSoonCount++; }
        }
      ?>
        <tr style="cursor:pointer;" onclick="window.location='dashboard.php?id=<?= $v['id'] ?>'">
          <td style="font-weight:600;font-family:'JetBrains Mono',monospace;"><?= h($v['vehicle_number']) ?></td>
          <td><?= h($v['vehicle_type'] ?? '—') ?> <?= $v['model'] ? '(' . h($v['model']) . ')' : '' ?></td>
          <td><?= h($v['driver_name'] ?? '— नेमलेला नाही —') ?></td>
          <td><?= h($fuelTypeLabels[$v['fuel_type']] ?? $v['fuel_type']) ?></td>
          <td class="font-mono"><?= number_format($v['current_odometer'], 1) ?> km</td>
          <td class="font-mono"><?= $avg ? $avg . ' km/l' : '—' ?></td>
          <td><?php if ($v['is_active']): ?><span class="status-chip done">सक्रिय</span><?php else: ?><span class="status-chip overdue">निष्क्रिय</span><?php endif; ?></td>
          <td>
            <?php if ($expiredCount > 0): ?>
              <span class="status-chip overdue">⚠️ <?= $expiredCount ?> कालबाह्य</span>
            <?php elseif ($dueSoonCount > 0): ?>
              <span class="status-chip pending">🟡 <?= $dueSoonCount ?> लवकरच</span>
            <?php else: ?>
              <span class="status-chip done">✅ ठीक</span>
            <?php endif; ?>
          </td>
          <td onclick="event.stopPropagation()">
            <a href="edit.php?id=<?= $v['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;"
              data-bs-toggle="modal" data-bs-target="#deleteModal<?= $v['id'] ?>"><i class="fa-solid fa-trash me-1"></i>Delete</button>
          </td>
        </tr>

        <div class="modal fade" id="deleteModal<?= $v['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="delete.php">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;color:var(--rose);margin-bottom:10px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>'<?= h($v['vehicle_number']) ?>' कायमचे डिलीट करायचे?
                  </div>
                  <p style="font-size:13px;color:var(--text-dim);">
                    यामुळे या वाहनाच्या सर्व <strong>ट्रिप, इंधन आणि देखभाल नोंदी</strong> कायमच्या डिलीट होतील. ही क्रिया परत करता येणार नाही.
                  </p>
                  <label class="form-label-custom">पुष्टीसाठी वाहन क्रमांक टाइप करा: <span class="font-mono" style="color:var(--rose);"><?= h($v['vehicle_number']) ?></span></label>
                  <input type="text" name="confirm_number" class="form-control-custom" required>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;">कायमचे डिलीट करा</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
