<?php
// 📷 विद्यार्थी फोटो — ID Card साठी:
//   1) वर्ग-तुकडी निवडा → प्रत्येक विद्यार्थ्यावर टॅप → मोबाईल Camera ने फोटो → लगेच जतन (एकेक)
//   2) बरेच फोटो एकत्र — File चं नाव = प्रवेश क्र. (Gr.No.) किंवा Roll No (उदा. 2892.jpg) → आपोआप जुळवणी
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
$orgId = activeOrgId();
if (!canManageStudents()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">विद्यार्थी फोटो टाकण्याची परवानगी नाही.</div>'); }
if (!studentsReady($pdo)) { header('Location: index.php'); exit; }

$f = ['campus' => (int)($_REQUEST['campus'] ?? 0), 'year' => trim((string)($_REQUEST['year'] ?? '')), 'class' => trim((string)($_REQUEST['class'] ?? '')), 'div' => trim((string)($_REQUEST['div'] ?? ''))];
$years = $pdo->prepare("SELECT DISTINCT academic_year FROM students WHERE organization_id = ? ORDER BY academic_year DESC");
$years->execute([$orgId]);
$years = $years->fetchAll(PDO::FETCH_COLUMN);
if ($f['year'] === '' && $years) $f['year'] = $years[0];

$where = " FROM students s WHERE s.organization_id = ? AND s.is_active = 1";
$wp = [$orgId];
if ($f['campus']) { $where .= " AND s.campus_id = ?"; $wp[] = $f['campus']; }
if ($f['year'] !== '') { $where .= " AND s.academic_year = ?"; $wp[] = $f['year']; }
$cwhere = $where; $cwp = $wp;
if ($f['class'] !== '') { $where .= " AND COALESCE(s.class_name,'') = ?"; $wp[] = $f['class']; }
if ($f['div'] !== '') { $where .= " AND COALESCE(s.division,'') = ?"; $wp[] = $f['div']; }

$setPhoto = function ($sid, $path) use ($pdo, $orgId) {
    $old = $pdo->prepare("SELECT photo_path FROM students WHERE id = ? AND organization_id = ?");
    $old->execute([$sid, $orgId]);
    $old = $old->fetchColumn();
    $pdo->prepare("UPDATE students SET photo_path = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$path, $sid, $orgId]);
    if ($old && $old !== $path && strpos($old, 'assets/uploads/students/') === 0 && is_file(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
};

// 1) एका विद्यार्थ्याचा फोटो (AJAX — Camera / File)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'one') {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int)($_POST['student_id'] ?? 0);
    $ok = $pdo->prepare("SELECT name FROM students WHERE id = ? AND organization_id = ?");
    $ok->execute([$sid, $orgId]);
    $name = $ok->fetchColumn();
    if (!$name) { echo json_encode(['ok' => false, 'msg' => 'विद्यार्थी सापडला नाही.'], JSON_UNESCAPED_UNICODE); exit; }
    [$path, $err] = eowmsSavePhotoUpload($_FILES['photo'] ?? null, 'students', 'stu' . $sid . '_', 700);
    if (!$path) { echo json_encode(['ok' => false, 'msg' => $err ?: 'फोटो निवडा.'], JSON_UNESCAPED_UNICODE); exit; }
    $setPhoto($sid, $path);
    logActivity('विद्यार्थी फोटो टाकला', 'student', $sid, $name);
    echo json_encode(['ok' => true, 'url' => '../' . $path . '?t=' . time()], JSON_UNESCAPED_UNICODE); exit;
}

// 2) बरेच फोटो एकत्र — File नाव = प्रवेश क्र. / Roll No
$bulkResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    $st = $pdo->prepare("SELECT s.id, s.name, s.admission_no, s.roll_no, s.class_name, s.division" . $where);
    $st->execute($wp);
    $byAdm = []; $byRoll = []; $rollDup = [];
    $norm = fn($x) => strtoupper(preg_replace('/[^0-9A-Za-z]/', '', strtr((string)$x, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'])));
    foreach ($st->fetchAll() as $r) {
        if ($r['admission_no'] !== '') $byAdm[$norm($r['admission_no'])] = $r;
        $rk = $norm(ltrim((string)$r['roll_no'], '0'));
        if ($rk !== '') { if (isset($byRoll[$rk])) $rollDup[$rk] = true; $byRoll[$rk] = $r; }
    }
    $files = $_FILES['photos'] ?? null;
    $bulkResult = ['ok' => [], 'miss' => [], 'err' => []];
    $n = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    for ($i = 0; $i < $n && $i < 400; $i++) {
        $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        $stem = pathinfo((string)$one['name'], PATHINFO_FILENAME);
        $key = $norm($stem);
        $stu = $byAdm[$key] ?? null;
        if (!$stu && $f['class'] !== '') { $rk = ltrim($key, '0'); if (isset($byRoll[$rk]) && empty($rollDup[$rk])) $stu = $byRoll[$rk]; }
        if (!$stu) { $bulkResult['miss'][] = $one['name']; continue; }
        [$path, $err] = eowmsSavePhotoUpload($one, 'students', 'stu' . $stu['id'] . '_', 700);
        if (!$path) { $bulkResult['err'][] = $one['name'] . ' — ' . $err; continue; }
        $setPhoto($stu['id'], $path);
        $bulkResult['ok'][] = $stu['name'];
    }
    if ($bulkResult['ok']) logActivity('विद्यार्थी फोटो एकत्र टाकले', 'organization', $orgId, count($bulkResult['ok']) . ' फोटो');
}

$campuses = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll(PDO::FETCH_KEY_PAIR);
$cst = $pdo->prepare("SELECT COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n, SUM(s.photo_path IS NULL OR s.photo_path = '') np" . $cwhere . " GROUP BY cl, dv ORDER BY CAST(cl AS UNSIGNED), cl, dv");
$cst->execute($cwp);
$classes = $cst->fetchAll();
$rows = [];
if ($f['class'] !== '' || count($classes) <= 1) {
    $st = $pdo->prepare("SELECT s.id, s.name, s.admission_no, s.roll_no, s.photo_path" . $where . " ORDER BY CAST(s.roll_no AS UNSIGNED), s.roll_no, s.name LIMIT 400");
    $st->execute($wp);
    $rows = $st->fetchAll();
}
$done = count(array_filter($rows, fn($r) => !empty($r['photo_path']) && eowmsPhotoExists($r['photo_path'])));
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div']], $over), fn($v) => $v !== '' && $v !== null); return '?' . http_build_query($q); };

$pageTitle = 'विद्यार्थी फोटो';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .cl-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;}
  .cl-chips a{font-size:12.5px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .cl-chips a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
  .cl-chips a small{opacity:.7;margin-left:3px;}
  .ph-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:10px;padding:14px;}
  .ph-t{position:relative;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface);cursor:pointer;text-align:center;}
  .ph-t .im{aspect-ratio:5/6;background:var(--canvas);display:flex;align-items:center;justify-content:center;font-size:28px;color:var(--text-dim);}
  .ph-t .im img{width:100%;height:100%;object-fit:cover;}
  .ph-t .nm{font-size:12px;font-weight:600;padding:5px 6px 1px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .ph-t .cd{font-size:10.5px;color:var(--text-dim);padding-bottom:6px;}
  .ph-t .tick{position:absolute;top:6px;right:6px;width:22px;height:22px;border-radius:50%;background:#16A34A;color:#fff;font-size:13px;display:none;align-items:center;justify-content:center;}
  .ph-t.has .tick{display:flex;}
  .ph-t.busy{opacity:.5;pointer-events:none;}
  .ph-t input{display:none;}
  .ph-prog{height:8px;border-radius:99px;background:var(--canvas);overflow:hidden;} .ph-prog i{display:block;height:100%;background:#16A34A;}
  @media (max-width:575px){ .ph-grid{grid-template-columns:repeat(3,1fr);gap:8px;padding:10px;} }
</style>

<div class="page-eyebrow"><a href="index.php" style="color:inherit;text-decoration:none;">🎓 विद्यार्थी</a> · <a href="id-cards.php<?= h($qs()) ?>" style="color:inherit;">🪪 ID Cards</a></div>
<div class="page-title">📷 विद्यार्थी फोटो</div>

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

<?php if ($bulkResult): ?>
  <div class="alert <?= $bulkResult['ok'] ? 'alert-success' : 'alert-warning' ?>" style="border-radius:10px;font-size:13px;">
    ✓ <?= count($bulkResult['ok']) ?> फोटो जुळले व जतन झाले.
    <?php if ($bulkResult['miss']): ?><div class="mt-1">⚠️ <?= count($bulkResult['miss']) ?> File चं नाव कोणत्याही प्रवेश क्र.<?= $f['class'] !== '' ? ' / Roll No' : '' ?> शी जुळलं नाही: <?= h(implode(', ', array_slice($bulkResult['miss'], 0, 15))) ?><?= count($bulkResult['miss']) > 15 ? '…' : '' ?></div><?php endif; ?>
    <?php if ($bulkResult['err']): ?><div class="mt-1">✕ <?= h(implode(' | ', array_slice($bulkResult['err'], 0, 8))) ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($classes): ?>
<div class="cl-chips">
  <?php foreach ($classes as $c): $on = $f['class'] === $c['cl'] && $f['div'] === $c['dv']; ?>
    <a href="<?= h($qs(['class' => $c['cl'], 'div' => $c['dv']])) ?>" class="<?= $on ? 'on' : '' ?>"><?= h(($c['cl'] !== '' ? $c['cl'] : '—') . ($c['dv'] !== '' ? ' - ' . $c['dv'] : '')) ?><small><?= (int)$c['n'] - (int)$c['np'] ?>/<?= (int)$c['n'] ?> 📷</small></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">या वर्षासाठी विद्यार्थी नाहीत — आधी <a href="import.php">यादी Import करा</a>.</div>
<?php endif; ?>

<?php if ($rows): ?>
<div class="panel mb-3">
  <div class="panel-head d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div><div class="title"><?= h($f['class'] !== '' ? $f['class'] . ($f['div'] !== '' ? ' - ' . $f['div'] : '') : 'विद्यार्थी') ?> — <span id="phDone"><?= $done ?></span>/<?= count($rows) ?> फोटो</div>
      <div class="sub">विद्यार्थ्यावर टॅप करा → मोबाईलवर Camera उघडेल → फोटो काढा, लगेच जतन होतो</div></div>
    <a href="id-cards.php<?= h($qs(['generate' => 1])) ?>" target="_blank" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">🪪 या वर्गाची ID Cards</a>
  </div>
  <div style="padding:0 14px;"><div class="ph-prog"><i id="phBar" style="width:<?= count($rows) ? round($done * 100 / count($rows)) : 0 ?>%;"></i></div></div>
  <div class="ph-grid">
    <?php foreach ($rows as $r): $has = !empty($r['photo_path']) && eowmsPhotoExists($r['photo_path']); ?>
      <label class="ph-t <?= $has ? 'has' : '' ?>" data-id="<?= (int)$r['id'] ?>">
        <div class="im"><?php if ($has): ?><img src="../<?= h($r['photo_path']) ?>" alt="" loading="lazy"><?php else: ?>📷<?php endif; ?></div>
        <div class="nm"><?= h($r['name']) ?></div>
        <div class="cd"><?= $r['roll_no'] !== null && $r['roll_no'] !== '' ? 'Roll ' . h($r['roll_no']) . ' · ' : '' ?><?= h($r['admission_no']) ?></div>
        <span class="tick">✓</span>
        <input type="file" accept="image/*">
      </label>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title">📁 बरेच फोटो एकत्र</div><div class="sub">File चं नाव = प्रवेश क्र. (Gr.No.)<?= $f['class'] !== '' ? ' किंवा Roll No' : '' ?> — उदा. <b>2892.jpg</b>. मोठे फोटो आपोआप लहान होतात.</div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;" class="d-flex flex-wrap gap-2 align-items-center" data-no-smart-filter>
    <input type="hidden" name="action" value="bulk">
    <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
    <input type="file" name="photos[]" multiple accept="image/png,image/jpeg,image/webp" class="form-control-custom" style="flex:1;min-width:220px;" required>
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:600;">⬆ Upload करा</button>
    <?php if ($f['class'] === ''): ?><div style="font-size:12px;color:var(--text-dim);width:100%;">💡 Roll No ने जुळवायचं असेल तर आधी वरून वर्ग-तुकडी निवडा.</div><?php endif; ?>
  </form>
</div>

<script>
(function () {
  var done = document.getElementById('phDone'), bar = document.getElementById('phBar'), total = document.querySelectorAll('.ph-t').length;
  document.querySelectorAll('.ph-t input[type=file]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      if (!inp.files.length) return;
      var t = inp.closest('.ph-t'), fd = new FormData();
      fd.append('action', 'one'); fd.append('student_id', t.dataset.id); fd.append('photo', inp.files[0]);
      t.classList.add('busy');
      fetch('photos.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); }).then(function (j) {
        t.classList.remove('busy');
        if (!j.ok) { alert(j.msg || 'फोटो जतन झाला नाही'); return; }
        var wasDone = t.classList.contains('has');
        t.querySelector('.im').innerHTML = '<img src="' + j.url + '" alt="">'; t.classList.add('has');
        if (!wasDone && done) { var n = +done.textContent + 1; done.textContent = n; bar.style.width = Math.round(n * 100 / total) + '%'; }
        inp.value = '';
      }).catch(function () { t.classList.remove('busy'); alert('Network त्रुटी — पुन्हा प्रयत्न करा'); });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
