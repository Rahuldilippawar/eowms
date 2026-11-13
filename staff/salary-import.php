<?php
// 💰 पगार रचना आयात — जुन्या Software ची "Employee Salary Detail" Excel (.xls/.xlsx/CSV) → Emp Id ने कर्मचाऱ्यांशी जुळवून
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasRole('super_admin')) { http_response_code(403); die('पगार माहिती फक्त Management व Super Admin साठी.'); }
require_once __DIR__ . '/../includes/salary-helpers.php';
require_once __DIR__ . '/../includes/sheet-reader.php';
$orgId = activeOrgId();
$me = currentUser();
$ready = salaryReady($pdo);
$error = null; $preview = null;
$KEY = 'salimp_' . $orgId;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'preview') {
        if (empty($_FILES['sal_file']['tmp_name']) || ($_FILES['sal_file']['error'] ?? 1) !== UPLOAD_ERR_OK) { $error = 'पगाराची Excel File निवडा.'; }
        else {
            try {
                $parsed = salaryParseSheet(eowmsReadSheet($_FILES['sal_file']['tmp_name'], $_FILES['sal_file']['name']));
                if ($parsed['error']) { $error = $parsed['error']; }
                else { $_SESSION[$KEY] = ['rows' => $parsed['rows'], 'file' => mb_substr($_FILES['sal_file']['name'], 0, 80), 'at' => time()]; header('Location: salary-import.php?step=preview'); exit; }
            } catch (Throwable $e) { $error = 'File वाचता आली नाही: ' . $e->getMessage(); }
        }
    } elseif ($act === 'apply' && !empty($_SESSION[$KEY]['rows'])) {
        $eff = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['effective_from'] ?? '') ? $_POST['effective_from'] : date('Y-m-01');
        $pick = array_flip(array_map('strval', (array)($_POST['codes'] ?? [])));
        $map = salaryImportMatch($pdo, $orgId, $_SESSION[$KEY]['rows']);
        $cnt = ['new' => 0, 'changed' => 0, 'same' => 0];
        $pdo->beginTransaction();
        try {
            foreach ($map as $m) {
                if (!$m['user'] || !isset($pick[$m['row']['code']])) continue;
                $r = $m['row'];
                if (empty($_POST['ids_too'])) { $r['uan_no'] = ''; $r['esic_no'] = ''; }
                $cnt[salarySave($pdo, $orgId, (int)$m['user']['id'], $r, $eff, 'import', $me['id'], $_SESSION[$KEY]['file'])]++;
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        logActivity('पगार रचना आयात', 'user', null, "{$cnt['new']} नवीन, {$cnt['changed']} बदल, {$cnt['same']} तसेच — लागू: $eff — " . $_SESSION[$KEY]['file']);
        unset($_SESSION[$KEY]);
        $_SESSION['flash'] = "✅ पगार रचना जतन झाली — {$cnt['new']} नवीन, {$cnt['changed']} बदलले, {$cnt['same']} आधीसारखेच. लागू: " . date('d/m/Y', strtotime($eff));
        header('Location: salary-report.php');
        exit;
    } elseif ($act === 'cancel') { unset($_SESSION[$KEY]); header('Location: salary-import.php'); exit; }
}

/** फाईलमधल्या ओळी ↔ कर्मचारी (Emp Id) + नाव जुळतं का + आधीचा पगार */
function salaryImportMatch(PDO $pdo, $orgId, array $rows) {
    $codes = array_values(array_unique(array_column($rows, 'code')));
    $users = [];
    if ($codes) {
        $st = $pdo->prepare("SELECT u.id, u.name, u.employee_code, u.is_active, u.gender, d.name dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.organization_id = ? AND u.employee_code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")");
        $st->execute(array_merge([$orgId], $codes));
        foreach ($st as $u) { $users[(string)$u['employee_code']] = $u; }
    }
    $cur = salaryCurrent($pdo, $orgId, array_column($users, 'id'));
    $nm = fn($s) => preg_replace('/[^a-z]/', '', strtolower((string)$s));
    $out = [];
    foreach ($rows as $r) {
        $u = $users[$r['code']] ?? null;
        $sim = 0;
        if ($u && $r['name'] !== '') {
            $a = $nm($u['name']); $b = $nm($r['name']);
            if ($a === $b) $sim = 100;
            else { $wa = preg_split('/\s+/', strtolower($u['name'])); $wb = preg_split('/\s+/', strtolower($r['name'])); $sim = (int)round(count(array_intersect($wa, $wb)) * 100 / max(1, max(count($wa), count($wb)))); }
        } elseif ($u) { $sim = 100; }
        $c = $u ? ($cur[(int)$u['id']] ?? null) : null;
        $out[] = ['row' => $r, 'user' => $u, 'sim' => $sim, 'cur' => $c,
            'state' => !$u ? 'missing' : (!$c ? 'new' : (round((float)$c['gross'], 2) === round((float)$r['gross'], 2) && round((float)$c['basic'], 2) === round((float)$r['basic'], 2) ? 'same' : 'changed'))];
    }
    return $out;
}

if ($ready && ($_GET['step'] ?? '') === 'preview' && !empty($_SESSION[$KEY]['rows'])) {
    $preview = salaryImportMatch($pdo, $orgId, $_SESSION[$KEY]['rows']);
}
$rs = fn($v) => '₹' . number_format((float)$v, (float)$v == floor((float)$v) ? 0 : 2);

$pageTitle = 'पगार रचना आयात';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><a href="salary-report.php" style="color:inherit;text-decoration:none;">← पगार अहवाल</a></div>
<div class="page-title mb-1">💰 पगार रचना आयात (Excel)</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">जुन्या Software मधली "Employee Salary Detail" Excel (.xls / .xlsx / CSV) द्या — Emp Id ने कर्मचाऱ्यांशी जुळवून प्रत्येकाचा एकूण पगार, Basic, HRA, प्रवास भत्ता, वैद्यकीय भत्ता, PF/ESIC/PT जतन होतात. आधी पूर्वावलोकन, मग जतन.</div>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ Database Update बाकी — <a href="../system-update/db-update.php">System Update → Database Update</a> चालवा.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div><?php endif; ?>

<?php if (!$preview): ?>
<form method="post" enctype="multipart/form-data" class="panel" style="padding:18px 20px;max-width:640px;">
  <input type="hidden" name="act" value="preview">
  <label class="form-label-custom">पगाराची Excel File</label>
  <input type="file" name="sal_file" accept=".xls,.xlsx,.csv" class="form-control-custom" required>
  <div style="font-size:12px;color:var(--text-dim);margin-top:8px;line-height:1.7;">
    ✔ जुन्या Software चा Report जसाच्या तसा चालतो (विभागांची शीर्षकं, दोन ओळींचं Header).<br>
    ✔ साधी Sheet पण चालते — स्तंभ: <b>Emp Id, Name, Actual Salary (Gross), Basic, HRA, TA, Medical, PF, PF No, ESIC No, PT, HC</b>.<br>
    ✔ कर्मचारी <b>Emp Id</b> ने जुळवले जातात (कर्मचारी → Edit → कर्मचारी क्रमांक).
  </div>
  <button class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;padding:9px 20px;" <?= $ready ? '' : 'disabled' ?>>पूर्वावलोकन पहा →</button>
</form>
<?php else:
    $st = array_count_values(array_column($preview, 'state'));
    $okRows = array_filter($preview, fn($m) => $m['user']);
    $sumGross = array_sum(array_map(fn($m) => $m['row']['gross'], $okRows));
    $byDept = [];
    foreach ($preview as $m) { $byDept[$m['row']['dept'] ?: '—'][] = $m; }
    require_once __DIR__ . '/../includes/smart-insights.php';
    smartTiles([
        ['label' => 'File मधील कर्मचारी', 'value' => count($preview), 'sub' => count($byDept) . ' विभाग · ' . h($_SESSION[$KEY]['file'])],
        ['label' => 'जुळले', 'value' => count($okRows), 'sub' => 'एकूण मासिक ' . $rs($sumGross), 'tone' => 'ok'],
        ['label' => 'नवीन / बदल', 'value' => ($st['new'] ?? 0) . ' / ' . ($st['changed'] ?? 0), 'sub' => ($st['same'] ?? 0) . ' आधीसारखेच'],
        ['label' => 'सापडले नाहीत', 'value' => $st['missing'] ?? 0, 'sub' => ($st['missing'] ?? 0) ? 'Emp Id यादीत नाही' : 'सगळे जुळले', 'tone' => ($st['missing'] ?? 0) ? 'bad' : 'ok'],
    ]);
    $weak = array_filter($preview, fn($m) => $m['user'] && $m['sim'] < 60);
    if ($weak): ?><div class="alert alert-warning" style="font-size:13px;border-radius:10px;">⚠ <?= count($weak) ?> जणांचं नाव File व System मध्ये वेगळं दिसतं — खालच्या यादीत <b>पिवळ्या</b> ओळी तपासा; चुकीचे असतील तर टिक काढा.</div><?php endif; ?>
<form method="post">
  <input type="hidden" name="act" value="apply">
  <div class="panel mb-3" style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:14px 24px;align-items:flex-end;">
    <div><label class="form-label-custom">कधीपासून लागू</label><input type="date" name="effective_from" value="<?= date('Y-m-01') ?>" class="form-control-custom" required></div>
    <label style="font-size:13px;display:flex;gap:7px;align-items:center;"><input type="checkbox" name="ids_too" value="1" checked> PF (UAN) व ESIC क्रमांक पण भरा</label>
    <div class="ms-auto d-flex gap-2">
      <button type="submit" name="act" value="cancel" formnovalidate class="btn" style="border:1px solid var(--line);border-radius:9px;">रद्द</button>
      <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:700;padding:9px 22px;" onclick="return confirm('निवडलेल्यांची पगार रचना जतन करायची?');">✅ जतन करा</button>
    </div>
  </div>
  <?php foreach ($byDept as $dn => $list): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= h($dn) ?> <span style="font-size:11.5px;color:var(--text-dim);font-weight:400;">(<?= count($list) ?> · <?= $rs(array_sum(array_map(fn($m) => $m['row']['gross'], $list))) ?>)</span></div></div>
    <div class="table-responsive"><table class="rtable si-t">
      <thead><tr><th data-no-sort><input type="checkbox" class="si-all" checked></th><th>Emp Id</th><th>नाव (File / System)</th><th>एकूण</th><th>Basic</th><th>HRA</th><th>TA</th><th>वैद्यकीय</th><th>PF / ESIC / PT</th><th>स्थिती</th></tr></thead>
      <tbody>
      <?php foreach ($list as $m): $r = $m['row']; $u = $m['user']; ?>
        <tr class="<?= !$u ? 'miss' : ($m['sim'] < 60 ? 'weak' : '') ?>">
          <td><?php if ($u): ?><input type="checkbox" name="codes[]" value="<?= h($r['code']) ?>" <?= $m['sim'] >= 60 && $m['state'] !== 'same' ? 'checked' : '' ?>><?php endif; ?></td>
          <td class="font-mono"><?= h($r['code']) ?></td>
          <td><b><?= h($r['name']) ?></b><?php if ($u && $m['sim'] < 100): ?><div style="font-size:11.5px;color:#B45309;">System: <?= h($u['name']) ?></div><?php elseif ($u): ?><div style="font-size:11px;color:var(--text-dim);"><?= h($r['desig']) ?><?= !(int)$u['is_active'] ? ' · <span style="color:#B42318;">निष्क्रिय</span>' : '' ?></div><?php endif; ?></td>
          <td class="font-mono" style="font-weight:700;"><?= $rs($r['gross']) ?><?php if ($m['state'] === 'changed'): $d = $r['gross'] - (float)$m['cur']['gross']; ?><div style="font-size:11px;color:<?= $d >= 0 ? '#137A4B' : '#B42318' ?>;"><?= $d >= 0 ? '▲' : '▼' ?> <?= $rs(abs($d)) ?></div><?php endif; ?></td>
          <td class="font-mono"><?= $rs($r['basic']) ?></td><td class="font-mono"><?= $rs($r['hra']) ?></td><td class="font-mono"><?= $rs($r['ta']) ?></td><td class="font-mono"><?= $rs($r['medical']) ?></td>
          <td style="font-size:11.5px;white-space:nowrap;"><?= $r['pf_applicable'] ? '✅' : '—' ?> / <?= $r['esic_applicable'] ? '✅' : '—' ?> / <?= $r['pt_applicable'] ? '✅' : '—' ?><?= $r['is_handicap'] ? ' ♿' : '' ?></td>
          <td style="white-space:nowrap;"><?= ['missing' => '<span class="status-chip" style="background:#FDECEC;color:#B42318;">❌ Emp Id नाही</span>', 'new' => '<span class="status-chip pending">🆕 नवीन</span>', 'changed' => '<span class="status-chip" style="background:#FEF3C7;color:#B45309;">✏️ बदल</span>', 'same' => '<span class="status-chip done">✔ तसाच</span>'][$m['state']] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endforeach; ?>
</form>
<style>.si-t tr.miss td{background:rgba(220,38,38,.05);color:var(--text-dim);} .si-t tr.weak td{background:rgba(245,158,11,.10);}</style>
<script>document.querySelectorAll('.si-all').forEach(function (a) { a.addEventListener('change', function () { a.closest('table').querySelectorAll('input[name="codes[]"]').forEach(function (c) { c.checked = a.checked; }); }); });</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
