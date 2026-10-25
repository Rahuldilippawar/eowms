<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'grievances', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_grievances') || isManagementOrAbove();

const GRIEVANCE_SLA_DAYS = 7;
$allowedStatuses = ['open', 'in_progress', 'resolved', 'closed'];

/**
 * गुप्त (Anonymous) तक्रार — तक्रारदाराचा User-ID Database मध्ये ठेवत नाही;
 * त्याऐवजी HMAC (गुप्त किल्ली config/app-secret.php मध्ये — DB/Backup मध्ये नाही),
 * ज्यावरून फक्त तो स्वतःच आपली तक्रार बघू शकतो.
 */
function grievanceSecret() {
    static $secret = null;
    if ($secret !== null) { return $secret; }
    $file = __DIR__ . '/../config/app-secret.php';
    if (is_readable($file)) {
        $s = include $file;
        if (is_string($s) && strlen($s) >= 32) { return $secret = $s; }
    }
    $new = bin2hex(random_bytes(32));
    $fh = @fopen($file, 'x'); // एकाच वेळी दोन Requests — एकच File तयार होते
    if ($fh) {
        fwrite($fh, "<?php\n// आपोआप तयार झालेली गुप्त किल्ली (गुप्त तक्रारींची ओळख) — बदलू/Delete करू नका, Git मध्ये टाकू नका.\nreturn '$new';\n");
        fclose($fh);
        return $secret = $new;
    }
    if (is_readable($file)) {
        $s = include $file;
        if (is_string($s) && strlen($s) >= 32) { return $secret = $s; }
    }
    // config/ लिहिता येत नाही — तरीही DB बाहेरची स्थिर किल्ली
    return $secret = hash('sha256', __FILE__ . '|' . ($GLOBALS['DB_USER'] ?? '') . '|' . ($GLOBALS['DB_PASS'] ?? '') . '|' . php_uname('n'));
}
function grievanceSubmitterHash($userId) {
    return hash_hmac('sha256', 'grievance-submitter:' . (int)$userId, grievanceSecret());
}

$hasNewColumns = true;
try { $pdo->query("SELECT submitter_hash FROM grievances LIMIT 1"); } catch (PDOException $e) { $hasNewColumns = false; }
$myHash = grievanceSubmitterHash($user['id']);

// जुन्या गुप्त तक्रारी — User-ID काढून HMAC मध्ये बदलतो (एकदाच होतं)
if ($hasNewColumns) {
    try {
        $oldAnon = $pdo->prepare("SELECT id, submitted_by FROM grievances WHERE organization_id = ? AND is_anonymous = 1 AND submitted_by IS NOT NULL");
        $oldAnon->execute([$orgId]);
        $conv = $pdo->prepare("UPDATE grievances SET submitter_hash = ?, submitted_by = NULL WHERE id = ?");
        foreach ($oldAnon->fetchAll() as $row) { $conv->execute([grievanceSubmitterHash($row['submitted_by']), $row['id']]); }
    } catch (PDOException $e) {}
}

function grievanceLoad($pdo, $id, $orgId) {
    $st = $pdo->prepare("SELECT * FROM grievances WHERE id = ? AND organization_id = ?");
    $st->execute([$id, $orgId]);
    return $st->fetch();
}
$isSubmitter = function ($g) use ($user, $myHash) {
    return ($g['submitted_by'] !== null && (int)$g['submitted_by'] === (int)$user['id'])
        || (!empty($g['submitter_hash']) && hash_equals($g['submitter_hash'], $myHash));
};
$isAssignee = fn($g) => !empty($g['assigned_to']) && (int)$g['assigned_to'] === (int)$user['id'];

// तक्रार हाताळणारे (manage_grievances; नसेल तर Management) — नवीन तक्रार/टिप्पणीची सूचना
$handlerIds = function () use ($orgId) {
    $ids = notifyUserIdsByPermission($orgId, 'manage_grievances', false);
    return $ids ?: notifyUserIdsByCategory($orgId, ['management']);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit') {
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        if ($description) {
            $slaDeadline = date('Y-m-d', strtotime('+' . GRIEVANCE_SLA_DAYS . ' days'));
            if ($hasNewColumns) {
                $pdo->prepare("INSERT INTO grievances (organization_id, submitted_by, submitter_hash, category, description, is_anonymous, sla_deadline) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $isAnonymous ? null : $user['id'], $isAnonymous ? $myHash : null, $category ?: null, $description, $isAnonymous, $slaDeadline]);
            } else {
                $pdo->prepare("INSERT INTO grievances (organization_id, submitted_by, category, description, is_anonymous, sla_deadline) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $user['id'], $category ?: null, $description, $isAnonymous, $slaDeadline]);
            }
            $newId = (int)$pdo->lastInsertId();
            notifyUsers($handlerIds(), $orgId, '🎫 नवीन तक्रार #' . $newId, ($category ? "$category — " : '') . mb_substr($description, 0, 60), 'grievances/index.php#g' . $newId, $user['id']);
            $_SESSION['flash'] = '✅ तुमची तक्रार नोंदवली गेली — ' . GRIEVANCE_SLA_DAYS . ' दिवसात उत्तर मिळेल.';
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $g = grievanceLoad($pdo, $id, $orgId);
        if (!$g) { header('Location: index.php'); exit; }
        $link = 'grievances/index.php#g' . $id;

        if ($action === 'update_status' && ($canManage || $isAssignee($g))) {
            $status = $_POST['status'] ?? $g['status'];
            if (!in_array($status, $allowedStatuses, true)) { $status = $g['status']; }
            $resolution = trim($_POST['resolution'] ?? '');
            // नेमणूक फक्त हाताळणाऱ्यांनाच बदलता येते; नेमलेली व्यक्ती फक्त स्थिती/Resolution
            $assignedTo = $g['assigned_to'];
            if ($canManage) {
                $assignedTo = ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null;
                if ($assignedTo) {
                    $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
                    $chk->execute([$assignedTo, $orgId]);
                    if (!$chk->fetch()) { $assignedTo = $g['assigned_to']; }
                }
            }
            // resolved_at — फक्त "सोडवलं" मध्ये जाताना; पुन्हा उघडल्यास रिकामं
            if ($status === 'resolved' && $g['status'] !== 'resolved') { $resolvedAt = date('Y-m-d H:i:s'); }
            elseif (in_array($status, ['open', 'in_progress'], true)) { $resolvedAt = null; }
            else { $resolvedAt = $g['resolved_at']; }
            $pdo->prepare("UPDATE grievances SET status = ?, resolution = ?, assigned_to = ?, resolved_at = ? WHERE id = ? AND organization_id = ?")
                ->execute([$status, $resolution ?: null, $assignedTo, $resolvedAt, $id, $orgId]);

            if ($assignedTo && (int)$assignedTo !== (int)$g['assigned_to'] && (int)$assignedTo !== (int)$user['id']) {
                notifyUser($assignedTo, $orgId, '🎫 तुम्हाला तक्रार नेमली — #' . $id, ($g['category'] ? $g['category'] . ' — ' : '') . mb_substr($g['description'], 0, 60), $link);
            }
            if (!$g['is_anonymous'] && $g['submitted_by'] && $status !== $g['status']) {
                notifyUser($g['submitted_by'], $orgId, '📋 तुमच्या तक्रारीवर Update', 'स्थिती: ' . $status, $link);
            }
            $_SESSION['flash'] = 'Update झालं.';
        } elseif ($action === 'comment' && ($isSubmitter($g) || $canManage || $isAssignee($g))) {
            $comment = trim($_POST['comment'] ?? '');
            if ($comment !== '' && $hasNewColumns) {
                $bySubmitter = $isSubmitter($g);
                // गुप्त तक्रारदाराची टिप्पणी — user_id ठेवत नाही
                $pdo->prepare("INSERT INTO grievance_comments (grievance_id, user_id, author_role, comment) VALUES (?, ?, ?, ?)")
                    ->execute([$id, ($bySubmitter && $g['is_anonymous']) ? null : $user['id'], $bySubmitter ? 'submitter' : 'handler', $comment]);
                if ($bySubmitter) {
                    notifyUsers($g['assigned_to'] ? [$g['assigned_to']] : $handlerIds(), $orgId, '💬 तक्रारीवर पुढील माहिती — #' . $id, mb_substr($comment, 0, 80), $link, $user['id']);
                } elseif (!$g['is_anonymous'] && $g['submitted_by']) {
                    notifyUser($g['submitted_by'], $orgId, '💬 तुमच्या तक्रारीवर उत्तर', mb_substr($comment, 0, 80), $link);
                }
                $_SESSION['flash'] = 'टिप्पणी जोडली.';
            }
        } elseif ($action === 'reopen' && $isSubmitter($g) && in_array($g['status'], ['resolved', 'closed'], true) && $hasNewColumns) {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason !== '') {
                $pdo->prepare("UPDATE grievances SET status = 'open', resolved_at = NULL, escalated_at = NULL, reopened_count = reopened_count + 1, sla_deadline = ? WHERE id = ?")
                    ->execute([date('Y-m-d', strtotime('+' . GRIEVANCE_SLA_DAYS . ' days')), $id]);
                $pdo->prepare("INSERT INTO grievance_comments (grievance_id, user_id, author_role, comment) VALUES (?, ?, 'submitter', ?)")
                    ->execute([$id, $g['is_anonymous'] ? null : $user['id'], '🔁 पुन्हा उघडली: ' . $reason]);
                notifyUsers($g['assigned_to'] ? [$g['assigned_to']] : $handlerIds(), $orgId, '🔁 तक्रार पुन्हा उघडली — #' . $id, mb_substr($reason, 0, 80), $link, $user['id']);
                $_SESSION['flash'] = 'तक्रार पुन्हा उघडली.';
            }
        }
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'तक्रार निवारण';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// हाताळणाऱ्यांना — गुप्त तक्रारीचं नाव SQL मधूनच येत नाही (CASE)
$selectCols = "g.*, CASE WHEN g.is_anonymous = 1 THEN NULL ELSE u.name END AS submitter_name, a.name AS assigned_name";
if ($canManage) {
    $grievancesStmt = $pdo->prepare("
        SELECT $selectCols
        FROM grievances g LEFT JOIN users u ON u.id = g.submitted_by LEFT JOIN users a ON a.id = g.assigned_to
        WHERE g.organization_id = ? ORDER BY FIELD(g.status,'open','in_progress','resolved','closed'), g.created_at DESC
    ");
    $grievancesStmt->execute([$orgId]);
} else {
    // स्वतःच्या (गुप्तसुद्धा) + मला नेमलेल्या
    $grievancesStmt = $pdo->prepare("
        SELECT $selectCols
        FROM grievances g LEFT JOIN users u ON u.id = g.submitted_by LEFT JOIN users a ON a.id = g.assigned_to
        WHERE g.organization_id = ? AND (g.submitted_by = ? OR g.assigned_to = ?" . ($hasNewColumns ? " OR g.submitter_hash = ?" : "") . ")
        ORDER BY FIELD(g.status,'open','in_progress','resolved','closed'), g.created_at DESC
    ");
    $grievancesStmt->execute($hasNewColumns ? [$orgId, $user['id'], $user['id'], $myHash] : [$orgId, $user['id'], $user['id']]);
}
$grievances = $grievancesStmt->fetchAll();

$commentsByGrievance = [];
if ($grievances && $hasNewColumns) {
    $ids = array_column($grievances, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $cStmt = $pdo->prepare("SELECT c.*, CASE WHEN c.author_role = 'handler' THEN u.name ELSE NULL END AS author_name FROM grievance_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.grievance_id IN ($ph) ORDER BY c.id");
    $cStmt->execute($ids);
    foreach ($cStmt->fetchAll() as $c) { $commentsByGrievance[$c['grievance_id']][] = $c; }
}

$openCount = count(array_filter($grievances, fn($g) => in_array($g['status'], ['open', 'in_progress'], true)));
$overdueCount = count(array_filter($grievances, fn($g) => in_array($g['status'], ['open', 'in_progress'], true) && $g['sla_deadline'] && strtotime($g['sla_deadline']) < strtotime('today')));

$allStaff = [];
if ($canManage) {
    $staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $staffStmt->execute([$orgId]);
    $allStaff = $staffStmt->fetchAll();
}

$statusLabels = ['open' => '🔴 नवीन', 'in_progress' => '🟡 सुरू आहे', 'resolved' => '✅ सोडवलं', 'closed' => '⚪ बंद'];
$statusChips = ['open' => 'overdue', 'in_progress' => 'pending', 'resolved' => 'done', 'closed' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎫 तक्रार निवारण <?= !$canManage ? '— माझ्या' : '' ?></div>
  <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#submitModal">+ नवीन तक्रार</button>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Anonymous Option उपलब्ध — <?= GRIEVANCE_SLA_DAYS ?> दिवसांत उत्तराची हमी</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण</div><div class="val"><?= count($grievances) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 Open</div><div class="val" style="color:var(--amber);"><?= $openCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ SLA चुकलेल्या</div><div class="val" style="color:<?= $overdueCount > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $overdueCount ?></div></div></div>
</div>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($grievances)): ?><div class="col-12"><div class="panel"><div class="empty-state"><div class="icon"><i class="fa-solid fa-gavel"></i></div><div class="title">कुठलीही तक्रार नाही</div></div></div></div><?php endif; ?>
  <?php foreach ($grievances as $g):
    $isOverdue = in_array($g['status'], ['open', 'in_progress'], true) && $g['sla_deadline'] && strtotime($g['sla_deadline']) < strtotime('today');
    $mine = $isSubmitter($g);
    $canHandle = $canManage || $isAssignee($g);
  ?>
    <div class="col-md-6" id="g<?= $g['id'] ?>">
      <div class="panel" style="padding:18px;<?= $isOverdue ? 'border-left:3px solid var(--rose);' : '' ?>">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <span class="status-chip <?= $statusChips[$g['status']] ?? 'pending' ?>"><?= $statusLabels[$g['status']] ?? h($g['status']) ?></span>
          <span style="font-size:10.5px;color:var(--text-dim);">#<?= $g['id'] ?> · <?= date('d M Y', strtotime($g['created_at'])) ?><?= $isOverdue ? ' · ⚠️ SLA चुकली' : '' ?></span>
        </div>
        <?php if ($g['category']): ?><div style="font-size:11px;color:var(--rail);font-weight:600;margin-bottom:4px;"><?= h($g['category']) ?></div><?php endif; ?>
        <div style="font-size:13px;"><?= h($g['description']) ?></div>
        <?php if ($canHandle): ?>
          <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">👤 <?= $g['is_anonymous'] ? 'Anonymous' : h($g['submitter_name'] ?? '—') ?><?= $g['assigned_name'] ? ' · नेमलं: ' . h($g['assigned_name']) : '' ?></div>
        <?php elseif ($mine && $g['is_anonymous']): ?>
          <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">🔒 गुप्त — तुमचं नाव कोणालाही दिसत नाही</div>
        <?php endif; ?>
        <?php if ($g['resolution']): ?><div style="font-size:12px;background:var(--canvas);border-radius:8px;padding:8px 12px;margin-top:8px;"><strong>Resolution:</strong> <?= h($g['resolution']) ?></div><?php endif; ?>

        <?php foreach ($commentsByGrievance[$g['id']] ?? [] as $c): ?>
          <div style="font-size:12px;border-left:2px solid var(--line);padding:4px 10px;margin-top:6px;">
            <span style="color:var(--text-dim);font-size:10.5px;"><?= $c['author_role'] === 'submitter' ? ($g['is_anonymous'] ? 'तक्रारदार (गुप्त)' : 'तक्रारदार') : h($c['author_name'] ?? 'हाताळणारे') ?> · <?= date('d M, h:i A', strtotime($c['created_at'])) ?></span>
            <div style="white-space:pre-wrap;"><?= h($c['comment']) ?></div>
          </div>
        <?php endforeach; ?>

        <?php if ($hasNewColumns && ($mine || $canHandle)): ?>
          <form method="POST" class="d-flex gap-2 mt-2">
            <input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $g['id'] ?>">
            <input type="text" name="comment" class="form-control-custom" style="font-size:12px;" placeholder="<?= $mine ? 'पुढील माहिती / टिप्पणी…' : 'उत्तर / टिप्पणी…' ?>" required>
            <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;">पाठवा</button>
          </form>
        <?php endif; ?>

        <?php if ($hasNewColumns && $mine && in_array($g['status'], ['resolved', 'closed'], true)): ?>
          <form method="POST" class="d-flex gap-2 mt-2">
            <input type="hidden" name="action" value="reopen"><input type="hidden" name="id" value="<?= $g['id'] ?>">
            <input type="text" name="reason" class="form-control-custom" style="font-size:12px;" placeholder="समाधान नाही? कारण लिहा" required>
            <button type="submit" class="btn btn-sm" style="border:1px solid var(--amber);color:var(--amber);border-radius:7px;font-size:11px;">🔁 पुन्हा उघडा</button>
          </form>
        <?php endif; ?>

        <?php if ($canHandle): ?>
          <button type="button" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#updateModal"
            onclick="document.getElementById('updId').value='<?= $g['id'] ?>';document.getElementById('updStatus').value='<?= h($g['status']) ?>';<?php if ($canManage): ?>document.getElementById('updAssigned').value='<?= $g['assigned_to'] ?? '' ?>';<?php endif; ?>document.getElementById('updResolution').value=<?= h(json_encode($g['resolution'] ?? '')) ?>;">
            स्थिती बदला
          </button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="submitModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="submit">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन तक्रार नोंदवा</div>
        <label class="form-label-custom">प्रकार (ऐच्छिक)</label>
        <input type="text" name="category" class="form-control-custom mb-2" placeholder="उदा. Workplace, Facilities, HR">
        <label class="form-label-custom">तपशील</label>
        <textarea name="description" class="form-control-custom mb-2" rows="4" required></textarea>
        <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_anonymous" style="width:16px;height:16px;"> गुप्त ठेवा (माझं नाव कोणालाही दिसणार नाही)</label>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">पाठवा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="updateModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" id="updId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">स्थिती Update करा</div>
        <label class="form-label-custom">स्थिती</label>
        <select name="status" id="updStatus" class="form-select-custom mb-2">
          <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
        </select>
        <?php if ($canManage): ?>
        <label class="form-label-custom">कोणाला नेमून द्यायचं</label>
        <select name="assigned_to" id="updAssigned" class="form-select-custom mb-2">
          <option value="">-- कोणीच नाही --</option>
          <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="form-label-custom">Resolution (काय केलं)</label>
        <textarea name="resolution" id="updResolution" class="form-control-custom" rows="3"></textarea>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
