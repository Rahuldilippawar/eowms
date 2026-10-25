<?php
$pageTitle = 'कर्मचारी सूची';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// 5-Tier Access: स्वतःचा विभाग → अनेक विभाग → एक कॉलेज → अनेक कॉलेज →
// संपूर्ण संस्था
$visibleDeptIds = getVisibleDepartmentIdsForStaffTracking();
$canSeeList = !($visibleDeptIds !== null && empty($visibleDeptIds));
$orgId = activeOrgId();

// 🌳 Tree View (College/School → विभाग → कर्मचारी) — पूर्वीची "Trust Directory".
// हे सगळ्या Login असलेल्या कर्मचाऱ्यांसाठी आहे — पण फक्त नाव, हुद्दा, विभाग, फोन
// एवढीच माहिती; पूर्ण यादी (Email/Role/Status/Edit) वरच्या Tiered नियमांनुसारच.
$staffView = (($_GET['view'] ?? '') === 'tree' || !$canSeeList) ? 'tree' : 'list';
$viewTabs = function ($active) use ($canSeeList) {
    $html = '<div class="period-toggle mb-3" style="width:fit-content;">';
    if ($canSeeList) { $html .= '<a href="index.php" class="period-btn ' . ($active === 'list' ? 'active' : '') . '">📋 यादी</a>'; }
    $html .= '<a href="index.php?view=tree" class="period-btn ' . ($active === 'tree' ? 'active' : '') . '">🌳 Tree View</a></div>';
    return $html;
};

if ($staffView === 'tree') {
    $campusStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
    $campusStmt->execute([$orgId]);
    $treeCampuses = $campusStmt->fetchAll();

    $deptStmt = $pdo->prepare("SELECT d.id, d.campus_id, d.name, d.name_local, c.college_type FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY d.name_local");
    $deptStmt->execute([$orgId]);
    $treeDepts = $deptStmt->fetchAll();

    $staffStmt = $pdo->prepare("SELECT id, name, designation, mobile, department_id FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $staffStmt->execute([$orgId]);
    $staffByDept = [];
    foreach ($staffStmt->fetchAll() as $s) { $staffByDept[(int)$s['department_id']][] = $s; }

    // Trust-स्तरीय विभाग (campus_id NULL, किंवा Trust HQ Campus खालचे) — वेगळा गट
    $groups = [];
    $trustDepts = array_filter($treeDepts, fn($d) => $d['campus_id'] === null || $d['college_type'] === 'Trust HQ');
    if ($trustDepts) { $groups[] = ['key' => 'trust', 'title' => '🏛️ Trust-स्तरीय विभाग', 'depts' => $trustDepts]; }
    foreach ($treeCampuses as $c) {
        if ($c['college_type'] === 'Trust HQ') continue;
        $groups[] = ['key' => 'campus-' . $c['id'], 'title' => '🏫 ' . $c['name'], 'depts' => array_filter($treeDepts, fn($d) => (int)$d['campus_id'] === (int)$c['id'])];
    }
    $noDeptStaff = $staffByDept[0] ?? [];
    ?>
    <div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
    <div class="page-title mb-1">📇 कर्मचारी — Tree View</div>
    <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">🏛️ <?= h(activeOrgName()) ?> अंतर्गत सर्व School/College → विभाग → कर्मचारी</p>
    <?= $viewTabs('tree') ?>

    <div class="panel mb-3"><div style="padding:14px 20px;"><input type="text" id="treeSearch" class="form-control-custom" placeholder="🔍 नाव, हुद्दा किंवा विभाग टाइप करा..."></div></div>

    <div id="staffTree">
      <?php foreach ($groups as $g): $count = 0; foreach ($g['depts'] as $d) { $count += count($staffByDept[(int)$d['id']] ?? []); } ?>
        <div class="panel mb-3 tree-group">
          <div class="panel-head" style="cursor:pointer;" onclick="toggleTreeNode('<?= $g['key'] ?>')">
            <div class="title"><?= h($g['title']) ?> <span style="font-size:11px;color:var(--text-dim);font-weight:400;">(<?= count($g['depts']) ?> विभाग · <?= $count ?> कर्मचारी)</span></div>
          </div>
          <div id="<?= $g['key'] ?>" class="tree-body" style="display:none;">
            <?php if (empty($g['depts'])): ?><div style="padding:14px 20px;color:var(--text-dim);font-size:12.5px;">या College/School मध्ये अजून कोणताही विभाग नाही</div><?php endif; ?>
            <?php foreach ($g['depts'] as $d): $members = $staffByDept[(int)$d['id']] ?? []; $deptName = $d['name_local'] ?: $d['name']; ?>
              <div class="tree-dept" style="padding:12px 20px;border-top:1px solid var(--line);">
                <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="var l=this.nextElementSibling;l.style.display=l.style.display==='none'?'block':'none';">
                  <span style="font-weight:600;font-size:13.5px;"><?= h($deptName) ?></span>
                  <span style="font-size:11px;color:var(--text-dim);"><?= count($members) ?> कर्मचारी ▾</span>
                </div>
                <div class="tree-staff" style="display:none;margin-top:8px;">
                  <?php if (empty($members)): ?><div style="font-size:12px;color:#999;">या विभागात कोणीही कर्मचारी नाही</div><?php endif; ?>
                  <?php foreach ($members as $s): ?>
                    <div class="tree-person" data-search="<?= h(mb_strtolower($s['name'] . ' ' . $s['designation'] . ' ' . $deptName)) ?>" style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:12.5px;">
                      <span style="width:26px;height:26px;border-radius:50%;background:var(--rail);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= h(mb_substr($s['name'], 0, 2)) ?></span>
                      <span><?= h($s['name']) ?><?php if ($s['designation']): ?> <span style="color:#999;">— <?= h($s['designation']) ?></span><?php endif; ?>
                        <span style="color:#999;font-size:11px;"> · <?= h($deptName) ?></span>
                        <?php if (!empty($s['mobile'])): ?> <a href="tel:<?= h($s['mobile']) ?>" style="color:var(--rail);font-size:11.5px;text-decoration:none;">📞 <?= h($s['mobile']) ?></a><?php endif; ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <script>
    function toggleTreeNode(id) { var el = document.getElementById(id); el.style.display = el.style.display === 'none' ? 'block' : 'none'; }
    document.getElementById('treeSearch').addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      document.querySelectorAll('#staffTree .tree-group').forEach(function (g) {
        var groupHit = false;
        g.querySelectorAll('.tree-dept').forEach(function (d) {
          var deptHit = false;
          d.querySelectorAll('.tree-person').forEach(function (p) {
            var hit = q.length < 2 || p.dataset.search.indexOf(q) !== -1;
            p.style.display = hit ? 'flex' : 'none';
            if (hit) deptHit = true;
          });
          d.style.display = (q.length < 2 || deptHit) ? '' : 'none';
          d.querySelector('.tree-staff').style.display = (q.length >= 2 && deptHit) ? 'block' : 'none';
          if (deptHit) groupHit = true;
        });
        g.style.display = (q.length < 2 || groupHit) ? '' : 'none';
        g.querySelector('.tree-body').style.display = (q.length >= 2 && groupHit) ? 'block' : 'none';
      });
    });
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
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
<?= $viewTabs('list') ?>

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
          <option value="<?= $dept['id'] ?>" <?= (string)$requestedDeptId === (string)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
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
