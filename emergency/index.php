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

$pageTitle = 'आणीबाणी सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("
    SELECT ea.*, u.name AS triggered_by_name, u.mobile AS triggered_by_mobile, ru.name AS resolved_by_name,
      (SELECT COUNT(*) FROM emergency_alert_responses er WHERE er.alert_id = ea.id) AS responder_count
    FROM emergency_alerts ea JOIN users u ON u.id = ea.triggered_by LEFT JOIN users ru ON ru.id = ea.resolved_by
    WHERE ea.organization_id = ? ORDER BY ea.status = 'active' DESC, ea.created_at DESC
");
$stmt->execute([$orgId]);
$alerts = $stmt->fetchAll();

$typeLabels = ['fire' => '🔥 आग', 'medical' => '🚑 वैद्यकीय', 'security' => '🛡️ सुरक्षा', 'other' => '🆘 इतर'];
$activeCount = count(array_filter($alerts, fn($a) => $a['status'] === 'active'));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🆘 आणीबाणी सूचना</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($activeCount > 0): ?>
  <div class="alert" style="background:var(--rose-soft);color:var(--rose);border-radius:10px;font-size:14px;padding:16px 20px;font-weight:600;margin-bottom:20px;">
    🚨 सध्या <?= $activeCount ?> सक्रिय आणीबाणी सूचना आहे/आहेत!
  </div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>प्रकार</th><th>कोणी पाठवली</th><th>स्थान</th><th>तपशील</th><th>दिनांक</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($alerts)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही आणीबाणी सूचना नाही</td></tr><?php endif; ?>
      <?php foreach ($alerts as $a): ?>
        <tr style="<?= $a['status'] === 'active' ? 'background:var(--rose-soft);' : '' ?>">
          <td style="font-weight:600;"><?= h($typeLabels[$a['alert_type']] ?? $a['alert_type']) ?></td>
          <td><?= h($a['triggered_by_name']) ?><?php if ($a['triggered_by_mobile']): ?><div style="font-size:10.5px;color:var(--text-dim);"><a href="tel:<?= h($a['triggered_by_mobile']) ?>" style="color:var(--rail);">📞 <?= h($a['triggered_by_mobile']) ?></a></div><?php endif; ?></td>
          <td><?= h($a['location'] ?: '—') ?><?php if (!empty($a['latitude'])): ?><div><a href="https://www.google.com/maps?q=<?= (float)$a['latitude'] ?>,<?= (float)$a['longitude'] ?>" target="_blank" rel="noopener" style="font-size:10.5px;color:var(--rail);">🗺️ नकाशा</a></div><?php endif; ?></td>
          <td style="max-width:220px;"><?= h($a['message'] ?: '—') ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
