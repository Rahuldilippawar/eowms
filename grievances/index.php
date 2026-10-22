<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'grievances', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_grievances') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit') {
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        if ($description) {
            $slaDeadline = date('Y-m-d', strtotime('+7 days'));
            $pdo->prepare("INSERT INTO grievances (organization_id, submitted_by, category, description, is_anonymous, sla_deadline) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $user['id'], $category ?: null, $description, $isAnonymous, $slaDeadline]);
            $_SESSION['flash'] = '✅ तुमची तक्रार नोंदवली गेली — 7 दिवसात उत्तर मिळेल.';
        }
    } elseif ($action === 'update_status' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'open';
        $resolution = trim($_POST['resolution'] ?? '');
        $assignedTo = ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null;
        $resolvedAt = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE grievances SET status = ?, resolution = ?, assigned_to = ?, resolved_at = ? WHERE id = ? AND organization_id = ?")
            ->execute([$status, $resolution ?: null, $assignedTo, $resolvedAt, $id, $orgId]);

        $submitterStmt = $pdo->prepare("SELECT submitted_by, is_anonymous FROM grievances WHERE id = ?");
        $submitterStmt->execute([$id]);
        $g = $submitterStmt->fetch();
        if ($g && !$g['is_anonymous']) {
            notifyUser($g['submitted_by'], $orgId, '📋 तुमच्या तक्रारीवर Update', "स्थिती: $status", 'grievances/index.php');
        }
        $_SESSION['flash'] = 'Update झालं.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'तक्रार निवारण';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

if ($canManage) {
    $grievancesStmt = $pdo->prepare("
        SELECT g.*, u.name AS submitter_name, a.name AS assigned_name
        FROM grievances g LEFT JOIN users u ON u.id = g.submitted_by LEFT JOIN users a ON a.id = g.assigned_to
        WHERE g.organization_id = ? ORDER BY FIELD(g.status,'open','in_progress','resolved','closed'), g.created_at DESC
    ");
    $grievancesStmt->execute([$orgId]);
} else {
    $grievancesStmt = $pdo->prepare("SELECT * FROM grievances WHERE submitted_by = ? ORDER BY created_at DESC");
    $grievancesStmt->execute([$user['id']]);
}
$grievances = $grievancesStmt->fetchAll();

$openCount = count(array_filter($grievances, fn($g) => in_array($g['status'], ['open', 'in_progress'], true)));
$overdueCount = count(array_filter($grievances, fn($g) => in_array($g['status'], ['open', 'in_progress'], true) && $g['sla_deadline'] && strtotime($g['sla_deadline']) < time()));

if ($canManage) {
    $staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $staffStmt->execute([$orgId]);
    $allStaff = $staffStmt->fetchAll();
}

$statusLabels = ['open' => '🔴 नवीन', 'in_progress' => '🟡 सुरू आहे', 'resolved' => '✅ सोडवलं', 'closed' => '⚪ बंद'];
$statusChips = ['open' => 'overdue', 'in_progress' => 'pending', 'resolved' => 'done', 'closed' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎫 तक्रार निवारण <?= !$canManage ? '— माझ्या' : '' ?></div>
  <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#submitModal">+ नवीन तक्रार</button>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Anonymous Option उपलब्ध — 7 दिवसांत उत्तराची हमी</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण</div><div class="val"><?= count($grievances) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 Open</div><div class="val" style="color:var(--amber);"><?= $openCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ SLA चुकलेल्या</div><div class="val" style="color:<?= $overdueCount > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $overdueCount ?></div></div></div>
</div>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($grievances)): ?><div class="col-12"><div class="panel"><div class="empty-state"><div class="icon"><i class="fa-solid fa-gavel"></i></div><div class="title">कुठलीही तक्रार नाही</div></div></div></div><?php endif; ?>
  <?php foreach ($grievances as $g):
    $isOverdue = in_array($g['status'], ['open', 'in_progress'], true) && $g['sla_deadline'] && strtotime($g['sla_deadline']) < time();
  ?>
    <div class="col-md-6">
      <div class="panel" style="padding:18px;<?= $isOverdue ? 'border-left:3px solid var(--rose);' : '' ?>">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <span class="status-chip <?= $statusChips[$g['status']] ?>"><?= $statusLabels[$g['status']] ?></span>
          <span style="font-size:10.5px;color:var(--text-dim);"><?= date('d M Y', strtotime($g['created_at'])) ?></span>
        </div>
        <?php if ($g['category']): ?><div style="font-size:11px;color:var(--rail);font-weight:600;margin-bottom:4px;"><?= h($g['category']) ?></div><?php endif; ?>
        <div style="font-size:13px;"><?= h($g['description']) ?></div>
        <?php if ($canManage): ?>
          <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">👤 <?= $g['is_anonymous'] ? 'Anonymous' : h($g['submitter_name']) ?><?= $g['assigned_name'] ? ' · नेमलं: ' . h($g['assigned_name']) : '' ?></div>
        <?php endif; ?>
        <?php if ($g['resolution']): ?><div style="font-size:12px;background:var(--canvas);border-radius:8px;padding:8px 12px;margin-top:8px;"><strong>Resolution:</strong> <?= h($g['resolution']) ?></div><?php endif; ?>
        <?php if ($canManage): ?>
          <button type="button" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#updateModal"
            onclick="document.getElementById('updId').value='<?= $g['id'] ?>';document.getElementById('updStatus').value='<?= $g['status'] ?>';document.getElementById('updAssigned').value='<?= $g['assigned_to'] ?? '' ?>';document.getElementById('updResolution').value=<?= json_encode($g['resolution'] ?? '') ?>;">
            स्थिती बदला
          </button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="submitModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="submit">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन तक्रार नोंदवा</div>
        <label class="form-label-custom">प्रकार (ऐच्छिक)</label>
        <input type="text" name="category" class="form-control-custom mb-2" placeholder="उदा. Workplace, Facilities, HR">
        <label class="form-label-custom">तपशील</label>
        <textarea name="description" class="form-control-custom mb-2" rows="4" required></textarea>
        <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_anonymous" style="width:16px;height:16px;"> गुप्त ठेवा (माझं नाव कोणालाही दिसणार नाही)</label>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">पाठवा</button>
      </div>
    </form>
  </div></div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="updateModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" id="updId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">स्थिती Update करा</div>
        <label class="form-label-custom">स्थिती</label>
        <select name="status" id="updStatus" class="form-select-custom mb-2">
          <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">कोणाला नेमून द्यायचं</label>
        <select name="assigned_to" id="updAssigned" class="form-select-custom mb-2">
          <option value="">-- कोणीच नाही --</option>
          <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">Resolution (काय केलं)</label>
        <textarea name="resolution" id="updResolution" class="form-control-custom" rows="3"></textarea>
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
