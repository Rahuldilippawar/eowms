<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'staff_messages', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'send') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $text = trim($_POST['message_text'] ?? '');
        // Recipient याच Organization चाच आहे का — Cross-Org Message टाळण्यासाठी
        $recipCheckStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
        $recipCheckStmt->execute([$recipientId, $orgId]);
        if ($recipCheckStmt->fetch() && $text && $recipientId !== (int)$user['id']) {
            $pdo->prepare("INSERT INTO staff_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)")->execute([$user['id'], $recipientId, $text]);
            notifyUser($recipientId, $orgId, '💬 नवीन Message', $user['name'] . ': ' . mb_substr($text, 0, 60), 'messages/index.php?with=' . $user['id']);
        }
    }
    header('Location: index.php' . (!empty($_GET['with']) ? '?with=' . (int)$_GET['with'] : ''));
    exit;
}

$pageTitle = 'Messages';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// सगळे Conversations — कोणाशी-कोणाशी बोलणं झालंय (Latest Message सह)
$conversationsStmt = $pdo->prepare("
    SELECT
      CASE WHEN sm.sender_id = ? THEN sm.recipient_id ELSE sm.sender_id END AS other_id,
      MAX(sm.created_at) AS last_at
    FROM staff_messages sm
    WHERE sm.sender_id = ? OR sm.recipient_id = ?
    GROUP BY other_id ORDER BY last_at DESC
");
$conversationsStmt->execute([$user['id'], $user['id'], $user['id']]);
$conversationRows = $conversationsStmt->fetchAll();

$conversations = [];
foreach ($conversationRows as $row) {
    $otherStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE id = ?");
    $otherStmt->execute([$row['other_id']]);
    $other = $otherStmt->fetch();
    if (!$other) continue;
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) c FROM staff_messages WHERE sender_id = ? AND recipient_id = ? AND is_read = 0");
    $unreadStmt->execute([$row['other_id'], $user['id']]);
    $conversations[] = ['other' => $other, 'last_at' => $row['last_at'], 'unread' => $unreadStmt->fetch()['c']];
}

$withUserId = (int)($_GET['with'] ?? 0);
$withUser = null;
$messages = [];
if ($withUserId) {
    $withStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE id = ? AND organization_id = ?");
    $withStmt->execute([$withUserId, $orgId]);
    $withUser = $withStmt->fetch();
    if ($withUser) {
        $msgStmt = $pdo->prepare("
            SELECT * FROM staff_messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
            ORDER BY created_at
        ");
        $msgStmt->execute([$user['id'], $withUserId, $withUserId, $user['id']]);
        $messages = $msgStmt->fetchAll();
        // वाचलेलं म्हणून Mark करतो
        $pdo->prepare("UPDATE staff_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0")->execute([$withUserId, $user['id']]);
    }
}

$allStaffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 AND id != ? ORDER BY name");
$allStaffStmt->execute([$orgId, $user['id']]);
$allStaff = $allStaffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">💬 Messages</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="panel" style="max-height:560px;overflow-y:auto;">
      <div class="panel-head">
        <div class="title">Conversations</div>
        <button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:6px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#newMsgModal">+ नवीन</button>
      </div>
      <?php foreach ($conversations as $c): ?>
        <a href="?with=<?= $c['other']['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;padding:12px 18px;text-decoration:none;color:inherit;border-bottom:1px solid var(--line);<?= $withUserId === (int)$c['other']['id'] ? 'background:var(--rail-soft);' : '' ?>">
          <div>
            <div style="font-size:13px;font-weight:600;"><?= h($c['other']['name']) ?></div>
            <div style="font-size:10.5px;color:var(--text-dim);"><?= h($c['other']['designation'] ?: '') ?></div>
          </div>
          <?php if ($c['unread'] > 0): ?><span style="background:var(--rose);color:#fff;font-size:10px;font-weight:700;border-radius:10px;padding:2px 7px;"><?= $c['unread'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (empty($conversations)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 18px;">अजून कुठलंही Conversation नाही</p><?php endif; ?>
    </div>
  </div>

  <div class="col-md-8">
    <?php if (!$withUser): ?>
      <div class="panel" style="padding:50px;text-align:center;color:var(--text-dim);">डावीकडून एक Conversation निवडा, किंवा "+ नवीन" दाबा.</div>
    <?php else: ?>
      <div class="panel">
        <div class="panel-head"><div class="title"><?= h($withUser['name']) ?></div><div class="sub"><?= h($withUser['designation'] ?: '') ?></div></div>
        <div style="height:400px;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:8px;" id="chatWindow">
          <?php foreach ($messages as $m): $isMine = (int)$m['sender_id'] === (int)$user['id']; ?>
            <div style="align-self:<?= $isMine ? 'flex-end' : 'flex-start' ?>;max-width:70%;">
              <div style="background:<?= $isMine ? 'var(--rail)' : 'var(--canvas)' ?>;color:<?= $isMine ? '#fff' : 'inherit' ?>;padding:9px 14px;border-radius:12px;font-size:13px;"><?= nl2br(h($m['message_text'])) ?></div>
              <div style="font-size:9.5px;color:var(--text-dim);margin-top:2px;text-align:<?= $isMine ? 'right' : 'left' ?>;"><?= date('h:i A', strtotime($m['created_at'])) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($messages)): ?><p style="font-size:12px;color:var(--text-dim);text-align:center;margin-top:30px;">अजून काही Message नाही — पहिलं पाठवा</p><?php endif; ?>
        </div>
        <form method="POST" action="?with=<?= $withUser['id'] ?>" style="padding:14px 20px;border-top:1px solid var(--line);display:flex;gap:8px;">
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="recipient_id" value="<?= $withUser['id'] ?>">
          <input type="text" name="message_text" class="form-control-custom" placeholder="Message टाइप करा..." required autocomplete="off">
          <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:8px;padding:9px 18px;">पाठवा</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="newMsgModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <div class="modal-body" style="padding:22px 24px;">
      <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Conversation सुरू करा</div>
      <select onchange="window.location = '?with=' + this.value" class="form-select-custom">
        <option value="">-- व्यक्ती निवडा --</option>
        <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div></div>
</div>

<script>
var chatWin = document.getElementById('chatWindow');
if (chatWin) { chatWin.scrollTop = chatWin.scrollHeight; }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
