<?php
// 🪪 Bulk ID Cards — विभाग/संपूर्ण संस्थेची Cards एकत्र (Front + Back जोडीने किंवा एकच बाजू)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/id-card.php';
requireLogin('../');
$user = currentUser();

if (!isManagementOrAbove() && !hasRole('super_admin')) {
    http_response_code(403);
    die('तुम्हाला Bulk ID Cards तयार करण्याची परवानगी नाही.');
}

$orgId = activeOrgId();
$deptId = (int)($_GET['department_id'] ?? 0);
$onlyPhoto = !empty($_GET['only_photo']);
$size = ($_GET['size'] ?? '') === 'lg' ? 'lg' : 'cr80';
$side = in_array($_GET['side'] ?? '', ['front', 'back'], true) ? $_GET['side'] : 'both';
$codes = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($_GET['codes'] ?? '')))));

$sql = "SELECT u.*, COALESCE(NULLIF(d.name_local, ''), d.name) AS dept_name, c.name AS campus_name, c.address AS campus_address, c.city AS campus_city
        FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id
        WHERE u.organization_id = ? AND u.is_active = 1";
$params = [$orgId];
if ($deptId) { $sql .= " AND u.department_id = ?"; $params[] = $deptId; }
if ($codes) { $sql .= " AND u.employee_code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")"; $params = array_merge($params, $codes); }
$sql .= " ORDER BY d.name_local, u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();
$noPhoto = array_values(array_filter($all, fn($e) => empty($e['photo_path'])));
$noEmergency = count(array_filter($all, fn($e) => empty($e['emergency_contact_mobile'])));
$employees = $onlyPhoto ? array_values(array_filter($all, fn($e) => !empty($e['photo_path']))) : $all;

if (!empty($_GET['generate'])) {
    $orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $orgStmt->execute([$orgId]);
    $org = $orgStmt->fetch() ?: [];
    logActivity('Bulk ID Cards तयार केली', 'organization', $orgId, count($employees) . ' Cards');
    ?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bulk ID Cards (<?= count($employees) ?>)</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/id-card.css?v=1">
<style>
  .idc-bulk{display:flex;flex-wrap:wrap;gap:22px;justify-content:center;padding:22px 16px 40px;}
  .idc-bulk .idc-pair{gap:8px;}
  @media print{ .idc-bulk{gap:5mm;padding:0;} .idc-bulk .idc-pair{gap:2mm;} }
</style>
</head>
<body class="idc-page">
<div class="idc-toolbar">
  <a class="back" href="id-cards-bulk.php?<?= h(http_build_query(array_diff_key($_GET, ['generate' => 1]))) ?>">← मागे</a>
  <span style="font-size:13px;color:#C9CCE0;"><?= count($employees) ?> Cards · <?= $size === 'lg' ? 'मोठा Badge' : 'PVC Card' ?> · <?= ['both' => 'Front + Back', 'front' => 'फक्त Front', 'back' => 'फक्त Back'][$side] ?></span>
  <button type="button" class="print" onclick="window.print()">🖨️ सगळे Print करा</button>
</div>
<?php if (!$employees): ?>
  <div class="idc-hint" style="margin-top:40px;font-size:14px;">निवडलेल्या अटींनुसार एकही कर्मचारी नाही.</div>
<?php endif; ?>
<!--i18n:off-->
<div class="idc-sheet idc-bulk <?= $size === 'lg' ? 'idc-lg' : '' ?>">
  <?php foreach ($employees as $emp): $c = idCardData($emp, $org); ?>
    <div class="idc-pair">
      <?php if ($side !== 'back') { echo idCardFront($c); } ?>
      <?php if ($side !== 'front') { echo idCardBack($c); } ?>
    </div>
  <?php endforeach; ?>
</div>
<!--i18n:on-->
</body>
</html>
    <?php
    exit;
}

$dStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$dStmt->execute([$orgId]);
$departments = $dStmt->fetchAll();

$pageTitle = 'Bulk ID Cards';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .bk-opt{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .bk-opt label{flex:1;min-width:150px;display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;cursor:pointer;font-size:13px;transition:border-color .15s,background .15s;}
  .bk-opt label:has(input:checked){border-color:var(--rail);background:color-mix(in srgb,var(--rail) 6%,transparent);}
  .bk-opt input{accent-color:var(--rail);margin-top:3px;}
  .bk-opt b{display:block;font-size:13.5px;} .bk-opt small{color:var(--text-dim);font-size:11.5px;}
  .bk-miss{display:flex;flex-wrap:wrap;gap:6px;} .bk-miss a{font-size:12px;padding:3px 10px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);text-decoration:none;color:inherit;}
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">🪪 Bulk ID Card Generator</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">संपूर्ण संस्था, एक विभाग किंवा निवडक कर्मचारी — सगळ्यांची Front + Back ID Cards एकाच वेळी Print करा. QR मध्ये सुरक्षित Signature असतं.</p>

<?= smartTiles([
    ['label' => 'Cards तयार होतील', 'value' => count($employees), 'sub' => $deptId || $codes ? 'निवडलेल्या अटींनुसार' : 'सर्व Active कर्मचारी', 'tone' => 'ok'],
    ['label' => 'फोटो नाही', 'value' => count($noPhoto), 'sub' => count($noPhoto) ? 'Card वर फक्त आद्याक्षरं दिसतील' : 'सगळ्यांचे फोटो आहेत', 'tone' => count($noPhoto) ? 'warn' : 'ok'],
    ['label' => 'Emergency Contact नाही', 'value' => $noEmergency, 'sub' => 'Back वर हाताने लिहायची जागा', 'tone' => $noEmergency ? 'warn' : 'ok'],
]) ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel">
      <form method="GET" style="padding:20px 22px;" target="_blank">
        <input type="hidden" name="generate" value="1">
        <label class="form-label-custom">विभाग</label>
        <select name="department_id" class="form-select-custom mb-3" onchange="location.search='department_id='+this.value">
          <option value="">-- संपूर्ण संस्था (सर्व विभाग) --</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="form-label-custom">फक्त हे कर्मचारी (ऐच्छिक — Employee ID, स्वल्पविरामाने)</label>
        <input type="text" name="codes" class="form-control-custom mb-3" value="<?= h(implode(', ', $codes)) ?>" placeholder="उदा. MTN-STF1, GM-001">

        <div class="form-label-custom">आकार</div>
        <div class="bk-opt">
          <label><input type="radio" name="size" value="cr80" <?= $size === 'cr80' ? 'checked' : '' ?>><span><b>PVC Card (54×86 mm)</b><small>ID Card Printer / PVC Sheet साठी</small></span></label>
          <label><input type="radio" name="size" value="lg" <?= $size === 'lg' ? 'checked' : '' ?>><span><b>मोठा Badge (86×137 mm)</b><small>कागदावर Print + Lamination</small></span></label>
        </div>
        <div class="form-label-custom">बाजू</div>
        <div class="bk-opt">
          <label><input type="radio" name="side" value="both" <?= $side === 'both' ? 'checked' : '' ?>><span><b>Front + Back</b><small>जोडीने — कापून चिकटवा</small></span></label>
          <label><input type="radio" name="side" value="front" <?= $side === 'front' ? 'checked' : '' ?>><span><b>फक्त Front</b></span></label>
          <label><input type="radio" name="side" value="back" <?= $side === 'back' ? 'checked' : '' ?>><span><b>फक्त Back</b></span></label>
        </div>
        <label class="d-flex align-items-center gap-2 mb-3" style="font-size:13px;"><input type="checkbox" name="only_photo" value="1" <?= $onlyPhoto ? 'checked' : '' ?> style="accent-color:var(--rail);width:16px;height:16px;"> फक्त फोटो असलेल्यांची Cards</label>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:10px;font-size:14px;padding:11px;font-weight:600;"><i class="fa-solid fa-id-card me-1"></i>ID Cards तयार करा</button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">📷 फोटो नसलेले (<?= count($noPhoto) ?>)</div><div class="sub">Profile वर फोटो टाकल्यावर Card अधिक व्यावसायिक दिसतं</div></div>
      <div style="padding:12px 18px 18px;">
        <?php if (!$noPhoto): ?><div style="font-size:13px;color:var(--text-dim);">✅ सगळ्यांचे फोटो आहेत.</div>
        <?php else: ?>
          <div class="bk-miss">
            <?php foreach (array_slice($noPhoto, 0, 40) as $e): ?><a href="profile.php?id=<?= (int)$e['id'] ?>"><?= h($e['name']) ?></a><?php endforeach; ?>
            <?php if (count($noPhoto) > 40): ?><span style="font-size:12px;color:var(--text-dim);">+<?= count($noPhoto) - 40 ?> आणखी</span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
