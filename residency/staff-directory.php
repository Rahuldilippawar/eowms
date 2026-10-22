<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$isMgmt = hasPermission('manage_residency_all_depts');
if (!$isMgmt && $userDeptCode !== 'RES') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला रहिवासी विभागाची माहिती पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$pageTitle = 'Staff Directory — निवासी/अनिवासी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$view = $_GET['view'] ?? 'residential';
$groupBy = $_GET['group_by'] ?? 'department'; // department किंवा unit

// निवासी — हे नेहमी थेट `users` Table शीच Join करतो — कुठलीही वेगळी
// Duplicate व्यक्ती-नोंद नाही, फक्त residency_occupants.user_id द्वारे Link.
$residentialStmt = $pdo->prepare("
    SELECT ro.id AS occupant_id, ro.moved_in_date, u.id AS user_id, u.name, u.email, u.mobile, u.designation,
           d.name_local AS dept_name, d.id AS dept_id, b.name AS building_name, ml.name AS room_name, c.name AS campus_name
    FROM residency_occupants ro
    JOIN users u ON u.id = ro.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    JOIN maintenance_locations ml ON ml.id = ro.location_id
    LEFT JOIN buildings b ON b.id = ml.building_id
    LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE ro.organization_id = ? AND ro.occupant_type = 'staff' AND ro.status = 'active' AND ro.user_id IS NOT NULL
    ORDER BY d.name_local, u.name
");
$residentialStmt->execute([$orgId]);
$residentialStaff = $residentialStmt->fetchAll();

// Unlinked — जुन्या Import मधून आलेल्या, अजून `users` शी Link न झालेल्या
// नोंदी (Data-Integrity Gap — यांना Manually Link करायला हवं)
$unlinkedStmt = $pdo->prepare("
    SELECT ro.id, ro.name, ml.name AS room_name, b.name AS building_name
    FROM residency_occupants ro JOIN maintenance_locations ml ON ml.id = ro.location_id LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ro.organization_id = ? AND ro.occupant_type = 'staff' AND ro.status = 'active' AND ro.user_id IS NULL
    ORDER BY ro.name
");
$unlinkedStmt->execute([$orgId]);
$unlinkedOccupants = $unlinkedStmt->fetchAll();

// अनिवासी — जे Staff, Active Residency Occupant List मध्ये नाहीतच
$residentialUserIds = array_column($residentialStaff, 'user_id');
$nonResSql = "
    SELECT u.id AS user_id, u.name, u.email, u.mobile, u.designation, d.name_local AS dept_name, d.id AS dept_id, c.name AS campus_name
    FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.organization_id = ? AND u.is_active = 1
";
$nonResParams = [$orgId];
if (!empty($residentialUserIds)) {
    $ph = implode(',', array_fill(0, count($residentialUserIds), '?'));
    $nonResSql .= " AND u.id NOT IN ($ph)";
    $nonResParams = array_merge($nonResParams, $residentialUserIds);
}
$nonResSql .= " ORDER BY d.name_local, u.name";
$nonResStmt = $pdo->prepare($nonResSql);
$nonResStmt->execute($nonResParams);
$nonResidentialStaff = $nonResStmt->fetchAll();

// Grouping — निवडलेल्या पद्धतीनुसार (विभाग किंवा Building/Unit)
function groupStaffList($list, $groupBy) {
    $grouped = [];
    foreach ($list as $row) {
        $key = $groupBy === 'unit' ? ($row['building_name'] ?? 'इतर') : ($row['dept_name'] ?? 'विभाग नमूद नाही');
        $grouped[$key][] = $row;
    }
    ksort($grouped);
    return $grouped;
}
$groupedResidential = groupStaffList($residentialStaff, $groupBy);
$groupedNonResidential = groupStaffList($nonResidentialStaff, 'department'); // अनिवासींना Unit नसतंच
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📇 Staff Directory — निवासी/अनिवासी</div>
  <div class="d-flex gap-2">
    <button onclick="window.print()" class="btn no-print" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">🖨️ Print/Export</button>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>रहिवासी विभाग</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">प्रत्येक नोंद थेट Staff Master शी Linked — कुठलीही Duplicate Entry नाही.</p>

<?php if (!empty($unlinkedOccupants)): ?>
  <div class="alert" style="background:var(--rose-soft, #F7E2E3);border-radius:10px;font-size:13px;padding:14px 18px;margin-bottom:20px;">
    ⚠️ <strong><?= count($unlinkedOccupants) ?> निवासी नोंदी</strong> अजून Staff Account शी Link झालेल्या नाहीत (जुन्या Import मधून आलेल्या असू शकतात) — त्यामुळे त्यांचा Email/Mobile इथे दिसणार नाही.
    <a href="link-unlinked.php" style="color:#8A1F2B;font-weight:700;text-decoration:underline;">आत्ता Link करा →</a>
  </div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4 no-print">
  <a href="?view=residential&group_by=<?= h($groupBy) ?>" class="btn" style="background:<?= $view === 'residential' ? 'var(--rail)' : '#fff' ?>;color:<?= $view === 'residential' ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">🏠 निवासी (<?= count($residentialStaff) ?>)</a>
  <a href="?view=nonresidential" class="btn" style="background:<?= $view === 'nonresidential' ? 'var(--rail)' : '#fff' ?>;color:<?= $view === 'nonresidential' ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">🚶 अनिवासी (<?= count($nonResidentialStaff) ?>)</a>
  <?php if ($view === 'residential'): ?>
    <select onchange="window.location = '?view=residential&group_by=' + this.value" class="form-select-custom" style="width:auto;margin-left:auto;">
      <option value="department" <?= $groupBy === 'department' ? 'selected' : '' ?>>विभाग-निहाय गटवारी</option>
      <option value="unit" <?= $groupBy === 'unit' ? 'selected' : '' ?>>Building/Unit-निहाय गटवारी</option>
    </select>
  <?php endif; ?>
</div>

<?php
$activeGrouped = $view === 'residential' ? $groupedResidential : $groupedNonResidential;
foreach ($activeGrouped as $groupName => $rows):
?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h($groupName) ?></div><div class="sub"><?= count($rows) ?> जण</div></div>
    <table class="rtable">
      <thead>
        <tr>
          <th>नाव</th><th>पद</th><th>विभाग</th>
          <?php if ($view === 'residential'): ?><th>Building/Room</th><th>राहतो कधीपासून</th><?php endif; ?>
          <th>Mobile</th><th>Email</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="font-weight:600;"><a href="../staff/profile.php?id=<?= $r['user_id'] ?>" style="color:inherit;text-decoration:none;"><?= h($r['name']) ?></a></td>
            <td><?= h($r['designation'] ?: '—') ?></td>
            <td><?= h($r['dept_name'] ?: '—') ?></td>
            <?php if ($view === 'residential'): ?>
              <td><?= h($r['building_name'] ?: '') ?><?= $r['building_name'] && $r['room_name'] ? ' — ' : '' ?><?= h($r['room_name'] ?: '') ?></td>
              <td class="font-mono"><?= date('d M Y', strtotime($r['moved_in_date'])) ?></td>
            <?php endif; ?>
            <td class="font-mono"><?= h($r['mobile'] ?: '—') ?></td>
            <td style="font-size:12px;"><?= h($r['email'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php if (empty($activeGrouped)): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">कुठलाही Staff सापडला नाही.</div>
<?php endif; ?>

<style>@media print { .no-print, .topbar, .sidebar { display:none !important; } }</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
