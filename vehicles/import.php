<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
require_once __DIR__ . '/../includes/vehicle-import.php';
if (!canManageVehicles()) { requireRole(['super_admin'], '../'); } // वाहन यादी/नवीन वाहन प्रमाणेच
$orgId = activeOrgId();

// ---------- Preview (File Upload झाल्यावर) ----------
$preview = null;
if (isset($_GET['preview']) && !empty($_SESSION['vimport']['token']) && (int)$_SESSION['vimport']['org'] === (int)$orgId) {
    $file = __DIR__ . '/../storage/tmp/vimport-' . preg_replace('/[^a-f0-9]/', '', $_SESSION['vimport']['token']) . '.json';
    if (is_file($file)) {
        $rows = json_decode(file_get_contents($file), true) ?: [];
        $ci = array_flip(array_map(fn($h) => strtolower(trim((string)$h)), $rows[0] ?? []));
        $detect = vehImportDetectSwap(array_slice($rows, 1), $ci);
        $swap = isset($_GET['swap']) ? $_GET['swap'] === '1' : $detect['suggest'];
        $update = isset($_GET['update']) ? $_GET['update'] === '1' : true;
        $fallback = (int)($_GET['fallback_dept'] ?? 0);
        $dStmt = $pdo->prepare("SELECT d.id, d.name_local, d.name, c.name campus FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY c.name, d.name_local");
        $dStmt->execute([$orgId]);
        $departments = $dStmt->fetchAll();
        if (!$fallback) { // वाहन/वाहतूक/परिवहन विभाग आपोआप सुचवतो — Report च्या शीर्षकातलं ठिकाण (उदा. SHAHAPUR) ज्या Campus मध्ये, तो आधी
            $hint = mb_strtolower((string)($_SESSION['vimport']['hint'] ?? ''));
            $best = -1;
            foreach ($departments as $d) {
                if (!preg_match('/वाहन|वाहतूक|परिवहन|transport|vehicle/iu', ($d['name_local'] ?? '') . ' ' . $d['name'])) continue;
                $score = 0;
                foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$d['campus'])) as $w) { if (mb_strlen($w) > 3 && $hint !== '' && str_contains($hint, $w)) $score++; }
                if ($score > $best) { $best = $score; $fallback = (int)$d['id']; }
            }
        }
        $res = vehImportAnalyse($pdo, $orgId, $rows, ['swap' => $swap, 'fallback_dept' => $fallback, 'update' => $update, 'no_dept_ok' => !empty($_SESSION['vimport']['report'])]);
        $preview = compact('rows', 'detect', 'swap', 'update', 'fallback', 'departments', 'res');
    }
}

$pageTitle = 'वाहन Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$colLabel = ['date_of_registration' => 'नोंदणी', 'permit_validity' => 'परमिट', 'puc_validity' => 'PUC', 'insurance_validity' => 'विमा', 'fitness_validity' => 'फिटनेस',
    'road_tax_validity' => 'रोड टॅक्स', 'green_tax_validity' => 'ग्रीन टॅक्स', 'mv_tax_validity' => 'MV टॅक्स'];
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));
?>
<style>
  .vi-table td,.vi-table th{white-space:nowrap;font-size:12.5px;}
  .vi-d{display:inline-block;padding:2px 7px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:11.5px;background:var(--canvas);}
  .vi-d.exp{background:color-mix(in srgb,var(--rose,#e11d48) 13%,transparent);color:var(--rose,#e11d48);font-weight:700;}
  .vi-d.soon{background:color-mix(in srgb,var(--amber,#d97706) 15%,transparent);color:var(--amber,#b45309);font-weight:700;}
  .vi-d.fix{box-shadow:inset 0 0 0 1px var(--rail);}
  .vi-act{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;}
  .vi-act.create{background:color-mix(in srgb,var(--green,#16a34a) 14%,transparent);color:var(--green,#16a34a);}
  .vi-act.update{background:color-mix(in srgb,#2563EB 14%,transparent);color:#2563EB;}
  .vi-act.skip,.vi-act.skip-existing{background:color-mix(in srgb,var(--rose,#e11d48) 12%,transparent);color:var(--rose,#e11d48);}
  .vi-opt{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;font-size:13px;}
  .vi-opt:has(input:checked){border-color:var(--rail);background:color-mix(in srgb,var(--rail) 5%,transparent);}
  .vi-opt input{accent-color:var(--rail);margin-top:3px;width:16px;height:16px;}
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 वाहन Data Import करा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वाहन यादी</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">Excel (.xlsx) किंवा CSV मधून अनेक वाहनं — बसेससह — एकाच वेळी. Permit / PUC / विमा / फिटनेस / कर यांच्या तारखा "नूतनीकरण व अनुपालन" मध्ये आपोआप जातात आणि मुदत जवळ आली की Reminder येतो.</p>

<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?> <a href="index.php">वाहन यादी</a> · <a href="../renewals/index.php">नूतनीकरण</a></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div><?php unset($_SESSION['form_error']); endif; ?>

<?php if ($preview && $preview['res']['missingCols']): ?>
  <div class="alert alert-danger" style="font-size:13px;">File मध्ये हे Columns नाहीत: <b><?= h(implode(', ', $preview['res']['missingCols'])) ?></b> — Template प्रमाणे पहिली ओळ (Header) ठेवा.</div>
<?php elseif ($preview):
  $items = $preview['res']['items'];
  $cnt = ['create' => 0, 'update' => 0, 'skip' => 0, 'skip-existing' => 0];
  $renewalCount = 0; $expired = 0; $dueSoon = 0; $fixes = 0;
  foreach ($items as $it) {
      $cnt[$it['action']]++;
      if (in_array($it['action'], ['create', 'update'], true)) {
          foreach (VEH_IMPORT_RENEWAL_COLS as $col) { $dt = $it['dates'][$col] ?? null; if ($dt) { $renewalCount++; if ($dt < $today) { $expired++; } elseif ($dt <= $soon) { $dueSoon++; } } }
      }
      $fixes += count($it['dateNotes']);
  }
  $qs = fn($over) => '?' . http_build_query(array_merge(['preview' => 1, 'swap' => $preview['swap'] ? 1 : 0, 'update' => $preview['update'] ? 1 : 0, 'fallback_dept' => $preview['fallback']], $over));
?>
  <div class="panel mb-3" style="border:2px solid var(--rail);">
    <div class="panel-head"><div class="title">🔍 Import आधी तपासणी — <?= h($_SESSION['vimport']['name'] ?? '') ?></div><div class="sub">अजून काहीही जोडलेलं नाही. खाली तपासा आणि "Import करा" दाबा.</div></div>
    <div style="padding:14px 18px 6px;">
      <?= smartTiles([
          ['label' => 'नवीन वाहनं', 'value' => $cnt['create'], 'sub' => 'जोडली जातील', 'tone' => 'ok'],
          ['label' => 'आधीच असलेली', 'value' => $cnt['update'] + $cnt['skip-existing'], 'sub' => $preview['update'] ? 'माहिती अद्ययावत होईल' : 'वगळली जातील', 'tone' => 'ok'],
          ['label' => 'नूतनीकरण-तारखा', 'value' => $renewalCount, 'sub' => $expired . ' मुदत संपलेली · ' . $dueSoon . ' 30 दिवसांत', 'tone' => $expired ? 'bad' : ($dueSoon ? 'warn' : 'ok')],
          ['label' => 'त्रुटी', 'value' => $cnt['skip'], 'sub' => $cnt['skip'] ? 'या ओळी वगळल्या जातील' : 'एकही नाही', 'tone' => $cnt['skip'] ? 'bad' : 'ok'],
      ]) ?>

      <div class="row g-2 mb-3">
        <div class="col-lg-5">
          <label class="vi-opt"><input type="checkbox" <?= $preview['swap'] ? 'checked' : '' ?> onchange="location.href='<?= h($qs(['swap' => $preview['swap'] ? 0 : 1])) ?>'">
            <span><b>Excel ने दिवस/महिना उलट वाचलेल्या तारखा दुरुस्त करा</b><br><small style="color:var(--text-dim);"><?= $preview['detect']['suggest']
              ? '✓ या File मध्ये असं दिसतं: Excel-तारखांपैकी एकाचाही "दिवस" 12 पेक्षा मोठा नाही, तर हाताने लिहिलेल्या ' . (int)$preview['detect']['textDayFirst'] . ' तारखा दिवस/महिना पद्धतीच्या आहेत — म्हणून आपोआप चालू केलं.'
              : 'File मधल्या Excel-तारखा (' . (int)$preview['detect']['serial'] . ') उलट दिसत नाहीत. गरज असेल तरच चालू करा.' ?></small></span></label>
        </div>
        <div class="col-lg-3">
          <label class="vi-opt"><input type="checkbox" <?= $preview['update'] ? 'checked' : '' ?> onchange="location.href='<?= h($qs(['update' => $preview['update'] ? 0 : 1])) ?>'">
            <span><b>आधीच असलेल्या वाहनांची माहिती अद्ययावत करा</b><br><small style="color:var(--text-dim);">File मध्ये रिकामं / NA असेल तिथे जुनी माहिती तशीच राहते.</small></span></label>
        </div>
        <div class="col-lg-4">
          <div class="vi-opt" style="flex-direction:column;gap:6px;">
            <b><?= !empty($_SESSION['vimport']['report']) ? 'ही सगळी वाहनं या विभागात' : 'File मधला विभाग सापडला नाही तर हा विभाग वापरा' ?></b>
            <select class="form-select-custom" onchange="location.href='<?= h($qs(['fallback_dept' => ''])) ?>'.replace('fallback_dept=', 'fallback_dept=' + this.value)">
              <option value="0">— निवडा —</option>
              <?php foreach ($preview['departments'] as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $preview['fallback'] === (int)$d['id'] ? 'selected' : '' ?>><?= h(($d['name_local'] ?: $d['name']) . ($d['campus'] ? ' — ' . $d['campus'] : ' — ट्रस्ट')) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table class="rtable vi-table">
        <thead><tr><th>#</th><th>स्थिती</th><th>वाहन क्र.</th><th>प्रकार / Model</th><th>क्षमता</th><th>इंधन</th><th>विभाग</th>
          <?php foreach ($colLabel as $col => $lbl): if (isset($preview['res']['colIndex'][$col])): ?><th><?= h($lbl) ?></th><?php endif; endforeach; ?><?php if (isset($preview['res']['colIndex']['insurance_cost'])): ?><th>विमा खर्च</th><?php endif; ?><th>सूचना</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= (int)$it['line'] ?></td>
            <td><span class="vi-act <?= h($it['action']) ?>"><?= ['create' => 'नवीन', 'update' => 'अद्ययावत', 'skip' => 'वगळणार', 'skip-existing' => 'आधीच आहे'][$it['action']] ?></span></td>
            <td><b class="font-mono"><?= h($it['number']) ?></b></td>
            <td><?= h(trim($it['type'] . ' · ' . $it['model'], ' ·')) ?><?= $it['class'] ? '<div style="font-size:11px;color:var(--text-dim);">' . h($it['class']) . '</div>' : '' ?></td>
            <td><?= $it['capacity'] !== null ? (int)$it['capacity'] : '—' ?></td>
            <td><?= h($it['fuel']) ?><?= $it['fuelRaw'] && strtolower($it['fuelRaw']) !== $it['fuel'] ? '<div style="font-size:11px;color:var(--text-dim);">' . h($it['fuelRaw']) . '</div>' : '' ?></td>
            <td><?= h($it['deptName']) ?></td>
            <?php foreach ($colLabel as $col => $lbl): if (!isset($preview['res']['colIndex'][$col])) { continue; } $dt = $it['dates'][$col]; $note = $it['dateNotes'][$col] ?? ''; $kind = $it['kinds'][$col] ?? '';
              $cls = $dt && $col !== 'date_of_registration' ? ($dt < $today ? 'exp' : ($dt <= $soon ? 'soon' : '')) : ''; ?>
              <td><?php if ($dt): ?><span class="vi-d <?= $cls ?> <?= $note ? 'fix' : '' ?>" title="<?= h($note) ?>"><?= date('d/m/Y', strtotime($dt)) ?><?= $note ? ' ⓘ' : '' ?></span>
                <?php elseif ($kind === 'lifetime'): ?><span class="vi-d" title="<?= h($note) ?>">आजीवन</span>
                <?php elseif ($kind === 'bad'): ?><span class="vi-d exp" title="<?= h($note) ?>">? ⓘ</span>
                <?php else: ?><span style="color:var(--text-dim);">—</span><?php endif; ?></td>
            <?php endforeach; ?>
            <?php if (isset($preview['res']['colIndex']['insurance_cost'])): ?><td class="font-mono"><?= !empty($it['insCost']) ? '₹' . number_format($it['insCost']) : '—' ?></td><?php endif; ?>
            <td style="white-space:normal;min-width:220px;font-size:11.5px;">
              <?php foreach ($it['errors'] as $e): ?><div style="color:var(--rose);">✗ <?= h($e) ?></div><?php endforeach; ?>
              <?php foreach ($it['notes'] as $e): ?><div style="color:var(--text-dim);">• <?= h($e) ?></div><?php endforeach; ?>
              <?php foreach ($it['dateNotes'] as $col => $e): if (($it['kinds'][$col] ?? '') === 'bad' || ($it['kinds'][$col] ?? '') === 'fixed' && !str_starts_with($e, 'Excel')): ?><div style="color:var(--amber,#b45309);">⚠ <?= h(($colLabel[$col] ?? $col) . ': ' . $e) ?></div><?php endif; endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:14px 18px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <form method="POST" action="import-save.php" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='⏳ जोडत आहे…';">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="swap" value="<?= $preview['swap'] ? 1 : 0 ?>">
        <input type="hidden" name="update" value="<?= $preview['update'] ? 1 : 0 ?>">
        <input type="hidden" name="fallback_dept" value="<?= (int)$preview['fallback'] ?>">
        <button class="btn" style="background:var(--rail);color:#fff;border-radius:10px;font-size:14px;padding:10px 22px;font-weight:700;" <?= $cnt['create'] + $cnt['update'] ? '' : 'disabled' ?>>
          <i class="fa-solid fa-check me-1"></i>Import करा — <?= $cnt['create'] ?> नवीन<?= $cnt['update'] ? ' + ' . $cnt['update'] . ' अद्ययावत' : '' ?></button>
      </form>
      <a href="import.php" style="font-size:13px;">रद्द करा / दुसरी File</a>
      <span style="font-size:12px;color:var(--text-dim);margin-left:auto;">ⓘ असलेल्या तारखेवर Mouse ठेवा — काय दुरुस्त केलं ते दिसेल. <span class="vi-d exp">लाल</span> = मुदत संपलेली, <span class="vi-d soon">पिवळी</span> = 30 दिवसांत.</span>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title"><?= $preview ? 'दुसरी File निवडा' : 'Excel / CSV फाईल अपलोड करा' ?></div></div>
      <form method="POST" action="import-save.php" enctype="multipart/form-data" style="padding:22px 24px;">
        <div class="upload-box mb-3"><input type="file" name="csv_file" accept=".xlsx,.xls,.csv,.txt" required style="width:100%;"></div>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
          <i class="fa-solid fa-magnifying-glass me-1"></i>तपासा (Preview)
        </button>
        <div style="font-size:12px;color:var(--text-dim);margin-top:8px;">आधी Preview दिसेल — तुम्ही "Import करा" दाबेपर्यंत काहीही जोडलं जात नाही.</div>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">फॉरमॅट</div></div>
      <div style="padding:18px 20px;">
        <ul style="font-size:11.5px;color:var(--text-dim);padding-left:16px;line-height:1.7;">
          <li>Columns: <span class="font-mono">department_name, vehicle_number, vehicle_type, model, date_of_registration, chassis_no, engine_no, vehicle_class, passenger_capacity, fuel_type, permit_validity, puc_validity, insurance_validity, fitness_validity, road_tax_validity, green_tax_validity, mv_tax_validity</span></li>
          <li><span class="font-mono">department_name, vehicle_number</span> — अनिवार्य (बाकी Columns नसले तरी चालतात)</li>
          <li>📄 <b>परिवहन विभागाचा Report पण जसाच्या तसा चालतो</b> — <i>Sr.No, Vehical No, Type of Vehical, Seating Capacity, Engine No., Chassis No., Permit / Insurance / MV Tax / Green Tax / Fitness / PUC (From–To)</i>; विभाग Preview मध्ये निवडा. दुसऱ्या Sheet मधला <i>Insurance Cost</i> पण विम्याच्या नोंदीत जातो.</li>
          <li>तारखा कोणत्याही पद्धतीने: <b>25/11/2022</b>, <b>24 Nov 2037</b>, <b>2027-02-04</b> किंवा Excel ची तारीख. <b>NA</b> / रिकामं = लागू नाही; <b>LTT / One time tax</b> = आजीवन</li>
          <li><span class="font-mono">fuel_type</span> — Petrol / Diesel / CNG / Electric (Petrol/CNG → CNG)</li>
          <li>वाहन क्रमांक जागा/रेषा वगळून जुळवतो — <span class="font-mono">MH-17 AG-3785</span> = <span class="font-mono">MH17AG3785</span></li>
        </ul>
        <a href="import-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Sample Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
