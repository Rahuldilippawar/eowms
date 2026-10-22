<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// संगीत विभागाचाच Staff, किंवा 'manage_music' Permission असलेला, किंवा
// Management-श्रेणीतला — यांनाच Instruments/Students/इ. Manage करता येईल.
$musicDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$musicDeptCheck->execute([$user['department_id'] ?? 0]);
$isMusicDept = ($musicDeptCheck->fetch()['code'] ?? '') === 'MUS';
if (!$isMusicDept && !hasPermission('manage_music') && !isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला संगीत विभाग व्यवस्थापित करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['instrument_name'] ?? '');
        $type = trim($_POST['instrument_type'] ?? '');
        $brandModel = trim($_POST['brand_model'] ?? '');
        $purchaseDate = ($_POST['purchase_date'] ?? '') ?: null;
        $purchaseCost = ($_POST['purchase_cost'] ?? '') !== '' ? (float)$_POST['purchase_cost'] : null;

        if ($name) {
            $pdo->prepare("INSERT INTO music_instruments (organization_id, department_id, instrument_name, instrument_type, brand_model, purchase_date, purchase_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $user['department_id'] ?? null, $name, $type, $brandModel, $purchaseDate, $purchaseCost, $user['id']]);
            logActivity('वाद्य जोडलं', 'music_instrument', $pdo->lastInsertId(), $name);
            $_SESSION['flash'] = "\"$name\" यशस्वीरित्या जोडलं गेलं.";
        }
    } elseif ($action === 'log') {
        $instrumentId = (int)($_POST['instrument_id'] ?? 0);
        $logType = $_POST['log_type'] ?? '';
        $holderName = trim($_POST['holder_name'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        $pdo->prepare("INSERT INTO music_instrument_log (instrument_id, action_type, holder_name, remarks, logged_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$instrumentId, $logType, $holderName, $remarks, $user['id']]);

        if ($logType === 'issued') {
            $pdo->prepare("UPDATE music_instruments SET remarks = ? WHERE id = ?")->execute(["सध्या: $holderName कडे", $instrumentId]);
        } elseif ($logType === 'returned') {
            $pdo->prepare("UPDATE music_instruments SET remarks = NULL WHERE id = ?")->execute([$instrumentId]);
        } elseif ($logType === 'tuned') {
            $pdo->prepare("UPDATE music_instruments SET last_tuned_date = CURDATE(), condition_status = 'good' WHERE id = ?")->execute([$instrumentId]);
        } elseif ($logType === 'repaired') {
            $pdo->prepare("UPDATE music_instruments SET condition_status = 'good' WHERE id = ?")->execute([$instrumentId]);
        }
        $_SESSION['flash'] = 'नोंद जतन झाली.';
    } elseif ($action === 'update_status') {
        $instrumentId = (int)($_POST['instrument_id'] ?? 0);
        $status = $_POST['condition_status'] ?? 'good';
        $pdo->prepare("UPDATE music_instruments SET condition_status = ? WHERE id = ?")->execute([$status, $instrumentId]);
        $_SESSION['flash'] = 'स्थिती अद्ययावत केली.';
    }
    header('Location: instruments.php');
    exit;
}

$pageTitle = 'वाद्य-साहित्य Inventory';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("SELECT mi.*, u.name AS holder_name FROM music_instruments mi LEFT JOIN users u ON u.id = mi.current_holder_id WHERE mi.organization_id = ? AND mi.is_active = 1 ORDER BY mi.instrument_name");
$stmt->execute([$orgId]);
$instruments = $stmt->fetchAll();

$statusLabels = ['excellent' => '🟢 उत्तम', 'good' => '🟢 चांगली', 'needs_tuning' => '🟡 Tuning हवी', 'under_repair' => '🟠 दुरुस्तीत', 'damaged' => '🔴 खराब'];
$statusChips = ['excellent' => 'done', 'good' => 'done', 'needs_tuning' => 'pending', 'under_repair' => 'pending', 'damaged' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎻 वाद्य-साहित्य Inventory</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संगीत विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन वाद्य जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="add">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">वाद्याचं नाव</label><input type="text" name="instrument_name" class="form-control-custom" placeholder="उदा. हार्मोनियम 1" required></div>
      <div class="col-md-3"><label class="form-label-custom">प्रकार</label><input type="text" name="instrument_type" class="form-control-custom" placeholder="उदा. सुषिरवाद्य"></div>
      <div class="col-md-3"><label class="form-label-custom">Brand/Model</label><input type="text" name="brand_model" class="form-control-custom"></div>
      <div class="col-md-1"><label class="form-label-custom">खरेदी दिनांक</label><input type="date" name="purchase_date" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">किंमत (₹)</label><input type="number" step="0.01" name="purchase_cost" class="form-control-custom"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">जोडा</button>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>वाद्य</th><th>प्रकार</th><th>स्थिती</th><th>शेवटची Tuning</th><th>सध्या कोणाकडे</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($instruments)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतंही वाद्य जोडलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($instruments as $i): ?>
        <tr>
          <td style="font-weight:600;"><?= h($i['instrument_name']) ?><?php if ($i['brand_model']): ?><div style="font-size:10px;color:var(--text-dim);"><?= h($i['brand_model']) ?></div><?php endif; ?></td>
          <td><?= h($i['instrument_type'] ?: '—') ?></td>
          <td>
            <select name="condition_status" onchange="document.getElementById('statusForm<?= $i['id'] ?>').submit()" form="statusForm<?= $i['id'] ?>" class="form-select-custom" style="width:auto;font-size:11.5px;padding:4px 8px;">
              <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $i['condition_status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select>
            <form method="POST" id="statusForm<?= $i['id'] ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="instrument_id" value="<?= $i['id'] ?>"></form>
          </td>
          <td><?= $i['last_tuned_date'] ? date('d M Y', strtotime($i['last_tuned_date'])) : '—' ?></td>
          <td style="font-size:11.5px;"><?= h($i['remarks'] ?: '—') ?></td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#logModal<?= $i['id'] ?>">नोंद करा</button>
          </td>
        </tr>
        <div class="modal fade" id="logModal<?= $i['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="action" value="log"><input type="hidden" name="instrument_id" value="<?= $i['id'] ?>">
              <div class="modal-body" style="padding:20px;">
                <div style="font-weight:600;margin-bottom:10px;"><?= h($i['instrument_name']) ?></div>
                <label class="form-label-custom">काय नोंदवायचं</label>
                <select name="log_type" class="form-select-custom mb-3">
                  <option value="issued">📤 दिलं (Issued)</option>
                  <option value="returned">📥 परत आलं (Returned)</option>
                  <option value="tuned">🎵 Tuning केली</option>
                  <option value="repaired">🔧 दुरुस्त केली</option>
                </select>
                <label class="form-label-custom">कोणाला/कोणी (Issued असल्यास)</label>
                <input type="text" name="holder_name" class="form-control-custom mb-3">
                <label class="form-label-custom">टिप्पणी</label>
                <textarea name="remarks" class="form-control-custom" rows="2"></textarea>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
