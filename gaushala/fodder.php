<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_fodder_types', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_gaushala') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_type') {
        $name = trim($_POST['name'] ?? '');
        $unit = trim($_POST['unit'] ?? '') ?: 'Kg';
        $reorderLevel = (float)($_POST['reorder_level'] ?? 0);
        $campusId = (int)($_POST['campus_id'] ?? 0);
        if ($name && $campusId) {
            $pdo->prepare("INSERT INTO gaushala_fodder_types (organization_id, campus_id, name, unit, reorder_level, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $name, $unit, $reorderLevel, $user['id']]);
            $_SESSION['flash'] = 'नवीन चारा-प्रकार जोडला गेला.';
        }
    } elseif ($action === 'transaction') {
        $typeId = (int)($_POST['fodder_type_id'] ?? 0);
        $txnType = in_array($_POST['transaction_type'] ?? '', ['in', 'out'], true) ? $_POST['transaction_type'] : 'in';
        $quantity = (float)($_POST['quantity'] ?? 0);
        $txnDate = ($_POST['transaction_date'] ?? '') ?: date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? '') ?: null;

        // Stock एकाच वेळी दोघांनी बदलला तरी चुकू नये — Transaction + Row Lock
        $pdo->beginTransaction();
        $typeCheckStmt = $pdo->prepare("SELECT current_stock FROM gaushala_fodder_types WHERE id = ? AND organization_id = ? FOR UPDATE");
        $typeCheckStmt->execute([$typeId, $orgId]);
        $typeRow = $typeCheckStmt->fetch();

        if ($typeRow && $quantity > 0) {
            if ($txnType === 'out' && $quantity > $typeRow['current_stock']) {
                $_SESSION['flash'] = '⚠️ इतका Stock उपलब्ध नाही.';
            } else {
                $pdo->prepare("INSERT INTO gaushala_fodder_transactions (fodder_type_id, transaction_type, quantity, transaction_date, remarks, recorded_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$typeId, $txnType, $quantity, $txnDate, $remarks, $user['id']]);
                $pdo->prepare("UPDATE gaushala_fodder_types SET current_stock = current_stock " . ($txnType === 'in' ? '+' : '-') . " ? WHERE id = ?")->execute([$quantity, $typeId]);
                $_SESSION['flash'] = 'नोंद जतन झाली.';
            }
        }
        $pdo->commit();
    }
    header('Location: fodder.php');
    exit;
}

$pageTitle = 'चारा व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$typesStmt = $pdo->prepare("SELECT gft.*, c.name AS campus_name FROM gaushala_fodder_types gft LEFT JOIN campuses c ON c.id = gft.campus_id WHERE gft.organization_id = ? ORDER BY gft.name");
$typesStmt->execute([$orgId]);
$fodderTypes = $typesStmt->fetchAll();

$recentStmt = $pdo->prepare("
    SELECT gft.transaction_type, gft.quantity, gft.transaction_date, gft.remarks, gf.name AS fodder_name, gf.unit, u.name AS recorder_name
    FROM gaushala_fodder_transactions gft JOIN gaushala_fodder_types gf ON gf.id = gft.fodder_type_id JOIN users u ON u.id = gft.recorded_by
    WHERE gf.organization_id = ? ORDER BY gft.created_at DESC LIMIT 20
");
$recentStmt->execute([$orgId]);
$recentTxns = $recentStmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🌾 चारा व्यवस्थापन</div>
  <div class="d-flex gap-2">
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
    <?php if ($canManage): ?>
      <button type="button" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;" data-bs-toggle="modal" data-bs-target="#addTypeModal">+ नवीन प्रकार</button>
      <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#txnModal">+ In/Out नोंद</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($fodderTypes as $ft):
    $isLow = $ft['current_stock'] <= $ft['reorder_level'];
  ?>
    <div class="col-md-3 col-6">
      <div class="kpi-mini" style="<?= $isLow ? 'border:1px solid var(--rose);' : '' ?>">
        <div class="lbl"><?= h($ft['name']) ?></div>
        <div class="val" style="color:<?= $isLow ? 'var(--rose)' : 'var(--rail)' ?>;"><?= number_format($ft['current_stock'], 1) ?> <?= h($ft['unit']) ?></div>
        <?php if ($isLow): ?><div style="font-size:10px;color:var(--rose);margin-top:2px;">⚠️ कमी झालाय</div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($fodderTypes)): ?><div class="col-12"><p style="color:var(--text-dim);font-size:13px;">अजून कुठलाही चारा-प्रकार जोडलेला नाही</p></div><?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📋 अलीकडच्या नोंदी</div></div>
  <table class="rtable">
    <thead><tr><th>प्रकार</th><th>In/Out</th><th>प्रमाण</th><th>दिनांक</th><th>नोंद कोणी केली</th><th>टिप्पणी</th></tr></thead>
    <tbody>
      <?php if (empty($recentTxns)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($recentTxns as $t): ?>
        <tr>
          <td style="font-weight:600;"><?= h($t['fodder_name']) ?></td>
          <td><?= $t['transaction_type'] === 'in' ? '🟢 In' : '🔴 Out' ?></td>
          <td class="font-mono"><?= number_format($t['quantity'], 1) ?> <?= h($t['unit']) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
          <td><?= h($t['recorder_name']) ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($t['remarks'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="addTypeModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_type">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन चारा-प्रकार जोडा</div>
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" placeholder="उदा. हिरवा चारा, कडबा, पेंड" required>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">Unit</label><input type="text" name="unit" class="form-control-custom" value="Kg"></div>
          <div class="col-md-6"><label class="form-label-custom">Reorder Level</label><input type="number" step="0.1" name="reorder_level" class="form-control-custom"></div>
        </div>
        <label class="form-label-custom">College/School</label>
        <select name="campus_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="txnModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="transaction">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">In/Out नोंद करा</div>
        <label class="form-label-custom">चारा-प्रकार</label>
        <select name="fodder_type_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($fodderTypes as $ft): ?><option value="<?= $ft['id'] ?>"><?= h($ft['name']) ?> (सध्या <?= number_format($ft['current_stock'], 1) ?> <?= h($ft['unit']) ?>)</option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">प्रकार</label>
        <select name="transaction_type" class="form-select-custom mb-2">
          <option value="in">🟢 In (आलं)</option><option value="out">🔴 Out (वापरलं)</option>
        </select>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">प्रमाण</label><input type="number" step="0.1" name="quantity" class="form-control-custom" required></div>
          <div class="col-md-6"><label class="form-label-custom">दिनांक</label><input type="date" name="transaction_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <label class="form-label-custom">टिप्पणी (ऐच्छिक)</label>
        <input type="text" name="remarks" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
