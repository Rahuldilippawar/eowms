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
$onlyNew = !empty($_GET['only_new']);
$layout = ($_GET['layout'] ?? '') === 'pvc' ? 'pvc' : 'a4';
$size = ($_GET['size'] ?? '') === 'lg' ? 'lg' : 'cr80';
$design = ($_GET['design'] ?? '') === 'modern' ? 'modern' : 'classic';
$side = in_array($_GET['side'] ?? '', ['front', 'back', 'both'], true) ? $_GET['side'] : ($design === 'classic' ? 'front' : 'both');
$canManage = canStudentCards(); // फोटो, Card Design, Print — manage_students किंवा student_id_cards
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
        $pdo->prepare("INSERT INTO student_card_settings (campus_id, organization_id, top_line, title, subtitle, logo_path, signature_path, sign_label, color, show_qr, show_blood, updated_by, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE top_line=VALUES(top_line), title=VALUES(title), subtitle=VALUES(subtitle), logo_path=VALUES(logo_path),
            signature_path=VALUES(signature_path), sign_label=VALUES(sign_label), color=VALUES(color), show_qr=VALUES(show_qr), show_blood=VALUES(show_blood), updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$cid, $orgId, $cut('top_line', 120), $cut('title', 200), $cut('subtitle', 120), $logo === null ? $old['logo_path'] : ($logo ?: null),
                $sign === null ? $old['signature_path'] : ($sign ?: null), $cut('sign_label', 60), $color, !empty($_POST['show_qr']) ? 1 : 0, !empty($_POST['show_blood']) ? 1 : 0, currentUser()['id']]);
        // एकदाच Upload — हाच Logo / सही सगळ्या College / School ला (नवीन Upload केलेलंच; बाकी Design तसंच)
        if (!empty($_POST['apply_all']) && ($logo || $sign)) {
            $others = $pdo->prepare("SELECT id FROM campuses WHERE organization_id = ? AND id <> ?");
            $others->execute([$orgId, $cid]);
            $cp = $pdo->prepare("INSERT INTO student_card_settings (campus_id, organization_id, logo_path, signature_path, updated_by, updated_at) VALUES (?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE logo_path = COALESCE(VALUES(logo_path), logo_path), signature_path = COALESCE(VALUES(signature_path), signature_path), updated_by = VALUES(updated_by), updated_at = NOW()");
            foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $oc) $cp->execute([$oc, $orgId, $logo ?: null, $sign ?: null, currentUser()['id']]);
        }
        logActivity('विद्यार्थी ID Card Design बदलले', 'campus', $cid, !empty($_POST['apply_all']) ? 'सर्व College' : '');
        $_SESSION['flash'] = $err ? '⚠️ ' . $err : '✓ ID Card Design जतन झालं.';
    }
    header('Location: id-cards.php?' . http_build_query(array_filter(['campus' => $cid, 'year' => $_POST['year'] ?? '', 'class' => $_POST['class'] ?? '', 'div' => $_POST['div'] ?? ''], fn($v) => $v !== '' && $v !== 0)) . '#design'); exit;
}
$designs = studentCardSettingsAll($pdo, $orgId, $org);

// 🖨️ Print केल्याची नोंद (Print बटणावरून) — कोणाचं Card कधी, किती वेळा छापलं
$printsReady = (function () use ($pdo) { try { $pdo->query("SELECT 1 FROM student_card_prints LIMIT 0"); return true; } catch (PDOException $e) { return false; } })();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_printed') {
    header('Content-Type: application/json; charset=utf-8');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
    $ids = array_slice($ids, 0, 2000);
    if (!$printsReady || !$ids) { echo json_encode(['ok' => false]); exit; }
    $pp = [$orgId];
    $chk = $pdo->prepare("SELECT s.id FROM students s WHERE s.organization_id = ?" . studentScopeSql($scope, $pp) . " AND s.id IN (" . implode(',', $ids) . ")");
    $chk->execute($pp);
    $okIds = $chk->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare("INSERT INTO student_card_prints (organization_id, student_id, printed_by, printed_at) VALUES (?, ?, ?, NOW())");
    foreach ($okIds as $sid) $ins->execute([$orgId, $sid, currentUser()['id']]);
    if ($okIds) logActivity('विद्यार्थी ID Cards Print केली', 'organization', $orgId, count($okIds) . ' Cards');
    echo json_encode(['ok' => true, 'n' => count($okIds)]); exit;
}

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
$printInfo = [];
if ($printsReady && $all) {
    $pst = $pdo->prepare("SELECT student_id, COUNT(*) n, MAX(printed_at) last FROM student_card_prints WHERE organization_id = ? AND student_id IN (" . implode(',', array_map('intval', array_column($all, 'id'))) . ") GROUP BY student_id");
    $pst->execute([$orgId]);
    foreach ($pst->fetchAll() as $r) $printInfo[(int)$r['student_id']] = $r;
}
foreach ($all as &$r) {
    $r['has_photo'] = !empty($r['photo_path']) && eowmsPhotoExists($r['photo_path']);
    $r['printed'] = $printInfo[(int)$r['id']] ?? null;
    $r['missing'] = array_keys(array_filter(['📷 फोटो' => !$r['has_photo'], 'जन्मतारीख' => empty($r['date_of_birth']), 'पत्ता' => empty($r['address']), 'Contact' => empty($r['parent_mobile']) && empty($r['mobile'])]));
} unset($r);
$printedCount = count(array_filter($all, fn($s) => $s['printed']));
$noPhoto = array_values(array_filter($all, fn($s) => !$s['has_photo']));
$noParent = count(array_filter($all, fn($s) => empty($s['parent_mobile']) && empty($s['mobile'])));
$noDob = count(array_filter($all, fn($s) => empty($s['date_of_birth'])));
$list = array_values(array_filter($all, fn($s) => (!$onlyPhoto || $s['has_photo']) && (!$onlyNew || !$s['printed'])));
$incomplete = count(array_filter($list, fn($s) => $s['missing']));

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
<link rel="stylesheet" href="../assets/css/id-card.css?v=8">
<style>
  .idc-bulk{display:flex;flex-wrap:wrap;gap:22px;justify-content:center;padding:22px 16px 40px;}
  .idc-bulk .idc-pair{gap:8px;flex-direction:column;align-items:center;}
  .idc-bulk .idc-cards{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;}
  .idc-sheet.show-front .idc-back,.idc-sheet.show-back .idc-front,.idc-sheet.show-back .sic{display:none;}
  .idc-warn{display:flex;gap:4px;flex-wrap:wrap;justify-content:center;max-width:4.4in;font-size:11px;}
  .idc-warn span{background:#FEF3C7;color:#92400E;border-radius:99px;padding:1px 8px;}
  .idc-warn span.pr{background:#E0F2FE;color:#075985;}
  .idc-sheet.skip-incomplete .idc-pair.incomplete{display:none;}
  .idc-toolbar .chk{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#C9CCE0;cursor:pointer;}
  .a4pg{display:contents;}
  .idc-toast{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);background:#16A34A;color:#fff;padding:9px 16px;border-radius:10px;font-size:13px;display:none;z-index:30;}
  @media print{
    .idc-warn,.idc-toast{display:none !important;}
    .idc-bulk{display:block;padding:0;} .idc-bulk .idc-cards{gap:4mm;}
    body.lay-a4 .a4pg{display:flex;flex-wrap:wrap;gap:4mm;align-content:flex-start;padding:1.6mm;break-after:page;page-break-after:always;}
    body.lay-a4 .a4pg:last-child{break-after:auto;page-break-after:auto;}
    body.lay-a4 .sic,body.lay-a4 .idc{outline:.2mm dashed #9CA3AF;outline-offset:1.2mm;box-shadow:none;}
    body.lay-pvc .idc-bulk{display:block;} body.lay-pvc .idc-bulk .idc-pair,body.lay-pvc .idc-bulk .idc-cards{display:block;margin:0;}
    body.lay-pvc .sic,body.lay-pvc .idc{box-shadow:none;border-radius:0;page-break-after:always;break-after:page;margin:0;}
  }
</style>
<style id="pageRule"><?= $layout === 'pvc' ? '@media print{@page{size:54mm 86mm;margin:0;}}' : '@media print{@page{size:A4;margin:8mm;}}' ?></style>
</head>
<body class="idc-page lay-<?= $layout ?>">
<div class="idc-toolbar">
  <a class="back" href="<?= h($back) ?>">← मागे</a>
  <span style="font-size:13px;color:#C9CCE0;"><?= $single ? h($single['name']) : count($list) . ' Cards' ?></span>
  <div class="grp" data-opt="size" role="group" aria-label="आकार">
    <button type="button" data-v="cr80" class="<?= $size === 'cr80' ? 'on' : '' ?>" title="54 × 86 mm">PVC Card</button>
    <button type="button" data-v="lg" class="<?= $size === 'lg' ? 'on' : '' ?>" title="86 × 137 mm">मोठा Badge</button>
  </div>
  <div class="grp" data-opt="side" role="group" aria-label="बाजू">
    <button type="button" data-v="front" class="<?= $side === 'front' ? 'on' : '' ?>">Front</button>
    <button type="button" data-v="both" class="<?= $side === 'both' ? 'on' : '' ?>">Front + Back</button>
    <button type="button" data-v="back" class="<?= $side === 'back' ? 'on' : '' ?>">Back</button>
  </div>
  <div class="grp" data-opt="layout" role="group" aria-label="Printer">
    <button type="button" data-v="a4" class="<?= $layout === 'a4' ? 'on' : '' ?>" title="A4 कागदावर — कापायच्या रेषांसह">A4 कागद</button>
    <button type="button" data-v="pvc" class="<?= $layout === 'pvc' ? 'on' : '' ?>" title="PVC ID Card Printer — प्रत्येक Card वेगळं पान">Card Printer</button>
  </div>
  <?php if ($incomplete && !$single): ?><label class="chk"><input type="checkbox" id="skipInc"> ⚠️ अपूर्ण <?= $incomplete ?> वगळा</label><?php endif; ?>
  <button type="button" class="print" id="printBtn">🖨️ Print करा</button>
</div>
<?php if (!$list): ?>
  <div class="idc-hint" style="margin-top:40px;font-size:14px;">निवडलेल्या अटींनुसार एकही विद्यार्थी नाही.</div>
<?php else: ?>
  <div class="idc-hint">पिवळ्या खुणा फक्त स्क्रीनवर दिसतात, Print मध्ये येत नाहीत. लांब नाव / पत्ता आपोआप लहान अक्षरात बसतो. Print केल्यावर नोंद होते — पुढच्या वेळी "फक्त नवीन" निवडता येतं.</div>
<?php endif; ?>
<!--i18n:off-->
<div class="idc-sheet idc-bulk <?= $size === 'lg' ? 'idc-lg' : '' ?> show-<?= $side ?>" id="idcSheet">
  <?php foreach ($list as $s): $c = studentCardData($s, $org, $designs[(int)$s['campus_id']] ?? []); ?>
    <div class="idc-pair<?= $s['missing'] ? ' incomplete' : '' ?>" data-id="<?= (int)$s['id'] ?>">
      <!--i18n:on--><?php if ($s['missing'] || $s['printed']): ?><div class="idc-warn"><?php foreach ($s['missing'] as $m): ?><span><?= h($m) ?> नाही</span><?php endforeach; ?><?php if ($s['printed']): ?><span class="pr">🖨️ आधी छापलं <?= h(date('d/m/Y', strtotime($s['printed']['last']))) ?><?= (int)$s['printed']['n'] > 1 ? ' · ' . (int)$s['printed']['n'] . ' वेळा' : '' ?></span><?php endif; ?></div><?php endif; ?><!--i18n:off-->
      <div class="idc-cards"><?= $design === 'classic' ? studentCardClassic($c) : studentCardFront($c) ?><?= studentCardBack($c) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<!--i18n:on-->
<div class="idc-toast" id="toast"></div>
<script src="../assets/js/id-card-fit.js?v=4"></script>
<script>
  (function () {
    var sheet = document.getElementById('idcSheet'), csrf = <?= json_encode(function_exists('csrfToken') ? csrfToken() : '') ?>;
    var pageCss = { a4: '@media print{@page{size:A4;margin:8mm;}}', pvc: '@media print{@page{size:54mm 86mm;margin:0;}}' };
    document.querySelectorAll('.idc-toolbar .grp').forEach(function (g) {
      g.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        g.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        var opt = g.dataset.opt, v = b.dataset.v;
        if (opt === 'size') { sheet.classList.toggle('idc-lg', v === 'lg'); window.idCardFit && idCardFit(); }
        else if (opt === 'layout') { document.body.className = document.body.className.replace(/lay-\w+/, 'lay-' + v); document.getElementById('pageRule').textContent = pageCss[v]; }
        else sheet.className = sheet.className.replace(/show-\w+/, 'show-' + v);
        var u = new URL(location.href); u.searchParams.set(opt, v); history.replaceState(null, '', u);
        paginate();
      });
    });
    var skip = document.getElementById('skipInc');
    if (skip) skip.addEventListener('change', function () { sheet.classList.toggle('skip-incomplete', skip.checked); paginate(); });
    // A4: पानानुसार गट — Card दोन पानांत कापलं जाऊ नये (PVC 9 / Front+Back 3 / मोठा Badge 4 किंवा 2 प्रति पान)
    function paginate() {
      [].slice.call(sheet.querySelectorAll('.a4pg')).forEach(function (g) { while (g.firstChild) sheet.insertBefore(g.firstChild, g); g.remove(); });
      var lg = sheet.classList.contains('idc-lg'), both = /show-both/.test(sheet.className), per = lg ? (both ? 2 : 4) : (both ? 3 : 9);
      var pairs = [].slice.call(sheet.querySelectorAll('.idc-pair')).filter(function (p) { return !(sheet.classList.contains('skip-incomplete') && p.classList.contains('incomplete')); });
      for (var i = 0; i < pairs.length; i += per) {
        var g = document.createElement('div'); g.className = 'a4pg'; sheet.insertBefore(g, pairs[i]);
        pairs.slice(i, i + per).forEach(function (p) { g.appendChild(p); });
      }
    }
    paginate();
    window.addEventListener('beforeprint', paginate);
    document.getElementById('printBtn').addEventListener('click', function () {
      var ids = [].slice.call(sheet.querySelectorAll('.idc-pair')).filter(function (p) { return p.offsetParent !== null; }).map(function (p) { return p.dataset.id; });
      window.print();
      if (!ids.length) return;
      var fd = new FormData(); fd.append('action', 'mark_printed'); fd.append('ids', ids.join(',')); fd.append('_csrf', csrf);
      fetch('id-cards.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok) { var t = document.getElementById('toast'); t.textContent = '✓ ' + j.n + ' Cards छापल्याची नोंद झाली'; t.style.display = 'block'; setTimeout(function () { t.style.display = 'none'; }, 3500); }
      }).catch(function () {});
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
<link rel="stylesheet" href="../assets/css/id-card.css?v=8">
<script src="../assets/js/id-card-fit.js?v=4"></script>

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

<?php
// 🖼️ Logo / ✍️ सही एकदाच — ज्या College चे विद्यार्थी आहेत पण Logo किंवा सही नाही त्यांची आठवण
$needSetup = [];
if ($canManage) {
    $cq = $pdo->prepare("SELECT DISTINCT campus_id FROM students WHERE organization_id = ? AND is_active = 1");
    $cq->execute([$orgId]);
    foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $d = $designs[(int)$cid] ?? null;
        if ($d && (!$d['logo'] || !$d['sign']) && isset($campuses[(int)$cid])) $needSetup[(int)$cid] = $d;
    }
}
?>
<?php if ($needSetup): ?>
<div class="panel mb-3" style="border:1.5px dashed var(--rail);">
  <div style="padding:14px 18px;" class="d-flex flex-wrap align-items-center gap-2 justify-content-between">
    <div><div style="font-weight:700;font-size:14px;">🖼️ College Logo व ✍️ Principal सही — फक्त एकदाच Upload करा</div>
      <div style="font-size:12.5px;color:var(--text-dim);">एकदा जतन केल्यावर प्रत्येक Card वर आपोआप येतात. बाकी:
        <?php foreach ($needSetup as $cid => $d): ?><a href="id-cards.php?campus=<?= $cid ?>#design" class="sf-chip" style="display:inline-block;font-size:12px;border-radius:99px;padding:2px 9px;background:var(--canvas);margin:2px;text-decoration:none;color:inherit;"><?= h($campuses[$cid]) ?>: <?= $d['logo'] ? '✓' : '✕' ?> Logo · <?= $d['sign'] ? '✓' : '✕' ?> सही</a><?php endforeach; ?>
      </div></div>
    <a href="id-cards.php?campus=<?= (int)array_key_first($needSetup) ?>#design" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;font-weight:600;">⬆ Logo / सही Upload करा</a>
  </div>
</div>
<?php endif; ?>
<?= smartTiles([
    ['label' => 'Cards तयार होतील', 'value' => count($list), 'sub' => $f['class'] !== '' ? h($f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '')) : 'निवडलेल्या वर्षाचे सर्व सक्रिय', 'tone' => 'ok'],
    ['label' => 'फोटो नाही', 'value' => count($noPhoto), 'sub' => count($noPhoto) ? 'Card वर आद्याक्षरं दिसतील' : 'सगळ्यांचे फोटो आहेत', 'tone' => count($noPhoto) ? 'warn' : 'ok'],
    ['label' => 'अपूर्ण माहिती', 'value' => $incomplete, 'sub' => $incomplete ? 'फोटो / जन्मतारीख / पत्ता / Contact नाही' : 'सगळ्यांची माहिती पूर्ण', 'tone' => $incomplete ? 'warn' : 'ok'],
    ['label' => 'Card आधी छापलेले', 'value' => $printedCount . ' / ' . count($all), 'sub' => $printedCount < count($all) ? (count($all) - $printedCount) . ' अजून बाकी' : 'सगळ्यांचे छापले', 'tone' => $printedCount < count($all) ? 'warn' : 'ok'],
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
        <div class="form-label-custom">Printer</div>
        <div class="bk-opt">
          <label><input type="radio" name="layout" value="a4" <?= $layout === 'a4' ? 'checked' : '' ?>><span><b>A4 कागद</b><small>एका पानावर 8–9 Cards, कापायच्या रेषा</small></span></label>
          <label><input type="radio" name="layout" value="pvc" <?= $layout === 'pvc' ? 'checked' : '' ?>><span><b>ID Card Printer</b><small>प्रत्येक Card वेगळं पान (54×86 mm)</small></span></label>
        </div>
        <label class="d-flex align-items-center gap-2 mb-2" style="font-size:13px;"><input type="checkbox" name="only_new" value="1" <?= $onlyNew ? 'checked' : '' ?> style="accent-color:var(--rail);width:16px;height:16px;"> फक्त नवीन — ज्यांचं Card अजून छापलं नाही<?= $printedCount ? ' (' . (count($all) - $printedCount) . ')' : '' ?></label>
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
    $sampleStu = null;
    foreach ($all as $x) { if ((int)$x['campus_id'] === $dc) { $sampleStu = $x; break; } }
    $sampleStu = $sampleStu ?: ['id' => 0, 'admission_no' => '1234', 'name' => 'Student Name Surname', 'class_name' => 'FE', 'division' => '', 'course' => 'AIML', 'roll_no' => '1', 'date_of_birth' => '2008-06-19', 'blood_group' => 'B+',
        'academic_year' => studentCurrentAcademicYear(), 'parent_mobile' => '9876543210', 'mobile' => '', 'address' => 'At. Post, Tal., Dist.', 'photo_path' => '', 'is_active' => 1, 'campus_name' => $campuses[$dc] ?? '', 'father_name' => '', 'mother_name' => ''];
    $dsSample = studentCardData($sampleStu, $org, $ds);
?>
<div class="panel mt-3" id="design">
  <div class="panel-head"><div class="title">🎨 Card Design — <?= h($campuses[$dc] ?? '') ?></div><div class="sub">प्रत्येक College / School साठी एकदाच — मग सगळ्या Cards वर आपोआप</div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;" data-no-smart-filter>
    <input type="hidden" name="action" value="save_design">
    <?php foreach (['year', 'class', 'div'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$f[$k]) ?>"><?php endforeach; ?>
    <div class="row g-4"><div class="col-lg-8">
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
      <?php if (count($campuses) > 1): ?><div class="col-12" style="font-size:13px;"><label class="d-flex align-items-center gap-2"><input type="checkbox" name="apply_all" value="1" style="accent-color:var(--rail);width:16px;height:16px;"> आत्ता Upload केलेला Logo / सही <b>सर्व College / School</b> ला लावा (एकदाच Upload)</label></div><?php endif; ?>
      <div class="col-md-2 col-6"><label class="form-label-custom">सहीखाली</label><input type="text" name="sign_label" class="form-control-custom" maxlength="60" value="<?= h($dsSaved['sign_label'] ?? '') ?>" placeholder="PRINCIPAL"></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">रंग</label><input type="color" name="color" class="form-control form-control-color" style="width:100%;height:38px;" value="<?= h($ds['color']) ?>"></div>
      <div class="col-12 d-flex flex-wrap gap-3" style="font-size:13px;">
        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="show_qr" value="1" <?= $ds['show_qr'] ? 'checked' : '' ?> style="accent-color:var(--rail);width:16px;height:16px;"> फोटोच्या बाजूला लहान QR (Scan करून खरं Card तपासता येतं)</label>
        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="show_blood" value="1" <?= $ds['show_blood'] ? 'checked' : '' ?> style="accent-color:var(--rail);width:16px;height:16px;"> रक्तगट (🩸 फोटोच्या डाव्या बाजूला)</label>
      </div>
    </div>
    <div class="d-flex justify-content-end mt-3"><button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:600;">✓ Design जतन करा</button></div>
    </div>
    <div class="col-lg-4">
      <div class="form-label-custom">👀 लगेच दिसणारा नमुना</div>
      <!--i18n:off--><div class="pv-wrap idc-sheet" id="dsPreview" style="border-radius:14px;"><?= studentCardClassic($dsSample) ?></div><!--i18n:on-->
    </div>
    </div>
  </form>
</div>
<script>
(function () {
  var f = document.querySelector('#design form'), card = document.querySelector('#dsPreview .sic'); if (!f || !card) return;
  var ph = function (n) { var i = f.querySelector('[name=' + n + ']'); return i ? (i.value.trim() || i.placeholder || '') : ''; };
  function ensure(sel, parentSel, cls, before) { var e = card.querySelector(sel); if (!e) { e = document.createElement('div'); e.className = cls; var p = card.querySelector(parentSel); before ? p.insertBefore(e, p.querySelector(before)) : p.appendChild(e); } return e; }
  function upd() {
    card.style.setProperty('--sc', f.querySelector('[name=color]').value);
    ensure('.sic-top', '.sic-head', 'sic-top', '.sic-title').textContent = ph('top_line');
    card.querySelector('.sic-title').textContent = ph('title');
    var sub = ensure('.sic-sub', '.sic-head', 'sic-sub'); sub.textContent = ph('subtitle'); sub.style.display = sub.textContent ? '' : 'none';
    card.querySelector('.sic-sign div:last-child').textContent = ph('sign_label') || 'PRINCIPAL';
    var q = card.querySelector('.sic-qr'); if (q) q.style.visibility = f.querySelector('[name=show_qr]').checked ? '' : 'hidden';
    var b = card.querySelector('.sic-blood'); if (b) b.style.visibility = f.querySelector('[name=show_blood]').checked ? '' : 'hidden';
    window.idCardFit && idCardFit(card);
  }
  function img(input, make) { input.addEventListener('change', function () { var file = input.files[0]; if (!file) return; var r = new FileReader(); r.onload = function () { make(r.result); upd(); }; r.readAsDataURL(file); }); }
  img(f.querySelector('[name=logo]'), function (src) {
    var l = card.querySelector('.sic-logo'); if (!l) { l = document.createElement('div'); l.className = 'sic-logo'; card.insertBefore(l, card.querySelector('.sic-mid')); card.classList.remove('sic-nologo'); }
    l.innerHTML = '<img alt="" src="' + src + '">';
  });
  img(f.querySelector('[name=signature]'), function (src) {
    var sg = card.querySelector('.sic-sign'), i = sg.querySelector('img'); if (!i) { i = document.createElement('img'); sg.insertBefore(i, sg.firstChild); } i.src = src;
  });
  f.addEventListener('input', upd); f.addEventListener('change', upd);
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
