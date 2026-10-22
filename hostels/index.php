<?php
$pageTitle = 'वसतिगृह व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_hostels', '../');

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT h.*, c.name AS campus_name,
      (SELECT COUNT(*) FROM hostel_students s WHERE s.hostel_id = h.id AND s.is_active = 1) AS student_count,
      (SELECT COUNT(*) FROM hostel_staff hs WHERE hs.hostel_id = h.id) AS staff_count
    FROM hostels h
    LEFT JOIN campuses c ON c.id = h.campus_id
    WHERE h.organization_id = ?
    ORDER BY h.name
");
$stmt->execute([$orgId]);
$hostels = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s192')) ?></div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="room-allocation.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-bed me-1"></i>🛏️ Room वाटप</a>
    <a href="fees.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-money-bill me-1"></i>💰 Fee</a>
    <a href="health.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-briefcase-medical me-1"></i>🏥 आरोग्य</a>
    <a href="leave.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-door-open me-1"></i>🚪 सुट्टी-नोंद</a>
    <a href="alumni.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;"><i class="fa-solid fa-graduation-cap me-1"></i>🎓 Alumni</a>
    <a href="bulk-import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;">
      <i class="fa-solid fa-file-csv me-1"></i>वसतिगृह CSV आयात
    </a>
    <a href="student-import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;">
      <i class="fa-solid fa-file-csv me-1"></i>विद्यार्थी CSV आयात
    </a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i><?= h(t('s198')) ?>
    </a>
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
    <thead><tr><th><?= h(t('s37')) ?></th><th><?= h(t('s8')) ?></th><th><?= h(t('s24')) ?></th><th><?= h(t('s25')) ?></th><th><?= h(t('s80')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($hostels)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s81')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($hostels as $h): ?>
        <tr>
          <td style="font-weight:500;"><?= h($h['name']) ?></td>
          <td><?= h($h['campus_name'] ?? '—') ?></td>
          <td class="font-mono"><?= $h['capacity'] ?: '—' ?></td>
          <td class="font-mono"><?= $h['student_count'] ?></td>
          <td class="font-mono"><?= $h['staff_count'] ?></td>
          <td><?php if ($h['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td>
            <a href="dashboard.php?id=<?= $h['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><?= h(t('s193')) ?></a>
            <a href="edit.php?id=<?= $h['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <?php if (hasRole('super_admin')): ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;"
                data-bs-toggle="modal" data-bs-target="#deleteModal<?= $h['id'] ?>"><i class="fa-solid fa-trash me-1"></i>Delete</button>
            <?php endif; ?>
          </td>
        </tr>

        <?php if (hasRole('super_admin')): ?>
        <div class="modal fade" id="deleteModal<?= $h['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="delete.php">
                <input type="hidden" name="id" value="<?= $h['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;color:var(--rose);margin-bottom:10px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>'<?= h($h['name']) ?>' कायमचे डिलीट करायचे?
                  </div>
                  <p style="font-size:13px;color:var(--text-dim);">
                    यामुळे या वसतिगृहाचे <strong><?= $h['student_count'] ?> <?= h(t('s25')) ?></strong> <?= h(t('s194')) ?> <strong><?= $h['staff_count'] ?> <?= h(t('s79')) ?></strong> नोंदी कायमच्या डिलीट होतील. ही क्रिया परत करता येणार नाही.
                  </p>
                  <label class="form-label-custom"><?= h(t('s195')) ?> <span class="font-mono" style="color:var(--rose);"><?= h($h['name']) ?></span></label>
                  <input type="text" name="confirm_name" class="form-control-custom" required>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;"><?= h(t('s3')) ?></button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;"><?= h(t('s82')) ?></button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
