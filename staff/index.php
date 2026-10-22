<?php
$pageTitle = 'कर्मचारी सूची';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// 5-Tier Access: स्वतःचा विभाग → अनेक विभाग → एक कॉलेज → अनेक कॉलेज →
// संपूर्ण संस्था
$visibleDeptIds = getVisibleDepartmentIdsForStaffTracking();
if ($visibleDeptIds !== null && empty($visibleDeptIds)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s251')) . ' <a href="../index.php">' . h(t('s16')) . '</a></div>');
}

$orgId = activeOrgId();
$requestedDeptId = $_GET['department_id'] ?? '';
$status = $_GET['status'] ?? 'all';

$sql = "SELECT u.*, d.name_local AS dept_name, d.name AS dept_name_en FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ?";
$params = [$orgId];
if ($visibleDeptIds !== null) {
    // Tiered Access — फक्त बघता येण्यायोग्य विभागच दाखवतो. Dropdown मधून
    // विशिष्ट विभाग निवडला असेल, आणि तो Visible List मध्ये असेल — तरच
    // त्यावर आणखी Narrow करतो.
    if ($requestedDeptId && in_array((int)$requestedDeptId, $visibleDeptIds, true)) {
        $sql .= " AND u.department_id = ?"; $params[] = $requestedDeptId;
    } else {
        $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
        $sql .= " AND u.department_id IN ($ph)"; $params = array_merge($params, $visibleDeptIds);
    }
} elseif ($requestedDeptId) {
    $sql .= " AND u.department_id = ?"; $params[] = $requestedDeptId;
}
if ($status === 'active') { $sql .= " AND u.is_active = 1"; }
if ($status === 'inactive') { $sql .= " AND u.is_active = 0"; }
$sql .= " ORDER BY u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll();

$countAll = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id = ?");
$countAll->execute([$orgId]);
$countAll = $countAll->fetch()['c'];
$countActive = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND is_active = 1");
$countActive->execute([$orgId]);
$countActive = $countActive->fetch()['c'];
$countInactive = $countAll - $countActive;

$deptDropdownSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
$deptDropdownParams = [$orgId];
if ($visibleDeptIds !== null) {
    $ph2 = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $deptDropdownSql .= " AND id IN ($ph2)"; $deptDropdownParams = array_merge($deptDropdownParams, $visibleDeptIds);
}
$deptDropdownSql .= " ORDER BY name_local";
$stmt = $pdo->prepare($deptDropdownSql);
$stmt->execute($deptDropdownParams);
$departments = $stmt->fetchAll();
$campusesForCascade = [];
if ($visibleDeptIds === null) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

try {
    $roleLabels = [];
    foreach ($pdo->query("SELECT role_key, label, label_local FROM role_definitions")->fetchAll() as $rd) {
        $roleLabels[$rd['role_key']] = $rd['label_local'] ?: $rd['label'];
    }
} catch (PDOException $e) {
    $roleLabels = [
        'super_admin' => 'Super Admin', 'president' => 'President', 'executive_president' => 'Exec. President',
        'general_manager' => 'General Manager', 'hod' => 'HOD', 'staff' => 'Staff',
    ];
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s252')) ?></div>
  <div class="d-flex gap-2">
    <?php if (isManagementOrAbove() || hasRole('super_admin')): ?>
      <a href="id-cards-bulk.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-id-card me-1"></i>Bulk ID Cards</a>
    <?php endif; ?>
    <?php if (hasRole('super_admin')): ?>
      <a href="extra-access.php" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-unlock-keyhole me-1"></i>🔓 Extra विभाग Access</a>
      <a href="extra-campus-access.php" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-building-columns me-1"></i>🏫 Extra Campus Access</a>
      <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-file-csv me-1"></i>CSV Import</a>
      <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-user-plus me-1"></i><?= h(t('s253')) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s95')) ?></div><div class="val"><?= $countAll ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s4')) ?></div><div class="val" style="color:var(--rail);"><?= $countActive ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s5')) ?></div><div class="val" style="color:var(--rose);"><?= $countInactive ?></div></div></div>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <a href="?status=all" class="period-btn <?= $status === 'all' ? 'active' : '' ?>"><?= h(t('s19')) ?></a>
    <a href="?status=active" class="period-btn <?= $status === 'active' ? 'active' : '' ?>"><?= h(t('s4')) ?></a>
    <a href="?status=inactive" class="period-btn <?= $status === 'inactive' ? 'active' : '' ?>"><?= h(t('s5')) ?></a>
  </div>
  <?php if ($visibleDeptIds === null || count($departments) > 1): ?>
    <form method="GET" class="d-flex gap-2">
      <input type="hidden" name="status" value="<?= h($status) ?>">
      <?php if (count($campusesForCascade) > 1): ?>
        <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_stfIdx(this.value)">
          <option value="">-- College/School --</option>
          <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
        <option value=""><?= h(t('s18')) ?></option>
        <?php foreach ($departments as $dept): ?>
          <option value="<?= $dept['id'] ?>" <?= (string)$filterDeptId === (string)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_stfIdx(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value=""><?= h(t('s18')) ?></option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <table class="rtable">
    <thead>
      <tr><th><?= h(t('s6')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s45')) ?></th><th>Role</th><th>Email</th><th><?= h(t('s2')) ?></th><?php if (hasRole('super_admin')): ?><th></th><?php endif; ?></tr>
    </thead>
    <tbody>
      <?php if (empty($staffList)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s254')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($staffList as $person): ?>
        <tr style="cursor:pointer;" onclick="window.location='profile.php?id=<?= $person['id'] ?>'">
          <td style="font-weight:500;">
            <div class="d-flex align-items-center gap-2">
              <span class="font-mono" style="width:28px;height:28px;border-radius:7px;background:var(--ink-soft);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;font-weight:600;"><?= h(mb_substr($person['name'], 0, 2)) ?></span>
              <?= h($person['name']) ?>
            </div>
          </td>
          <td onclick="event.stopPropagation()">
            <?php if ($person['department_id']): ?>
              <a href="../departments/dashboard.php?id=<?= $person['department_id'] ?>" style="color:var(--rail);font-weight:600;text-decoration:none;"><?= h($person['dept_name'] ?? $person['dept_name_en'] ?? '—') ?></a>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?= h($person['designation'] ?? '—') ?></td>
          <td><span class="status-chip pending"><?= h($roleLabels[$person['role']] ?? $person['role']) ?></span></td>
          <td class="font-mono" style="font-size:12px;"><?= h($person['email']) ?></td>
          <td><?php if ($person['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <?php if (hasRole('super_admin')): ?>
            <td>
              <a href="edit.php?id=<?= $person['id'] ?>" onclick="event.stopPropagation()" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
              <?php if ($person['role'] !== 'super_admin'): ?>
                <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;"
                  data-bs-toggle="modal" data-bs-target="#deleteModal<?= $person['id'] ?>"><i class="fa-solid fa-trash me-1"></i>Delete</button>
              <?php else: ?>
                <span title="Super Admin अकाउंट डिलीट करता येत नाही" style="font-size:11px;color:var(--text-dim);"><i class="fa-solid fa-lock"></i> <?= h(t('s255')) ?></span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>

        <?php if (hasRole('super_admin') && $person['role'] !== 'super_admin'): ?>
        <div class="modal fade" id="deleteModal<?= $person['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="delete.php">
                <input type="hidden" name="id" value="<?= $person['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;color:var(--rose);margin-bottom:10px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>'<?= h($person['name']) ?>' कायमचे डिलीट करायचे?
                  </div>
                  <p style="font-size:13px;color:var(--text-dim);"><?= h(t('s256')) ?></p>
                  <label class="form-label-custom"><?= h(t('s257')) ?> <span class="font-mono" style="color:var(--rose);"><?= h($person['name']) ?></span></label>
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
