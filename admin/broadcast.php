<?php
// 📢 Broadcast — संपूर्ण संस्था / Campus / विभाग / पद-श्रेणी / पद यांना एकदम सूचना (App + Mobile Push); कोणी वाचलं ते दिसतं
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$orgId = activeOrgId();
$me = currentUser();
$ready = saTableReady($pdo, 'broadcasts');

$campuses = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name"); $campuses->execute([$orgId]); $campuses = $campuses->fetchAll(PDO::FETCH_KEY_PAIR);
$depts = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local,''), name) FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY 2"); $depts->execute([$orgId]); $depts = $depts->fetchAll(PDO::FETCH_KEY_PAIR);
$roles = $pdo->query("SELECT role_key, COALESCE(NULLIF(label_local,''), label) FROM role_definitions WHERE role_key <> 'super_admin' ORDER BY label")->fetchAll(PDO::FETCH_KEY_PAIR);
$cats = ['management' => 'सर्व Management', 'hod' => 'सर्व HOD', 'staff' => 'सर्व Staff'];

/** प्राप्तकर्ते — [ids, label] */
function bcRecipients(PDO $pdo, $orgId, $aud, $val, $campuses, $depts, $roles, $cats) {
    $base = "SELECT u.id FROM users u LEFT JOIN role_definitions rd ON rd.role_key = u.role WHERE u.organization_id = ? AND u.is_active = 1";
    switch ($aud) {
        case 'campus': $sql = "$base AND u.campus_id = ?"; $p = [(int)$val]; $label = 'Campus: ' . ($campuses[(int)$val] ?? '?'); break;
        case 'dept': $sql = "$base AND u.department_id = ?"; $p = [(int)$val]; $label = 'विभाग: ' . ($depts[(int)$val] ?? '?'); break;
        case 'category': $sql = "$base AND rd.category = ?"; $p = [(string)$val]; $label = $cats[$val] ?? $val; break;
        case 'role': $sql = "$base AND u.role = ?"; $p = [(string)$val]; $label = 'पद: ' . ($roles[$val] ?? $val); break;
        default: $sql = $base; $p = []; $label = 'संपूर्ण संस्था'; $aud = 'all';
    }
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$orgId], $p));
    return [array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $label, $aud];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aud = $_POST['audience'] ?? 'all';
    $val = $_POST['audience_' . $aud] ?? null;
    if (isset($_POST['preview'])) {   // किती जणांना जाईल (AJAX)
        [$ids, $label] = bcRecipients($pdo, $orgId, $aud, $val, $campuses, $depts, $roles, $cats);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['count' => count(array_diff($ids, [(int)$me['id']])), 'label' => $label]);
        exit;
    }
    $title = trim($_POST['title'] ?? '');
    $msg = trim($_POST['message'] ?? '');
    $link = trim($_POST['link'] ?? '');
    if (!$ready || $title === '' || mb_strlen($title) > 200) { $_SESSION['flash'] = '⚠️ शीर्षक आवश्यक आहे (200 अक्षरांपर्यंत).'; header('Location: broadcast.php'); exit; }
    if ($link !== '' && (preg_match('#^(https?:)?//#i', $link) || strpos($link, '..') !== false || !preg_match('#^[a-z0-9_\-/]+\.php(\?[^\s"<>]*)?$#i', $link))) { $link = ''; }   // फक्त App मधली पानं
    [$ids, $label, $aud] = bcRecipients($pdo, $orgId, $aud, $val, $campuses, $depts, $roles, $cats);
    $ids = array_values(array_diff($ids, [(int)$me['id']]));
    if (!$ids) { $_SESSION['flash'] = '⚠️ या गटात एकही सक्रिय व्यक्ती नाही.'; header('Location: broadcast.php'); exit; }

    $pdo->prepare("INSERT INTO broadcasts (organization_id, title, message, link, audience, audience_value, audience_label, recipients, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$orgId, $title, $msg ?: null, $link ?: null, $aud, $val, $label, count($ids), $me['id']]);
    $bid = (int)$pdo->lastInsertId();
    $before = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM notifications")->fetchColumn();
    @set_time_limit(120);
    notifyUsers($ids, $orgId, '📢 ' . $title, $msg ?: null, $link ?: 'notifications/index.php');
    $pdo->prepare("UPDATE notifications SET broadcast_id = ? WHERE id > ? AND organization_id = ? AND title = ?")->execute([$bid, $before, $orgId, '📢 ' . $title]);
    logActivity('Broadcast पाठवला', 'broadcast', $bid, $label . ' — ' . count($ids) . ' जण');
    $_SESSION['flash'] = '✅ "' . $title . '" — ' . count($ids) . ' जणांना पाठवला (' . $label . ').';
    header('Location: broadcast.php');
    exit;
}

$history = [];
if ($ready) {
    $st = $pdo->prepare("SELECT b.*, u.name sender, (SELECT COUNT(*) FROM notifications n WHERE n.broadcast_id = b.id AND n.is_read = 1) reads,
        (SELECT COUNT(*) FROM notifications n WHERE n.broadcast_id = b.id) delivered
        FROM broadcasts b LEFT JOIN users u ON u.id = b.sent_by WHERE b.organization_id = ? ORDER BY b.created_at DESC LIMIT 30");
    $st->execute([$orgId]);
    $history = $st->fetchAll();
}
$showUnread = (int)($_GET['unread'] ?? 0);
$unreadList = [];
if ($showUnread && $ready) {
    $st = $pdo->prepare("SELECT u.name, d.name_local dept FROM notifications n JOIN users u ON u.id = n.user_id LEFT JOIN departments d ON d.id = u.department_id
        JOIN broadcasts b ON b.id = n.broadcast_id WHERE n.broadcast_id = ? AND b.organization_id = ? AND n.is_read = 0 ORDER BY u.name");
    $st->execute([$showUnread, $orgId]);
    $unreadList = $st->fetchAll();
}

$pageTitle = 'Broadcast';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">📢 Broadcast — सगळ्यांना एकदम सूचना</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">App मध्ये 🔔 सूचना + Mobile Push (App बंद असतानाही). उदा. "उद्या सुट्टी", "रात्री 10 वाजता System Update".</div>
<?= saNav('broadcast') ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ Database Update बाकी — <a href="../system-update/db-update.php">System Update → Database Update</a> चालवा.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <form method="POST" class="panel" id="bcForm" onsubmit="return confirm('ही सूचना ' + (document.getElementById('bcCount').dataset.n || '?') + ' जणांना पाठवायची?');">
      <div class="panel-head"><div class="title">नवीन सूचना</div></div>
      <div style="padding:14px 18px;">
        <label class="form-label-custom">कोणाला</label>
        <div class="bc-aud">
          <?php foreach (['all' => '🏢 सगळे', 'campus' => '🏫 Campus', 'dept' => '🗂️ विभाग', 'category' => '👔 श्रेणी', 'role' => '🎖️ पद'] as $k => $l): ?>
            <label><input type="radio" name="audience" value="<?= $k ?>" <?= $k === 'all' ? 'checked' : '' ?>><span><?= $l ?></span></label>
          <?php endforeach; ?>
        </div>
        <select name="audience_campus" class="form-select-custom bc-val" data-for="campus" hidden><?php foreach ($campuses as $i => $n): ?><option value="<?= (int)$i ?>"><?= h($n) ?></option><?php endforeach; ?></select>
        <select name="audience_dept" class="form-select-custom bc-val" data-for="dept" hidden><?php foreach ($depts as $i => $n): ?><option value="<?= (int)$i ?>"><?= h($n) ?></option><?php endforeach; ?></select>
        <select name="audience_category" class="form-select-custom bc-val" data-for="category" hidden><?php foreach ($cats as $i => $n): ?><option value="<?= h($i) ?>"><?= h($n) ?></option><?php endforeach; ?></select>
        <select name="audience_role" class="form-select-custom bc-val" data-for="role" hidden><?php foreach ($roles as $i => $n): ?><option value="<?= h($i) ?>"><?= h($n) ?></option><?php endforeach; ?></select>
        <div id="bcCount" class="bc-count">…</div>
        <label class="form-label-custom mt-2">शीर्षक</label>
        <input type="text" name="title" class="form-control-custom" maxlength="200" required placeholder="उदा. उद्या (शनिवार) कार्यालयाला सुट्टी">
        <label class="form-label-custom mt-2">संदेश (ऐच्छिक)</label>
        <textarea name="message" class="form-control-custom" rows="3" maxlength="1000" placeholder="तपशील…"></textarea>
        <label class="form-label-custom mt-2">Click केल्यावर कोणतं पान (ऐच्छिक)</label>
        <input type="text" name="link" class="form-control-custom" placeholder="उदा. staff/holidays.php" pattern="[a-zA-Z0-9_\-/]+\.php(\?.*)?">
        <button type="submit" class="btn w-100 mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;" <?= $ready ? '' : 'disabled' ?>>📢 पाठवा</button>
      </div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title">पाठवलेल्या सूचना</div><div class="sub">किती जणांनी वाचल्या</div></div>
      <?php if (!$history): ?><div class="sm-empty">अजून एकही Broadcast नाही.</div><?php else: foreach ($history as $b): $pct = $b['delivered'] ? (int)round($b['reads'] * 100 / $b['delivered']) : 0; ?>
        <div class="bc-row">
          <div style="flex:1;min-width:0;"><b><?= h($b['title']) ?></b>
            <small><?= h($b['audience_label']) ?> · <?= h($b['sender'] ?: '') ?> · <?= date('d/m/Y h:i A', strtotime($b['created_at'])) ?></small>
            <?php if ($b['message']): ?><div class="bc-msg"><?= h(mb_strimwidth($b['message'], 0, 140, '…')) ?></div><?php endif; ?></div>
          <div class="bc-read"><div class="bar"><i style="width:<?= $pct ?>%;"></i></div><span><b><?= (int)$b['reads'] ?></b>/<?= (int)$b['delivered'] ?> वाचलं</span>
            <?php if ($b['delivered'] > $b['reads']): ?><a href="?unread=<?= (int)$b['id'] ?>#unread">न वाचलेले →</a><?php endif; ?></div>
        </div>
        <?php if ($showUnread === (int)$b['id']): ?>
          <div class="bc-unread" id="unread"><b>न वाचलेले (<?= count($unreadList) ?>):</b> <?= h(implode(', ', array_map(fn($u) => $u['name'] . ($u['dept'] ? ' (' . $u['dept'] . ')' : ''), $unreadList))) ?></div>
        <?php endif; ?>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<style>
  .bc-aud { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; } .bc-aud label { margin: 0; cursor: pointer; } .bc-aud input { display: none; }
  .bc-aud span { display: block; border: 1px solid var(--line); border-radius: 999px; padding: 5px 12px; font-size: 12.5px; }
  .bc-aud input:checked + span { background: var(--rail); border-color: var(--rail); color: #fff; }
  .bc-count { font-size: 12px; color: var(--text-dim); margin-top: 6px; } .bc-count b { color: var(--rail); }
  .bc-row { display: flex; gap: 12px; align-items: flex-start; padding: 11px 16px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .bc-row b { display: block; font-size: 13.5px; } .bc-row small { font-size: 11.5px; color: var(--text-dim); } .bc-msg { font-size: 12px; margin-top: 3px; color: var(--text-dim); }
  .bc-read { min-width: 150px; text-align: right; font-size: 12px; } .bc-read .bar { height: 6px; background: var(--canvas); border-radius: 4px; overflow: hidden; margin-bottom: 4px; } .bc-read .bar i { display: block; height: 100%; background: #1B8A83; }
  .bc-read a { display: block; color: var(--rail); font-size: 11.5px; }
  .bc-unread { margin: 0 16px 10px; padding: 8px 12px; background: var(--canvas); border-radius: 8px; font-size: 12px; }
</style>
<script>
(function () {
  var form = document.getElementById('bcForm'), countEl = document.getElementById('bcCount'), timer;
  function sync() {
    var aud = form.querySelector('input[name=audience]:checked').value;
    form.querySelectorAll('.bc-val').forEach(function (s) { s.hidden = s.dataset.for !== aud; });
    clearTimeout(timer); timer = setTimeout(function () {
      var fd = new FormData(form); fd.append('preview', '1');
      fetch('broadcast.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        countEl.dataset.n = j.count; countEl.innerHTML = '👥 <b>' + j.count + '</b> जणांना जाईल · ' + j.label;
      }).catch(function () { countEl.textContent = ''; });
    }, 150);
  }
  form.addEventListener('change', function (e) { if (e.target.name === 'audience' || e.target.classList.contains('bc-val')) sync(); });
  sync();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
