<?php
$pageTitle = 'नूतनीकरण व अनुपालन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/renewal-helpers.php';

$orgId = activeOrgId();
$filter = $_GET['filter'] ?? 'all';
// लवचिक Department-Visibility — फक्त परवानगी असलेले विभाग दिसतील
$visibleDeptIds = getVisibleDepartmentIds('view_org_wide_dashboards');
$requestedDeptId = $_GET['department_id'] ?? '';
if ($visibleDeptIds === null) {
    $filterDeptId = $requestedDeptId;
} else {
    $filterDeptId = ($requestedDeptId !== '' && in_array((int)$requestedDeptId, $visibleDeptIds, true)) ? $requestedDeptId : ($user['department_id'] ?? '');
}
$filterType = $_GET['item_type'] ?? '';
$filterRegBody = $_GET['regulatory_body'] ?? '';
// ⏳ मुदत-Timeline Filter — Chip वरचा आकडा आणि यादी एकच टप्पा: 0–7, 8–30, 31–90, 90+ दिवस
$bands = ['7' => [0, 7], '30' => [8, 30], '90' => [31, 90], 'far' => [91, null]];
$within = isset($bands[(string)($_GET['within'] ?? '')]) ? (string)$_GET['within'] : '';
$focusItemId = (int)($_GET['item'] ?? 0); // Notification मधून आलेली विशिष्ट नोंद
$activeSql = renewalActiveSql('r');
$activeSqlPlain = renewalActiveSql('');

$sql = "SELECT r.*, d.name_local AS dept_name, d.name AS dept_name_en, DATEDIFF(r.expiry_date, CURDATE()) AS days_left
        FROM renewal_items r JOIN departments d ON d.id = r.department_id
        WHERE r.organization_id = ? AND $activeSql";
$params = [$orgId];

// स्थिती नेहमी expiry_date वरून — DB मधल्या status वरून नाही
if (in_array($filter, ['expired', 'due_soon', 'active'], true)) {
    $sql .= " AND " . renewalStatusSql($filter, 'r');
}
if ($within !== '') {
    [$bFrom, $bTo] = $bands[$within];
    $sql .= " AND r.expiry_date >= DATE_ADD(CURDATE(), INTERVAL $bFrom DAY)" . ($bTo !== null ? " AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $bTo DAY)" : '');
}
if ($focusItemId) {
    $sql .= " AND r.id = ?";
    $params[] = $focusItemId;
}
if ($filterDeptId) {
    $sql .= " AND r.department_id = ?";
    $params[] = $filterDeptId;
} elseif ($visibleDeptIds !== null) {
    $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $sql .= " AND r.department_id IN ($ph)";
    $params = array_merge($params, $visibleDeptIds);
}
if ($filterType) {
    $sql .= " AND r.item_type = ?";
    $params[] = $filterType;
}
if ($filterRegBody) {
    $sql .= " AND r.regulatory_body = ?";
    $params[] = $filterRegBody;
}
$sql .= " ORDER BY r.expiry_date";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Chips/Chart चे आकडे — तेच विभाग-Filter लावून, स्थिती expiry_date वरून
$countScopeSql = "organization_id = ? AND $activeSqlPlain";
$countScopeParams = [$orgId];
if ($filterDeptId) {
    $countScopeSql .= " AND department_id = ?";
    $countScopeParams[] = $filterDeptId;
} elseif ($visibleDeptIds !== null) {
    $countScopeSql .= empty($visibleDeptIds) ? " AND 0" : " AND department_id IN (" . implode(',', array_fill(0, count($visibleDeptIds), '?')) . ")";
    $countScopeParams = array_merge($countScopeParams, $visibleDeptIds);
}
$countStmt = $pdo->prepare("SELECT COUNT(*) c,
    SUM(CASE WHEN " . renewalStatusSql('due_soon', '') . " THEN 1 ELSE 0 END) due,
    SUM(CASE WHEN " . renewalStatusSql('expired', '') . " THEN 1 ELSE 0 END) expired
    FROM renewal_items WHERE $countScopeSql");
$countStmt->execute($countScopeParams);
$countRow = $countStmt->fetch();
$countAll = (int)$countRow['c'];
$countDue = (int)$countRow['due'];
$countExpired = (int)$countRow['expired'];

$countHealthy = max(0, $countAll - $countDue - $countExpired);

// 📊 Smart — पुढील १२ महिन्यांचा खर्च-अंदाज व "आत्ता कृती करा" यादी (त्याच विभाग-Filter प्रमाणे)
$forecast = [];
for ($i = 0; $i < 12; $i++) { $k = date('Y-m', strtotime(date('Y-m-01') . " +$i month")); $forecast[$k] = ['label' => date('M y', strtotime($k . '-01')), 'cost' => 0.0, 'cnt' => 0]; }
$fcStmt = $pdo->prepare("SELECT DATE_FORMAT(expiry_date, '%Y-%m') ym, COUNT(*) cnt, SUM(COALESCE(renewal_cost, 0)) cost
    FROM renewal_items WHERE $countScopeSql AND expiry_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 11 MONTH))
    GROUP BY ym");
$fcStmt->execute($countScopeParams);
foreach ($fcStmt->fetchAll() as $r) { if (isset($forecast[$r['ym']])) { $forecast[$r['ym']]['cost'] = (float)$r['cost']; $forecast[$r['ym']]['cnt'] = (int)$r['cnt']; } }
$forecastTotal = array_sum(array_column($forecast, 'cost'));
$overdueCostStmt = $pdo->prepare("SELECT SUM(COALESCE(renewal_cost, 0)) FROM renewal_items WHERE $countScopeSql AND expiry_date < CURDATE()");
$overdueCostStmt->execute($countScopeParams);
$overdueCost = (float)$overdueCostStmt->fetchColumn();
$compliancePct = $countAll > 0 ? (int)round(($countAll - $countExpired) * 100 / $countAll) : null;
$actStmt = $pdo->prepare("SELECT r.id, r.item_name, r.expiry_date, r.renewal_cost, d.name_local AS dept_name, DATEDIFF(r.expiry_date, CURDATE()) days_left
    FROM renewal_items r JOIN departments d ON d.id = r.department_id
    WHERE r.id IN (SELECT id FROM renewal_items WHERE $countScopeSql AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
    ORDER BY r.expiry_date LIMIT 6");
$actStmt->execute($countScopeParams);
$actionItems = $actStmt->fetchAll();

// Government Compliance Overview — Regulatory Body-निहाय सारांश
$regBodyStmt = $pdo->prepare("
    SELECT regulatory_body,
      COUNT(*) AS total,
      SUM(CASE WHEN " . renewalStatusSql('expired', '') . " THEN 1 ELSE 0 END) AS expired,
      SUM(CASE WHEN " . renewalStatusSql('due_soon', '') . " THEN 1 ELSE 0 END) AS due_soon
    FROM renewal_items WHERE $countScopeSql AND regulatory_body IS NOT NULL
    GROUP BY regulatory_body ORDER BY expired DESC, due_soon DESC
");
$regBodyStmt->execute($countScopeParams);
$regBodyOverview = $regBodyStmt->fetchAll();

// 🧠 Smart — मुदत-Timeline, प्रकारनिहाय, सर्वात जोखमीचे विभाग, पुढील 30 दिवसांचा खर्च
$bk = $pdo->prepare("SELECT
    SUM(expiry_date < CURDATE()) b_exp,
    SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) b7,
    SUM(expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) b30,
    SUM(expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)) b90,
    SUM(expiry_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY)) b_far,
    SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN COALESCE(renewal_cost, 0) ELSE 0 END) cost30,
    SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) n30,
    SUM(NOT EXISTS (SELECT 1 FROM renewal_attachments a WHERE a.renewal_item_id = renewal_items.id)) no_doc   -- प्रमाणपत्र जोडलेलं नाही
    FROM renewal_items WHERE $countScopeSql");
try { $bk->execute($countScopeParams); $buckets = $bk->fetch(); }
catch (PDOException $e) { $buckets = []; }
$buckets = array_map(fn($v) => $v === null ? null : (float)$v, $buckets ?: []);
$typeCountStmt = $pdo->prepare("SELECT item_type, COUNT(*) n, SUM(expiry_date < CURDATE()) exp_n, SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) soon_n
    FROM renewal_items WHERE $countScopeSql GROUP BY item_type ORDER BY exp_n DESC, soon_n DESC, n DESC");
$typeCountStmt->execute($countScopeParams);
$typeCounts = $typeCountStmt->fetchAll();
$riskDepts = [];
if (!$filterDeptId && ($visibleDeptIds === null || count($visibleDeptIds) > 1)) {
    $rd = $pdo->prepare("SELECT r.department_id, COALESCE(NULLIF(d.name_local,''), d.name) dept, SUM(r.expiry_date < CURDATE()) exp_n,
        SUM(r.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) soon_n, COUNT(*) n
        FROM renewal_items r JOIN departments d ON d.id = r.department_id
        WHERE r.id IN (SELECT id FROM renewal_items WHERE $countScopeSql)
        GROUP BY r.department_id HAVING exp_n > 0 OR soon_n > 0 ORDER BY exp_n DESC, soon_n DESC LIMIT 5");
    $rd->execute($countScopeParams);
    $riskDepts = $rd->fetchAll();
}
// स्मार्ट निष्कर्ष — एका ओळीत
$insight = null;
if ($countAll > 0) {
    if ($countExpired > 0) { $insight = ['🚨', 'bad', $countExpired . ' नोंदींची मुदत संपली आहे' . ($overdueCost > 0 ? ' (अंदाजे ₹' . number_format($overdueCost) . ')' : '') . ($riskDepts ? ' — सर्वात जास्त: ' . $riskDepts[0]['dept'] : '') . '. आधी त्या Renew करा.']; }
    elseif (($buckets['b7'] ?? 0) > 0) { $insight = ['⏰', 'warn', (int)$buckets['b7'] . ' नोंदी 7 दिवसांत संपतील — आजच कागदपत्रं व Payment तयार ठेवा.']; }
    elseif (($buckets['n30'] ?? 0) > 0) { $insight = ['📅', 'warn', 'पुढच्या 30 दिवसांत ' . (int)$buckets['n30'] . ' नूतनीकरणं' . (($buckets['cost30'] ?? 0) > 0 ? ' — सुमारे ₹' . number_format($buckets['cost30']) . ' लागतील' : '') . '.']; }
    else { $insight = ['✅', 'good', 'सगळं अनुपालन वेळेत — पुढचे 30 दिवस एकही नोंद संपत नाही.']; }
}
$qsKeep = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['filter' => $filter !== 'all' ? $filter : null, 'department_id' => $filterDeptId ?: null, 'item_type' => $filterType ?: null, 'regulatory_body' => $filterRegBody ?: null, 'within' => $within !== '' ? $within : null], $over), fn($v) => $v !== null && $v !== ''));


$deptSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
$deptParams = [$orgId];
if ($visibleDeptIds !== null && !empty($visibleDeptIds)) {
    $ph3 = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $deptSql .= " AND id IN ($ph3)";
    $deptParams = array_merge($deptParams, $visibleDeptIds);
}
$deptSql .= " ORDER BY name_local";
$deptStmt = $pdo->prepare($deptSql);
$deptStmt->execute($deptParams);
$departments = $deptStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();

$typeStmt = $pdo->prepare("SELECT DISTINCT item_type FROM renewal_items WHERE organization_id = ? AND $activeSqlPlain ORDER BY item_type");
$typeStmt->execute([$orgId]);
$allTypes = array_column($typeStmt->fetchAll(), 'item_type');

function renewalTypeIcon($type) { return renewalTypeIconFor($type); }

// Executive President, General Manager, इतर Management-tier — आणि कुठलाही
// विशिष्ट विभाग Filter केलेला नसेल — तर एकत्रित (Centralized), विभाग-निहाय
// Grouped दृश्य दाखवतो. Staff/HOD ला (जे आपोआप त्यांच्याच विभागापुरते मर्यादित
// असतात) नेहमीचंच साधं यादी-स्वरूप दिसत राहतं.
$showGrouped = isManagementOrAbove() && !$filterDeptId;
$groupedItems = [];
if ($showGrouped) {
    foreach ($items as $item) {
        $groupedItems[$item['department_id']]['dept_name'] = $item['dept_name'] ?: $item['dept_name_en'];
        $groupedItems[$item['department_id']]['items'][] = $item;
    }
    uasort($groupedItems, fn($a, $b) => strcmp($a['dept_name'], $b['dept_name']));
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s67')) ?></div>
  <div class="d-flex gap-2 align-items-center rn-actions">
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 18px;font-weight:600;white-space:nowrap;">
      <i class="fa-solid fa-plus me-1"></i>नवीन नोंद
    </a>
    <details class="rn-more">
      <summary title="अधिक"><i class="fa-solid fa-ellipsis"></i> अधिक</summary>
      <div class="rn-more-menu">
        <a href="calendar.php"><i class="fa-solid fa-calendar-days"></i> Calendar</a>
        <a href="cost-analysis.php"><i class="fa-solid fa-money-bill-trend-up"></i> खर्च-कल</a>
        <a href="report.php<?= $filterDeptId ? '?department_id=' . h($filterDeptId) : '' ?>"><i class="fa-solid fa-chart-column"></i> अहवाल</a>
        <a href="import.php"><i class="fa-solid fa-file-import"></i> Import</a>
        <?php if (hasRole('super_admin')): ?>
        <a href="clear-vehicles.php" style="color:var(--rose);" title="नूतनीकरणातून सर्व वाहनांच्या नोंदी हटवा"><i class="fa-solid fa-car-burst"></i> वाहन नोंदी हटवा</a>
        <?php endif; ?>
      </div>
    </details>
  </div>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>
<?php if ($focusItemId): ?>
  <div class="alert alert-info" style="border-radius:10px;font-size:12.5px;">🔔 सूचनेतील नोंद दाखवत आहे — <a href="index.php">सगळ्या नोंदी बघा</a></div>
<?php endif; ?>

<?php if ($countAll > 0): ?>
<!-- ---------- 🛡️ Compliance Banner ---------- -->
<div class="rn-hero">
  <div class="rn-ring" style="--p:<?= (int)$compliancePct ?>;--c:<?= $compliancePct >= 95 ? '#4ADE80' : ($compliancePct >= 80 ? '#FBBF24' : '#F87171') ?>;"><span><b><?= (int)$compliancePct ?>%</b><small>Compliance</small></span></div>
  <div class="rn-hk">
    <div class="rn-k"><div class="v"><?= $countAll ?></div><div class="l">एकूण</div></div>
    <div class="rn-k"><div class="v" style="color:#86EFAC;"><?= $countHealthy ?></div><div class="l">सुस्थितीत</div></div>
    <div class="rn-k"><div class="v" style="color:#FCD34D;"><?= (int)($buckets['n30'] ?? 0) ?></div><div class="l">30 दिवसांत</div></div>
    <div class="rn-k"><div class="v" style="color:#FCA5A5;"><?= $countExpired ?></div><div class="l">संपली</div></div>
  </div>
  <div class="rn-hsub">💰 30 दिवसांचा खर्च <b>₹<?= number_format((float)($buckets['cost30'] ?? 0)) ?></b><?php if (($buckets['no_doc'] ?? null) !== null && $buckets['no_doc'] > 0): ?> · 📄 प्रमाणपत्र नाही <b><?= (int)$buckets['no_doc'] ?></b><?php endif; ?></div>
</div>
<?php if ($insight): ?><div class="rn-tip <?= $insight[1] ?>"><span><?= $insight[0] ?></span><div><b>स्मार्ट निष्कर्ष:</b> <?= h($insight[2]) ?></div></div><?php endif; ?>

<!-- ---------- ⏳ मुदत-Timeline — Click करून Filter ---------- -->
<div class="rn-timeline">
  <?php foreach ([['expired', 'b_exp', '🔴 मुदत संपली', 'rose'], ['7', 'b7', '🟠 0–7 दिवस', 'orange'], ['30', 'b30', '🟡 8–30 दिवस', 'amber'], ['90', 'b90', '🔵 31–90 दिवस', 'blue'], ['far', 'b_far', '🟢 90+ दिवस', 'green']] as [$key, $col, $lbl, $tone]):
    $n = (int)($buckets[$col] ?? 0);
    $href = $key === 'expired' ? $qsKeep(['filter' => 'expired', 'within' => null]) : $qsKeep(['within' => $key, 'filter' => null]);
    $on = ($key === 'expired' && $filter === 'expired') || ($within === $key);
  ?>
    <a href="<?= h($href) ?>" class="rn-tl <?= $tone ?> <?= $on ? 'on' : '' ?>" style="flex:<?= max(1, $n) ?>;"><b><?= $n ?></b><span><?= $lbl ?></span></a>
  <?php endforeach; ?>
  <?php if ($within !== '' || $filter !== 'all'): ?><a href="<?= h($qsKeep(['within' => null, 'filter' => null])) ?>" class="rn-tl-clear" title="Filter काढा">✕</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($countAll > 0 || $typeCounts || $riskDepts || !empty($regBodyOverview)): ?>
<details class="rn-ana" id="rnAna">
  <summary><span>📊 विश्लेषण</span><small>प्रकारनिहाय · जोखमीचे विभाग · शासकीय Compliance · 12 महिन्यांचा खर्च-अंदाज</small><i class="fa-solid fa-chevron-down"></i></summary>
  <div class="rn-ana-body">
<?php if ($typeCounts || $riskDepts): ?>
<div class="row g-3 mb-3">
  <?php if ($typeCounts): ?>
  <div class="col-lg-<?= $riskDepts ? '7' : '12' ?>">
    <div class="panel h-100"><div class="panel-head"><div class="title">🗂️ प्रकारनिहाय</div><div class="sub">Click करून फक्त तो प्रकार</div></div>
      <div class="rn-types">
        <?php foreach ($typeCounts as $tc): ?>
          <a href="<?= h($qsKeep(['item_type' => $filterType === $tc['item_type'] ? null : $tc['item_type']])) ?>" class="rn-type <?= $filterType === $tc['item_type'] ? 'on' : '' ?>">
            <span><?= renewalTypeIconFor($tc['item_type']) ?> <?= h($tc['item_type']) ?></span><b><?= (int)$tc['n'] ?></b>
            <?php if ($tc['exp_n'] > 0): ?><em class="r"><?= (int)$tc['exp_n'] ?> संपली</em><?php elseif ($tc['soon_n'] > 0): ?><em class="a"><?= (int)$tc['soon_n'] ?> लवकरच</em><?php else: ?><em class="g">✓</em><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($riskDepts): ?>
  <div class="col-lg-5">
    <div class="panel h-100"><div class="panel-head"><div class="title">🎯 सर्वात जोखमीचे विभाग</div><div class="sub">संपलेल्या / 30 दिवसांत संपणाऱ्या नोंदी</div></div>
      <div style="padding:6px 18px 12px;">
        <?php foreach ($riskDepts as $rd): ?>
          <a href="<?= h($qsKeep(['department_id' => $rd['department_id']])) ?>" class="rn-risk">
            <span><b><?= h($rd['dept']) ?></b><small><?= (int)$rd['n'] ?> नोंदी</small></span>
            <span><?php if ($rd['exp_n'] > 0): ?><em class="r"><?= (int)$rd['exp_n'] ?> संपली</em><?php endif; ?><?php if ($rd['soon_n'] > 0): ?><em class="a"><?= (int)$rd['soon_n'] ?> लवकरच</em><?php endif; ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($regBodyOverview)): ?>
<div class="panel mb-3" style="border-color:var(--rail);">
  <div class="panel-head"><div class="title">🏛️ Government Compliance Overview</div><div class="sub">CBSE, State Board, AICTE, DTE, MSBTE, इ. — शासकीय Compliance चा सारांश</div></div>
  <div style="padding:14px 20px;display:flex;gap:14px;flex-wrap:wrap;">
    <?php foreach ($regBodyOverview as $rb): ?>
      <a href="?regulatory_body=<?= urlencode($rb['regulatory_body']) ?>&department_id=<?= h($filterDeptId) ?>" style="text-decoration:none;color:inherit;background:var(--canvas);border-radius:10px;padding:10px 16px;min-width:130px;">
        <div style="font-size:11.5px;font-weight:700;color:var(--ink);">🏛️ <?= h($rb['regulatory_body']) ?></div>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;">एकूण: <?= $rb['total'] ?></div>
        <?php if ($rb['expired'] > 0): ?><div style="font-size:10.5px;color:var(--rose);font-weight:600;">⚠️ <?= $rb['expired'] ?> मुदत संपली</div><?php endif; ?>
        <?php if ($rb['due_soon'] > 0): ?><div style="font-size:10.5px;color:var(--amber);font-weight:600;">🟡 <?= $rb['due_soon'] ?> लवकरच</div><?php endif; ?>
        <?php if ($rb['expired'] == 0 && $rb['due_soon'] == 0): ?><div style="font-size:10.5px;color:var(--rail);font-weight:600;">✅ सर्व ठीक</div><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($countAll > 0): ?>
<div class="row g-3 mt-2 mb-1">
  <div class="col-12">
    <div class="panel h-100">
      <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><div class="title">💰 पुढील १२ महिन्यांचा नूतनीकरण-खर्च अंदाज</div><div class="sub">कोणत्या महिन्यात किती रक्कम लागेल — आधीच Budget ठरवा</div></div>
        <div class="ai-month-kpis"><span>१२ महिने <b>₹<?= number_format($forecastTotal) ?></b></span><?php if ($overdueCost > 0): ?><span>संपलेल्यांचा <b class="c-rose">₹<?= number_format($overdueCost) ?></b></span><?php endif; ?></div>
      </div>
      <div style="padding:12px 18px;height:220px;position:relative;"><canvas id="renewalForecastChart"></canvas></div>
    </div>
  </div>
  </div><?php // row (खर्च-अंदाज) ?>
<?php endif; ?>
  </div>
</details>
<?php endif; ?>

<?php if ($countAll > 0 && $actionItems): ?>
<div class="row g-3 mb-2">
  <?php if ($actionItems): ?>
  <div class="col-12">
    <div class="panel" style="border-left:4px solid var(--rose);">
      <div class="panel-head"><div class="title">🚨 आत्ता कृती करा — संपलेली व ७ दिवसांत संपणारी</div><div class="sub">नोंद उघडून "नूतनीकरण करा" दाबा</div></div>
      <div class="rn-action-grid">
        <?php foreach ($actionItems as $ai): $dl = (int)$ai['days_left']; ?>
          <a class="rn-action" href="?item=<?= (int)$ai['id'] ?>">
            <span class="sm-when <?= $dl < 0 ? 'rose' : 'amber' ?>"><?= $dl < 0 ? abs($dl) . ' दिवसांपूर्वी संपलं' : ($dl === 0 ? 'आज संपतं' : $dl . ' दिवस बाकी') ?></span>
            <div class="sm-t mt-1"><?= h($ai['item_name']) ?></div>
            <div class="sm-s"><?= h($ai['dept_name']) ?> · <?= date('d M Y', strtotime($ai['expiry_date'])) ?><?= $ai['renewal_cost'] ? ' · ₹' . number_format((float)$ai['renewal_cost']) : '' ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($countAll > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var ana = document.getElementById('rnAna'), drawn = false;
  function draw() { if (drawn || !ana || !ana.open) return; drawn = true;
  var fc = <?= json_encode(array_values($forecast), JSON_UNESCAPED_UNICODE) ?>;
  var dim = getComputedStyle(document.documentElement).getPropertyValue('--text-dim').trim() || '#6B7080';
  var line = getComputedStyle(document.documentElement).getPropertyValue('--line').trim() || '#E4E6EC';
  new Chart(document.getElementById('renewalForecastChart'), {
    type: 'bar',
    data: { labels: fc.map(function (x) { return x.label; }), datasets: [{ label: 'खर्च (₹)', data: fc.map(function (x) { return x.cost; }), backgroundColor: fc.map(function (x, i) { return i === 0 ? '#B23A48' : (i < 3 ? '#C97F1D' : '#1B8A83'); }), borderRadius: 5, maxBarThickness: 34 }] },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ₹' + c.raw.toLocaleString('en-IN') + ' · ' + fc[c.dataIndex].cnt + ' नोंदी'; } } } },
      scales: { x: { grid: { display: false }, ticks: { color: dim, font: { size: 10.5 } } }, y: { beginAtZero: true, ticks: { color: dim, callback: function (v) { return '₹' + Number(v).toLocaleString('en-IN'); } }, grid: { color: line } } } }
  });
  }
  // 📊 विश्लेषण उघडं/बंद — लक्षात ठेवा; उघडल्यावरच Chart काढा
  try { if (localStorage.getItem('rnAnaOpen') === '1') ana.open = true; } catch (e) {}
  ana.addEventListener('toggle', function () { try { localStorage.setItem('rnAnaOpen', ana.open ? '1' : '0'); } catch (e) {} draw(); });
  draw();
});
</script>
<?php endif; ?>

<?php if ($showGrouped): ?>
  <div style="background:var(--rail-soft,#DCEFEC);color:var(--rail);border-radius:9px;padding:8px 14px;font-size:12px;font-weight:600;margin-top:12px;display:inline-flex;align-items:center;gap:6px;">
    🏢 Centralized दृश्य — सर्व विभाग एकत्रित दाखवले आहेत
  </div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between mt-3 mb-3 gap-2">
  <div class="period-toggle">
    <a href="?filter=all&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $filter === 'all' ? 'active' : '' ?>"><?= h(t('s160')) ?><?= $countAll ?>)</a>
    <a href="?filter=due_soon&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $filter === 'due_soon' ? 'active' : '' ?>"><?= h(t('s161')) ?><?= $countDue ?>)</a>
    <a href="?filter=expired&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $filter === 'expired' ? 'active' : '' ?>"><?= h(t('s162')) ?><?= $countExpired ?>)</a>
  </div>
  <form method="GET" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_renIdx(this.value)">
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
    function loadDeptsCascade_renIdx(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true; sel.innerHTML = '<option value="">लोड होत आहे...</option>';
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<option value=""><?= h(t('s18')) ?></option>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>
    <select name="item_type" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">-- सगळे प्रकार --</option>
      <?php foreach ($allTypes as $t): ?>
        <option value="<?= h($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= renewalTypeIcon($t) ?> <?= h($t) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="regulatory_body" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">-- सगळे Regulatory Body --</option>
      <?php foreach (['CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','RTO/Transport','Other Government'] as $rb): ?>
        <option value="<?= h($rb) ?>" <?= $filterRegBody === $rb ? 'selected' : '' ?>>🏛️ <?= h($rb) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="rn-tools">
  <input type="search" id="rnFind" class="form-control-custom" placeholder="🔍 नाव, संदर्भ क्र., Vendor शोधा…">
  <select id="rnSort" class="form-select-custom" style="max-width:200px;">
    <option value="">↕ मुदतीनुसार (जवळची आधी)</option><option value="far">मुदतीनुसार (दूरची आधी)</option><option value="name">नावानुसार</option>
  </select>
  <button type="button" id="rnCsv" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:7px 14px;"><i class="fa-solid fa-file-excel me-1" style="color:#16A34A;"></i>Excel</button>
  <span id="rnCount" style="font-size:12.5px;color:var(--text-dim);margin-left:auto;"></span>
</div>

<?php
  // Grouped असेल तर प्रत्येक विभाग वेगळ्या Section मध्ये, नाहीतर एकच सरळ यादी
  $sections = $showGrouped
      ? array_map(fn($g) => ['title' => $g['dept_name'], 'items' => $g['items']], $groupedItems)
      : [['title' => null, 'items' => $items]];
?>

<?php if ($showGrouped && empty($groupedItems)): ?>
  <div class="panel" style="padding:24px;text-align:center;color:var(--text-dim);"><?= h(t('s34')) ?></div>
<?php endif; ?>

<?php foreach ($sections as $section): if ($showGrouped && empty($section['items'])) continue; ?>
<div class="panel rn-list <?= $showGrouped ? 'mb-3' : '' ?>">
  <?php if ($section['title']): ?>
    <div class="panel-head"><div class="title">🏢 <?= h($section['title']) ?></div><div class="sub"><?= count($section['items']) ?> नोंदी</div></div>
  <?php endif; ?>
  <table class="rtable">
    <thead>
      <tr><th><?= h(t('s51')) ?></th><th>प्रकार</th><?php if (!$showGrouped): ?><th><?= h(t('s1')) ?></th><?php endif; ?><th><?= h(t('s163')) ?></th><th><?= h(t('s30')) ?></th><th><?= h(t('s164')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr>
    </thead>
    <tbody>
      <?php if (empty($section['items']) && !$showGrouped): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s34')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($section['items'] as $item):
        $days = (int)$item['days_left'];
        $statusInfo = renewalStatusInfo($days);
        $color = $statusInfo['color'];
        $label = $days < 0 ? abs($days) . ' दिवस उलटले' : $days . ' दिवस';
        $chip = $statusInfo['chip'];
      ?>
        <tr class="rn-row" data-days="<?= $days ?>" data-name="<?= h(mb_strtolower($item['item_name'])) ?>" data-dept="<?= h($item['dept_name'] ?: $item['dept_name_en']) ?>" data-type="<?= h($item['item_type']) ?>" data-exp="<?= h($item['expiry_date']) ?>" data-ref="<?= h((string)$item['reference_no']) ?>" data-vendor="<?= h((string)$item['vendor_name']) ?>" data-cost="<?= h((string)$item['renewal_cost']) ?>" data-status="<?= h($statusInfo['label']) ?>">
          <td class="rc-name" style="font-weight:500;">
            <?= h($item['item_name']) ?>
            <?php if ($item['regulatory_body']): ?>
              <span style="font-size:9.5px;background:var(--rail-soft);color:var(--rail);padding:1px 7px;border-radius:6px;font-weight:700;margin-left:4px;">🏛️ <?= h($item['regulatory_body']) ?></span>
            <?php endif; ?>
            <?php if ($item['vendor_name']): ?>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:2px;">
                <?= h($item['vendor_name']) ?>
                <?php if ($item['vendor_contact']): ?>
                  · <?php if (str_contains($item['vendor_contact'], '@')): ?>
                    <a href="mailto:<?= h($item['vendor_contact']) ?>" style="color:var(--rail);"><i class="fa-regular fa-envelope"></i> <?= h($item['vendor_contact']) ?></a>
                  <?php else: ?>
                    <a href="tel:<?= h($item['vendor_contact']) ?>" style="color:var(--rail);"><i class="fa-solid fa-phone"></i> <?= h($item['vendor_contact']) ?></a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="rc-type"><span style="background:var(--canvas);border-radius:7px;padding:3px 10px;font-size:11.5px;font-weight:600;white-space:nowrap;"><?= renewalTypeIcon($item['item_type']) ?> <?= h($item['item_type']) ?></span></td>
          <?php if (!$showGrouped): ?><td class="rc-dept"><?= h($item['dept_name'] ?: $item['dept_name_en']) ?></td><?php endif; ?>
          <td class="font-mono rc-ref <?= $item['reference_no'] ? '' : 'rc-none' ?>"><?= h($item['reference_no'] ?: '—') ?></td>
          <td class="font-mono rc-exp"><?= date('d M Y', strtotime($item['expiry_date'])) ?></td>
          <td class="rc-days"><span class="font-mono" style="font-weight:600;color:var(--<?= $color ?>);"><?= h($label) ?></span>
            <div class="rn-life" title="उरलेली मुदत"><i style="width:<?= $days < 0 ? 100 : max(4, min(100, (int)round(100 - $days / 3.65))) ?>%;background:var(--<?= $color ?>);"></i></div></td>
          <td class="rc-st"><span class="status-chip <?= $chip ?>"><?= h($statusInfo['label']) ?></span></td>
          <td class="rc-act">
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"
              data-bs-toggle="modal" data-bs-target="#renewModal<?= $item['id'] ?>"><?= h(t('s165')) ?></button>
            <a href="edit.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--text-dim);margin-left:4px;" title="संपादित करा / प्रमाणपत्र"><i class="fa-solid fa-pen"></i></a>
            <a href="history.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--text-dim);margin-left:4px;" title="इतिहास बघा"><i class="fa-solid fa-clock-rotate-left"></i></a>
            <a href="payment.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--rail);margin-left:6px;" title="Payment Track करा">💰</a>
          </td>
        </tr>

        <div class="modal fade" id="renewModal<?= $item['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="renew.php" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-bottom:14px;"><?= h($item['item_name']) ?> <?= h(t('s166')) ?></div>
                  <label class="form-label-custom"><?= h(t('s167')) ?></label>
                  <input type="date" name="new_expiry_date" class="form-control-custom mb-3" required>
                  <label class="form-label-custom"><?= h(t('s70')) ?></label>
                  <input type="number" step="0.01" name="renewal_cost" class="form-control-custom mb-3">
                  <label class="form-label-custom">Vendor (बदलायचं असल्यास)</label>
                  <input type="text" name="vendor_name" class="form-control-custom mb-3" placeholder="<?= h($item['vendor_name'] ?: '') ?>">
                  <label class="form-label-custom">नवीन Reference / प्रमाणपत्र क्रमांक (बदलायचा असल्यास)</label>
                  <input type="text" name="reference_no" class="form-control-custom mb-3" maxlength="100" placeholder="<?= h($item['reference_no'] ?? '') ?>">
                  <label class="form-label-custom">नवीन प्रमाणपत्र (PDF/प्रतिमा, ऐच्छिक)</label>
                  <input type="file" name="certificate" class="form-control-custom" accept=".pdf,.jpg,.jpeg,.png,.webp">
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;"><?= h(t('s3')) ?></button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;"><?= h(t('s168')) ?></button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>



<style>
  .rn-hero{display:flex;gap:18px;align-items:center;flex-wrap:wrap;border-radius:18px;padding:16px 20px;margin:10px 0 10px;color:#fff;
    background:radial-gradient(110% 140% at 100% 0%,rgba(217,83,15,.32) 0%,transparent 48%),linear-gradient(135deg,#141833 0%,#1F2450 60%,#262B5E 100%);box-shadow:0 12px 30px -18px rgba(20,24,51,.6);}
  .rn-ring{width:92px;height:92px;border-radius:50%;display:grid;place-items:center;flex:0 0 92px;background:conic-gradient(var(--c) calc(var(--p)*1%), rgba(255,255,255,.12) 0);}
  .rn-ring span{width:72px;height:72px;border-radius:50%;background:#1A1E44;display:grid;place-items:center;text-align:center;line-height:1;}
  .rn-ring b{font-size:19px;font-weight:800;} .rn-ring small{display:block;font-size:8.5px;color:rgba(255,255,255,.65);margin-top:3px;}
  .rn-hk{flex:1;display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;}
  .rn-k{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:8px 12px;}
  .rn-k .v{font-size:20px;font-weight:800;line-height:1.1;} .rn-k .l{font-size:11px;color:rgba(255,255,255,.7);font-weight:600;}
  .rn-tip{display:flex;gap:10px;align-items:center;border-radius:12px;padding:9px 14px;font-size:13px;margin-bottom:10px;border:1px solid;}
  .rn-tip > span{font-size:19px;} .rn-tip.bad{background:#FDECEC;border-color:#F5C2C7;} .rn-tip.warn{background:#FEF6E7;border-color:#F3D9A4;} .rn-tip.good{background:#E7F6EF;border-color:#B7E4CC;}
  html[data-theme="dark"] .rn-tip{background:var(--surface);}
  .rn-timeline{display:flex;gap:6px;align-items:stretch;margin-bottom:14px;}
  .rn-tl{min-width:92px;border-radius:12px;padding:8px 12px;text-decoration:none;color:inherit;display:flex;flex-direction:column;border:1px solid var(--line);background:var(--surface,#fff);transition:transform .15s,box-shadow .15s;}
  .rn-tl:hover{transform:translateY(-1px);box-shadow:0 6px 16px -10px rgba(0,0,0,.3);color:inherit;}
  .rn-tl b{font-size:18px;font-weight:800;line-height:1.1;} .rn-tl span{font-size:11.5px;color:var(--text-dim);font-weight:600;white-space:nowrap;}
  .rn-tl.rose{border-top:4px solid #DC2626;} .rn-tl.orange{border-top:4px solid #EA580C;} .rn-tl.amber{border-top:4px solid #D97706;} .rn-tl.blue{border-top:4px solid #2563EB;} .rn-tl.green{border-top:4px solid #16A34A;}
  .rn-tl.on{outline:2px solid var(--rail);background:color-mix(in srgb,var(--rail) 7%,var(--surface,#fff));}
  .rn-tl-clear{align-self:center;border:1px solid var(--line);border-radius:99px;width:30px;height:30px;display:grid;place-items:center;text-decoration:none;color:var(--text-dim);}
  .rn-types{display:flex;flex-wrap:wrap;gap:8px;padding:10px 16px 14px;}
  .rn-type{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:6px 10px;text-decoration:none;color:inherit;font-size:12.5px;background:var(--canvas);}
  .rn-type.on{border-color:var(--rail);background:color-mix(in srgb,var(--rail) 9%,var(--surface,#fff));} .rn-type b{font-weight:800;}
  .rn-type em,.rn-risk em{font-style:normal;font-size:10.5px;font-weight:700;border-radius:99px;padding:1px 7px;margin-left:2px;}
  em.r{background:#FDECEC;color:#B42318;} em.a{background:#FEF3C7;color:#92400E;} em.g{background:#E7F6EF;color:#137A4B;}
  .rn-risk{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:7px 0;border-bottom:1px dashed var(--line);text-decoration:none;color:inherit;font-size:13px;}
  .rn-risk:last-child{border-bottom:0;} .rn-risk small{display:block;font-size:11px;color:var(--text-dim);}
  .rn-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;} .rn-tools .form-control-custom{max-width:320px;}
  .rn-life{height:4px;border-radius:99px;background:var(--canvas);overflow:hidden;margin-top:4px;width:90px;} .rn-life i{display:block;height:100%;border-radius:99px;}
  .rn-hsub{flex-basis:100%;font-size:12px;color:rgba(255,255,255,.75);} .rn-hsub b{color:#fff;}
  .rn-more{position:relative;} .rn-more summary{list-style:none;cursor:pointer;border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:8px 14px;background:var(--surface,#fff);white-space:nowrap;}
  .rn-more summary::-webkit-details-marker{display:none;}
  .rn-more-menu{position:absolute;right:0;top:calc(100% + 6px);z-index:50;min-width:190px;background:var(--surface,#fff);border:1px solid var(--line);border-radius:12px;box-shadow:0 14px 30px -12px rgba(16,22,43,.25);padding:6px;}
  .rn-more-menu a{display:flex;gap:10px;align-items:center;padding:9px 12px;border-radius:8px;text-decoration:none;color:var(--text);font-size:13.5px;}
  .rn-more-menu a:hover{background:var(--canvas);} .rn-more-menu i{width:16px;color:var(--text-dim);}
  @media (max-width:767.98px){
    .rn-more[open] > summary::after{content:'';position:fixed;inset:0;background:rgba(10,14,30,.45);z-index:2990;}
    .rn-more-menu{position:fixed;left:0;right:0;top:auto;bottom:0;z-index:3000;border-radius:18px 18px 0 0;padding:10px 10px calc(14px + env(safe-area-inset-bottom));min-width:0;}
    .rn-more-menu a{padding:13px 14px;font-size:15px;}
  }
  .rn-ana{background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;margin-bottom:12px;}
  .rn-ana > summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;padding:11px 16px;}
  .rn-ana > summary::-webkit-details-marker{display:none;}
  .rn-ana > summary span{font-weight:700;font-size:14px;white-space:nowrap;} .rn-ana > summary small{color:var(--text-dim);font-size:11.5px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .rn-ana > summary i{color:var(--text-dim);transition:transform .2s;} .rn-ana[open] > summary i{transform:rotate(180deg);}
  .rn-ana-body{padding:0 12px 12px;} .rn-ana-body .panel{box-shadow:none;}
  .rn-action-grid{display:flex !important;gap:10px;overflow-x:auto;padding:10px 14px 14px;scroll-snap-type:x mandatory;}
  .rn-action-grid .rn-action{flex:0 0 230px;scroll-snap-align:start;}
  @media (max-width:700px){
    .rn-timeline{flex-wrap:wrap;} .rn-tl{flex:1 1 calc(33% - 6px) !important;min-width:0;padding:6px 8px;} .rn-tl b{font-size:16px;} .rn-tl span{font-size:10.5px;}
    .rn-hero{gap:10px;padding:12px;} .rn-ring{width:64px;height:64px;flex-basis:64px;} .rn-ring span{width:50px;height:50px;} .rn-ring b{font-size:14px;} .rn-ring small{font-size:7px;}
    .rn-hk{grid-template-columns:repeat(4,1fr);gap:5px;} .rn-k{padding:6px 6px;text-align:center;} .rn-k .v{font-size:16px;} .rn-k .l{font-size:9.5px;}
    .rn-actions .btn{padding:8px 12px !important;} .page-title{font-size:20px;}
    .rn-action-grid .rn-action{flex-basis:78%;}
    /* तक्ता → Cards */
    .rn-list table.rtable{min-width:0 !important;}
    .rn-list .rtable thead{display:none;} .rn-list .rtable,.rn-list .rtable tbody{display:block;width:100%;}
    .rn-list .rtable tr.rn-row{display:flex;flex-wrap:wrap;align-items:center;gap:5px 8px;padding:10px 14px;border-bottom:1px solid var(--line);}
    .rn-list .rtable tr.rn-row td{display:block;border:0 !important;padding:0 !important;font-size:12px;position:static !important;box-shadow:none !important;background:transparent !important;width:auto !important;}
    .rn-list .rtable tr.rn-row td.rc-name{flex:1 1 60%;order:1;font-size:13.5px;line-height:1.3;}
    .rn-list .rtable tr.rn-row td.rc-days{order:2;text-align:right;font-size:12.5px;} .rn-list .rn-life{width:64px;margin-left:auto;}
    .rn-list .rtable tr.rn-row td.rc-type{order:3;} .rn-list .rtable tr.rn-row td.rc-exp{order:4;color:var(--text-dim);}
    .rn-list .rtable tr.rn-row td.rc-dept{order:5;color:var(--text-dim);} .rn-list .rtable tr.rn-row td.rc-ref{order:6;color:var(--text-dim);font-size:11px;}
    .rn-list .rtable tr.rn-row td.rc-none{display:none;}
    .rn-list .rtable tr.rn-row td.rc-st{order:7;} .rn-list .rtable tr.rn-row td.rc-st .status-chip{font-size:10.5px;padding:2px 8px;}
    .rn-list .rtable tr.rn-row td.rc-act{order:8;margin-left:auto;display:flex;align-items:center;gap:4px;}
    .rn-list .rtable tr.rn-row td.rc-act .btn{padding:3px 10px !important;font-size:11.5px !important;}
  }
</style>
<script>
(function () {   // '⋯ अधिक' — बाहेर टॅप / Esc ने बंद
  var m = document.querySelector('.rn-more'); if (!m) return;
  document.addEventListener('click', function (e) { if (m.open && !m.querySelector('.rn-more-menu').contains(e.target) && !m.querySelector('summary').contains(e.target)) m.open = false; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') m.open = false; });
})();
</script>
<script>
(function () {   // 🔍 शोध · ↕ क्रम · 📊 Excel — दिसणाऱ्या नोंदींवर
  var q = document.getElementById('rnFind'), so = document.getElementById('rnSort'), cnt = document.getElementById('rnCount');
  if (!q) return;
  var rows = [].slice.call(document.querySelectorAll('tr.rn-row'));
  function run() {
    var s = q.value.trim().toLowerCase(), shown = 0;
    rows.forEach(function (r) { var ok = !s || r.textContent.toLowerCase().indexOf(s) !== -1; r.style.display = ok ? '' : 'none'; if (ok) shown++; });
    document.querySelectorAll('.rtable').forEach(function (t) {   // विभाग-Section रिकामा झाला तर लपवा
      var p = t.closest('.panel'); if (!p || !p.querySelector('tr.rn-row')) return;
      p.style.display = [].some.call(t.querySelectorAll('tr.rn-row'), function (r) { return r.style.display !== 'none'; }) ? '' : 'none';
    });
    cnt.textContent = shown + ' / ' + rows.length;
  }
  function sort() {
    var v = so.value;
    document.querySelectorAll('.rtable tbody').forEach(function (tb) {
      var rs = [].slice.call(tb.querySelectorAll('tr.rn-row'));
      rs.sort(function (a, b) { if (v === 'name') return a.dataset.name.localeCompare(b.dataset.name); var d = (+a.dataset.days) - (+b.dataset.days); return v === 'far' ? -d : d; });
      rs.forEach(function (r) { tb.appendChild(r); });
    });
  }
  q.addEventListener('input', run); so.addEventListener('change', sort); run();
  document.getElementById('rnCsv').addEventListener('click', function () {
    var esc = function (x) { x = String(x == null ? '' : x); if (/^[=+\-@]/.test(x) && !/^-?\d+(\.\d+)?$/.test(x)) x = "'" + x; return '"' + x.replace(/"/g, '""') + '"'; };   // Formula-injection टाळा (आकडे सोडून)
    var lines = [['Item', 'Type', 'Department', 'Reference No', 'Expiry', 'Days Left', 'Status', 'Vendor', 'Renewal Cost'].map(esc).join(',')];
    rows.filter(function (r) { return r.style.display !== 'none'; }).forEach(function (r) {
      var d = r.dataset; lines.push([r.cells[0].childNodes[0].textContent.trim(), d.type, d.dept, d.ref, d.exp, d.days, d.status, d.vendor, d.cost].map(esc).join(','));
    });
    var blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'renewals-' + new Date().toISOString().slice(0, 10) + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
