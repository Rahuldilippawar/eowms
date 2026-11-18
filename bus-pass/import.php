<?php
// 📥 बस विद्यार्थी Import (बस पास विभाग — विद्यार्थी विभागापासून वेगळं) — CSV / Excel: Upload → Columns (कुठून / कुठपर्यंत / मार्गसह) → Preview → Import → (हवं तर मागे घ्या)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/sheet-reader.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';
$user = currentUser();
$orgId = activeOrgId();
if (!canBusPass()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बस विद्यार्थी Import ची परवानगी नाही.</div>'); }
if (!busStudentsReady($pdo)) { header('Location: index.php'); exit; }

$tmpDir = __DIR__ . '/../storage/tmp/bus-student-import';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
$tokFile = function ($t) use ($tmpDir) { return preg_match('/^[a-f0-9]{24}$/', (string)$t) ? $tmpDir . '/' . $t . '.json' : null; };
$load = function ($t) use ($tokFile, $orgId, $user) {
    $f = $tokFile($t); if (!$f || !is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return $d && (int)$d['org'] === (int)$orgId && (int)$d['uid'] === (int)$user['id'] ? $d : null;
};

$campusRows = $pdo->prepare("SELECT id, name, city FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusRows->execute([$orgId]);
$campusRows = $campusRows->fetchAll(PDO::FETCH_UNIQUE);
$campuses = array_map(fn($c) => $c['name'], $campusRows);
$fields = busStudentImportFields();

// 📄 नमुना CSV
if (isset($_GET['template'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bus-students-template.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Reg No', 'Student Name', 'Class', 'Division', 'Source', 'Destination (Stop)', 'Total Fee', 'Paid Fee', 'Balance Fee', 'Route / Bus', 'Parent Mobile']);
    fputcsv($o, ['26161436', 'SADGIR DIKSHA RAMDAS', 'DSE', 'COMP', 'AMRIT CAMPUS', 'ATGAON', '13750', '7000', '6750', 'Route 3', '9876543210']);
    fclose($o); exit;
}

/** एक ओळ → जतनासाठी बस विद्यार्थी (Defaults: College, वर्ष, वर्ग, तुकडी, कुठपर्यंत) */
$busSrc = busSource($pdo, $orgId);
$buildRow = function (array $r, array $d) use ($campuses, $busSrc) {
    $s = studentRowFromSheet($r, $d['map']);
    if (isset($s['_err'])) return $s;
    $o = $d['opts'];
    $s['campus_id'] = (int)$o['campus'] ?: null;
    if (!empty($s['campus'])) {
        $k = studentNameKey($s['campus']);
        foreach ($campuses as $cid => $cn) { if ($k !== '' && studentNameKey($cn) === $k) { $s['campus_id'] = (int)$cid; break; } }
    }
    unset($s['campus'], $s['admission_date']);
    foreach (['academic_year' => 'year', 'class_name' => 'class', 'division' => 'div', 'to_place' => 'to', 'from_place' => 'from', 'route_name' => 'route'] as $k => $ok) {
        if (empty($s[$k]) && ($o[$ok] ?? '') !== '') $s[$k] = $o[$ok];
    }
    if (empty($s['from_place'])) $s['from_place'] = $busSrc; // Source नेहमी (उदा. AMRIT CAMPUS)
    foreach (['from_place', 'to_place'] as $k) { if (!empty($s[$k]) && !preg_match('/^[A-Z0-9 .,&\-]+$/', (string)$s[$k])) $s[$k] = nameCase((string)$s[$k]); }
    // ₹ शुल्क: एकूण + बाकी दिलं असेल आणि भरलेलं नसेल तर भरलेलं = एकूण − बाकी
    $tot = busMoney($s['total_fee'] ?? null); $paid = busMoney($s['paid_fee'] ?? null); $bal = busMoney($s['balance_fee'] ?? null);
    if ($paid === null && $tot !== null && $bal !== null) $paid = max(0, $tot - $bal);
    if ($tot === null && $paid !== null && $bal !== null) $tot = $paid + $bal;
    $s['total_fee'] = $tot; $s['paid_fee'] = $paid; unset($s['balance_fee']);
    return $s;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'upload') {
        $campus = (int)($_POST['campus'] ?? 0);
        if ($campus && !isset($campuses[$campus])) $campus = 0;
        if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { $_SESSION['flash'] = '⚠️ File निवडा.'; header('Location: import.php'); exit; }
        if ($_FILES['file']['size'] > 8 * 1024 * 1024) { $_SESSION['flash'] = '⚠️ File 8 MB पेक्षा मोठी आहे.'; header('Location: import.php'); exit; }
        $isPdf = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION)) === 'pdf';
        if ($isPdf) {
            // 📄 Bus Fee Register ची PDF थेट — Regis No, Name, Class, Division, Stop, Total / Paid / Balance Fee, College
            require_once __DIR__ . '/../includes/pdf-table.php';
            $rows = pdfBusFeeRegister((string)file_get_contents($_FILES['file']['tmp_name']));
            if (!$rows) { $_SESSION['flash'] = '⚠️ ही PDF वाचता आली नाही (Scan केलेली / वेगळ्या रचनेची). कृपया Excel किंवा CSV वापरा.'; header('Location: import.php'); exit; }
        }
        else try { $rows = eowmsReadSheet($_FILES['file']['tmp_name'], $_FILES['file']['name']); }
        catch (Throwable $e) { $_SESSION['flash'] = '⚠️ File वाचता आली नाही: ' . $e->getMessage(); header('Location: import.php'); exit; }
        $rows = array_values(array_filter($rows, fn($r) => is_array($r) && count(array_filter($r, fn($c) => trim((string)$c) !== '')) > 0));
        if (count($rows) < 2) { $_SESSION['flash'] = '⚠️ File मध्ये विद्यार्थी सापडले नाहीत.'; header('Location: import.php'); exit; }
        $g = studentGuessHeader($rows, $fields);
        $t = bin2hex(random_bytes(12));
        file_put_contents($tokFile($t), json_encode([
            'org' => $orgId, 'uid' => $user['id'], 'file' => mb_substr($_FILES['file']['name'], 0, 80), 'rows' => $rows, 'hrow' => $g['row'], 'map' => $g['map'],
            'opts' => ['campus' => $campus, 'year' => trim((string)($_POST['year'] ?? '')) ?: studentCurrentAcademicYear(), 'class' => trim((string)($_POST['class'] ?? '')),
                'div' => trim((string)($_POST['div'] ?? '')), 'from' => mb_substr(trim((string)($_POST['from'] ?? '')), 0, 120), 'to' => mb_substr(trim((string)($_POST['to'] ?? '')), 0, 120),
                'route' => mb_substr(trim((string)($_POST['route'] ?? '')), 0, 120), 'mode' => ($_POST['mode'] ?? '') === 'new' ? 'new' : 'upsert'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        header('Location: import.php?t=' . $t); exit;
    }
    if ($action === 'map' || $action === 'apply') {
        $t = $_POST['t'] ?? ''; $d = $load($t);
        if (!$d) { $_SESSION['flash'] = '⚠️ Import ची वेळ संपली — File पुन्हा Upload करा.'; header('Location: import.php'); exit; }
        $hrow = max(0, min(count($d['rows']) - 1, (int)($_POST['hrow'] ?? $d['hrow'])));
        if ($hrow !== (int)$d['hrow']) { $d['map'] = studentGuessHeader([$d['rows'][$hrow]], $fields)['map']; $d['hrow'] = $hrow; }
        elseif (isset($_POST['col']) && is_array($_POST['col'])) {
            $map = []; $used = [];
            foreach ($_POST['col'] as $c => $k) { $k = (string)$k; $map[(int)$c] = ($k !== '' && isset($fields[$k]) && !isset($used[$k])) ? $k : ''; if ($map[(int)$c] !== '') $used[$k] = true; }
            $d['map'] = $map;
        }
        file_put_contents($tokFile($t), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        if ($action === 'map') { header('Location: import.php?t=' . $t); exit; }
        if (!in_array('name', $d['map'], true) && !in_array('first_name', $d['map'], true) && !in_array('last_name', $d['map'], true)) {
            $_SESSION['flash'] = '⚠️ "विद्यार्थ्याचे नाव" कोणत्या Column मध्ये आहे ते निवडा.'; header('Location: import.php?t=' . $t); exit;
        }
        // ✓ Import
        $batch = 'BUS-' . date('ymdHis') . '-' . substr($t, 0, 4);
        $c = ['add' => 0, 'upd' => 0, 'skip' => 0, 'err' => 0];
        $pdo->beginTransaction();
        try {
            foreach (array_slice($d['rows'], $d['hrow'] + 1) as $r) {
                $s = $buildRow($r, $d);
                if (isset($s['_err'])) { $c['err']++; continue; }
                $ex = busStudentFind($pdo, $orgId, $s);
                if ($ex && $d['opts']['mode'] === 'new') { $c['skip']++; continue; }
                if ($ex) { busStudentSave($pdo, $orgId, $ex, $s, $user['id'], null, true); $c['upd']++; }
                else { busStudentSave($pdo, $orgId, 0, $s, $user['id'], $batch); $c['add']++; }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('bus student import: ' . $e->getMessage());
            $_SESSION['flash'] = '⚠️ Import पूर्ण झाला नाही (काहीही जतन झालं नाही): ' . mb_substr($e->getMessage(), 0, 160);
            header('Location: import.php?t=' . $t); exit;
        }
        @unlink($tokFile($t));
        logActivity('बस विद्यार्थी Import', 'bus_student_import', null, $d['file'] . " · नवीन {$c['add']}, अद्ययावत {$c['upd']}, वगळले {$c['skip']}, त्रुटी {$c['err']} · $batch");
        $_SESSION['flash'] = "✓ Import झाला — नवीन {$c['add']}, अद्ययावत {$c['upd']}" . ($c['skip'] ? ", आधीच असलेले वगळले {$c['skip']}" : '') . ($c['err'] ? ", चुकीच्या ओळी {$c['err']}" : '') . '.';
        header('Location: students.php?' . http_build_query(array_filter(['campus' => $d['opts']['campus'] ?: '', 'year' => $d['opts']['year']]))); exit;
    }
    if ($action === 'undo_import') {
        $b = (string)($_POST['batch'] ?? '');
        if (preg_match('/^BUS-[0-9]{12}-[a-f0-9]{4}$/', $b)) {
            // पास दिलेले विद्यार्थी काढत नाही (पासचा इतिहास राहावा)
            $st = $pdo->prepare("DELETE FROM bus_students WHERE organization_id = ? AND import_batch = ? AND NOT EXISTS (SELECT 1 FROM student_bus_passes p WHERE p.bus_student_id = bus_students.id)");
            $st->execute([$orgId, $b]);
            logActivity('बस विद्यार्थी Import मागे घेतला', 'bus_student_import', null, $b . ' · ' . $st->rowCount());
            $_SESSION['flash'] = '✓ Import मागे घेतला — ' . $st->rowCount() . ' नवीन विद्यार्थी काढले.';
        }
        header('Location: import.php'); exit;
    }
}

$t = $_GET['t'] ?? '';
$d = $t ? $load($t) : null;
$preview = null;
if ($d) {
    $preview = ['new' => 0, 'upd' => 0, 'err' => 0, 'errs' => [], 'sample' => []];
    foreach (array_slice($d['rows'], $d['hrow'] + 1) as $i => $r) {
        $s = $buildRow($r, $d);
        if (isset($s['_err'])) { $preview['err']++; if (count($preview['errs']) < 8) $preview['errs'][] = 'ओळ ' . ($d['hrow'] + $i + 2) . ': ' . $s['_err']; continue; }
        $ex = busStudentFind($pdo, $orgId, $s);
        $ex ? $preview['upd']++ : $preview['new']++;
        if (count($preview['sample']) < 12) $preview['sample'][] = $s + ['_ex' => $ex];
    }
}
$batches = $pdo->prepare("SELECT import_batch, COUNT(*) n, MIN(created_at) at FROM bus_students WHERE organization_id = ? AND import_batch IS NOT NULL GROUP BY import_batch ORDER BY at DESC LIMIT 5");
$batches->execute([$orgId]);
$batches = $batches->fetchAll();

$pageTitle = 'बस विद्यार्थी Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .im-steps{display:flex;gap:6px;margin-bottom:12px;font-size:12px;font-weight:700;flex-wrap:wrap;}
  .im-steps span{padding:5px 12px;border-radius:999px;background:var(--canvas);color:var(--text-dim);}
  .im-steps span.on{background:var(--ink);color:#fff;}
  .im-map{overflow-x:auto;}
  .im-map table{border-collapse:separate;border-spacing:0;font-size:12px;min-width:100%;}
  .im-map th,.im-map td{border-bottom:1px solid var(--line);padding:6px 8px;white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;}
  .im-map th{background:var(--canvas);vertical-align:top;}
  .im-map select{font-size:12px !important;padding:5px 26px 5px 8px !important;min-width:150px;}
  .im-map select.set{border-color:#16A34A !important;background-color:#F0FDF4 !important;}
  .im-k{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0;}
  .im-k > div{border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:var(--surface);}
  .im-k b{font-size:20px;display:block;}
  .im-k span{font-size:11px;color:var(--text-dim);}
</style>

<div class="page-eyebrow">🚌 विद्यार्थी वाहतूक</div>
<?php $bpTab = 'import'; require __DIR__ . '/_tabs.php'; ?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">📥 बस विद्यार्थी Import</div>
  <a href="?template=1" style="font-size:12.5px;font-weight:600;color:var(--rail);text-decoration:none;">📄 नमुना CSV</a>
</div>
<div class="im-steps"><span class="<?= !$d ? 'on' : '' ?>">1. File</span><span class="<?= $d ? 'on' : '' ?>">2. Columns व Preview</span><span>3. Import</span></div>

<?php if (!$d): ?>
<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;">
    <input type="hidden" name="action" value="upload">
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">यादीची File (Excel .xlsx / .xls, CSV किंवा Bus Fee Register ची PDF) *</label><input type="file" name="file" accept=".csv,.xlsx,.xls,.pdf" class="form-control-custom" required></div>
      <div class="col-md-6"><label class="form-label-custom">College / School</label>
        <select name="campus" class="form-select-custom"><option value="">— (File मधून / नाही) —</option>
          <?php foreach ($campuses as $cid => $cn): ?><option value="<?= $cid ?>" <?= count($campuses) === 1 ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">शैक्षणिक वर्ष</label><input type="text" name="year" value="<?= h(studentCurrentAcademicYear()) ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">इयत्ता / वर्ग <span style="font-weight:400">(File मध्ये नसेल तर)</span></label><input type="text" name="class" class="form-control-custom" placeholder="उदा. 10 वी"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">तुकडी</label><input type="text" name="div" class="form-control-custom" placeholder="A"></div>
      <div class="col-6 col-md-4"><label class="form-label-custom">🏫 Source (कुठून) <span style="font-weight:400">(File मध्ये नसेल तर)</span></label><input type="text" name="from" class="form-control-custom" maxlength="120" value="<?= h(busSource($pdo, $orgId)) ?>"></div>
      <div class="col-6 col-md-4"><label class="form-label-custom">🚏 Destination / थांबा <span style="font-weight:400">(File मध्ये नसेल तर)</span></label><input type="text" name="to" class="form-control-custom" maxlength="120"></div>
      <div class="col-6 col-md-4"><label class="form-label-custom">🛣️ मार्ग / बस <span style="font-weight:400">(ऐच्छिक)</span></label><input type="text" name="route" class="form-control-custom" maxlength="120" placeholder="उदा. Route 3"></div>
      <div class="col-12"><label class="form-label-custom">आधीच असलेले बस विद्यार्थी (Gr.No. / Roll / नावानुसार)</label>
        <div class="d-flex gap-3 flex-wrap" style="font-size:13px;">
          <label><input type="radio" name="mode" value="upsert" checked> नवीन जोडा आणि असलेल्यांची माहिती अद्ययावत करा</label>
          <label><input type="radio" name="mode" value="new"> फक्त नवीन जोडा</label>
        </div></div>
    </div>
    <div style="font-size:11.5px;color:var(--text-dim);margin-top:10px;">💡 पहिल्या ओळीत Column ची नावं असावीत (उदा. GR No, Student Name, Class, From / गाव / थांबा, To, Route, Parent Mobile) — मराठी नावंही चालतील. ही यादी फक्त बस पाससाठी आहे — विद्यार्थी विभागात काहीही बदलत नाही. Import आधी काहीही जतन होत नाही.</div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:700;padding:9px 20px;">पुढे — Columns तपासा →</button>
  </form>
</div>

<?php if ($batches): ?>
<div class="panel mt-3">
  <div class="panel-head"><div class="title">🕘 अलीकडचे Import</div><div class="sub">चुकीची File टाकली असल्यास — त्या Import ने जोडलेले नवीन बस विद्यार्थी काढा (ज्यांना पास दिला ते राहतात)</div></div>
  <?php foreach ($batches as $b): ?>
    <form method="POST" class="d-flex align-items-center gap-2 flex-wrap" style="padding:8px 20px;border-top:1px solid var(--line);font-size:13px;" onsubmit="return confirm('या Import ने जोडलेले <?= (int)$b['n'] ?> विद्यार्थी काढायचे? (अद्ययावत केलेले आधीचे विद्यार्थी तसेच राहतील)');">
      <input type="hidden" name="action" value="undo_import"><input type="hidden" name="batch" value="<?= h($b['import_batch']) ?>">
      <span><?= h(date('d/m/Y h:i A', strtotime($b['at']))) ?> · <b><?= (int)$b['n'] ?></b> नवीन</span>
      <button type="submit" class="btn btn-sm ms-auto" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;">↺ हा Import मागे घ्या</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else:
  $hdr = $d['rows'][$d['hrow']] ?? [];
  $ncol = max(array_map('count', array_slice($d['rows'], $d['hrow'], 6)));
  $samples = array_slice($d['rows'], $d['hrow'] + 1, 3);
?>
<div class="panel">
  <div class="panel-head"><div class="title">📄 <?= h($d['file']) ?></div><div class="sub"><?= $d['opts']['campus'] ? h($campuses[$d['opts']['campus']] ?? '') . ' · ' : '' ?><?= h($d['opts']['year']) ?><?= $d['opts']['class'] !== '' ? ' · वर्ग ' . h($d['opts']['class']) : '' ?> · <?= count($d['rows']) - $d['hrow'] - 1 ?> ओळी</div></div>
  <form method="POST" style="padding:12px 16px;" id="mapForm">
    <input type="hidden" name="t" value="<?= h($t) ?>">
    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:12.5px;">
      Column ची नावं कोणत्या ओळीत आहेत?
      <select name="hrow" class="form-select-custom" style="width:auto;" onchange="this.form.querySelector('[name=action]').value='map';this.form.submit()">
        <?php for ($i = 0; $i < min(10, count($d['rows'])); $i++): ?><option value="<?= $i ?>" <?= $i === (int)$d['hrow'] ? 'selected' : '' ?>>ओळ <?= $i + 1 ?></option><?php endfor; ?>
      </select>
    </div>
    <div class="im-map">
      <table>
        <tr><?php for ($c = 0; $c < $ncol; $c++): $k = $d['map'][$c] ?? ''; ?>
          <th><div style="font-weight:700;"><?= h(trim((string)($hdr[$c] ?? '')) ?: 'Column ' . ($c + 1)) ?></div>
            <select name="col[<?= $c ?>]" class="form-select-custom <?= $k !== '' ? 'set' : '' ?>" onchange="this.classList.toggle('set', this.value !== '')">
              <option value="">— दुर्लक्ष —</option>
              <?php foreach ($fields as $fk => [$fl]): ?><option value="<?= $fk ?>" <?= $k === $fk ? 'selected' : '' ?>><?= h($fl) ?></option><?php endforeach; ?>
            </select></th>
        <?php endfor; ?></tr>
        <?php foreach ($samples as $sr): ?><tr><?php for ($c = 0; $c < $ncol; $c++): $cv = $sr[$c] ?? ''; ?><td><?= h(is_float($cv) && floor($cv) == $cv ? sprintf('%.0f', $cv) : (string)$cv) ?></td><?php endfor; ?></tr><?php endforeach; ?>
      </table>
    </div>
    <input type="hidden" name="action" value="map">
    <button type="submit" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;">↻ Columns बदलले — Preview अद्ययावत करा</button>
  </form>
</div>

<div class="im-k">
  <div><b style="color:#16A34A;"><?= $preview['new'] ?></b><span>➕ नवीन बस विद्यार्थी</span></div>
  <div><b style="color:#2563EB;"><?= $preview['upd'] ?></b><span>↻ आधीच आहेत<?= $d['opts']['mode'] === 'new' ? ' (वगळले जातील)' : ' (अद्ययावत होतील)' ?></span></div>
  <div><b style="color:<?= $preview['err'] ? '#DC2626' : 'var(--text-dim)' ?>;"><?= $preview['err'] ?></b><span>⚠️ चुकीच्या ओळी (वगळल्या जातील)</span></div>
</div>
<?php if ($preview['errs']): ?><div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;"><?= h(implode(' · ', $preview['errs'])) ?></div><?php endif; ?>

<?php if ($preview['sample']): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">👀 Preview — पहिले <?= count($preview['sample']) ?></div></div>
  <div style="overflow-x:auto;">
  <table class="rtable" style="font-size:12.5px;">
    <thead><tr><th></th><th>Reg No</th><th>नाव</th><th>Class · Division</th><th>Source ⇄ Destination</th><th>₹ एकूण / भरले / बाकी</th></tr></thead>
    <tbody><?php foreach ($preview['sample'] as $s): ?>
      <tr><td><?= $s['_ex'] ? '<span class="status-chip pending">↻</span>' : '<span class="status-chip done">➕</span>' ?></td>
        <td><?= h($s['admission_no'] ?? '') ?: '—' ?></td><td><b><?= h($s['name']) ?></b></td>
        <td><?= h(trim(($s['class_name'] ?? '') . (!empty($s['division']) ? ' - ' . $s['division'] : '') . (!empty($s['course']) ? ' · ' . $s['course'] : ''))) ?></td>
        <td><?= h(($s['from_place'] ?? '') ?: '—') ?> ⇄ <?= h(($s['to_place'] ?? '') ?: '—') ?></td>
        <td><?= $s['total_fee'] !== null ? h(number_format((float)$s['total_fee'])) . ' / ' . h(number_format((float)($s['paid_fee'] ?? 0))) . ' / <b>' . h(number_format((float)$s['total_fee'] - (float)($s['paid_fee'] ?? 0))) . '</b>' : '—' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap">
  <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:10px;">← दुसरी File</a>
  <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:700;padding:9px 22px;" <?= !$preview['new'] && !$preview['upd'] ? 'disabled' : '' ?>
    onclick="if(confirm('<?= (int)$preview['new'] ?> नवीन<?= $d['opts']['mode'] === 'upsert' ? ' व ' . (int)$preview['upd'] . ' अद्ययावत' : '' ?> — Import करायचे?')){var f=document.getElementById('mapForm');f.querySelector('[name=action]').value='apply';f.submit();}">✓ Import करा</button>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
