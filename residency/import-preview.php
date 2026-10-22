<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();
require_once __DIR__ . '/import-engine.php';

if (empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: import.php');
    exit;
}

// अपलोड केलेली File — तात्पुरती, Unique Token सह साठवतो — जेणेकरून
// Preview आणि प्रत्यक्ष Import (Confirm) — दोन्ही पायऱ्यांत तीच File वापरता येईल.
$tempDir = __DIR__ . '/../backups/residency-import-temp';
if (!is_dir($tempDir)) { mkdir($tempDir, 0755, true); }
$token = bin2hex(random_bytes(16));
$tempPath = $tempDir . '/' . $token . '.csv';
move_uploaded_file($_FILES['csv_file']['tmp_name'], $tempPath);
$originalFilename = $_FILES['csv_file']['name'];

$parsed = residencyImportParseCsv($tempPath);
if (isset($parsed['error'])) {
    unlink($tempPath);
    $_SESSION['form_error'] = $parsed['error'];
    header('Location: import.php');
    exit;
}

$preview = residencyImportPreview($pdo, $orgId, $parsed['rows']);

$pageTitle = 'Import Preview';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">👁️ Import Preview — Confirm करण्याआधी तपासा</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">फाईल: <strong><?= h($originalFilename) ?></strong> — अजून काहीही Save झालेलं नाही, फक्त काय होणार आहे ते दाखवत आहोत.</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">नवीन Buildings</div><div class="val" style="color:var(--rail);"><?= count($preview['new_buildings']) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">नवीन Rooms</div><div class="val" style="color:var(--rail);"><?= count($preview['new_rooms']) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण निवासी नोंदी</div><div class="val"><?= $preview['total_occupants'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">वगळलेल्या ओळी</div><div class="val" style="color:<?= count($preview['errors']) > 0 ? 'var(--rose)' : 'inherit' ?>;"><?= count($preview['errors']) ?></div></div></div>
</div>

<?php if (!$preview['campus_id']): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;padding:16px 20px;">
    ⚠️ आधी किमान 1 Campus तयार असणं आवश्यक आहे — Import पुढे नेता येणार नाही.
  </div>
<?php else: ?>

  <?php if (!empty($preview['new_buildings'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">🏢 या नवीन Buildings तयार होतील</div></div>
    <div style="padding:12px 20px;display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach ($preview['new_buildings'] as $b): ?><span class="status-chip done"><?= h($b) ?></span><?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($preview['new_rooms'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">🚪 या नवीन Rooms तयार होतील (<?= count($preview['new_rooms']) ?>)</div></div>
    <div style="padding:12px 20px;max-height:200px;overflow-y:auto;display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach ($preview['new_rooms'] as $r): ?><span class="status-chip pending"><?= h($r['building']) ?> — <?= h($r['room']) ?></span><?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="panel mb-3">
    <div class="panel-head"><div class="title">👤 निवासी नोंदी (<?= count($preview['valid_rows']) ?>)</div></div>
    <table class="rtable">
      <thead><tr><th>Building</th><th>Room</th><th>प्रकार</th><th>नाव</th><th>नातं</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($preview['valid_rows'], 0, 30) as $r): ?>
          <tr>
            <td><?= h($r['building_name']) ?></td>
            <td><?= h($r['room_name']) ?></td>
            <td><?= $r['occupant_type'] === 'staff' ? '👔 Staff' : '👪 कुटुंबीय' ?></td>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['relation'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (count($preview['valid_rows']) > 30): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text-dim);">... आणि <?= count($preview['valid_rows']) - 30 ?> अजून ...</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($preview['errors'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:16px 20px;">
    <strong>⚠️ या ओळी वगळल्या जातील:</strong>
    <ul class="mb-0"><?php foreach ($preview['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <div class="d-flex gap-2 mt-3">
    <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:14px;padding:11px 22px;">← रद्द करा</a>
    <form method="POST" action="import-execute.php">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="filename" value="<?= h($originalFilename) ?>">
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 26px;font-weight:600;">✓ Confirm — Import करा</button>
    </form>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
