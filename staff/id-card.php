<?php
// 🪪 एका कर्मचाऱ्याचं ID Card — Front + Back, CR80 (PVC Printer) किंवा मोठा Badge
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/id-card.php';
requireLogin('../');
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

// स्वतःचं Card सगळे बघू/Print करू शकतात; दुसऱ्याचं फक्त Management / Super Admin
$stmt = $pdo->prepare("
    SELECT u.*, COALESCE(NULLIF(d.name_local, ''), d.name) AS dept_name, c.name AS campus_name, c.address AS campus_address, c.city AS campus_city
    FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.id = ? AND u.organization_id = ?
");
$stmt->execute([$id, $orgId]);
$emp = $stmt->fetch();
if (!$emp) { die('कर्मचारी सापडला नाही.'); }

if ((int)$emp['id'] !== (int)$user['id'] && !isManagementOrAbove() && !hasRole('super_admin')) {
    http_response_code(403);
    die('तुम्हाला हे ID Card बघण्याची परवानगी नाही.');
}

$orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$orgStmt->execute([$orgId]);
$org = $orgStmt->fetch() ?: [];
$card = idCardData($emp, $org);
$size = ($_GET['size'] ?? '') === 'lg' ? 'lg' : 'cr80';
$side = in_array($_GET['side'] ?? '', ['front', 'back'], true) ? $_GET['side'] : 'both';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ID Card — <?= h($emp['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/id-card.css?v=1">
</head>
<body class="idc-page">
<div class="idc-toolbar">
  <a class="back" href="profile.php?id=<?= (int)$emp['id'] ?>">← मागे</a>
  <div class="grp" data-opt="size" role="group" aria-label="आकार">
    <button type="button" data-v="cr80" class="<?= $size === 'cr80' ? 'on' : '' ?>" title="PVC Card Printer — 54 × 86 mm">PVC Card</button>
    <button type="button" data-v="lg" class="<?= $size === 'lg' ? 'on' : '' ?>" title="कागदावर Print करून Lamination — 86 × 137 mm">मोठा Badge</button>
  </div>
  <div class="grp" data-opt="side" role="group" aria-label="बाजू">
    <button type="button" data-v="both" class="<?= $side === 'both' ? 'on' : '' ?>">दोन्ही बाजू</button>
    <button type="button" data-v="front" class="<?= $side === 'front' ? 'on' : '' ?>">Front</button>
    <button type="button" data-v="back" class="<?= $side === 'back' ? 'on' : '' ?>">Back</button>
  </div>
  <button type="button" class="print" onclick="window.print()">🖨️ Print करा</button>
</div>
<div class="idc-hint">QR मध्ये सुरक्षित Signature आहे — Scan केल्यावर Card खरं आहे का, कर्मचारी सध्या Active आहे का ते लगेच दिसतं.</div>

<!--i18n:off-->
<div class="idc-sheet idc-stage <?= $size === 'lg' ? 'idc-lg' : '' ?> show-<?= $side ?>" id="idcSheet">
  <div class="idc-pair">
    <div class="idc-col-front"><div class="idc-side-lbl">Front</div><?= idCardFront($card) ?></div>
    <div class="idc-col-back"><div class="idc-side-lbl">Back</div><?= idCardBack($card) ?></div>
  </div>
</div>
<!--i18n:on-->

<script>
  // आकार / बाजू — URL मध्ये ठेवतो (Bookmark/Refresh केलं तरी तसंच), Print साठी लगेच बदल
  (function () {
    var sheet = document.getElementById('idcSheet');
    document.querySelectorAll('.idc-toolbar .grp').forEach(function (g) {
      g.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        g.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        var opt = g.dataset.opt, v = b.dataset.v;
        if (opt === 'size') sheet.classList.toggle('idc-lg', v === 'lg');
        else sheet.className = sheet.className.replace(/show-\w+/, 'show-' + v);
        var u = new URL(location.href); u.searchParams.set(opt, v); history.replaceState(null, '', u);
      });
    });
  })();
</script>
</body>
</html>
