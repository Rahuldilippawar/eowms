<?php
// 🔐 Active Sessions — आत्ता कोण कुठून Login आहे; फोन हरवला / कर्मचारी सोडून गेला तर एका Click ने Logout
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$orgId = activeOrgId();
$me = currentUser();
$ready = saTableReady($pdo, 'user_sessions');
$win = saActiveWindowMinutes();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $myHash = hash('sha256', session_id());
    if ($a === 'revoke') {
        $st = $pdo->prepare("SELECT s.id, s.session_hash, u.name, u.id uid FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND u.organization_id = ? AND s.ended_at IS NULL");
        $st->execute([(int)($_POST['sid'] ?? 0), $orgId]);
        $s = $st->fetch();
        if ($s && $s['session_hash'] !== $myHash) {
            $pdo->prepare("UPDATE user_sessions SET ended_at = NOW(), revoked_by = ? WHERE id = ?")->execute([$me['id'], $s['id']]);
            logActivity('Session बंद केलं (Force Logout)', 'user', (int)$s['uid'], $s['name']);
            $_SESSION['flash'] = '✅ ' . $s['name'] . ' यांचं हे Login बंद केलं — पुढच्या Click ला (1 मिनिटात) Logout होईल.';
        }
    } elseif ($a === 'revoke_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        $chk = $pdo->prepare("SELECT name FROM users WHERE id = ? AND organization_id = ?");
        $chk->execute([$uid, $orgId]);
        if ($name = $chk->fetchColumn()) {
            $n = $pdo->prepare("UPDATE user_sessions SET ended_at = NOW(), revoked_by = ? WHERE user_id = ? AND ended_at IS NULL AND session_hash <> ?");
            $n->execute([$me['id'], $uid, $myHash]);
            logActivity('सगळीकडून Logout केलं', 'user', $uid, $name . ' — ' . $n->rowCount() . ' Sessions');
            $_SESSION['flash'] = '✅ ' . $name . ' — ' . $n->rowCount() . ' ठिकाणांहून Logout केलं.';
        }
    }
    header('Location: sessions.php');
    exit;
}

$rows = [];
if ($ready) {
    $st = $pdo->prepare("SELECT s.*, u.name, u.role, d.name_local dept, TIMESTAMPDIFF(MINUTE, s.last_seen, NOW()) idle
        FROM user_sessions s JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.organization_id = ? AND s.ended_at IS NULL AND s.last_seen >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY s.last_seen DESC");
    $st->execute([$orgId, $win]);
    $rows = $st->fetchAll();
}
$myHash = session_id() !== '' ? hash('sha256', session_id()) : '';
$device = function ($ua) {
    $ua = (string)$ua;
    $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad/i', $ua) ? 'iPhone/iPad' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'Mac' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'अज्ञात'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser')));
    $icon = preg_match('/Android|iPhone|Mobile/i', $ua) ? '📱' : '💻';
    return "$icon $os · $br";
};
$users = count(array_unique(array_column($rows, 'user_id')));
$now5 = count(array_filter($rows, fn($r) => $r['idle'] <= 5));
$multi = array_filter(array_count_values(array_column($rows, 'user_id')), fn($c) => $c > 1);

$pageTitle = 'Active Sessions';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">🔐 Active Sessions</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">गेल्या <?= $win / 60 ?> तासांत हालचाल असलेले Login. फोन हरवला किंवा कर्मचारी सोडून गेला — "Logout करा" दाबा; पुढच्या Click ला (1 मिनिटात) ते Login बंद होतं.</div>
<?= saNav('sessions') ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ Database Update बाकी — <a href="../system-update/db-update.php">System Update → Database Update</a> चालवा.</div><?php endif; ?>
<?php smartTiles([
    ['label' => 'चालू Sessions', 'value' => count($rows), 'sub' => $users . ' व्यक्ती'],
    ['label' => 'आत्ता सक्रिय', 'value' => $now5, 'sub' => 'गेल्या 5 मिनिटांत', 'tone' => 'ok'],
    ['label' => 'एकापेक्षा जास्त ठिकाणी', 'value' => count($multi), 'sub' => 'एकाच वेळी 2+ Device', 'tone' => $multi ? 'warn' : ''],
]); ?>

<div class="panel">
  <?php if (!$rows): ?><div class="sm-empty">सध्या कोणतंही Session नाही.</div><?php else: ?>
  <div class="table-responsive"><table class="rtable">
    <thead><tr><th>व्यक्ती</th><th>Device</th><th>IP</th><th>Login</th><th>शेवटची हालचाल</th><th data-no-sort></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $mine = $r['session_hash'] === $myHash; ?>
      <tr>
        <td><b><?= h($r['name']) ?></b> <?= isset($multi[$r['user_id']]) ? '<span class="status-chip pending" title="एकाच वेळी अनेक ठिकाणी">' . $multi[$r['user_id']] . ' ठिकाणी</span>' : '' ?><div style="font-size:11px;color:var(--text-dim);"><?= h($r['dept'] ?: $r['role']) ?></div></td>
        <td style="font-size:12.5px;"><?= h($device($r['user_agent'])) ?></td>
        <td class="font-mono" style="font-size:12px;"><?= h($r['ip'] ?: '—') ?></td>
        <td style="font-size:12px;" data-sort="<?= h($r['created_at']) ?>"><?= date('d/m, h:i A', strtotime($r['created_at'])) ?></td>
        <td data-sort="<?= (int)$r['idle'] ?>"><?= $r['idle'] <= 1 ? '<b style="color:#137A4B;">● आत्ता</b>' : ((int)$r['idle'] . ' मिनिटांपूर्वी') ?></td>
        <td style="white-space:nowrap;text-align:right;">
          <?php if ($mine): ?><span class="status-chip done">हे तुमचं</span>
          <?php else: ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('<?= h(addslashes($r['name'])) ?> यांचं हे Login बंद करायचं?');"><input type="hidden" name="action" value="revoke"><input type="hidden" name="sid" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:4px 10px;">Logout करा</button></form>
            <?php if (isset($multi[$r['user_id']])): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('<?= h(addslashes($r['name'])) ?> यांना सगळ्या Devices वरून Logout करायचं?');"><input type="hidden" name="action" value="revoke_user"><input type="hidden" name="uid" value="<?= (int)$r['user_id'] ?>">
              <button class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;font-size:12px;padding:4px 10px;">सगळीकडून</button></form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
