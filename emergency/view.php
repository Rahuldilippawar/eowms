<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emergency-helpers.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? $_POST['alert_id'] ?? 0);
$baseSql = "SELECT ea.*, u.name AS triggered_by_name, u.mobile AS triggered_by_mobile, ru.name AS resolved_by_name%s
    FROM emergency_alerts ea JOIN users u ON u.id = ea.triggered_by LEFT JOIN users ru ON ru.id = ea.resolved_by%s
    WHERE ea.id = ? AND ea.organization_id = ?";
try {
    $st = $pdo->prepare(sprintf($baseSql, ', c.name AS campus_name', ' LEFT JOIN campuses c ON c.id = ea.campus_id'));
    $st->execute([$id, $orgId]);
} catch (PDOException $e) { // campus_id column not migrated yet
    $st = $pdo->prepare(sprintf($baseSql, '', ''));
    $st->execute([$id, $orgId]);
}
$alert = $st->fetch();
if (!$alert || !emergencyCanView($pdo, $alert, $user['id'])) {
    http_response_code(404);
    die('<div style="font-family:sans-serif;padding:40px;">सूचना सापडली नाही किंवा बघण्याची परवानगी नाही. <a href="../index.php">' . h(t('s16')) . '</a></div>');
}
$isSender = (int)$alert['triggered_by'] === (int)$user['id'];
$typeLabel = EMERGENCY_TYPE_LABELS[$alert['alert_type']] ?? '🆘';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $alert['status'] === 'active') {
    $action = $_POST['action'] ?? '';
    if ($action === 'ack' && !$isSender) {
        // "मी येत आहे" — प्रतिसाद देणारा + वेळ; पाठवणाऱ्याला आणि इतर Recipients ना कळवतो
        $ins = $pdo->prepare("INSERT IGNORE INTO emergency_alert_responses (alert_id, user_id) VALUES (?, ?)");
        $ins->execute([$id, $user['id']]);
        if ($ins->rowCount() > 0) {
            $pdo->prepare("UPDATE emergency_alerts SET first_ack_at = COALESCE(first_ack_at, NOW()) WHERE id = ?")->execute([$id]);
            $to = [(int)$alert['triggered_by']];
            $rStmt = $pdo->prepare("SELECT user_id FROM emergency_alert_recipients WHERE alert_id = ?");
            $rStmt->execute([$id]);
            $to = array_merge($to, array_map('intval', $rStmt->fetchAll(PDO::FETCH_COLUMN)));
            notifyUsers($to, $orgId, "🏃 {$user['name']} येत आहेत — $typeLabel", ($alert['location'] ?: 'स्थान नमूद नाही'), 'emergency/view.php?id=' . $id, $user['id']);
            logActivity('🆘 आणीबाणी — प्रतिसाद (मी येत आहे)', 'emergency_alert', $id);
        }
        $_SESSION['flash'] = '✅ तुमचा प्रतिसाद नोंदवला — पाठवणाऱ्याला कळवलं आहे.';
    } elseif ($action === 'resolve') {
        $remark = trim($_POST['resolution_remark'] ?? '') ?: null;
        try {
            $pdo->prepare("UPDATE emergency_alerts SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), resolution_remark = ? WHERE id = ? AND organization_id = ? AND status = 'active'")
                ->execute([$user['id'], $remark, $id, $orgId]);
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE emergency_alerts SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND organization_id = ? AND status = 'active'")
                ->execute([$user['id'], $id, $orgId]);
        }
        $to = [(int)$alert['triggered_by']];
        try {
            $rStmt = $pdo->prepare("SELECT user_id FROM emergency_alert_recipients WHERE alert_id = ?");
            $rStmt->execute([$id]);
            $to = array_merge($to, array_map('intval', $rStmt->fetchAll(PDO::FETCH_COLUMN)));
        } catch (PDOException $e) {}
        notifyUsers($to, $orgId, "✅ आणीबाणी नियंत्रणात — $typeLabel", $user['name'] . ($remark ? " — $remark" : ''), 'emergency/view.php?id=' . $id, $user['id']);
        logActivity('आणीबाणी सूचना Resolve केली', 'emergency_alert', $id, $remark);
        $_SESSION['flash'] = 'सूचना Resolved म्हणून चिन्हांकित केली.';
    }
    header('Location: view.php?id=' . $id);
    exit;
}

$iResponded = false;
try {
    $rs = $pdo->prepare("SELECT 1 FROM emergency_alert_responses WHERE alert_id = ? AND user_id = ?");
    $rs->execute([$id, $user['id']]);
    $iResponded = (bool)$rs->fetchColumn();
} catch (PDOException $e) {}

$pageTitle = 'आणीबाणी सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$hasGeo = !empty($alert['latitude']) && !empty($alert['longitude']);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div class="page-title mb-0">🆘 <?= h($typeLabel) ?> — आणीबाणी सूचना</div>
  <?php if (hasPermission('view_emergency_alerts')): ?><a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list me-1"></i>सर्व सूचना</a><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel" style="border-top:4px solid #B23A48;">
      <div style="padding:22px 24px;">
        <div id="sosStatusBanner" style="font-size:15px;font-weight:700;margin-bottom:12px;color:<?= $alert['status'] === 'active' ? 'var(--rose)' : 'var(--rail)' ?>;">
          <?= $alert['status'] === 'active' ? '🔴 सक्रिय' : '✅ Resolved' ?>
        </div>
        <div style="font-size:13px;line-height:2;">
          <div>👤 <strong><?= h($alert['triggered_by_name']) ?></strong><?php if ($alert['triggered_by_mobile']): ?> · <a href="tel:<?= h($alert['triggered_by_mobile']) ?>" style="color:var(--rail);">📞 <?= h($alert['triggered_by_mobile']) ?></a><?php endif; ?></div>
          <div>📍 <?= h($alert['location'] ?: 'स्थान नमूद नाही') ?><?= !empty($alert['campus_name']) ? ' · 🏫 ' . h($alert['campus_name']) : '' ?></div>
          <?php if ($hasGeo): ?>
            <div>🗺️ <a href="https://www.google.com/maps?q=<?= (float)$alert['latitude'] ?>,<?= (float)$alert['longitude'] ?>" target="_blank" rel="noopener" style="color:var(--rail);font-weight:600;">नकाशावर बघा (<?= round((float)$alert['latitude'], 5) ?>, <?= round((float)$alert['longitude'], 5) ?>)</a></div>
          <?php endif; ?>
          <?php if ($alert['message']): ?><div>📝 <?= h($alert['message']) ?></div><?php endif; ?>
          <div>🕒 <?= date('d M Y, h:i:s A', strtotime($alert['created_at'])) ?></div>
          <div id="sosResolvedInfo"><?php if ($alert['status'] === 'resolved'): ?>✅ <?= h($alert['resolved_by_name']) ?> — <?= date('h:i A', strtotime($alert['resolved_at'])) ?><?= !empty($alert['resolution_remark']) ? ' — ' . h($alert['resolution_remark']) : '' ?><?php endif; ?></div>
        </div>

        <?php if ($alert['status'] === 'active'): ?>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <?php if (!$isSender && !$iResponded): ?>
              <form method="POST"><input type="hidden" name="action" value="ack"><input type="hidden" name="alert_id" value="<?= $id ?>">
                <button type="submit" class="btn" style="background:#B23A48;color:#fff;border-radius:9px;font-weight:700;padding:10px 20px;">🏃 मी येत आहे / I'm responding</button>
              </form>
            <?php elseif ($iResponded): ?>
              <span class="status-chip done">✅ तुम्ही प्रतिसाद दिला आहे</span>
            <?php endif; ?>
          </div>
          <form method="POST" class="mt-3">
            <input type="hidden" name="action" value="resolve"><input type="hidden" name="alert_id" value="<?= $id ?>">
            <div class="d-flex gap-2">
              <input type="text" name="resolution_remark" class="form-control-custom" placeholder="काय झालं / शेरा (उदा. आग विझवली, रुग्णालयात नेलं)">
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;white-space:nowrap;padding:6px 14px;">✓ Resolved करा</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">📡 Live स्थिती</div><div class="sub" id="sosRecipients">—</div></div>
      <div style="padding:10px 20px 16px;font-size:13px;">
        <div id="sosResponders" style="color:var(--text-dim);">लोड होत आहे…</div>
        <div id="sosEscalated" style="font-size:12px;color:var(--amber);margin-top:8px;"></div>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:10px;">दर 10 सेकंदांनी आपोआप अद्ययावत होतं</div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var url = 'status.php?id=<?= $id ?>';
  var wasActive = <?= json_encode($alert['status'] === 'active') ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function load() {
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || d.error) return;
      document.getElementById('sosRecipients').textContent = d.recipients + ' जणांना कळवलं';
      document.getElementById('sosResponders').innerHTML = d.responders.length
        ? d.responders.map(function (r) { return '🏃 <strong>' + esc(r.name) + '</strong> येत आहेत · ' + esc(r.at); }).join('<br>')
        : '⏳ अजून कोणीही प्रतिसाद दिलेला नाही';
      document.getElementById('sosEscalated').textContent = d.escalated ? '🚨 5 मिनिटांत प्रतिसाद नसल्याने Management ला पुन्हा कळवलं' : '';
      if (d.status === 'resolved') {
        var b = document.getElementById('sosStatusBanner');
        b.textContent = '✅ Resolved'; b.style.color = 'var(--rail)';
        document.getElementById('sosResolvedInfo').textContent = '✅ ' + (d.resolved_by || '') + ' — ' + (d.resolved_at || '') + (d.remark ? ' — ' + d.remark : '');
        if (wasActive) { setTimeout(function () { location.reload(); }, 1500); wasActive = false; }
      }
    }).catch(function () {});
  }
  load();
  setInterval(load, 10000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
