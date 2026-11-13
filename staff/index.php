<?php
$pageTitle = 'कर्मचारी सूची';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// 5-Tier Access: स्वतःचा विभाग → अनेक विभाग → एक कॉलेज → अनेक कॉलेज →
// संपूर्ण संस्था
$visibleDeptIds = getVisibleDepartmentIdsForStaffTracking();
require_once __DIR__ . '/../includes/employee-report-access.php';
$canSeeDetails = staffCanSeeColleagueDetails(); // 🔒 साधा कर्मचारी — सहकाऱ्यांचे Mobile/Email/यादी नाही
$canSeeList = !($visibleDeptIds !== null && empty($visibleDeptIds)) && $canSeeDetails;
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

    $deptStmt = $pdo->prepare("SELECT d.id, d.campus_id, d.name, d.name_local, d.hod_id, c.college_type FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY d.name_local");
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
                        <?php if (!empty($s['mobile']) && ($canSeeDetails || (int)$s['id'] === (int)$user['id'] || (int)$s['id'] === (int)($d['hod_id'] ?? 0))): ?> <a href="tel:<?= h($s['mobile']) ?>" style="color:var(--rail);font-size:11.5px;text-decoration:none;">📞 <?= h($s['mobile']) ?></a><?php endif; ?>
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
$sql .= " ORDER BY u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$scopeList = $stmt->fetchAll();   // पाहण्याच्या अधिकारातले (विभाग Filter सह) — Smart आढावा याच्यावरून
// स्थिती Filter PHP मध्ये — आकडे आता संपूर्ण संस्थेचे नव्हे तर तुम्हाला दिसणाऱ्यांचेच
$staffList = array_values(array_filter($scopeList, fn($u) => $status === 'active' ? (int)$u['is_active'] === 1 : ($status === 'inactive' ? (int)$u['is_active'] === 0 : true)));
$countAll = count($scopeList);
$countActive = count(array_filter($scopeList, fn($u) => (int)$u['is_active'] === 1));
$countInactive = $countAll - $countActive;

// ---------- 🧠 Smart आढावा ----------
require_once __DIR__ . '/../includes/app-settings.php';
$retireAge = (int)(orgSetting($pdo, $orgId, 'retirement_age', 58) ?: 58);
$today = new DateTimeImmutable('today');
$profileFields = ['mobile' => 'Mobile', 'date_of_birth' => 'जन्मतारीख', 'joining_date' => 'रुजू दिनांक', 'photo_path' => 'फोटो', 'designation' => 'हुद्दा',
    'department_id' => 'विभाग', 'gender' => 'लिंग', 'emergency_contact_mobile' => 'आपत्कालीन संपर्क', 'aadhar_number' => 'आधार', 'bank_account_no' => 'बँक खाते'];
$nextYearly = function ($date) use ($today) {   // पुढचा वाढदिवस / वर्धापन — किती दिवसांत (29 फेब्रु → 28 फेब्रु)
    if (!$date || $date === '0000-00-00') return null;
    [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
    if (!$y || !$m || !$d) return null;
    $mk = function ($yr) use ($m, $d) { $dd = ($m === 2 && $d === 29 && !checkdate(2, 29, $yr)) ? 28 : $d; return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $yr, $m, $dd)); };
    $n = $mk((int)$today->format('Y'));
    if ($n < $today) $n = $mk((int)$today->format('Y') + 1);
    return ['days' => (int)$today->diff($n)->days, 'years' => (int)$n->format('Y') - $y];
};
$smart = ['new' => [], 'nologin' => [], 'incomplete' => [], 'bday' => [], 'anniv' => [], 'retire' => [], 'nodept' => [], 'leftActive' => []];
$mobiles = []; $deptCount = []; $typeCount = []; $genderCount = [];
foreach ($scopeList as $i => $u) {
    $missing = [];
    foreach ($profileFields as $f => $lbl) { if (empty($u[$f]) || $u[$f] === '0000-00-00') $missing[] = $lbl; }
    $scopeList[$i]['_missing'] = $missing;
    $scopeList[$i]['_pct'] = (int)round((count($profileFields) - count($missing)) * 100 / count($profileFields));
    $bd = $nextYearly($u['date_of_birth'] ?? null); $an = $nextYearly($u['joining_date'] ?? null);
    $scopeList[$i]['_bday'] = $bd; $scopeList[$i]['_anniv'] = $an;
    $retireOn = null;
    if (!empty($u['date_of_birth']) && $u['date_of_birth'] !== '0000-00-00') {
        $retireOn = (new DateTimeImmutable(substr($u['date_of_birth'], 0, 10)))->modify('+' . $retireAge . ' years');
    }
    $scopeList[$i]['_retire'] = $retireOn;
    $joinTs = !empty($u['joining_date']) && $u['joining_date'] !== '0000-00-00' ? strtotime($u['joining_date']) : strtotime((string)$u['created_at']);
    $scopeList[$i]['_new'] = $joinTs && $joinTs >= strtotime('-90 days');
    $scopeList[$i]['_nologin'] = !$u['last_login_at'] || strtotime($u['last_login_at']) < strtotime('-30 days');
    if (!(int)$u['is_active']) continue;
    if ($scopeList[$i]['_new']) $smart['new'][] = $u;
    if ($scopeList[$i]['_nologin']) $smart['nologin'][] = $u;
    if ($missing) $smart['incomplete'][] = $u;
    if ($bd && $bd['days'] <= 30) $smart['bday'][] = $u + ['_d' => $bd['days'], '_y' => $bd['years']];
    if ($an && $an['days'] <= 7 && $an['years'] >= 1) $smart['anniv'][] = $u + ['_d' => $an['days'], '_y' => $an['years']];
    if ($retireOn && $retireOn <= $today->modify('+6 months')) $smart['retire'][] = $u + ['_on' => $retireOn];
    if (empty($u['department_id'])) $smart['nodept'][] = $u;
    if (!empty($u['left_date']) && $u['left_date'] !== '0000-00-00' && strtotime($u['left_date']) <= time()) $smart['leftActive'][] = $u;
    $mob = preg_replace('/\D/', '', (string)$u['mobile']);
    if (strlen($mob) >= 10) $mobiles[substr($mob, -10)][] = $u['name'];
    $dn = $u['dept_name'] ?: ($u['dept_name_en'] ?: 'विभाग नाही');
    $deptCount[$dn] = ($deptCount[$dn] ?? 0) + 1;
    $tk = $u['teaching_mode'] ?: ($u['employment_type'] ?: 'इतर');
    $typeCount[$tk] = ($typeCount[$tk] ?? 0) + 1;
    $gk = ['Male' => '👨 पुरुष', 'Female' => '👩 महिला'][$u['gender'] ?? ''] ?? '— नोंद नाही';
    $genderCount[$gk] = ($genderCount[$gk] ?? 0) + 1;
}
$dupMobiles = array_filter($mobiles, fn($n) => count($n) > 1);
usort($smart['bday'], fn($a, $b) => $a['_d'] <=> $b['_d']);
usort($smart['anniv'], fn($a, $b) => $a['_d'] <=> $b['_d']);
usort($smart['retire'], fn($a, $b) => $a['_on'] <=> $b['_on']);
arsort($deptCount);
$byId = []; foreach ($scopeList as $u) { $byId[(int)$u['id']] = $u; }
$staffList = array_map(fn($u) => $byId[(int)$u['id']], $staffList);
$names = fn(array $list, $n = 3) => implode(', ', array_map(fn($u) => $u['name'], array_slice($list, 0, $n))) . (count($list) > $n ? ' +' . (count($list) - $n) : '');

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

<?php if (hasRole('super_admin')):
  $__sa = $pdo->query("SELECT name, email FROM users WHERE role = 'super_admin' ORDER BY id")->fetchAll();
  if (count($__sa) > 1): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">👑 <b>Super Admin एकच असावा</b> — सध्या <?= count($__sa) ?> आहेत: <?= h(implode(', ', array_map(fn($x) => $x['name'] . ' (' . $x['email'] . ')', $__sa))) ?>. जास्तीच्या लोकांचं पद "कर्मचारी बदला" मधून बदला; नवीन Super Admin यापुढे बनवता येत नाही.</div>
<?php endif; endif; ?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s252')) ?></div>
  <div class="d-flex gap-2">
    <?php if ($canSeeDetails): ?><a href="list-print.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;"><i class="fa-solid fa-print me-1"></i>यादी Print / PDF</a><?php endif; ?>
    <a href="employee-report.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;"><i class="fa-solid fa-file-lines me-1"></i>कर्मचारी अहवाल</a>
    <?php if (isManagementOrAbove() || hasRole('super_admin')): ?>
      <a href="id-cards-bulk.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-id-card me-1"></i>Bulk ID Cards</a>
    <?php endif; ?>
    <?php if (hasRole('super_admin')): ?>
      <a href="../roles/user-access.php" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-user-shield me-1"></i>👤 व्यक्तीनुसार अधिकार</a>
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

<?php require_once __DIR__ . '/../includes/smart-insights.php';
smartTiles([
    ['label' => 'सक्रिय कर्मचारी', 'value' => $countActive, 'sub' => 'एकूण ' . $countAll . ' · निष्क्रिय ' . $countInactive, 'tone' => 'ok', 'href' => '?status=active'],
    ['label' => '🆕 नवीन रुजू', 'value' => count($smart['new']), 'sub' => 'गेल्या 90 दिवसांत', 'href' => '#f=new'],
    ['label' => '🔐 Login नाही', 'value' => count($smart['nologin']), 'sub' => '30 दिवसांत एकदाही नाही', 'tone' => $smart['nologin'] ? 'warn' : 'ok', 'href' => '#f=nologin'],
    ['label' => '📋 अपूर्ण माहिती', 'value' => count($smart['incomplete']), 'sub' => $countActive ? round(count($smart['incomplete']) * 100 / max(1, $countActive)) . '% कर्मचारी' : '', 'tone' => $smart['incomplete'] ? 'warn' : 'ok', 'href' => '#f=incomplete'],
    ['label' => '🎂 वाढदिवस', 'value' => count($smart['bday']), 'sub' => 'पुढच्या 30 दिवसांत', 'href' => '#f=bday'],
]);
$alerts = [];
$todayB = array_filter($smart['bday'], fn($u) => $u['_d'] <= 1);
if ($todayB) $alerts[] = ['info', '🎂 ' . (min(array_column($todayB, '_d')) === 0 ? 'आज' : 'उद्या') . ' वाढदिवस — ' . $names($todayB), '#f=bday'];
if ($smart['anniv']) $alerts[] = ['info', '🎉 या आठवड्यात सेवेची वर्षपूर्ती — ' . implode(', ', array_map(fn($u) => $u['name'] . ' (' . $u['_y'] . ' वर्षं)', array_slice($smart['anniv'], 0, 3))), '#f=anniv'];
if ($smart['retire']) $alerts[] = ['warn', '👴 6 महिन्यांत निवृत्ती (' . $retireAge . ' वर्षं) — ' . implode(', ', array_map(fn($u) => $u['name'] . ' (' . $u['_on']->format('d/m/Y') . ')', array_slice($smart['retire'], 0, 3))), '#f=retire'];
if ($smart['leftActive']) $alerts[] = ['bad', '🚪 सोडून गेल्याची तारीख असूनही खातं सक्रिय — ' . $names($smart['leftActive']) . ' (Login बंद करा)', '#f=left'];
if ($smart['nodept']) $alerts[] = ['warn', '🗂️ विभाग न दिलेले — ' . $names($smart['nodept']), '#f=nodept'];
if ($dupMobiles) $alerts[] = ['warn', '📱 एकच Mobile अनेकांना — ' . implode(' · ', array_map(fn($n) => implode(', ', array_slice($n, 0, 3)), array_slice($dupMobiles, 0, 2))), '#f=dupmob'];
$neverLogged = array_filter($smart['nologin'], fn($u) => !$u['last_login_at']);
if ($neverLogged) $alerts[] = ['warn', '🔐 ' . count($neverLogged) . ' जणांनी कधीच Login केलं नाही — ' . $names($neverLogged), '#f=nologin'];
smartAlerts($alerts);
?>
<?php if ($countActive > 0): ?>
<details class="panel mb-3 st-charts">
  <summary style="padding:12px 18px;cursor:pointer;font-weight:600;font-size:13.5px;">📊 कर्मचारी रचना — विभाग, प्रकार, लिंग</summary>
  <div class="row g-3" style="padding:0 14px 14px;">
    <div class="col-lg-6"><?php smartBars('🗂️ विभागनिहाय', 'सक्रिय कर्मचारी — जास्त आधी', array_map(fn($k, $v) => ['name' => $k, 'value' => $v], array_keys(array_slice($deptCount, 0, 12, true)), array_slice($deptCount, 0, 12, true))); ?></div>
    <div class="col-lg-6 d-flex flex-column gap-3">
      <div><?php arsort($typeCount); smartBars('👔 प्रकार', 'Teaching / Non Teaching', array_map(fn($k, $v) => ['name' => $k, 'value' => $v], array_keys($typeCount), $typeCount), '#7B3F9E'); ?></div>
      <div><?php arsort($genderCount); smartBars('⚧ लिंग', '', array_map(fn($k, $v) => ['name' => $k, 'value' => $v], array_keys($genderCount), $genderCount), '#0891B2'); ?></div>
    </div>
  </div>
</details>
<?php endif; ?>

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

<div class="panel mb-2 st-tools">
  <input type="search" id="stQ" class="form-control-custom" placeholder="🔍 नाव, Mobile, Email, हुद्दा, विभाग, कर्मचारी क्रमांक…" autocomplete="off">
  <select id="stRole" class="form-select-custom"><option value="">सगळे Role</option><?php foreach (array_unique(array_column($staffList, 'role')) as $rk): ?><option value="<?= h($rk) ?>"><?= h($roleLabels[$rk] ?? $rk) ?></option><?php endforeach; ?></select>
  <select id="stSort" class="form-select-custom">
    <option value="name">↕ नावानुसार</option><option value="dept">विभागानुसार</option><option value="pct">माहिती अपूर्ण आधी</option>
    <option value="login">Login जुना आधी</option><option value="join">नवीन रुजू आधी</option><option value="bday">जवळचा वाढदिवस आधी</option>
  </select>
  <button type="button" id="stCsv" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;white-space:nowrap;"><i class="fa-solid fa-file-excel me-1" style="color:#137A4B;"></i>Excel</button>
  <div class="st-chips">
    <?php foreach (['' => 'सगळे', 'new' => '🆕 नवीन', 'nologin' => '🔐 Login नाही', 'incomplete' => '📋 अपूर्ण', 'bday' => '🎂 वाढदिवस', 'anniv' => '🎉 वर्षपूर्ती', 'retire' => '👴 निवृत्ती जवळ', 'nodept' => '🗂️ विभाग नाही', 'dupmob' => '📱 एकच Mobile', 'left' => '🚪 सोडून गेलेले'] as $fk => $fl): ?>
      <button type="button" class="st-chip<?= $fk === '' ? ' on' : '' ?>" data-f="<?= $fk ?>"><?= $fl ?></button>
    <?php endforeach; ?>
    <span id="stCount" class="st-count"></span>
  </div>
</div>

<div class="panel">
  <table class="rtable" id="stTable" data-no-filter>
    <thead>
      <tr><th><?= h(t('s6')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s45')) ?></th><th>Role</th><th>शेवटचं Login</th><th>माहिती</th><th><?= h(t('s2')) ?></th><?php if (hasRole('super_admin')): ?><th></th><?php endif; ?></tr>
    </thead>
    <tbody>
      <?php if (empty($staffList)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s254')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($staffList as $person): ?>
        <?php $mob10 = substr(preg_replace('/\D/', '', (string)$person['mobile']), -10);
          $flags = array_filter(['new' => $person['_new'], 'nologin' => $person['_nologin'], 'incomplete' => (bool)$person['_missing'],
            'bday' => $person['_bday'] && $person['_bday']['days'] <= 30, 'anniv' => $person['_anniv'] && $person['_anniv']['days'] <= 7 && $person['_anniv']['years'] >= 1,
            'retire' => $person['_retire'] && $person['_retire'] <= $today->modify('+6 months'), 'nodept' => empty($person['department_id']),
            'dupmob' => strlen($mob10) === 10 && isset($dupMobiles[$mob10]), 'left' => !empty($person['left_date']) && $person['left_date'] !== '0000-00-00' && strtotime($person['left_date']) <= time()]);
          $ll = $person['last_login_at'] ? strtotime($person['last_login_at']) : 0; ?>
        <tr style="cursor:pointer;" onclick="if (!event.target.closest('button, a, input, form, .modal')) window.location='profile.php?id=<?= $person['id'] ?>'"
          data-s="<?= h(mb_strtolower(implode(' ', [$person['name'], $person['email'], $person['mobile'], $person['designation'], $person['dept_name'], $person['dept_name_en'], $person['employee_code']]))) ?>"
          data-role="<?= h($person['role']) ?>" data-f=" <?= implode(' ', array_keys($flags)) ?> " data-pct="<?= $person['_pct'] ?>" data-login="<?= $ll ?>"
          data-join="<?= !empty($person['joining_date']) && $person['joining_date'] !== '0000-00-00' ? strtotime($person['joining_date']) : 0 ?>" data-bd="<?= $person['_bday']['days'] ?? 999 ?>"
          data-dept="<?= h($person['dept_name'] ?? $person['dept_name_en'] ?? '') ?>" data-name="<?= h($person['name']) ?>"
          data-csv="<?= h(json_encode([$person['employee_code'], $person['name'], $person['dept_name'] ?? $person['dept_name_en'], $person['designation'], $roleLabels[$person['role']] ?? $person['role'], $person['mobile'], $person['email'], $person['joining_date'], $person['last_login_at'], $person['_pct'] . '%', implode(' / ', $person['_missing']), $person['is_active'] ? 'सक्रिय' : 'निष्क्रिय'], JSON_UNESCAPED_UNICODE)) ?>">
          <td style="font-weight:500;">
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($person['photo_path'])): ?><img src="../<?= h($person['photo_path']) ?>" alt="" loading="lazy" onerror="this.outerHTML='<span class=&quot;font-mono st-ini&quot;><?= h(mb_substr($person['name'], 0, 2)) ?></span>'" style="width:30px;height:30px;border-radius:8px;object-fit:cover;flex-shrink:0;">
              <?php else: ?><span class="font-mono" style="width:30px;height:30px;border-radius:8px;background:var(--ink-soft);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0;"><?= h(mb_substr($person['name'], 0, 2)) ?></span><?php endif; ?>
              <div style="min-width:0;"><?= h($person['name']) ?>
                <?php if (!empty($flags['bday']) && $person['_bday']['days'] <= 7): ?><span title="वाढदिवस <?= $person['_bday']['days'] === 0 ? 'आज' : $person['_bday']['days'] . ' दिवसांत' ?>">🎂</span><?php endif; ?>
                <?php if (!empty($flags['new'])): ?><span class="st-tag">नवीन</span><?php endif; ?>
                <div style="font-size:11px;color:var(--text-dim);font-weight:400;"><?= $person['employee_code'] ? h($person['employee_code']) . ' · ' : '' ?><?= $person['mobile'] ? '<a href="tel:' . h($person['mobile']) . '" onclick="event.stopPropagation()" style="color:inherit;">📞 ' . h($person['mobile']) . '</a> · ' : '' ?><span class="font-mono"><?= h($person['email']) ?></span></div>
              </div>
            </div>
          </td>
          <td onclick="event.stopPropagation()">
            <?php if ($person['department_id']): ?>
              <?php if (!isDeptScoped() || (int)$person['department_id'] === (int)($user['department_id'] ?? 0)): ?>
                <a href="../departments/dashboard.php?id=<?= $person['department_id'] ?>" style="color:var(--rail);font-weight:600;text-decoration:none;"><?= h($person['dept_name'] ?? $person['dept_name_en'] ?? '—') ?></a>
              <?php else: ?>
                <span style="font-weight:600;"><?= h($person['dept_name'] ?? $person['dept_name_en'] ?? '—') ?></span>
              <?php endif; ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?= h($person['designation'] ?? '—') ?></td>
          <td><span class="status-chip pending"><?= h($roleLabels[$person['role']] ?? $person['role']) ?></span></td>
          <td style="font-size:12px;white-space:nowrap;" title="<?= $ll ? date('d/m/Y h:i A', $ll) : '' ?>"><?php if (!$ll): ?><span style="color:var(--rose);">कधीच नाही</span><?php else: $dd = (int)floor((time() - $ll) / 86400); ?><span style="color:<?= $dd > 30 ? 'var(--rose)' : ($dd > 7 ? 'var(--amber)' : 'var(--text-dim)') ?>;"><?= $dd === 0 ? 'आज' : ($dd === 1 ? 'काल' : $dd . ' दिवसांपूर्वी') ?></span><?php endif; ?></td>
          <td style="min-width:90px;" title="<?= $person['_missing'] ? h('नाही: ' . implode(', ', $person['_missing'])) : 'पूर्ण माहिती' ?>"><div class="st-pct"><i style="width:<?= $person['_pct'] ?>%;background:<?= $person['_pct'] >= 90 ? '#16A34A' : ($person['_pct'] >= 60 ? '#F59E0B' : '#DC2626') ?>;"></i></div><small style="font-size:10.5px;color:var(--text-dim);"><?= $person['_pct'] ?>%</small></td>
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
                  <div style="font-size:12.5px;background:var(--canvas);border-radius:8px;padding:8px 10px;margin-bottom:10px;">त्यांचा Login, हजेरी, रजा, कामाच्या नोंदी, Tasks, E-Campus कामं, Campus प्रमुख पद आणि फोटो — सगळं कायमचं हटेल. फक्त पूर्वीच्या कामाचा इतिहास ठेवायचा असेल तर Delete ऐवजी <b>Edit → निष्क्रिय</b> करा.</div>
                  <label class="form-label-custom"><?= h(t('s257')) ?> <span class="font-mono" style="color:var(--rose);"><?= h($person['name']) ?></span> <span style="font-weight:400;">(किंवा <b>हटवा</b>)</span></label>
                  <input type="text" name="confirm_name" class="form-control-custom" autocomplete="off" required>
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

<style>
  .st-tools { display:flex; flex-wrap:wrap; gap:8px; padding:12px 14px; align-items:center; }
  .st-tools #stQ { flex:1 1 260px; } .st-tools select { width:auto; }
  .st-chips { display:flex; flex-wrap:wrap; gap:6px; width:100%; align-items:center; }
  .st-chip { border:1px solid var(--line); background:var(--surface); border-radius:20px; padding:4px 11px; font-size:12px; cursor:pointer; color:var(--text); }
  .st-chip.on { background:var(--rail); border-color:var(--rail); color:#fff; } .st-chip.zero { opacity:.45; }
  .st-count { margin-left:auto; font-size:12px; color:var(--text-dim); }
  .st-tag { font-size:10px; background:#DCFCE7; color:#166534; border-radius:6px; padding:1px 6px; margin-left:4px; font-weight:600; }
  .st-ini { width:30px;height:30px;border-radius:8px;background:var(--ink-soft);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0; }
  .st-pct { height:6px; background:var(--canvas); border-radius:4px; overflow:hidden; } .st-pct i { display:block; height:100%; }
  @media (max-width:640px){ .st-tools select, .st-tools #stCsv { flex:1 1 45%; } }
</style>
<script>
(function () {
  var tb = document.querySelector('#stTable tbody'); if (!tb) return;
  var rows = Array.prototype.slice.call(tb.querySelectorAll('tr[data-s]'));
  var q = document.getElementById('stQ'), role = document.getElementById('stRole'), sort = document.getElementById('stSort'), cnt = document.getElementById('stCount');
  var chips = document.querySelectorAll('.st-chip'), f = '';
  // प्रत्येक Chip वर किती — शून्य असतील ते फिकट
  chips.forEach(function (c) { var k = c.dataset.f; if (!k) return; var n = rows.filter(function (r) { return r.dataset.f.indexOf(' ' + k + ' ') !== -1; }).length; c.textContent += ' (' + n + ')'; if (!n) c.classList.add('zero'); });
  function apply() {
    var s = q.value.trim().toLowerCase(), rl = role.value, shown = 0;
    rows.forEach(function (r) {
      var ok = (!s || r.dataset.s.indexOf(s) !== -1) && (!rl || r.dataset.role === rl) && (!f || r.dataset.f.indexOf(' ' + f + ' ') !== -1);
      r.style.display = ok ? '' : 'none'; if (ok) shown++;
    });
    cnt.textContent = rows.length + ' पैकी ' + shown + ' दाखवत आहे';
  }
  function order() {
    var k = sort.value, by = {
      name: function (a, b) { return a.dataset.name.localeCompare(b.dataset.name); },
      dept: function (a, b) { return a.dataset.dept.localeCompare(b.dataset.dept) || a.dataset.name.localeCompare(b.dataset.name); },
      pct: function (a, b) { return a.dataset.pct - b.dataset.pct; },
      login: function (a, b) { return a.dataset.login - b.dataset.login; },
      join: function (a, b) { return b.dataset.join - a.dataset.join; },
      bday: function (a, b) { return a.dataset.bd - b.dataset.bd; }
    }[k];
    rows.slice().sort(by).forEach(function (r) { tb.appendChild(r); });
  }
  function setF(v) { f = v; chips.forEach(function (c) { c.classList.toggle('on', c.dataset.f === v); }); if (v === 'bday') { sort.value = 'bday'; order(); } if (v === 'incomplete') { sort.value = 'pct'; order(); } apply(); }
  chips.forEach(function (c) { c.addEventListener('click', function () { setF(c.dataset.f); }); });
  q.addEventListener('input', apply); role.addEventListener('change', apply); sort.addEventListener('change', function () { order(); apply(); });
  // वरच्या Tiles / "लक्ष द्या" मधून #f=… → तोच Filter
  function fromHash() { var m = location.hash.match(/f=(\w+)/); if (m) { setF(m[1]); document.getElementById('stTable').scrollIntoView({ behavior: 'smooth', block: 'start' }); } }
  window.addEventListener('hashchange', fromHash); fromHash(); apply();
  // ⬇ Excel (CSV) — सध्या दिसणाऱ्या ओळीच
  document.getElementById('stCsv').addEventListener('click', function () {
    var esc = function (v) { v = v == null ? '' : String(v); if (/^[=+\-@]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; };
    var out = [['कर्मचारी क्रमांक', 'नाव', 'विभाग', 'हुद्दा', 'Role', 'Mobile', 'Email', 'रुजू दिनांक', 'शेवटचं Login', 'माहिती %', 'नसलेली माहिती', 'स्थिती'].map(esc).join(',')];
    rows.forEach(function (r) { if (r.style.display !== 'none') out.push(JSON.parse(r.dataset.csv).map(esc).join(',')); });
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + out.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = 'karmachari-yadi-' + new Date().toISOString().slice(0, 10) + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
