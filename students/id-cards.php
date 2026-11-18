<?php
// 🎓🪪 विद्यार्थी ID Cards — Import केलेल्या माहितीवरून आपोआप: एक विद्यार्थी, एक वर्ग-तुकडी, किंवा संपूर्ण शाळा/कॉलेज
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-id-card.php';
$orgId = activeOrgId();
$scope = studentVisibleScope($pdo);
if ($scope === []) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">विद्यार्थी ID Cards ची परवानगी नाही.</div>'); }
if (!studentsReady($pdo)) { header('Location: index.php'); exit; }

$f = [
    'campus' => (int)($_GET['campus'] ?? 0), 'year' => trim((string)($_GET['year'] ?? '')), 'class' => trim((string)($_GET['class'] ?? '')),
    'div' => trim((string)($_GET['div'] ?? '')), 'id' => (int)($_GET['id'] ?? 0),
];
$codes = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($_GET['codes'] ?? '')))));
$onlyPhoto = !empty($_GET['only_photo']);
$size = ($_GET['size'] ?? '') === 'lg' ? 'lg' : 'cr80';
$design = ($_GET['design'] ?? '') === 'modern' ? 'modern' : 'classic';
$side = in_array($_GET['side'] ?? '', ['front', 'back', 'both'], true) ? $_GET['side'] : ($design === 'classic' ? 'front' : 'both');
$canManage = canManageStudents();
$org = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$org = $org->fetch() ?: [];

// 🎨 College/School नुसार Card Design जतन (Logo, Principal सही, वरच्या ओळी, रंग)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_design' && $canManage) {
    $cid = (int)($_POST['design_campus'] ?? 0);
    $ok = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
    $ok->execute([$cid, $orgId]);
    if ($ok->fetchColumn()) {
        $old = $pdo->prepare("SELECT logo_path, signature_path FROM student_card_settings WHERE campus_id = ? AND organization_id = ?");
        $old->execute([$cid, $orgId]);
        $old = $old->fetch() ?: ['logo_path' => null, 'signature_path' => null];
        $err = '';
        [$logo, $e1] = eowmsSavePhotoUpload($_FILES['logo'] ?? null, 'idcard', 'logo_', 600);
        [$sign, $e2] = eowmsSavePhotoUpload($_FILES['signature'] ?? null, 'idcard', 'sign_', 600);
        $err = $e1 ?: $e2;
        if (!empty($_POST['remove_logo'])) $logo = '';
        if (!empty($_POST['remove_sign'])) $sign = '';
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? $_POST['color'] : null;
        $cut = fn($k, $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n) ?: null;
        $pdo->prepare("INSERT INTO student_card_settings (campus_id, organization_id, top_line, title, subtitle, logo_path, signature_path, sign_label, color, updated_by, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE top_line=VALUES(top_line), title=VALUES(title), subtitle=VALUES(subtitle), logo_path=VALUES(logo_path),
            signature_path=VALUES(signature_path), sign_label=VALUES(sign_label), color=VALUES(color), updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$cid, $orgId, $cut('top_line', 120), $cut('title', 200), $cut('subtitle', 120), $logo === null ? $old['logo_path'] : ($logo ?: null),
                $sign === null ? $old['signature_path'] : ($sign ?: null), $cut('sign_label', 60), $color, currentUser()['id']]);
        logActivity('विद्यार्थी ID Card Design बदलले', 'campus', $cid, '');
        $_SESSION['flash'] = $err ? '⚠️ ' . $err : '✓ ID Card Design जतन झालं.';
    }
    header('Location: id-cards.php?' . http_build_query(array_filter(['campus' => $cid, 'year' => $_POST['year'] ?? '', 'class' => $_POST['class'] ?? '', 'div' => $_POST['div'] ?? ''], fn($v) => $v !== '' && $v !== 0)) . '#design'); exit;
}
$designs = studentCardSettingsAll($pdo, $orgId, $org);

$years = $pdo->prepare("SELECT DISTINCT academic_year FROM students WHERE organization_id = ? ORDER BY academic_year DESC");
$years->execute([$orgId]);
$years = $years->fetchAll(PDO::FETCH_COLUMN);
if ($f['year'] === '' && $years && !$f['id']) $f['year'] = $years[0];

$sql = "SELECT s.*, c.name campus_name, c.address campus_address, c.city campus_city
        FROM students s LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.organization_id = ?";
$p = [$orgId];
$sql .= studentScopeSql($scope, $p);
if ($f['id']) { $sql .= " AND s.id = ?"; $p[] = $f['id']; }
else {
    $sql .= " AND s.is_active = 1";
    if ($f['campus']) { $sql .= " AND s.campus_id = ?"; $p[] = $f['campus']; }
    if ($f['year'] !== '') { $sql .= " AND s.academic_year = ?"; $p[] = $f['year']; }
    if ($f['class'] !== '') { $sql .= " AND COALESCE(s.class_name,'') = ?"; $p[] = $f['class']; }
    if ($f['div'] !== '') { $sql .= " AND COALESCE(s.division,'') = ?"; $p[] = $f['div']; }
    if ($codes) { $sql .= " AND (s.admission_no IN (" . implode(',', array_fill(0, count($codes), '?')) . ") OR s.roll_no IN (" . implode(',', array_fill(0, count($codes), '?')) . "))"; $p = array_merge($p, $codes, $codes); }
}
$sql .= " ORDER BY c.name, s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.roll_no, s.name";
$st = $pdo->prepare($sql);
$st->execute($p);
$all = $st->fetchAll();
foreach ($all as &$r) { $r['has_photo'] = !empty($r['photo_path']) && eowmsPhotoExists($r['photo_path']); } unset($r);
$noPhoto = array_values(array_filter($all, fn($s) => !$s['has_photo']));
$noParent = count(array_filter($all, fn($s) => empty($s['parent_mobile']) && empty($s['mobile'])));
$noDob = count(array_filter($all, fn($s) => empty($s['date_of_birth'])));
$list = $onlyPhoto ? array_values(array_filter($all, fn($s) => $s['has_photo'])) : $all;

if (!empty($_GET['generate']) || $f['id']) {
    if ($f['id'] && !$all) { $_SESSION['flash'] = '⚠️ विद्यार्थी सापडला नाही.'; header('Location: index.php'); exit; }
    if (!$f['id']) logActivity('विद्यार्थी ID Cards तयार केली', 'organization', $orgId, count($list) . ' Cards' . ($f['class'] !== '' ? ' · ' . $f['class'] . ($f['div'] !== '' ? '-' . $f['div'] : '') : ''));
    $back = $f['id'] ? 'student.php?id=' . $f['id'] : 'id-cards.php?' . http_build_query(array_diff_key($_GET, ['generate' => 1]));
    $single = $f['id'] ? $all[0] : null;
    ?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $single ? 'ID Card — ' . h($single['name']) : 'विद्यार्थी ID Cards (' . count($list) . ')' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&family=Roboto+Condensed:wght@400;700;800&family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/id-card.css?v=2">
<style>
  .idc-bulk{display:flex;flex-wrap:wrap;gap:22px;justify-content:center;padding:22px 16px 40px;}
  .idc-bulk .idc-pair{gap:8px;}
  .idc-sheet.show-front .idc-back,.idc-sheet.show-back .idc-front,.idc-sheet.show-back .sic{display:none;}
  @media print{ .idc-bulk{gap:5mm;padding:0;} .idc-bulk .idc-pair{gap:2mm;} }
</style>
</head>
<body class="idc-page">
<div class="idc-toolbar">
  <a class="back" href="<?= h($back) ?>">← मागे</a>
  <span style="font-size:13px;color:#C9CCE0;"><?= $single ? h($single['name']) : count($list) . ' Cards' ?></span>
  <div class="grp" data-opt="size" role="group" aria-label="आकार">
    <button type="button" data-v="cr80" class="<?= $size === 'cr80' ? 'on' : '' ?>" title="PVC Card Printer — 54 × 86 mm">PVC Card</button>
    <button type="button" data-v="lg" class="<?= $size === 'lg' ? 'on' : '' ?>" title="कागदावर Print करून Lamination — 86 × 137 mm">मोठा Badge</button>
  </div>
  <div class="grp" data-opt="side" role="group" aria-label="बाजू">
    <button type="button" data-v="front" class="<?= $side === 'front' ? 'on' : '' ?>">Front</button>
    <button type="button" data-v="both" class="<?= $side === 'both' ? 'on' : '' ?>">Front + Back (QR)</button>
    <button type="button" data-v="back" class="<?= $side === 'back' ? 'on' : '' ?>">Back</button>
  </div>
  <button type="button" class="print" onclick="window.print()">🖨️ Print करा</button>
</div>
<?php if (!$list): ?>
  <div class="idc-hint" style="margin-top:40px;font-size:14px;">निवडलेल्या अटींनुसार एकही विद्यार्थी नाही.</div>
<?php else: ?>
  <div class="idc-hint">QR मध्ये सुरक्षित Signature आहे — Scan केल्यावर Card खरं आहे का, विद्यार्थी सध्या शिकत आहे का ते लगेच दिसतं.</div>
<?php endif; ?>
<!--i18n:off-->
<div class="idc-sheet idc-bulk <?= $size === 'lg' ? 'idc-lg' : '' ?> show-<?= $side ?>" id="idcSheet">
  <?php foreach ($list as $s): $c = studentCardData($s, $org, $designs[(int)$s['campus_id']] ?? []); ?>
    <div class="idc-pair"><?= $design === 'classic' ? studentCardClassic($c) : studentCardFront($c) ?><?= studentCardBack($c) ?></div>
  <?php endforeach; ?>
</div>
<!--i18n:on-->
<script>
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
    <?php
    exit;
}

// ---------- निवड पान ----------
$campuses = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll(PDO::FETCH_KEY_PAIR);
// वर्ग-तुकडी chips (वर्ग/तुकडी फिल्टर सोडून)
$cp = [$orgId];
$csql = "SELECT COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n, SUM(s.photo_path IS NULL OR s.photo_path = '') np FROM students s WHERE s.organization_id = ? AND s.is_active = 1" . studentScopeSql($scope, $cp);
if ($f['campus']) { $csql .= " AND s.campus_id = ?"; $cp[] = $f['campus']; }
if ($f['year'] !== '') { $csql .= " AND s.academic_year = ?"; $cp[] = $f['year']; }
$csql .= " GROUP BY cl, dv ORDER BY CAST(cl AS UNSIGNED), cl, dv";
$cst = $pdo->prepare($csql);
$cst->execute($cp);
$classes = $cst->fetchAll();
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div']], $over), fn($v) => $v !== '' && $v !== null); return 'id-cards.php' . ($q ? '?' . http_build_query($q) : ''); };

$pageTitle = 'विद्यार्थी ID Cards';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .bk-opt{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .bk-opt label{flex:1;min-width:140px;display:flex;gap:10px;align-items:flex-start;padding:11px 13px;border:1.5px solid var(--line);border-radius:12px;cursor:pointer;font-size:13px;}
  .bk-opt label:has(input:checked){border-color:var(--rail);background:color-mix(in srgb,var(--rail) 6%,transparent);}
  .bk-opt input{accent-color:var(--rail);margin-top:3px;}
  .bk-opt b{display:block;font-size:13.5px;} .bk-opt small{color:var(--text-dim);font-size:11.5px;}
  .cl-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;}
  .cl-chips a{font-size:12.5px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .cl-chips a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
  .cl-chips a small{opacity:.7;margin-left:3px;}
  .bk-miss{display:flex;flex-wrap:wrap;gap:6px;} .bk-miss a{font-size:12px;padding:3px 10px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);text-decoration:none;color:inherit;}
  .pv-wrap{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;padding:14px 10px 18px;background:#ECEAF1;border-radius:0 0 14px 14px;}
</style>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/id-card.css?v=2">

<div class="page-eyebrow"><a href="index.php" style="color:inherit;text-decoration:none;">🎓 विद्यार्थी</a></div>
<div class="page-title">🪪 विद्यार्थी ID Cards</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">Import केलेल्या यादीवरून आपोआप Cards — वर्ग-तुकडी निवडा, Print करा. फोटो नसेल तर <a href="photos.php?<?= h(http_build_query(array_filter(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div']]))) ?>">📷 फोटो टाका</a> (मोबाईलने थेट काढता येतो).</p>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3" data-no-smart-filter>
  <?php if (count($campuses) > 1): ?>
  <select name="campus" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व College / School</option>
    <?php foreach ($campuses as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= $f['campus'] === (int)$cid ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="year" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php foreach ($years as $y): ?><option value="<?= h($y) ?>" <?= $f['year'] === $y ? 'selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?>
  </select>
</form>

<?php if ($classes): ?>
<div class="cl-chips">
  <a href="<?= h($qs(['class' => '', 'div' => ''])) ?>" class="<?= $f['class'] === '' ? 'on' : '' ?>">सर्व<small><?= array_sum(array_column($classes, 'n')) ?></small></a>
  <?php foreach ($classes as $c): $on = $f['class'] === $c['cl'] && $f['div'] === $c['dv']; ?>
    <a href="<?= h($qs(['class' => $c['cl'], 'div' => $c['dv']])) ?>" class="<?= $on ? 'on' : '' ?>"><?= h(($c['cl'] !== '' ? $c['cl'] : '—') . ($c['dv'] !== '' ? ' - ' . $c['dv'] : '')) ?><small><?= (int)$c['n'] ?></small></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?= smartTiles([
    ['label' => 'Cards तयार होतील', 'value' => count($list), 'sub' => $f['class'] !== '' ? h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) : 'निवडलेल्या वर्षाचे सर्व सक्रिय', 'tone' => 'ok'],
    ['label' => 'फोटो नाही', 'value' => count($noPhoto), 'sub' => count($noPhoto) ? 'Card वर आद्याक्षरं दिसतील' : 'सगळ्यांचे फोटो आहेत', 'tone' => count($noPhoto) ? 'warn' : 'ok'],
    ['label' => 'पालक मोबाईल नाही', 'value' => $noParent, 'sub' => 'Back वर हाताने लिहायची जागा', 'tone' => $noParent ? 'warn' : 'ok'],
    ['label' => 'जन्मतारीख नाही', 'value' => $noDob, 'sub' => $noDob ? 'Card वर "—" दिसेल' : 'सगळ्यांची आहे', 'tone' => $noDob ? 'warn' : 'ok'],
]) ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel">
      <form method="GET" style="padding:18px 20px;" target="_blank" data-no-smart-filter>
        <input type="hidden" name="generate" value="1">
        <?php foreach (['campus', 'year', 'class', 'div'] as $k): if ($f[$k] !== '' && $f[$k] !== 0): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$f[$k]) ?>"><?php endif; endforeach; ?>
        <label class="form-label-custom">फक्त हे विद्यार्थी (ऐच्छिक — प्रवेश क्र. / Roll No, स्वल्पविरामाने)</label>
        <input type="text" name="codes" class="form-control-custom mb-3" value="<?= h(implode(', ', $codes)) ?>" placeholder="उदा. 1021, 1022 किंवा Roll 5, 7">
        <div class="form-label-custom">Design</div>
        <div class="bk-opt">
          <label><input type="radio" name="design" value="classic" <?= $design === 'classic' ? 'checked' : '' ?>><span><b>संस्थेचा नमुना</b><small>निळी चौकट · Logo · Principal सही</small></span></label>
          <label><input type="radio" name="design" value="modern" <?= $design === 'modern' ? 'checked' : '' ?>><span><b>Modern (QR सह)</b><small>Front वर QR, Roll, रक्तगट</small></span></label>
        </div>
        <div class="form-label-custom">आकार</div>
        <div class="bk-opt">
          <label><input type="radio" name="size" value="cr80" <?= $size === 'cr80' ? 'checked' : '' ?>><span><b>PVC Card (54×86 mm)</b><small>ID Card Printer / PVC Sheet</small></span></label>
          <label><input type="radio" name="size" value="lg" <?= $size === 'lg' ? 'checked' : '' ?>><span><b>मोठा Badge (86×137 mm)</b><small>कागदावर Print + Lamination</small></span></label>
        </div>
        <div class="form-label-custom">बाजू</div>
        <div class="bk-opt">
          <label><input type="radio" name="side" value="front" <?= $side === 'front' ? 'checked' : '' ?>><span><b>फक्त Front</b><small>नमुन्याप्रमाणे एक बाजू</small></span></label>
          <label><input type="radio" name="side" value="both" <?= $side === 'both' ? 'checked' : '' ?>><span><b>Front + Back</b><small>Back वर QR, पालक, नियम</small></span></label>
          <label><input type="radio" name="side" value="back" <?= $side === 'back' ? 'checked' : '' ?>><span><b>फक्त Back</b></span></label>
        </div>
        <label class="d-flex align-items-center gap-2 mb-3" style="font-size:13px;"><input type="checkbox" name="only_photo" value="1" <?= $onlyPhoto ? 'checked' : '' ?> style="accent-color:var(--rail);width:16px;height:16px;"> फक्त फोटो असलेल्यांची Cards</label>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:10px;font-size:14px;padding:11px;font-weight:600;" <?= $all ? '' : 'disabled' ?>>🪪 <?= count($list) ?> ID Cards तयार करा</button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if ($list): $c0 = studentCardData($list[0], $org, $designs[(int)$list[0]['campus_id']] ?? []); ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">👀 नमुना — <?= h($list[0]['name']) ?></div></div>
      <!--i18n:off--><div class="pv-wrap idc-sheet"><?= studentCardClassic($c0) ?><?= studentCardBack($c0) ?></div><!--i18n:on-->
    </div>
    <?php endif; ?>
    <div class="panel">
      <div class="panel-head"><div class="title">📷 फोटो नसलेले (<?= count($noPhoto) ?>)</div><div class="sub">फोटो टाकल्यावर Card पूर्ण होतं</div></div>
      <div style="padding:12px 18px 18px;">
        <?php if (!$noPhoto): ?><div style="font-size:13px;color:var(--text-dim);">✅ सगळ्यांचे फोटो आहेत.</div>
        <?php else: ?>
          <div class="bk-miss">
            <?php foreach (array_slice($noPhoto, 0, 30) as $s): ?><a href="student.php?id=<?= (int)$s['id'] ?>"><?= h(($s['roll_no'] ? $s['roll_no'] . '. ' : '') . $s['name']) ?></a><?php endforeach; ?>
            <?php if (count($noPhoto) > 30): ?><span style="font-size:12px;color:var(--text-dim);">+<?= count($noPhoto) - 30 ?> आणखी</span><?php endif; ?>
          </div>
          <?php if ($canManage): ?><a href="photos.php?<?= h(http_build_query(array_filter(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div']]))) ?>" class="btn btn-sm mt-3" style="background:var(--rail);color:#fff;border-radius:8px;">📷 फोटो टाका / काढा</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($canManage && $campuses):
    $dc = $f['campus'] ?: (int)($list[0]['campus_id'] ?? array_key_first($campuses));
    $ds = $designs[$dc] ?? studentCardDefaults([], $org);
    $dsSaved = $pdo->prepare("SELECT * FROM student_card_settings WHERE campus_id = ? AND organization_id = ?"); $dsSaved->execute([$dc, $orgId]); $dsSaved = $dsSaved->fetch() ?: [];
?>
<div class="panel mt-3" id="design">
  <div class="panel-head"><div class="title">🎨 Card Design — <?= h($campuses[$dc] ?? '') ?></div><div class="sub">प्रत्येक College / School साठी एकदाच — मग सगळ्या Cards वर आपोआप</div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;" data-no-smart-filter>
    <input type="hidden" name="action" value="save_design">
    <?php foreach (['year', 'class', 'div'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$f[$k]) ?>"><?php endforeach; ?>
    <div class="row g-3">
      <?php if (count($campuses) > 1): ?>
      <div class="col-md-6"><label class="form-label-custom">College / School</label>
        <select name="design_campus" class="form-select-custom" onchange="location.href='id-cards.php?campus='+this.value+'#design'">
          <?php foreach ($campuses as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= $dc === (int)$cid ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?>
        </select></div>
      <?php else: ?><input type="hidden" name="design_campus" value="<?= (int)$dc ?>"><?php endif; ?>
      <div class="col-md-6"><label class="form-label-custom">सर्वात वरची लहान ओळ</label><input type="text" name="top_line" class="form-control-custom" maxlength="120" value="<?= h($dsSaved['top_line'] ?? '') ?>" placeholder="<?= h($ds['top_line']) ?>"></div>
      <div class="col-md-8"><label class="form-label-custom">संस्थेचं नाव (मोठ्या अक्षरात)</label><input type="text" name="title" class="form-control-custom" maxlength="200" value="<?= h($dsSaved['title'] ?? '') ?>" placeholder="<?= h($ds['title']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">खालची ओळ (उदा. AMRIT - DEGREE)</label><input type="text" name="subtitle" class="form-control-custom" maxlength="120" value="<?= h($dsSaved['subtitle'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">Logo (मध्ये मोठा) <?= $ds['logo'] ? '✓' : '' ?></label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control-custom">
        <?php if (!empty($dsSaved['logo_path'])): ?><label style="font-size:12px;"><input type="checkbox" name="remove_logo" value="1"> Logo काढा</label><?php endif; ?></div>
      <div class="col-md-4"><label class="form-label-custom">Principal सही (पांढऱ्या कागदावरचा फोटो) <?= $ds['sign'] ? '✓' : '' ?></label><input type="file" name="signature" accept="image/png,image/jpeg,image/webp" class="form-control-custom">
        <?php if (!empty($dsSaved['signature_path'])): ?><label style="font-size:12px;"><input type="checkbox" name="remove_sign" value="1"> सही काढा</label><?php endif; ?></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">सहीखाली</label><input type="text" name="sign_label" class="form-control-custom" maxlength="60" value="<?= h($dsSaved['sign_label'] ?? '') ?>" placeholder="PRINCIPAL"></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">रंग</label><input type="color" name="color" class="form-control form-control-color" style="width:100%;height:38px;" value="<?= h($ds['color']) ?>"></div>
    </div>
    <div class="d-flex justify-content-end mt-3"><button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:600;">✓ Design जतन करा</button></div>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
