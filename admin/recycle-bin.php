<?php
// 🗑️ Recycle Bin — Super Admin ने हटवलेल्या नोंदी 30 दिवस परत आणता येतात (जोडलेल्या नोंदींसकट)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$orgId = activeOrgId();
$ready = saTableReady($pdo, 'recycle_bin');
if ($ready) { recyclePurgeOld($pdo); }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['bin_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'restore') {
        [$ok, $msg] = recycleRestore($pdo, $id, $orgId);
        $_SESSION['flash'] = ($ok ? '✅ ' : '⚠️ ') . $msg;
    } elseif (($_POST['action'] ?? '') === 'purge') {
        $st = $pdo->prepare("DELETE FROM recycle_bin WHERE id = ? AND organization_id = ?");
        $st->execute([$id, $orgId]);
        if ($st->rowCount()) { logActivity('Recycle Bin मधून कायमचं काढलं', 'recycle_bin', $id); $_SESSION['flash'] = 'कायमचं काढलं.'; }
    }
    header('Location: recycle-bin.php');
    exit;
}

$rows = [];
if ($ready) {
    $st = $pdo->prepare("SELECT b.id, b.entity_table, b.entity_id, b.label, b.deleted_at, b.restored_at, LENGTH(b.payload) sz, u.name deleted_by_name, r.name restored_by_name,
        DATEDIFF(DATE_ADD(b.deleted_at, INTERVAL " . SA_RECYCLE_DAYS . " DAY), NOW()) days_left
        FROM recycle_bin b LEFT JOIN users u ON u.id = b.deleted_by LEFT JOIN users r ON r.id = b.restored_by
        WHERE b.organization_id = ? ORDER BY b.restored_at IS NOT NULL, b.deleted_at DESC LIMIT 200");
    $st->execute([$orgId]);
    $rows = $st->fetchAll();
}
$types = ['users' => '👤 कर्मचारी', 'campuses' => '🏫 Campus', 'hostels' => '🏠 वसतिगृह', 'vehicles' => '🚗 वाहन', 'vehicle_trips' => '🛣️ वाहन फेरी', 'vehicle_fuel_log' => '⛽ इंधन नोंद',
    'vehicle_maintenance' => '🔧 वाहन देखभाल', 'dead_stock_items' => '📦 Dead Stock', 'renewal_items' => '🔁 नूतनीकरण', 'meditation_quotes' => '🪷 सुविचार', 'satsang_duties' => '🪷 सत्संग Duty', 'departments' => '🗂️ विभाग'];
$open = array_filter($rows, fn($r) => !$r['restored_at']);

$pageTitle = 'Recycle Bin';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">🗑️ Recycle Bin</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">चुकून हटवलेली नोंद <?= SA_RECYCLE_DAYS ?> दिवसांत परत आणा — तिच्याशी थेट जोडलेल्या नोंदींसकट (उदा. वाहनाच्या फेऱ्या, कर्मचाऱ्याची हजेरी). त्यानंतर आपोआप कायमची जाते.</div>
<?= saNav('recycle-bin') ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ Database Update बाकी — त्यानंतरच हटवलेल्या नोंदी इथे येतील.</div><?php endif; ?>

<div class="panel">
  <?php if (!$rows): ?><div class="sm-empty" style="padding:40px 20px;">Recycle Bin रिकामं आहे.</div><?php else: ?>
  <div class="table-responsive"><table class="rtable">
    <thead><tr><th>नोंद</th><th>प्रकार</th><th>कोणी हटवली</th><th>कधी</th><th>बाकी</th><th data-no-sort></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="<?= $r['restored_at'] ? 'opacity:.55;' : '' ?>">
        <td><b><?= h($r['label'] ?: '#' . $r['entity_id']) ?></b><div class="font-mono" style="font-size:10.5px;color:var(--text-dim);"><?= h($r['entity_table']) ?> #<?= (int)$r['entity_id'] ?> · <?= round($r['sz'] / 1024, 1) ?> KB</div></td>
        <td><?= h($types[$r['entity_table']] ?? $r['entity_table']) ?></td>
        <td><?= h($r['deleted_by_name'] ?: '—') ?></td>
        <td style="font-size:12px;" data-sort="<?= h($r['deleted_at']) ?>"><?= date('d/m/Y h:i A', strtotime($r['deleted_at'])) ?></td>
        <td data-sort="<?= (int)$r['days_left'] ?>"><?= $r['restored_at'] ? '<span class="status-chip done">परत आणली</span><div style="font-size:10.5px;color:var(--text-dim);">' . h($r['restored_by_name'] ?: '') . ' · ' . date('d/m', strtotime($r['restored_at'])) . '</div>' : '<span style="font-size:12px;color:' . ($r['days_left'] <= 5 ? 'var(--rose)' : 'inherit') . ';">' . max(0, (int)$r['days_left']) . ' दिवस</span>' ?></td>
        <td style="white-space:nowrap;text-align:right;">
          <?php if (!$r['restored_at']): ?>
            <form method="POST" class="d-inline"><input type="hidden" name="action" value="restore"><input type="hidden" name="bin_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:4px 12px;">↩ परत आणा</button></form>
          <?php endif; ?>
          <form method="POST" class="d-inline" onsubmit="return confirm('ही प्रत कायमची काढायची? नंतर परत आणता येणार नाही.');"><input type="hidden" name="action" value="purge"><input type="hidden" name="bin_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm" title="कायमचं काढा" style="border:1px solid var(--line);color:var(--rose);border-radius:7px;font-size:12px;padding:4px 9px;">✕</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
