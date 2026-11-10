<?php
// 💬 Messages — WhatsApp-सारखं: शोध, शेवटचा संदेश, न वाचलेले, वाचल्याची खूण (✓✓), तारखेनुसार गट,
// Reload शिवाय पाठवणे व नवीन संदेश आपोआप, झटपट उत्तरं,
// HOD / Management साठी विभागाला एकाच वेळी संदेश. (संदेश हटवणं नाही — नोंदी हटवणं फक्त Super Admin, records_lock)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'staff_messages', '../');
$user = currentUser();
$orgId = activeOrgId();
$me = (int)$user['id'];
const MSG_MAX_LEN = 2000;

$canBroadcast = isManagementOrAbove() || hasCategory('hod');
$broadcastDepts = [];
if ($canBroadcast) {
    $vis = isManagementOrAbove() ? null : getVisibleDepartmentIds();
    $dq = $pdo->prepare("SELECT d.id, COALESCE(d.name_local, d.name) n, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1 AND u.id <> ?) c
        FROM departments d WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY n");
    $dq->execute([$me, $orgId]);
    foreach ($dq->fetchAll() as $d) if ((int)$d['c'] && ($vis === null || in_array((int)$d['id'], (array)$vis, true))) $broadcastDepts[(int)$d['id']] = $d;
}

$jsonOut = function ($data) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
$msgRow = fn($m) => ['id' => (int)$m['id'], 'mine' => (int)$m['sender_id'] === $me, 'text' => $m['message_text'], 'at' => $m['created_at'], 'read' => (int)$m['is_read'] === 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ajax = !empty($_POST['ajax']);
    $text = trim(mb_substr((string)($_POST['message_text'] ?? ''), 0, MSG_MAX_LEN));
    if ($action === 'send') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        // Recipient याच संस्थेचा व सक्रिय — Cross-Org Message टाळण्यासाठी
        $rc = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
        $rc->execute([$recipientId, $orgId]);
        if ($rc->fetch() && $text !== '' && $recipientId !== $me) {
            $pdo->prepare("INSERT INTO staff_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)")->execute([$me, $recipientId, $text]);
            $newId = (int)$pdo->lastInsertId();
            notifyUser($recipientId, $orgId, '💬 नवीन Message', $user['name'] . ': ' . mb_substr($text, 0, 60), 'messages/index.php?with=' . $me);
            if ($ajax) {
                $m = $pdo->prepare("SELECT * FROM staff_messages WHERE id = ?"); $m->execute([$newId]);
                $jsonOut(['ok' => true, 'msg' => $msgRow($m->fetch())]);
            }
        } elseif ($ajax) { $jsonOut(['ok' => false, 'error' => 'संदेश पाठवता आला नाही']); }
        header('Location: index.php?with=' . $recipientId);
        exit;
    }
    if ($action === 'broadcast' && $canBroadcast) {
        $deptId = (int)($_POST['department_id'] ?? 0);
        if (!isset($broadcastDepts[$deptId]) || $text === '') {
            $_SESSION['flash'] = '⚠️ विभाग व संदेश दोन्ही आवश्यक आहेत.';
        } else {
            $uq = $pdo->prepare("SELECT id FROM users WHERE department_id = ? AND organization_id = ? AND is_active = 1 AND id <> ? LIMIT 300");
            $uq->execute([$deptId, $orgId, $me]);
            $ids = array_map('intval', $uq->fetchAll(PDO::FETCH_COLUMN));
            $ins = $pdo->prepare("INSERT INTO staff_messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)");
            $pdo->beginTransaction();
            foreach ($ids as $rid) $ins->execute([$me, $rid, $text]);
            $pdo->commit();
            if (function_exists('notifyUsers')) notifyUsers($ids, $orgId, '📢 ' . $user['name'] . ' — विभागाला संदेश', mb_substr($text, 0, 80), 'messages/index.php?with=' . $me, $me);
            logActivity('विभागाला संदेश पाठवला', 'staff_messages', null, $broadcastDepts[$deptId]['n'] . ' — ' . count($ids) . ' जण');
            $_SESSION['flash'] = '📢 ' . $broadcastDepts[$deptId]['n'] . ' — ' . count($ids) . ' जणांना संदेश पाठवला.';
        }
        header('Location: index.php');
        exit;
    }
    header('Location: index.php');
    exit;
}

$withUserId = (int)($_GET['with'] ?? 0);

// 🔄 नवीन संदेश (Polling) — त्या व्यक्तीकडून आलेले नवीन + माझे वाचले गेले का
if (!empty($_GET['poll'])) {
    $after = (int)($_GET['after'] ?? 0);
    $out = ['msgs' => [], 'read' => [], 'unread_total' => 0];
    if ($withUserId) {
        $q = $pdo->prepare("SELECT * FROM staff_messages WHERE id > ? AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) ORDER BY id LIMIT 100");
        $q->execute([$after, $withUserId, $me, $me, $withUserId]);
        $out['msgs'] = array_map($msgRow, $q->fetchAll());
        $pdo->prepare("UPDATE staff_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0")->execute([$withUserId, $me]);
        $r = $pdo->prepare("SELECT id FROM staff_messages WHERE sender_id = ? AND recipient_id = ? AND is_read = 1 ORDER BY id DESC LIMIT 60");
        $r->execute([$me, $withUserId]);
        $out['read'] = array_map('intval', $r->fetchAll(PDO::FETCH_COLUMN));
    }
    $u = $pdo->prepare("SELECT COUNT(*) FROM staff_messages WHERE recipient_id = ? AND is_read = 0");
    $u->execute([$me]);
    $out['unread_total'] = (int)$u->fetchColumn();
    $jsonOut($out);
}

// Conversations — एकाच Query मध्ये: शेवटचा संदेश, वेळ, न वाचलेले
$cq = $pdo->prepare("SELECT x.other_id, x.last_id, x.unread, m.message_text last_text, m.sender_id last_sender, m.is_read last_read, m.created_at last_at,
        u.name, u.designation, u.photo_path, COALESCE(d.name_local, d.name) dept
    FROM (SELECT CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END other_id, MAX(id) last_id,
                 SUM(recipient_id = ? AND is_read = 0) unread
          FROM staff_messages WHERE sender_id = ? OR recipient_id = ? GROUP BY other_id) x
    JOIN staff_messages m ON m.id = x.last_id JOIN users u ON u.id = x.other_id AND u.organization_id = ?
    LEFT JOIN departments d ON d.id = u.department_id ORDER BY x.last_id DESC");
$cq->execute([$me, $me, $me, $me, $orgId]);
$conversations = $cq->fetchAll();
$unreadTotal = array_sum(array_map(fn($c) => (int)$c['unread'], $conversations));

$withUser = null; $messages = [];
if ($withUserId) {
    $w = $pdo->prepare("SELECT u.id, u.name, u.designation, u.photo_path, u.mobile, u.whatsapp_number, u.last_login_at, COALESCE(d.name_local, d.name) dept
        FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.organization_id = ?");
    $w->execute([$withUserId, $orgId]);
    $withUser = $w->fetch();
    if ($withUser) {
        $mq = $pdo->prepare("SELECT * FROM (SELECT * FROM staff_messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) ORDER BY id DESC LIMIT 300) t ORDER BY id");
        $mq->execute([$me, $withUserId, $withUserId, $me]);
        $messages = $mq->fetchAll();
        $pdo->prepare("UPDATE staff_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND is_read = 0")->execute([$withUserId, $me]);
    }
}

// नवीन संवादासाठी — सगळे कर्मचारी (माझ्या विभागाचे आधी)
$sq = $pdo->prepare("SELECT u.id, u.name, u.designation, u.photo_path, u.department_id, COALESCE(d.name_local, d.name) dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 AND u.id <> ? ORDER BY (u.department_id <=> ?) DESC, u.name");
$sq->execute([$orgId, $me, $user['department_id'] ?? null]);
$allStaff = $sq->fetchAll();

$initials = function ($name) { $p = preg_split('/\s+/u', trim((string)$name)); return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : '')); };
$avatar = function ($u, $size = 38) use ($initials) {
    $hue = crc32((string)($u['name'] ?? '')) % 360;
    $span = '<span class="ms-av" style="width:' . $size . 'px;height:' . $size . 'px;background:hsl(' . $hue . ',55%,45%);">' . h($initials($u['name'] ?? '?')) . '</span>';
    if (empty($u['photo_path'])) return $span;
    // फोटो सापडला नाही तर आद्याक्षरं
    return '<span class="ms-avw" style="width:' . $size . 'px;height:' . $size . 'px;">' . $span . '<img class="ms-av" style="width:' . $size . 'px;height:' . $size . 'px;" src="../' . h(ltrim($u['photo_path'], '/')) . '" alt="" loading="lazy" onerror="this.remove()"></span>';
};
$when = function ($ts) {
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('h:i A', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'काल';
    return date('d M', $t);
};
$linkify = fn($s) => nl2br(preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', h($s)));
$dayLabel = function ($d) { if ($d === date('Y-m-d')) return 'आज'; if ($d === date('Y-m-d', strtotime('-1 day'))) return 'काल'; return date('d M Y', strtotime($d)); };

$pageTitle = 'Messages';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">💬 Messages<?= $unreadTotal ? ' <span class="ms-badge">' . $unreadTotal . ' न वाचलेले</span>' : '' ?></div>
  <div class="d-flex gap-2">
    <?php if ($broadcastDepts): ?><button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;padding:8px 13px;" data-bs-toggle="modal" data-bs-target="#bcModal">📢 विभागाला संदेश</button><?php endif; ?>
    <button type="button" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:9px;padding:8px 14px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#newMsgModal">＋ नवीन संवाद</button>
  </div>
</div>

<div class="ms-wrap<?= $withUser ? ' open' : '' ?>">
  <div class="panel ms-list">
    <div class="ms-search"><input type="search" id="msQ" class="form-control-custom" placeholder="🔍 नाव / संदेश शोधा…" autocomplete="off"></div>
    <div id="msConv">
      <?php foreach ($conversations as $c): $last = ($c['last_sender'] == $me ? ((int)$c['last_read'] ? '✓✓ ' : '✓ ') : '') . $c['last_text']; ?>
        <a href="?with=<?= (int)$c['other_id'] ?>" class="ms-c<?= $withUserId === (int)$c['other_id'] ? ' on' : '' ?><?= (int)$c['unread'] ? ' unread' : '' ?>" data-q="<?= h(mb_strtolower($c['name'] . ' ' . $c['dept'] . ' ' . $c['last_text'])) ?>">
          <?= $avatar($c) ?>
          <div class="ms-ct"><div class="ms-cn"><b><?= h($c['name']) ?></b><small><?= h($when($c['last_at'])) ?></small></div>
            <div class="ms-cl"><span><?= h(mb_strimwidth(str_replace("\n", ' ', $last), 0, 70, '…')) ?></span><?= (int)$c['unread'] ? '<i>' . (int)$c['unread'] . '</i>' : '' ?></div></div>
        </a>
      <?php endforeach; ?>
    </div>
    <div id="msPeople" class="d-none"><div class="ms-sec">इतर कर्मचारी — नवीन संवाद</div>
      <?php $inConv = array_flip(array_map(fn($c) => (int)$c['other_id'], $conversations)); foreach ($allStaff as $s): if (isset($inConv[(int)$s['id']])) continue; ?>
        <a href="?with=<?= $s['id'] ?>" class="ms-c d-none" data-q="<?= h(mb_strtolower($s['name'] . ' ' . $s['dept'] . ' ' . $s['designation'])) ?>"><?= $avatar($s) ?><div class="ms-ct"><b><?= h($s['name']) ?></b><div class="ms-cl"><span><?= h(trim(($s['designation'] ?: '') . ($s['dept'] ? ' · ' . $s['dept'] : ''), ' ·')) ?></span></div></div></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$conversations): ?>
      <div class="ms-empty"><div style="font-size:30px;">💬</div><b>अजून कुठलंही संभाषण नाही</b><p>वर शोधा किंवा खालच्या सहकाऱ्यांपैकी कोणालाही संदेश पाठवा</p>
        <div class="ms-sugg"><?php foreach (array_slice($allStaff, 0, 8) as $s): ?><a href="?with=<?= $s['id'] ?>"><?= $avatar($s, 34) ?><span><?= h(explode(' ', trim($s['name']))[0]) ?></span></a><?php endforeach; ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="ms-chat">
    <?php if (!$withUser): ?>
      <div class="panel ms-none"><div style="font-size:40px;">👈</div><b>संभाषण निवडा</b><p>डावीकडून एक संभाषण निवडा, किंवा "＋ नवीन संवाद" दाबा.</p></div>
    <?php else: ?>
      <div class="panel ms-box">
        <div class="ms-head">
          <a href="index.php" class="ms-back" title="मागे">‹</a>
          <?= $avatar($withUser, 40) ?>
          <div class="ms-hn"><b><?= h($withUser['name']) ?></b><small><?= h(trim(($withUser['designation'] ?: '') . ($withUser['dept'] ? ' · ' . $withUser['dept'] : ''), ' ·')) ?><?= $withUser['last_login_at'] ? ' · शेवटचं Login ' . h($when($withUser['last_login_at'])) : '' ?></small></div>
          <div class="ms-ha">
            <button type="button" class="ms-ib" id="msFindBtn" title="या संभाषणात शोधा">🔍</button>
            <?php if ($withUser['mobile']): ?><a class="ms-ib" href="tel:<?= h($withUser['mobile']) ?>" title="Call">📞</a><?php endif; ?>
            <?php $wa = preg_replace('/\D/', '', (string)($withUser['whatsapp_number'] ?: $withUser['mobile'])); if (strlen($wa) >= 10): ?><a class="ms-ib" href="https://wa.me/<?= strlen($wa) === 10 ? '91' . $wa : $wa ?>" target="_blank" rel="noopener" title="WhatsApp">🟢</a><?php endif; ?>
          </div>
        </div>
        <div class="ms-find d-none"><input type="search" id="msFind" class="form-control-custom" placeholder="या संभाषणात शोधा…"><span id="msFindN"></span></div>
        <div class="ms-win" id="chatWindow">
          <?php $lastDay = null; foreach ($messages as $m): $isMine = (int)$m['sender_id'] === $me; $day = date('Y-m-d', strtotime($m['created_at']));
            if ($day !== $lastDay): $lastDay = $day; ?><div class="ms-day"><span><?= h($dayLabel($day)) ?></span></div><?php endif; ?>
            <div class="ms-m<?= $isMine ? ' me' : '' ?>" data-id="<?= $m['id'] ?>" data-at="<?= strtotime($m['created_at']) ?>">
              <div class="ms-bub"><?= $linkify($m['message_text']) ?><span class="ms-t"><?= date('h:i A', strtotime($m['created_at'])) ?><?= $isMine ? ' <em class="tick' . ((int)$m['is_read'] ? ' read' : '') . '">' . ((int)$m['is_read'] ? '✓✓' : '✓') . '</em>' : '' ?></span></div>
            </div>
          <?php endforeach; ?>
          <div class="ms-first<?= $messages ? ' d-none' : '' ?>" id="msFirst">👋 <?= h(explode(' ', trim($withUser['name']))[0]) ?> यांना पहिला संदेश पाठवा</div>
        </div>
        <div class="ms-quick" id="msQuick">
          <?php foreach (['🙏 नमस्कार', 'ठीक आहे 👍', 'करतो / करते', 'काम पूर्ण झालं ✅', 'थोड्या वेळाने बोलू', 'धन्यवाद 🙏'] as $qr): ?><button type="button"><?= h($qr) ?></button><?php endforeach; ?>
        </div>
        <form method="POST" action="index.php?with=<?= $withUser['id'] ?>" class="ms-send" id="msForm">
          <input type="hidden" name="action" value="send"><input type="hidden" name="recipient_id" value="<?= $withUser['id'] ?>">
          <textarea name="message_text" id="msText" class="form-control-custom" rows="1" maxlength="<?= MSG_MAX_LEN ?>" placeholder="Message टाइप करा…" title="Enter = पाठवा, Shift+Enter = नवी ओळ" required></textarea>
          <button type="submit" class="ms-go" title="पाठवा">➤</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="newMsgModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content" style="border-radius:14px;">
    <div class="modal-body" style="padding:20px 22px;">
      <div style="font-weight:700;font-size:15px;margin-bottom:10px;">नवीन संवाद सुरू करा</div>
      <input type="search" id="nmQ" class="form-control-custom mb-2" placeholder="🔍 नाव / विभाग / पद शोधा…" autocomplete="off">
      <div id="nmList" style="max-height:55vh;overflow:auto;">
        <?php $lastDept = false; foreach ($allStaff as $s): ?>
          <a href="?with=<?= $s['id'] ?>" class="ms-c" data-q="<?= h(mb_strtolower($s['name'] . ' ' . $s['dept'] . ' ' . $s['designation'])) ?>"><?= $avatar($s, 34) ?><div class="ms-ct"><b><?= h($s['name']) ?></b><div class="ms-cl"><span><?= h(trim(($s['designation'] ?: '') . ($s['dept'] ? ' · ' . $s['dept'] : ''), ' ·')) ?></span></div></div></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div></div>
</div>

<?php if ($broadcastDepts): ?>
<div class="modal fade" id="bcModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="broadcast">
      <div class="modal-body" style="padding:20px 22px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:4px;">📢 विभागाला संदेश</div>
        <p style="font-size:12px;color:var(--text-dim);">विभागातल्या प्रत्येकाला वेगळा संदेश जातो — उत्तरं तुमच्या संभाषणात येतील.</p>
        <label class="form-label-custom">विभाग</label>
        <select name="department_id" class="form-select-custom mb-2" required><option value="">-- निवडा --</option><?php foreach ($broadcastDepts as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['n']) ?> (<?= (int)$d['c'] ?> जण)</option><?php endforeach; ?></select>
        <label class="form-label-custom">संदेश</label>
        <textarea name="message_text" class="form-control-custom" rows="4" maxlength="<?= MSG_MAX_LEN ?>" required></textarea>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;" onclick="return confirm('या विभागातल्या सगळ्यांना संदेश पाठवायचा?')">📢 पाठवा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<style>
.ms-badge{font-size:12px;background:var(--rose);color:#fff;border-radius:20px;padding:2px 9px;vertical-align:middle;font-family:inherit;}
.ms-wrap{display:grid;grid-template-columns:340px minmax(0,1fr);gap:14px;align-items:start;}
.ms-chat,.ms-list{min-width:0;}
.ms-list{max-height:calc(100vh - 210px);min-height:420px;overflow-y:auto;padding:0;}
.ms-search{position:sticky;top:0;background:var(--panel,#fff);padding:10px;border-bottom:1px solid var(--line);z-index:2;}
.ms-sec{font-size:11px;font-weight:700;color:var(--text-dim);padding:10px 14px 4px;text-transform:uppercase;}
.ms-c{display:flex;gap:10px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;}
.ms-c:hover{background:color-mix(in srgb,var(--rail) 5%,transparent);} .ms-c.on{background:color-mix(in srgb,var(--rail) 12%,transparent);}
.ms-avw{position:relative;flex:none;display:inline-flex;} .ms-avw img{position:absolute;inset:0;}
.ms-av{flex:none;border-radius:50%;object-fit:cover;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;}
.ms-ct{flex:1;min-width:0;} .ms-ct b{font-size:13.5px;}
.ms-cn{display:flex;justify-content:space-between;gap:6px;} .ms-cn small{font-size:10.5px;color:var(--text-dim);white-space:nowrap;}
.ms-cl{display:flex;justify-content:space-between;gap:6px;align-items:center;} .ms-cl span{font-size:12px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ms-cl i{font-style:normal;background:#16A34A;color:#fff;font-size:10.5px;font-weight:700;border-radius:10px;padding:1px 7px;}
.ms-c.unread b,.ms-c.unread .ms-cl span{color:var(--text);font-weight:700;} .ms-c.unread .ms-cn small{color:#16A34A;font-weight:700;}
.ms-empty,.ms-none{padding:34px 22px;text-align:center;} .ms-empty b,.ms-none b{display:block;margin:4px 0;} .ms-empty p,.ms-none p{font-size:12.5px;color:var(--text-dim);}
.ms-sugg{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;} .ms-sugg a{display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;color:inherit;font-size:11px;width:56px;}
.ms-box{display:flex;flex-direction:column;height:calc(100vh - 210px);min-height:460px;overflow:hidden;padding:0;}
.ms-head{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--line);}
.ms-hn{flex:1;min-width:0;} .ms-hn b{display:block;font-size:14.5px;} .ms-hn small{font-size:11px;color:var(--text-dim);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ms-back{display:none;font-size:26px;line-height:1;text-decoration:none;color:var(--text);padding:0 4px;}
.ms-ha{display:flex;gap:4px;} .ms-ib{width:34px;height:34px;border-radius:50%;border:1px solid var(--line);background:var(--panel,#fff);display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:14px;cursor:pointer;}
.ms-find{display:flex;gap:8px;align-items:center;padding:8px 14px;border-bottom:1px solid var(--line);} .ms-find span{font-size:11.5px;color:var(--text-dim);white-space:nowrap;}
.ms-win{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:5px;background:color-mix(in srgb,var(--rail) 3%,var(--canvas,#F7F7F5));}
.ms-day{text-align:center;margin:8px 0;} .ms-day span{background:var(--panel,#fff);border:1px solid var(--line);border-radius:10px;padding:2px 10px;font-size:11px;color:var(--text-dim);}
.ms-m{display:flex;} .ms-m.me{justify-content:flex-end;}
.ms-bub{position:relative;max-width:75%;background:var(--panel,#fff);border:1px solid var(--line);padding:7px 11px 6px;border-radius:12px 12px 12px 3px;font-size:13.5px;line-height:1.5;word-wrap:break-word;overflow-wrap:anywhere;}
.ms-m.me .ms-bub{background:#DCF8C6;border-color:#C5ECAE;border-radius:12px 12px 3px 12px;color:#14281A;}
.ms-bub a{color:#1D4ED8;}
.ms-t{display:block;text-align:right;font-size:10px;color:#6B7280;margin-top:2px;white-space:nowrap;} .tick{font-style:normal;color:#9CA3AF;} .tick.read{color:#0EA5E9;}
.ms-bub mark{background:#FDE68A;padding:0;}
.ms-first{margin:auto;text-align:center;color:var(--text-dim);font-size:13px;}
.ms-quick{display:flex;gap:6px;overflow-x:auto;padding:8px 12px 0;} .ms-quick button{white-space:nowrap;border:1px solid var(--line);background:var(--panel,#fff);border-radius:16px;padding:4px 11px;font-size:12px;}
.ms-send{display:flex;gap:8px;align-items:flex-end;padding:10px 12px;} .ms-send textarea{flex:1;min-width:0;resize:none;height:42px;min-height:42px;max-height:120px;border-radius:21px;padding:10px 14px;line-height:1.35;}
.content .ms-send textarea.form-control-custom{min-height:42px;}
.ms-go{flex:none;width:42px;height:42px;border-radius:50%;border:0;background:var(--rail);color:#fff;font-size:17px;}
.ms-go:disabled{opacity:.5;}
@media (max-width:768px){
  .ms-wrap{grid-template-columns:1fr;}
  .ms-wrap.open .ms-list{display:none;} .ms-wrap:not(.open) .ms-chat{display:none;}
  .ms-back{display:block;} .ms-box{height:calc(100dvh - 250px);min-height:380px;} .ms-list{max-height:none;}
  .ms-ha .ms-ib{width:30px;height:30px;font-size:12px;} .ms-head{padding:8px 10px;gap:8px;}
  .ms-bub{max-width:85%;}
}
</style>
<script>
(function () {
  var lower = function (s) { return (s || '').toLowerCase(); };
  // 🔍 संभाषण + लोक शोध
  var q = document.getElementById('msQ');
  if (q) q.addEventListener('input', function () {
    var s = lower(q.value.trim()), anyPeople = false;
    document.querySelectorAll('#msConv .ms-c').forEach(function (a) { a.classList.toggle('d-none', !!s && a.dataset.q.indexOf(s) === -1); });
    document.querySelectorAll('#msPeople .ms-c').forEach(function (a) { var ok = !!s && a.dataset.q.indexOf(s) !== -1; a.classList.toggle('d-none', !ok); anyPeople = anyPeople || ok; });
    document.getElementById('msPeople').classList.toggle('d-none', !anyPeople);
  });
  var nq = document.getElementById('nmQ');
  if (nq) nq.addEventListener('input', function () { var s = lower(nq.value.trim()); document.querySelectorAll('#nmList .ms-c').forEach(function (a) { a.style.display = !s || a.dataset.q.indexOf(s) !== -1 ? '' : 'none'; }); });
  var nm = document.getElementById('newMsgModal'); if (nm) nm.addEventListener('shown.bs.modal', function () { nq.focus(); });

  var win = document.getElementById('chatWindow');
  if (!win) return;
  var withId = <?= (int)($withUser['id'] ?? 0) ?>, form = document.getElementById('msForm'), ta = document.getElementById('msText');
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  var linkify = function (s) { return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>'); };
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var tfmt = function (d) { var h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return pad(h) + ':' + pad(d.getMinutes()) + ' ' + ap; };
  var lastId = function () { var m = win.querySelectorAll('.ms-m'); return m.length ? +m[m.length - 1].dataset.id : 0; };
  var atBottom = function () { return win.scrollHeight - win.scrollTop - win.clientHeight < 80; };
  var down = function () { win.scrollTop = win.scrollHeight; };
  function append(m) {
    if (win.querySelector('.ms-m[data-id="' + m.id + '"]')) return;
    var d = new Date(m.at.replace(' ', 'T')), days = win.querySelectorAll('.ms-day span');
    if (!days.length || days[days.length - 1].textContent !== 'आज') { var dd = document.createElement('div'); dd.className = 'ms-day'; dd.innerHTML = '<span>आज</span>'; win.insertBefore(dd, document.getElementById('msFirst')); }
    var el = document.createElement('div'); el.className = 'ms-m' + (m.mine ? ' me' : ''); el.dataset.id = m.id; el.dataset.at = Math.floor(d.getTime() / 1000);
    el.innerHTML = '<div class="ms-bub">' + linkify(m.text) + '<span class="ms-t">' + tfmt(d) + (m.mine ? ' <em class="tick' + (m.read ? ' read' : '') + '">' + (m.read ? '✓✓' : '✓') + '</em>' : '') + '</span></div>';
    win.insertBefore(el, document.getElementById('msFirst'));
    document.getElementById('msFirst').classList.add('d-none');
  }
  down();
  // ✉️ Reload शिवाय पाठवा; Enter = पाठवा
  var grow = function () { ta.style.height = '42px'; ta.style.height = Math.min(120, Math.max(42, ta.scrollHeight)) + 'px'; };
  ta.addEventListener('input', grow);
  ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.innerWidth > 768) { e.preventDefault(); form.requestSubmit(); } });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = ta.value.trim(); if (!text) return;
    var btn = form.querySelector('.ms-go'); btn.disabled = true;
    var fd = new FormData(form); fd.append('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
      btn.disabled = false;
      if (j.ok) { append(j.msg); ta.value = ''; grow(); down(); ta.focus(); } else alert(j.error || 'संदेश पाठवता आला नाही');
    }).catch(function () { btn.disabled = false; form.submit(); });
  });
  document.querySelectorAll('#msQuick button').forEach(function (b) { b.onclick = function () { ta.value = (ta.value ? ta.value + ' ' : '') + b.textContent; grow(); ta.focus(); }; });
  // 🔄 दर 8 सेकंदांनी नवीन संदेश व ✓✓
  function poll() {
    if (document.hidden) return;
    fetch('index.php?poll=1&with=' + withId + '&after=' + lastId()).then(function (r) { return r.json(); }).then(function (j) {
      var stick = atBottom();
      (j.msgs || []).forEach(append);
      (j.read || []).forEach(function (id) { var t = win.querySelector('.ms-m[data-id="' + id + '"] .tick'); if (t) { t.textContent = '✓✓'; t.classList.add('read'); } });
      if (j.msgs && j.msgs.length && stick) down();
    }).catch(function () {});
  }
  setInterval(poll, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  // 🔍 या संभाषणात शोध
  var fb = document.getElementById('msFindBtn'), fi = document.getElementById('msFind');
  fb.onclick = function () { var box = document.querySelector('.ms-find'); box.classList.toggle('d-none'); if (!box.classList.contains('d-none')) fi.focus(); };
  fi.addEventListener('input', function () {
    var s = lower(fi.value.trim()), n = 0, first = null;
    win.querySelectorAll('.ms-m').forEach(function (m) {
      var hit = !!s && lower(m.querySelector('.ms-bub').textContent).indexOf(s) !== -1;
      m.style.opacity = s && !hit ? .35 : 1; if (hit) { n++; first = first || m; }
    });
    document.getElementById('msFindN').textContent = s ? n + ' सापडले' : '';
    if (first) first.scrollIntoView({ block: 'center' });
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
