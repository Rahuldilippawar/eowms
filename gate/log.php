<?php
// 📋 Gate अहवाल — कोण कधी आत आलं / बाहेर गेलं, आत्ता आत कोण आहे, अवैध प्रयत्न, तासवार आलेख, Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/gate.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/smart-insights.php';
if (!canGate()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">Gate अहवाल पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$ready = gateReady($pdo);
$day = (is_string($_GET['d'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'])) ? $_GET['d'] : date('Y-m-d');
$isToday = $day === date('Y-m-d');
$gf = ['dir' => in_array($_GET['dir'] ?? '', ['in', 'out', 'bad'], true) ? $_GET['dir'] : '', 'q' => trim((string)($_GET['q'] ?? '')), 'dept' => (int)($_GET['dept'] ?? 0)];
$gqs = function (array $over = []) use ($day, $gf) { $q = array_filter(array_merge(['d' => $day, 'dir' => $gf['dir'], 'q' => $gf['q'], 'dept' => $gf['dept'] ?: ''], $over), fn($v) => $v !== '' && $v !== null); return 'log.php' . ($q ? '?' . http_build_query($q) : ''); };

$rows = []; $gk = ['in' => 0, 'out' => 0, 'bad' => 0, 'people' => 0]; $inside = []; $hours = array_fill(0, 24, ['in' => 0, 'out' => 0]); $depts = [];
if ($ready) {
    $where = "g.organization_id = ? AND g.created_at >= ? AND g.created_at < ? + INTERVAL 1 DAY";
    $wp = [$orgId, $day, $day];
    $st = $pdo->prepare("SELECT COALESCE(SUM(result = 'ok' AND direction = 'in'), 0) i, COALESCE(SUM(result = 'ok' AND direction = 'out'), 0) o, COALESCE(SUM(result <> 'ok'), 0) bad, COUNT(DISTINCT CASE WHEN result = 'ok' THEN user_id END) people FROM gate_logs g WHERE $where");
    $st->execute($wp);
    $s = $st->fetch(); $gk = ['in' => (int)$s['i'], 'out' => (int)$s['o'], 'bad' => (int)$s['bad'], 'people' => (int)$s['people']];
    $st = $pdo->prepare("SELECT HOUR(created_at) h, direction, COUNT(*) n FROM gate_logs g WHERE $where AND result = 'ok' GROUP BY HOUR(created_at), direction");
    $st->execute($wp);
    foreach ($st->fetchAll() as $r) $hours[(int)$r['h']][$r['direction'] === 'in' ? 'in' : 'out'] = (int)$r['n'];
    // आत्ता आत — त्या दिवसाची शेवटची नोंद "आत"
    $st = $pdo->prepare("SELECT g.user_id, g.created_at, g.gate, u.name, u.employee_code, u.designation, u.photo_path, COALESCE(NULLIF(d.name_local, ''), d.name) dept_name
        FROM gate_logs g JOIN (SELECT user_id, MAX(id) mid FROM gate_logs g WHERE $where AND result = 'ok' GROUP BY user_id) x ON x.mid = g.id
        JOIN users u ON u.id = g.user_id LEFT JOIN departments d ON d.id = u.department_id WHERE g.direction = 'in' ORDER BY g.created_at");
    $st->execute($wp);
    $inside = $st->fetchAll();
    $sql = "SELECT g.*, u.name, u.employee_code, u.designation, u.photo_path, COALESCE(NULLIF(d.name_local, ''), d.name) dept_name, s.name by_name
        FROM gate_logs g LEFT JOIN users u ON u.id = g.user_id LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN users s ON s.id = g.scanned_by WHERE $where";
    $p = $wp;
    if ($gf['dir'] === 'bad') $sql .= " AND g.result <> 'ok'";
    elseif ($gf['dir'] !== '') { $sql .= " AND g.result = 'ok' AND g.direction = ?"; $p[] = $gf['dir']; }
    if ($gf['dept']) { $sql .= " AND u.department_id = ?"; $p[] = $gf['dept']; }
    if ($gf['q'] !== '') { $sql .= " AND (u.name LIKE ? OR u.employee_code LIKE ? OR g.gate LIKE ?)"; $lk = '%' . $gf['q'] . '%'; array_push($p, $lk, $lk, $lk); }
    $st = $pdo->prepare($sql . " ORDER BY g.id DESC LIMIT 1000");
    $st->execute($p);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local, ''), name) n FROM departments WHERE organization_id = ? ORDER BY n");
    $st->execute([$orgId]);
    $depts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$labels = gateResultLabels(); $mlabels = gateMethodLabels();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gate-' . $day . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Time', 'Emp ID', 'Name', 'Department', 'Direction', 'Result', 'Method', 'Gate', 'Scanned by']);
    foreach (array_reverse($rows) as $r) fputcsv($f, [date('d-m-Y H:i:s', strtotime($r['created_at'])), $r['employee_code'] ?: ($r['user_id'] ? 'ID-' . $r['user_id'] : ''), $r['name'] ?? '', $r['dept_name'] ?? '',
        $r['direction'] === 'in' ? 'IN' : ($r['direction'] === 'out' ? 'OUT' : ''), $labels[$r['result']] ?? $r['result'], strip_tags($mlabels[$r['method']] ?? $r['method']), $r['gate'] ?? '', $r['by_name'] ?? '']);
    exit;
}

$pageTitle = 'Gate अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$hmax = max(1, ...array_map(fn($x) => $x['in'] + $x['out'], array_values($hours)));
$av = function ($r) { $p = $r['photo_path'] ?? ''; return ($p && eowmsPhotoExists($p)) ? '<img src="../' . h($p) . '" alt="">' : h(mb_substr((string)($r['name'] ?? '?'), 0, 1)); };
?>
<style>
  .gt-act{display:flex;gap:6px;flex-wrap:wrap;}
  .gt-act a{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .gt-act a.pri{background:linear-gradient(90deg,#6D28D9,#2563EB);color:#fff;border:0;}
  .gt-hours{display:flex;align-items:flex-end;gap:3px;height:90px;padding-top:6px;}
  .gt-hours div{flex:1;display:flex;flex-direction:column-reverse;min-width:0;height:100%;position:relative;}
  .gt-hours i{display:block;width:100%;} .gt-hours i.in{background:#22C55E;border-radius:0 0 3px 3px;} .gt-hours i.out{background:#F97316;border-radius:3px 3px 0 0;}
  .gt-hl{display:flex;gap:3px;font-size:9.5px;color:var(--text-dim);} .gt-hl span{flex:1;text-align:center;min-width:0;}
  .gt-av{width:34px;height:34px;border-radius:9px;background:var(--canvas);overflow:hidden;display:inline-flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0;vertical-align:middle;}
  .gt-av img{width:100%;height:100%;object-fit:cover;}
  .gt-t td,.gt-t th{padding:8px 10px;font-size:13px;vertical-align:middle;} .gt-t th{font-size:11.5px;color:var(--text-dim);font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
  .gt-tag{font-size:11px;font-weight:800;padding:3px 9px;border-radius:99px;white-space:nowrap;}
  .gt-tag.in{background:#DCFCE7;color:#166534;} .gt-tag.out{background:#FFEDD5;color:#9A3412;} .gt-tag.bad{background:#FEE2E2;color:#991B1B;}
  .gt-in{display:flex;flex-wrap:wrap;gap:8px;} .gt-in div{display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:12px;padding:6px 10px 6px 6px;background:var(--surface);font-size:12.5px;min-width:0;}
  .gt-in small{color:var(--text-dim);display:block;}
  .gt-chips a{font-size:12.5px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .gt-chips a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
  @media (max-width:640px){ .gt-t thead{display:none;} .gt-t tr{display:block;border-bottom:1px solid var(--line);padding:6px 0;} .gt-t td{display:inline-block;border:0 !important;padding:3px 6px !important;} }
</style>

<div class="page-eyebrow">🚪 सुरक्षा</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">📋 Gate — ये-जा अहवाल</div>
  <div class="gt-act">
    <a class="pri" href="index.php">📷 Scanner उघडा</a>
    <a href="<?= h($gqs(['export' => 'csv'])) ?>">📊 Excel</a>
    <a href="../staff/my-id.php">📱 माझं Digital ID</a>
  </div>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ Gate साठी Database Update बाकी आहे — Super Admin ने Database Update चालवावं.</div><?php endif; ?>

<form method="get" class="d-flex gap-2 flex-wrap align-items-center mb-2" data-no-smart-filter>
  <input type="date" name="d" value="<?= h($day) ?>" max="<?= date('Y-m-d') ?>" class="form-control-custom" style="width:auto;" onchange="this.form.submit()">
  <select name="dept" class="form-select-custom" style="width:auto;max-width:220px;" onchange="this.form.submit()"><option value="">सर्व विभाग</option><?php foreach ($depts as $did => $dn): ?><option value="<?= (int)$did ?>" <?= $gf['dept'] === (int)$did ? 'selected' : '' ?>><?= h($dn) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= h($gf['q']) ?>" placeholder="नाव, Emp ID, Gate…" class="form-control-custom" style="width:auto;min-width:180px;">
  <?php if ($gf['dir'] !== ''): ?><input type="hidden" name="dir" value="<?= h($gf['dir']) ?>"><?php endif; ?>
  <button class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">शोधा</button>
  <?php if (!$isToday): ?><a href="log.php" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">आज</a><?php endif; ?>
</form>

<?= smartTiles([
    ['label' => 'आत आले', 'value' => $gk['in'], 'sub' => date('d/m/Y', strtotime($day)), 'tone' => 'ok', 'href' => $gqs(['dir' => 'in'])],
    ['label' => 'बाहेर गेले', 'value' => $gk['out'], 'sub' => 'Scan नोंदी', 'href' => $gqs(['dir' => 'out'])],
    ['label' => $isToday ? 'आत्ता आत आहेत' : 'दिवसअखेर आत', 'value' => count($inside), 'sub' => $gk['people'] . ' कर्मचारी आले', 'tone' => 'ok'],
    ['label' => 'अवैध / जुने QR', 'value' => $gk['bad'], 'sub' => $gk['bad'] ? 'तपासा' : 'काही नाही', 'tone' => $gk['bad'] ? 'bad' : 'ok', 'href' => $gqs(['dir' => 'bad'])],
]) ?>

<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">⏰ तासवार ये-जा</div><div class="sub"><span style="color:#16A34A;">■</span> आत <span style="color:#F97316;margin-left:6px;">■</span> बाहेर</div></div>
      <div style="padding:6px 16px 14px;">
        <div class="gt-hours"><?php for ($i = 5; $i <= 22; $i++): $hx = $hours[$i]; ?><div title="<?= $i ?>:00 — आत <?= $hx['in'] ?>, बाहेर <?= $hx['out'] ?>"><i class="in" style="height:<?= round($hx['in'] * 100 / $hmax, 1) ?>%;"></i><i class="out" style="height:<?= round($hx['out'] * 100 / $hmax, 1) ?>%;"></i></div><?php endfor; ?></div>
        <div class="gt-hl"><?php for ($i = 5; $i <= 22; $i++): ?><span><?= $i % 3 === 0 ? $i : '' ?></span><?php endfor; ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🟢 <?= $isToday ? 'आत्ता आत आहेत' : 'दिवसअखेर आत' ?> (<?= count($inside) ?>)</div><div class="sub">ज्यांची शेवटची नोंद आत आहे</div></div>
      <div style="padding:8px 14px 14px;max-height:260px;overflow:auto;">
        <?php if (!$inside): ?><div style="color:var(--text-dim);font-size:13px;">कोणी नाही.</div><?php else: ?>
        <div class="gt-in"><?php foreach ($inside as $r): ?><div><span class="gt-av"><?= $av($r) ?></span><span style="min-width:0;"><b><?= h($r['name']) ?></b><small><?= h($r['dept_name'] ?: '—') ?> · <?= date('h:i A', strtotime($r['created_at'])) ?> पासून</small></span></div><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head d-flex justify-content-between flex-wrap gap-2"><div class="title">🗂️ सर्व नोंदी (<?= count($rows) ?>)</div>
    <div class="gt-chips d-flex gap-1 flex-wrap"><?php foreach (['' => 'सर्व', 'in' => '⬇ आत', 'out' => '⬆ बाहेर', 'bad' => '⛔ अवैध'] as $dk => $dl): ?><a href="<?= h($gqs(['dir' => $dk])) ?>" class="<?= $gf['dir'] === $dk ? 'on' : '' ?>"><?= $dl ?></a><?php endforeach; ?></div></div>
  <div style="overflow-x:auto;">
    <table class="table gt-t mb-0">
      <thead><tr><th>वेळ</th><th>कर्मचारी</th><th>विभाग</th><th>आत / बाहेर</th><th>पद्धत</th><th>Gate</th><th>Scan केलं</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" style="color:var(--text-dim);">नोंद नाही.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $okr = $r['result'] === 'ok'; ?>
        <tr>
          <td style="white-space:nowrap;font-weight:600;"><?= date('h:i:s A', strtotime($r['created_at'])) ?></td>
          <td><span class="gt-av me-2"><?= $av($r) ?></span><b><?= h($r['name'] ?? '—') ?></b> <small style="color:var(--text-dim);"><?= h($r['employee_code'] ?: ($r['user_id'] ? 'ID-' . $r['user_id'] : '')) ?></small></td>
          <td><?= h($r['dept_name'] ?? '—') ?></td>
          <td><?php if ($okr): ?><span class="gt-tag <?= $r['direction'] === 'in' ? 'in' : 'out' ?>"><?= $r['direction'] === 'in' ? '⬇ आत' : '⬆ बाहेर' ?></span><?php else: ?><span class="gt-tag bad">⛔ <span><?= h($labels[$r['result']] ?? $r['result']) ?></span></span><?php endif; ?></td>
          <td style="white-space:nowrap;"><?= h($mlabels[$r['method']] ?? $r['method']) ?></td>
          <td><?= h($r['gate'] ?: '—') ?></td>
          <td style="color:var(--text-dim);"><?= h($r['by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
