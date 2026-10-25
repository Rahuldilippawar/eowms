<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
function loadTicket($pdo, $id, $orgId) {
    $st = $pdo->prepare("SELECT * FROM work_requests WHERE id = ? AND organization_id = ?");
    $st->execute([$id, $orgId]);
    return $st->fetch();
}
$req = loadTicket($pdo, $id, $orgId);
if (!$req || !ticketCanView($req)) {
    http_response_code(404);
    die('<div style="font-family:sans-serif;padding:40px;">विनंती सापडली नाही किंवा ती बघण्याची परवानगी नाही. <a href="../index.php">' . h(t('s16')) . '</a></div>');
}

$canManage = ticketCanManage($req);
$canAssign = ticketCanManage($req, false);
$isRequester = ticketIsRequester($req);
$link = 'requests/view.php?id=' . $id;
$code = $req['request_code'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash = null;

    if ($action === 'comment') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment !== '') {
            $pdo->prepare("INSERT INTO work_request_comments (request_id, user_id, comment) VALUES (?, ?, ?)")->execute([$id, $user['id'], $comment]);
            // दुसऱ्या बाजूला कळवतो
            $short = $user['name'] . ': ' . mb_substr($comment, 0, 80);
            if ($isRequester) {
                ticketNotifyReceivers($pdo, $req, $orgId, "💬 विनंतीवर टिप्पणी — $code", $short);
            } else {
                $to = [];
                if (!empty($req['requested_by_user_id'])) { $to[] = $req['requested_by_user_id']; }
                if (!empty($req['assigned_to_user_id'])) { $to[] = $req['assigned_to_user_id']; }
                notifyUsers($to, $orgId, "💬 विनंतीवर टिप्पणी — $code", $short, $link, $user['id']);
            }
            $flash = 'टिप्पणी जोडली गेली.';
        }
    } elseif ($action === 'update_status' && $canManage && in_array($req['status'], TICKET_OPEN_STATUSES, true)) {
        $status = $_POST['status'] ?? $req['status'];
        if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) { $status = $req['status']; }
        $remark = trim($_POST['remark'] ?? '') ?: null;
        $cost = ($_POST['cost_incurred'] ?? '') !== '' ? (float)$_POST['cost_incurred'] : null;
        $isDone = $status === 'completed';
        $pdo->prepare("UPDATE work_requests SET status = ?, completed_by = ?, completed_at = ?, completion_remarks = COALESCE(?, completion_remarks), cost_incurred = COALESCE(?, cost_incurred) WHERE id = ?")
            ->execute([$status, $isDone ? $user['id'] : null, $isDone ? date('Y-m-d H:i:s') : null, $remark, $cost, $id]);
        if ($status !== $req['status'] || $remark) {
            ticketLog($pdo, $id, 'status_changed', $req['status'], $status, $remark, $user['id']);
        }
        if ($status !== $req['status'] && !empty($req['requested_by_user_id'])) {
            notifyUser($req['requested_by_user_id'], $orgId, $isDone ? "✅ तुमची विनंती पूर्ण झाली — $code" : 'तुमच्या विनंतीची स्थिती बदलली',
                $code . ' — आता "' . strip_tags(TICKET_STATUS_LABELS[$status]) . '"' . ($isDone ? ' · कृपया Rating देऊन बंद करा' : ''), $link);
        }
        $flash = t('s520');
    } elseif ($action === 'assign' && $canAssign && in_array($req['status'], TICKET_OPEN_STATUSES, true)) {
        $assignTo = (int)($_POST['assigned_to'] ?? 0);
        $allowed = array_map('intval', array_column(ticketAssignableUsers($pdo, $req, $orgId), 'id'));
        if ($assignTo && in_array($assignTo, $allowed, true) && $assignTo !== (int)$req['assigned_to_user_id']) {
            $pdo->prepare("UPDATE work_requests SET assigned_to_user_id = ? WHERE id = ?")->execute([$assignTo, $id]);
            $nameStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $nameStmt->execute([$assignTo]);
            ticketLog($pdo, $id, 'assigned', $req['status'], $req['status'], 'नेमलं: ' . $nameStmt->fetchColumn(), $user['id']);
            notifyUser($assignTo, $orgId, "👤 तुम्हाला विनंती नेमली — $code", mb_substr($req['title'] ?: $req['work_description'], 0, 80), $link);
            $flash = 'विनंती नेमली गेली.';
        }
    } elseif ($action === 'cancel' && $isRequester && $req['status'] === 'pending') {
        $reason = trim($_POST['reason'] ?? '') ?: null;
        $pdo->prepare("UPDATE work_requests SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$id]);
        ticketLog($pdo, $id, 'cancelled', 'pending', 'cancelled', $reason, $user['id']);
        ticketNotifyReceivers($pdo, $req, $orgId, "✖ विनंती रद्द केली — $code", $user['name'] . ($reason ? " — $reason" : ''));
        $flash = 'विनंती रद्द केली.';
    } elseif ($action === 'close' && $isRequester && $req['status'] === 'completed') {
        $rating = (int)($_POST['rating'] ?? 0);
        $feedback = trim($_POST['feedback'] ?? '') ?: null;
        if ($rating >= 1 && $rating <= 5) {
            $pdo->prepare("UPDATE work_requests SET status = 'closed', closed_at = NOW(), rating = ?, feedback = ? WHERE id = ?")->execute([$rating, $feedback, $id]);
            ticketLog($pdo, $id, 'closed', 'completed', 'closed', "Rating: $rating/5" . ($feedback ? " — $feedback" : ''), $user['id']);
            $to = array_filter([$req['completed_by'], $req['assigned_to_user_id']]);
            notifyUsers($to, $orgId, "🔒 विनंती बंद — $code", "Rating: " . str_repeat('★', $rating) . ($feedback ? " — " . mb_substr($feedback, 0, 60) : ''), $link, $user['id']);
            $flash = 'धन्यवाद! विनंती बंद केली.';
        } else {
            $flash = 'कृपया 1 ते 5 पैकी Rating निवडा.';
        }
    } elseif ($action === 'reopen' && $isRequester && ticketCanReopen($req)) {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason !== '') {
            $pdo->prepare("UPDATE work_requests SET status = 'pending', completed_by = NULL, completed_at = NULL, closed_at = NULL, rating = NULL, feedback = NULL,
                reopened_count = reopened_count + 1, due_at = ?, escalation_level = 0, escalated_at = NULL WHERE id = ?")
                ->execute([ticketDueAt($req['priority']), $id]);
            ticketLog($pdo, $id, 'reopened', $req['status'], 'pending', $reason, $user['id']);
            $notifyReq = $req;
            if (!empty($req['completed_by']) && empty($req['assigned_to_user_id'])) { $notifyReq['assigned_to_user_id'] = $req['completed_by']; }
            ticketNotifyReceivers($pdo, $notifyReq, $orgId, "🔁 विनंती पुन्हा उघडली — $code", $user['name'] . ': ' . mb_substr($reason, 0, 80));
            $flash = 'विनंती पुन्हा उघडली.';
        } else {
            $flash = 'पुन्हा उघडण्याचं कारण लिहा.';
        }
    }

    if ($flash) { $_SESSION['flash'] = $flash; }
    header('Location: view.php?id=' . $id);
    exit;
}

$pageTitle = 'विनंती ' . $code;
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$infoStmt = $pdo->prepare("
    SELECT d.name_local AS dept_name, rd.name_local AS requesting_dept_name, au.name AS assigned_to_name, cu.name AS completed_by_name
    FROM work_requests r
    LEFT JOIN departments d ON d.id = r.department_id
    LEFT JOIN departments rd ON rd.id = r.requesting_department_id
    LEFT JOIN users au ON au.id = r.assigned_to_user_id
    LEFT JOIN users cu ON cu.id = r.completed_by
    WHERE r.id = ?");
$infoStmt->execute([$id]);
$info = $infoStmt->fetch();

$comments = [];
try {
    $cStmt = $pdo->prepare("SELECT c.*, u.name AS user_name FROM work_request_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.request_id = ? ORDER BY c.id");
    $cStmt->execute([$id]);
    $comments = $cStmt->fetchAll();
} catch (PDOException $e) {}

$actions = [];
try {
    $aStmt = $pdo->prepare("SELECT a.*, u.name AS user_name FROM work_request_actions a LEFT JOIN users u ON u.id = a.changed_by WHERE a.request_id = ? ORDER BY a.id");
    $aStmt->execute([$id]);
    $actions = $aStmt->fetchAll();
} catch (PDOException $e) {}

if ($canManage) {
    try {
        $pdo->prepare("INSERT INTO work_request_views (request_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE viewed_at = NOW()")->execute([$id, $user['id']]);
    } catch (PDOException $e) {}
}

$assignable = $canAssign ? ticketAssignableUsers($pdo, $req, $orgId) : [];
$sla = ticketSlaBadge($req);
$actionLabels = ['created' => '🆕 तयार केली', 'status_changed' => '🔄 स्थिती बदलली', 'assigned' => '👤 नेमणूक', 'comment' => '💬 टिप्पणी',
    'reopened' => '🔁 पुन्हा उघडली', 'closed' => '🔒 बंद केली', 'cancelled' => '✖ रद्द केली', 'escalated' => '⏰ Escalate'];
$isIt = $req['category'] === 'it';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0"><?= $isIt ? '🖥️' : '📋' ?> <?= h($req['title'] ?: 'विनंती') ?> <span class="font-mono" style="font-size:13px;color:var(--text-dim);"><?= h($code) ?></span></div>
  <div class="d-flex gap-3" style="align-self:center;">
    <?php if ($canManage): ?><a href="index.php<?= $isIt ? '?category=it' : '' ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-inbox me-1"></i>विनंत्यांची रांग</a><?php endif; ?>
    <?php if ($isRequester): ?><a href="my-requests.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-list-check me-1"></i>माझ्या विनंत्या</a><?php endif; ?>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="panel mb-3">
      <div style="padding:20px 22px;">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="status-chip <?= TICKET_STATUS_CHIPS[$req['status']] ?? 'pending' ?>"><?= TICKET_STATUS_LABELS[$req['status']] ?? h($req['status']) ?></span>
          <span class="status-chip done" style="font-size:10.5px;"><?= h(TICKET_CATEGORIES[$req['category']] ?? $req['category']) ?><?= $isIt && $req['sub_category'] ? ' · ' . h(TICKET_IT_SUB_CATEGORIES[$req['sub_category']] ?? $req['sub_category']) : '' ?></span>
          <span class="status-chip <?= in_array($req['priority'], ['high', 'urgent'], true) ? 'overdue' : 'pending' ?>" style="font-size:10.5px;">प्राधान्य: <?= h(TICKET_PRIORITIES[$req['priority']] ?? $req['priority']) ?></span>
          <?= $sla['html'] ?>
        </div>
        <p style="font-size:14px;white-space:pre-wrap;margin-bottom:10px;"><?= h($req['work_description']) ?></p>
        <div style="font-size:12px;color:var(--text-dim);line-height:1.9;">
          <div>👤 विनंती करणारे: <strong><?= h($req['requester_name']) ?></strong><?= $req['requester_contact'] ? ' · ' . h($req['requester_contact']) : '' ?><?= $info['requesting_dept_name'] ? ' · ' . h($info['requesting_dept_name']) : '' ?></div>
          <div>🏢 स्वीकारणारा विभाग: <strong><?= h($info['dept_name'] ?: ($isIt ? 'IT' : '—')) ?></strong></div>
          <?php if ($req['location']): ?><div>📍 <?= h($req['location']) ?></div><?php endif; ?>
          <div>🕒 <?= date('d M Y, h:i A', strtotime($req['created_at'])) ?> · वय: <?= h(ticketAgeLabel($req['created_at'], in_array($req['status'], TICKET_OPEN_STATUSES, true) ? null : ($req['completed_at'] ?: $req['closed_at'] ?: $req['cancelled_at']))) ?>
            <?php if ($req['due_at']): ?> · SLA मुदत: <?= date('d M, h:i A', strtotime($req['due_at'])) ?><?php endif; ?></div>
          <div>🙋 नेमलेले: <strong><?= h($info['assigned_to_name'] ?: '—') ?></strong></div>
          <?php if ($req['completed_at']): ?><div>✅ <?= h($info['completed_by_name'] ?? '') ?> — <?= date('d M Y, h:i A', strtotime($req['completed_at'])) ?></div><?php endif; ?>
          <?php if ($req['completion_remarks']): ?><div>📝 <?= h($req['completion_remarks']) ?></div><?php endif; ?>
          <?php if ($req['cost_incurred']): ?><div style="color:var(--amber);">💰 झालेला खर्च: ₹<?= number_format($req['cost_incurred'], 0) ?></div><?php endif; ?>
          <?php if ($req['rating']): ?><div>⭐ Rating: <span style="color:var(--amber);"><?= str_repeat('★', (int)$req['rating']) . str_repeat('☆', 5 - (int)$req['rating']) ?></span><?= $req['feedback'] ? ' — ' . h($req['feedback']) : '' ?></div><?php endif; ?>
          <?php if ($req['reopened_count'] > 0): ?><div>🔁 <?= (int)$req['reopened_count'] ?> वेळा पुन्हा उघडली</div><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">💬 टिप्पण्या</div><div class="sub">दोन्ही बाजूंना दिसतात</div></div>
      <div style="padding:6px 20px 16px;">
        <?php if (!$comments): ?><div style="font-size:12.5px;color:var(--text-dim);padding:10px 0;">अजून टिप्पणी नाही.</div><?php endif; ?>
        <?php foreach ($comments as $c): ?>
          <div style="padding:9px 0;border-bottom:1px solid var(--line);">
            <div style="font-size:11.5px;color:var(--text-dim);"><strong style="color:var(--ink);"><?= h($c['user_name'] ?? '—') ?></strong> · <?= date('d M, h:i A', strtotime($c['created_at'])) ?></div>
            <div style="font-size:13px;white-space:pre-wrap;"><?= h($c['comment']) ?></div>
          </div>
        <?php endforeach; ?>
        <form method="POST" class="mt-2">
          <input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $id ?>">
          <textarea name="comment" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी लिहा…" required></textarea>
          <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:6px 16px;">पाठवा</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if ($canManage && in_array($req['status'], TICKET_OPEN_STATUSES, true)): ?>
      <div class="panel mb-3"><div style="padding:18px 20px;">
        <div style="font-weight:700;margin-bottom:10px;">🔄 स्थिती बदला</div>
        <form method="POST">
          <input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= $id ?>">
          <select name="status" class="form-select-custom mb-2">
            <?php foreach (['pending', 'in_progress', 'completed'] as $s): ?><option value="<?= $s ?>" <?= $req['status'] === $s ? 'selected' : '' ?>><?= TICKET_STATUS_LABELS[$s] ?></option><?php endforeach; ?>
          </select>
          <input type="number" step="0.01" min="0" name="cost_incurred" class="form-control-custom mb-2" placeholder="झालेला खर्च (₹, असल्यास)">
          <input type="text" name="remark" class="form-control-custom mb-2" placeholder="टिप्पणी (ऐच्छिक — requester ला दिसेल)">
          <button type="submit" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px;">जतन करा</button>
        </form>
      </div></div>
    <?php endif; ?>

    <?php if ($canAssign && in_array($req['status'], TICKET_OPEN_STATUSES, true)): ?>
      <div class="panel mb-3"><div style="padding:18px 20px;">
        <div style="font-weight:700;margin-bottom:10px;">👤 <?= $req['assigned_to_user_id'] ? 'पुन्हा नेमा' : 'नेमा' ?></div>
        <?php if (!$assignable): ?>
          <div style="font-size:12px;color:var(--text-dim);">या विभागात नेमण्यासाठी कोणीही सक्रिय कर्मचारी नाही.</div>
        <?php else: ?>
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= $id ?>">
          <select name="assigned_to" class="form-select-custom" required>
            <option value="">-- व्यक्ती निवडा --</option>
            <?php foreach ($assignable as $a): ?><option value="<?= $a['id'] ?>" <?= (int)$req['assigned_to_user_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:6px 14px;">नेमा</button>
        </form>
        <?php endif; ?>
      </div></div>
    <?php endif; ?>

    <?php if ($isRequester && $req['status'] === 'completed'): ?>
      <div class="panel mb-3" style="border-left:3px solid var(--rail);"><div style="padding:18px 20px;">
        <div style="font-weight:700;margin-bottom:10px;">⭐ काम झालं? Rating देऊन बंद करा</div>
        <form method="POST">
          <input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="d-flex gap-3 mb-2" style="font-size:13px;">
            <?php for ($i = 1; $i <= 5; $i++): ?><label><input type="radio" name="rating" value="<?= $i ?>" required <?= $i === 5 ? 'checked' : '' ?>> <?= $i ?>★</label><?php endfor; ?>
          </div>
          <textarea name="feedback" class="form-control-custom mb-2" rows="2" placeholder="अभिप्राय (ऐच्छिक)"></textarea>
          <button type="submit" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px;">🔒 बंद करा</button>
        </form>
      </div></div>
    <?php endif; ?>

    <?php if ($isRequester && ticketCanReopen($req)): ?>
      <div class="panel mb-3"><div style="padding:18px 20px;">
        <div style="font-weight:700;margin-bottom:4px;">🔁 समस्या अजून आहे? पुन्हा उघडा</div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:8px;">पूर्ण झाल्यापासून <?= TICKET_REOPEN_DAYS ?> दिवसांच्या आत</div>
        <form method="POST">
          <input type="hidden" name="action" value="reopen"><input type="hidden" name="id" value="<?= $id ?>">
          <textarea name="reason" class="form-control-custom mb-2" rows="2" placeholder="कारण (आवश्यक)" required></textarea>
          <button type="submit" class="btn btn-sm w-100" style="border:1px solid var(--amber);color:var(--amber);border-radius:7px;padding:8px;">पुन्हा उघडा</button>
        </form>
      </div></div>
    <?php endif; ?>

    <?php if ($isRequester && $req['status'] === 'pending'): ?>
      <div class="panel mb-3"><div style="padding:18px 20px;">
        <form method="POST" onsubmit="return confirm('ही विनंती रद्द करायची?');">
          <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $id ?>">
          <input type="text" name="reason" class="form-control-custom mb-2" placeholder="रद्द करण्याचं कारण (ऐच्छिक)">
          <button type="submit" class="btn btn-sm w-100" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;padding:8px;">✖ विनंती रद्द करा</button>
        </form>
      </div></div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-head"><div class="title">🕘 इतिहास</div></div>
      <div style="padding:4px 20px 14px;">
        <?php if (!$actions): ?><div style="font-size:12px;color:var(--text-dim);padding:8px 0;">—</div><?php endif; ?>
        <?php foreach ($actions as $a): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--line);font-size:12px;">
            <strong><?= h($actionLabels[$a['action_type']] ?? $a['action_type']) ?></strong>
            <?php if ($a['action_type'] === 'status_changed' && $a['old_status'] !== $a['new_status']): ?>
              — <?= h(strip_tags(TICKET_STATUS_LABELS[$a['old_status']] ?? $a['old_status'])) ?> → <?= h(strip_tags(TICKET_STATUS_LABELS[$a['new_status']] ?? $a['new_status'])) ?>
            <?php endif; ?>
            <div style="color:var(--text-dim);font-size:11px;"><?= h($a['user_name'] ?? 'प्रणाली') ?> · <?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></div>
            <?php if ($a['remark']): ?><div style="font-size:12px;">📝 <?= h($a['remark']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
