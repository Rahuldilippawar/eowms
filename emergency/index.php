<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'emergency_alert_responses', '../');
$user = currentUser();
$orgId = activeOrgId();

if (!hasPermission('view_emergency_alerts')) {
    http_response_code(403);
    die('तुम्हाला हे पान बघण्याची परवानगी नाही.');
}

// Resolve / "मी येत आहे" — आता view.php वर (शेरा व प्रतिसाद-नोंदीसह)

$stmt = $pdo->prepare("
    SELECT ea.*, u.name AS triggered_by_name, u.mobile AS triggered_by_mobile, ru.name AS resolved_by_name, c.name AS campus_name,
      (SELECT COUNT(*) FROM emergency_alert_responses er WHERE er.alert_id = ea.id) AS responder_count,
      GREATEST(TIMESTAMPDIFF(SECOND, ea.created_at, ea.first_ack_at), 0) AS ack_sec,
      GREATEST(TIMESTAMPDIFF(SECOND, ea.created_at, ea.resolved_at), 0) AS res_sec
    FROM emergency_alerts ea JOIN users u ON u.id = ea.triggered_by LEFT JOIN users ru ON ru.id = ea.resolved_by LEFT JOIN campuses c ON c.id = ea.campus_id
    WHERE ea.organization_id = ? ORDER BY ea.status = 'active' DESC, ea.created_at DESC LIMIT 1000
");
$stmt->execute([$orgId]);
$alerts = $stmt->fetchAll();

$typeLabels = ['fire' => '🔥 आग', 'medical' => '🚑 वैद्यकीय', 'security' => '🛡️ सुरक्षा', 'other' => '🆘 इतर'];
$dur = function ($s) { if ($s === null || $s === '') return '—'; $s = (int)$s; return $s < 60 ? $s . ' से.' : ($s < 3600 ? round($s / 60) . ' मि.' : round($s / 3600, 1) . ' तास'); };

// 📊 Excel
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="emergency-alerts-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['दिनांक', 'वेळ', 'प्रकार', 'कोणी पाठवली', 'College/School', 'स्थान', 'तपशील', 'प्रतिसाद देणारे', 'पहिला प्रतिसाद (मिनिटं)', 'Escalate झाली', 'स्थिती', 'Resolve केली', 'Resolve वेळ (मिनिटं)', 'शेरा']);
    foreach ($alerts as $a) {
        fputcsv($out, [date('Y-m-d', strtotime($a['created_at'])), date('H:i', strtotime($a['created_at'])), strip_tags($typeLabels[$a['alert_type']] ?? $a['alert_type']), $a['triggered_by_name'], $a['campus_name'], $a['location'], $a['message'],
            $a['responder_count'], $a['ack_sec'] !== null ? round($a['ack_sec'] / 60, 1) : '', $a['escalated_at'] ? 'हो' : '', $a['status'] === 'active' ? 'सक्रिय' : 'Resolved', $a['resolved_by_name'],
            $a['res_sec'] !== null ? round($a['res_sec'] / 60, 1) : '', $a['resolution_remark']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'आणीबाणी सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$activeCount = count(array_filter($alerts, fn($a) => $a['status'] === 'active'));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-1">🆘 आणीबाणी सूचना</div>
  <a href="?export=csv" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;padding:8px 14px;">📊 Excel</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($activeCount > 0): ?>
  <div class="alert" style="background:var(--rose-soft);color:var(--rose);border-radius:10px;font-size:14px;padding:16px 20px;font-weight:600;margin-bottom:20px;">
    🚨 सध्या <?= $activeCount ?> सक्रिय आणीबाणी सूचना आहे/आहेत!
  </div>
<?php endif; ?>

<?php
// 🧠 आढावा — मागील 12 महिने
$y = array_filter($alerts, fn($a) => strtotime($a['created_at']) >= strtotime('-12 months'));
$acks = array_filter(array_column($y, 'ack_sec'), fn($v) => $v !== null);
$ress = array_filter(array_column($y, 'res_sec'), fn($v) => $v !== null);
$month = count(array_filter($alerts, fn($a) => strtotime($a['created_at']) >= strtotime(date('Y-m-01'))));
$noAck = count(array_filter($y, fn($a) => $a['ack_sec'] === null && (int)$a['responder_count'] === 0));
$esc = count(array_filter($y, fn($a) => !empty($a['escalated_at'])));
smartTiles([
    ['label' => 'या महिन्यात', 'value' => $month, 'sub' => count($y) . ' मागील 12 महिन्यांत'],
    ['label' => 'सरासरी पहिला प्रतिसाद', 'value' => $acks ? $dur(array_sum($acks) / count($acks)) : '—', 'tone' => $acks && array_sum($acks) / count($acks) > 300 ? 'warn' : 'ok', 'sub' => 'SOS ते "मी येत आहे"'],
    ['label' => 'सरासरी Resolve वेळ', 'value' => $ress ? $dur(array_sum($ress) / count($ress)) : '—'],
    ['label' => 'प्रतिसाद न मिळालेल्या', 'value' => $noAck, 'tone' => $noAck ? 'bad' : 'ok', 'sub' => $esc . ' Management ला Escalate'],
]);
$byType = []; $byCampus = [];
foreach ($y as $a) { $t = $typeLabels[$a['alert_type']] ?? $a['alert_type']; $byType[$t] = ($byType[$t] ?? 0) + 1; $cn = $a['campus_name'] ?: 'Campus नमूद नाही'; $byCampus[$cn] = ($byCampus[$cn] ?? 0) + 1; }
arsort($byType); arsort($byCampus);
if ($y) {
    echo '<div class="row g-3 mb-3"><div class="col-lg-6">';
    smartBars('🗂️ प्रकारानुसार', 'मागील 12 महिने', array_map(fn($k, $v) => ['name' => $k, 'value' => $v], array_keys($byType), array_values($byType)), 'var(--rose)');
    echo '</div><div class="col-lg-6">';
    smartBars('🏫 College / School नुसार', 'मागील 12 महिने', array_map(fn($k, $v) => ['name' => $k, 'value' => $v], array_slice(array_keys($byCampus), 0, 8), array_slice(array_values($byCampus), 0, 8)), 'var(--amber)');
    echo '</div></div>';
}
?>
<?php if (count($alerts) > 5): ?>
<div class="em-tools">
  <?php foreach (['' => 'सर्व', 'active' => '🔴 सक्रिय', 'resolved' => '✅ Resolved'] as $k => $l): ?><button type="button" class="em-chip<?= $k === '' ? ' on' : '' ?>" data-s="<?= $k ?>"><?= $l ?></button><?php endforeach; ?>
  <select id="emType" class="form-select-custom" style="width:auto;"><option value="">सर्व प्रकार</option><?php foreach ($typeLabels as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select>
  <input type="search" id="emQ" class="form-control-custom" placeholder="🔍 नाव / स्थान / तपशील" style="max-width:240px;">
</div>
<?php endif; ?>

<div class="panel">
  <table class="rtable" id="emTable">
    <thead><tr><th>प्रकार</th><th>कोणी पाठवली</th><th>स्थान</th><th>तपशील</th><th>दिनांक</th><th>प्रतिसाद</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($alerts)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही आणीबाणी सूचना नाही</td></tr><?php endif; ?>
      <?php foreach ($alerts as $a): ?>
        <tr style="<?= $a['status'] === 'active' ? 'background:var(--rose-soft);' : '' ?>" data-s="<?= h($a['status']) ?>" data-t="<?= h($a['alert_type']) ?>" data-q="<?= h(mb_strtolower($a['triggered_by_name'] . ' ' . $a['location'] . ' ' . $a['message'] . ' ' . $a['campus_name'])) ?>">
          <td style="font-weight:600;"><?= h($typeLabels[$a['alert_type']] ?? $a['alert_type']) ?></td>
          <td><?= h($a['triggered_by_name']) ?><?php if ($a['triggered_by_mobile']): ?><div style="font-size:10.5px;color:var(--text-dim);"><a href="tel:<?= h($a['triggered_by_mobile']) ?>" style="color:var(--rail);">📞 <?= h($a['triggered_by_mobile']) ?></a></div><?php endif; ?></td>
          <td><?= h($a['location'] ?: '—') ?><?php if (!empty($a['latitude'])): ?><div><a href="https://www.google.com/maps?q=<?= (float)$a['latitude'] ?>,<?= (float)$a['longitude'] ?>" target="_blank" rel="noopener" style="font-size:10.5px;color:var(--rail);">🗺️ नकाशा</a></div><?php endif; ?></td>
          <td style="max-width:220px;"><?= h($a['message'] ?: '—') ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?><?= $a['campus_name'] ? '<div style="color:var(--text-dim);">' . h($a['campus_name']) . '</div>' : '' ?></td>
          <td style="font-size:11.5px;white-space:nowrap;"><?php if ($a['ack_sec'] !== null): ?><span style="color:<?= $a['ack_sec'] > 300 ? 'var(--amber)' : 'var(--rail)' ?>;font-weight:600;">⚡ <?= h($dur($a['ack_sec'])) ?></span><?php elseif ($a['status'] === 'active'): ?><span style="color:var(--rose);font-weight:700;">⏳ <?= h($dur(time() - strtotime($a['created_at']))) ?> पासून</span><?php else: ?><span style="color:var(--text-dim);">—</span><?php endif; ?>
            <?= !empty($a['escalated_at']) ? '<div style="color:var(--rose);">⬆ Escalate</div>' : '' ?><?= $a['res_sec'] !== null ? '<div style="color:var(--text-dim);">✔ ' . h($dur($a['res_sec'])) . '</div>' : '' ?></td>
          <td>
            <?php if ($a['status'] === 'active'): ?>
              <span class="status-chip overdue">🔴 सक्रिय</span>
            <?php else: ?>
              <span class="status-chip done">✅ Resolved</span>
              <div style="font-size:10px;color:var(--text-dim);"><?= h($a['resolved_by_name']) ?> — <?= date('d M, h:i A', strtotime($a['resolved_at'])) ?><?= !empty($a['resolution_remark']) ? '<br>' . h($a['resolution_remark']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td>
            <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-sm" style="<?= $a['status'] === 'active' ? 'background:#B23A48;color:#fff;' : 'border:1px solid var(--line);' ?>border-radius:7px;font-size:11px;padding:5px 12px;"><?= $a['status'] === 'active' ? '🏃 प्रतिसाद / Resolve' : 'तपशील' ?></a>
            <?php if ($a['responder_count'] > 0): ?><div style="font-size:10px;color:var(--text-dim);margin-top:3px;"><?= (int)$a['responder_count'] ?> जण येत आहेत</div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<style>
.em-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;}
.em-chip{border:1px solid var(--line);background:var(--panel,#fff);border-radius:20px;padding:6px 13px;font-size:12.5px;font-weight:600;}
.em-chip.on{background:var(--rail);border-color:var(--rail);color:#fff;}
</style>
<script>
(function () {
  var s = '', rows = document.querySelectorAll('#emTable tbody tr[data-s]'), q = document.getElementById('emQ'), t = document.getElementById('emType');
  function apply() { var x = q ? q.value.trim().toLowerCase() : '', ty = t ? t.value : ''; rows.forEach(function (r) { r.style.display = (!s || r.dataset.s === s) && (!ty || r.dataset.t === ty) && (!x || r.dataset.q.indexOf(x) !== -1) ? '' : 'none'; }); }
  document.querySelectorAll('.em-chip').forEach(function (b) { b.onclick = function () { s = b.dataset.s; document.querySelectorAll('.em-chip').forEach(function (y) { y.classList.toggle('on', y === b); }); apply(); }; });
  if (q) q.oninput = apply; if (t) t.onchange = apply;
  // सक्रिय SOS असताना पान दर 30 सेकंदांनी ताजं (प्रतिसाद / Resolve लगेच दिसावा)
  <?php if ($activeCount > 0): ?>setTimeout(function () { if (!document.hidden && !(q && q.value)) location.reload(); }, 30000);<?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
