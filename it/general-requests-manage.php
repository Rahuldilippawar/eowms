<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
// IT विभागाचे सदस्य किंवा manage_it — Sidebar मध्येही हाच नियम (canManageItRequests)
if (!canManageItRequests($pdo, $user)) { requirePermission('manage_it', '../'); }
$orgId = activeOrgId();

// सर्वसाधारण IT Requests आता एकत्रित विनंती-रांगेत (requests/?category=it).
// हे पान फक्त Asset-विशिष्ट दुरुस्ती तक्रारींसाठी (?assets=1) उरलं आहे.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_GET['assets'])) {
    header('Location: ../requests/index.php?category=it');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    // ---------------- Asset-विशिष्ट दुरुस्ती तक्रार ----------------
    if ($action === 'start') {
        $pdo->prepare("UPDATE it_repair_tickets SET status = 'in_progress' WHERE id = ? AND organization_id = ?")->execute([$requestId, $orgId]);
        $_SESSION['flash'] = 'दुरुस्ती सुरू केली म्हणून नोंदवलं.';
    } elseif ($action === 'resolve_asset') {
        $resolutionNotes = trim($_POST['resolution_notes'] ?? '');
        $pdo->prepare("UPDATE it_repair_tickets SET status = 'resolved', resolution_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ? AND organization_id = ?")
            ->execute([$resolutionNotes, $user['id'], $requestId, $orgId]);
        $tStmt = $pdo->prepare("SELECT asset_id FROM it_repair_tickets WHERE id = ? AND organization_id = ?");
        $tStmt->execute([$requestId, $orgId]);
        $assetId = $tStmt->fetch()['asset_id'] ?? null;
        if ($assetId) {
            $otherPending = $pdo->prepare("SELECT COUNT(*) c FROM it_repair_tickets WHERE asset_id = ? AND status != 'resolved'");
            $otherPending->execute([$assetId]);
            if ((int)$otherPending->fetch()['c'] === 0) {
                // बाद (scrapped) केलेलं Asset पुन्हा "चालू" होऊ नये
                $pdo->prepare("UPDATE it_assets SET status = 'working' WHERE id = ? AND organization_id = ? AND status != 'scrapped'")->execute([$assetId, $orgId]);
            }
        }
        logActivity('IT तक्रार पूर्ण केली', 'it_repair_ticket', $requestId, '');
        $_SESSION['flash'] = 'तक्रार पूर्ण झाली म्हणून नोंदवली.';
    }
    header('Location: general-requests-manage.php?assets=1');
    exit;
}

$pageTitle = 'IT Asset तक्रारी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$filterStatus = $_GET['status'] ?? 'active';

// ---------------- Asset-विशिष्ट दुरुस्ती तक्रारी (QR/Asset-पानावरून) ----------------
$aSql = "
    SELECT t.*, a.asset_tag, l.lab_name, u.name AS requester_name,
      'asset' AS source, t.created_at AS sort_date
    FROM it_repair_tickets t JOIN it_assets a ON a.id = t.asset_id JOIN it_labs l ON l.id = t.lab_id LEFT JOIN users u ON u.id = t.reported_by
    WHERE t.organization_id = ?
";
if ($filterStatus === 'active') { $aSql .= " AND t.status != 'resolved'"; }
elseif ($filterStatus === 'resolved') { $aSql .= " AND t.status = 'resolved'"; }
elseif ($filterStatus !== 'all') { $aSql .= " AND 1=0"; } // इतर विशिष्ट Status (assigned/in_progress/closed) Asset-Tickets ला लागू नाहीत
$aStmt = $pdo->prepare($aSql);
$aStmt->execute([$orgId]);
$assetTickets = $aStmt->fetchAll();

// ---------------- दोन्ही एकत्र, प्राधान्य+दिनांकानुसार ----------------
$requests = $assetTickets;
usort($requests, fn($a, $b) => strtotime($b['sort_date']) <=> strtotime($a['sort_date']));

$itStaffStmt = $pdo->prepare("
    SELECT u.id, u.name FROM users u JOIN departments d ON d.id = u.department_id
    WHERE d.organization_id = ? AND d.code = 'IT' AND u.is_active = 1 ORDER BY u.name
");
$itStaffStmt->execute([$orgId]);
$itStaff = $itStaffStmt->fetchAll();

$categoryIcons = ['internet_network' => '🌐', 'software' => '💿', 'new_hardware' => '🖥️', 'account_access' => '🔑', 'printer_scanner' => '🖨️', 'other' => '📄'];
$statusLabels = ['open' => '🆕 नवीन', 'assigned' => '👤 नेमली', 'in_progress' => '⏳ सुरू आहे', 'resolved' => '✅ सुटली', 'closed' => '🔒 बंद'];
$statusChips = ['open' => 'overdue', 'assigned' => 'pending', 'in_progress' => 'pending', 'resolved' => 'done', 'closed' => 'done'];
$priorityColors = ['low' => 'var(--text-dim)', 'normal' => 'var(--ink)', 'high' => 'var(--amber)', 'urgent' => 'var(--rose)'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🛠️ IT Asset दुरुस्ती तक्रारी</div>
  <a href="../requests/index.php?category=it" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>IT Requests रांग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex gap-2 mb-3">
  <a href="?assets=1&status=active" class="period-btn <?= $filterStatus === 'active' ? 'active' : '' ?>">सक्रिय</a>
  <a href="?assets=1&status=resolved" class="period-btn <?= $filterStatus === 'resolved' ? 'active' : '' ?>">सुटलेल्या</a>
  <a href="?assets=1&status=all" class="period-btn <?= $filterStatus === 'all' ? 'active' : '' ?>">सगळ्या</a>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>प्रकार</th><th>शीर्षक</th><th>विभाग/व्यक्ती</th><th>प्राधान्य</th><th>नेमलं</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($requests)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलीही Request नाही</td></tr><?php endif; ?>
      <?php foreach ($requests as $r): ?>
        <?php if ($r['source'] === 'asset'): ?>
          <tr>
            <td style="font-size:20px;">🖥️</td>
            <td>
              <div style="font-weight:600;"><?= h($r['asset_tag']) ?> — Asset तक्रार</div>
              <div style="font-size:11px;color:var(--text-dim);"><?= h($r['issue_description']) ?></div>
              <div style="font-size:10.5px;color:var(--text-dim);">📍 <?= h($r['lab_name']) ?></div>
            </td>
            <td style="font-size:11.5px;">—<br><?= h($r['requester_name']) ?></td>
            <td>—</td>
            <td>—</td>
            <td><span class="status-chip <?= $r['status'] === 'resolved' ? 'done' : 'pending' ?>"><?= $r['status'] === 'resolved' ? '✅ पूर्ण' : ($r['status'] === 'in_progress' ? '🔧 सुरू आहे' : '🆕 नवीन') ?></span></td>
            <td>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;"><input type="hidden" name="source" value="asset"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="start">
                  <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;">सुरू करा</button>
                </form>
              <?php elseif ($r['status'] === 'in_progress'): ?>
                <button type="button" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#assetResolve<?= $r['id'] ?>">पूर्ण करा</button>
                <div class="modal fade" id="assetResolve<?= $r['id'] ?>" tabindex="-1">
                  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
                    <form method="POST">
                      <input type="hidden" name="source" value="asset"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="resolve_asset">
                      <div class="modal-body" style="padding:20px;">
                        <div style="font-weight:600;margin-bottom:10px;">काय दुरुस्त केलं?</div>
                        <textarea name="resolution_notes" class="form-control-custom" rows="3" placeholder="उदा. RAM बदलली"></textarea>
                      </div>
                      <div class="modal-footer" style="border-top:1px solid var(--line);">
                        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">✓ पूर्ण झालं</button>
                      </div>
                    </form>
                  </div></div>
                </div>
              <?php else: ?>
                <a href="asset-view.php?id=<?= $r['asset_id'] ?>" style="font-size:11.5px;color:var(--rail);">Asset बघा</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php else: ?>
        <tr>
          <td style="font-size:20px;"><?= $categoryIcons[$r['category']] ?? '📄' ?></td>
          <td>
            <div style="font-weight:600;"><?= h($r['title']) ?></div>
            <div class="font-mono" style="font-size:10px;color:var(--text-dim);"><?= h($r['request_code']) ?></div>
            <?php if ($r['location']): ?><div style="font-size:10.5px;color:var(--text-dim);">📍 <?= h($r['location']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:11.5px;"><?= h($r['dept_name']) ?><br><?= h($r['requester_name']) ?></td>
          <td style="font-weight:600;color:<?= $priorityColors[$r['priority']] ?>;"><?= h(ucfirst($r['priority'])) ?></td>
          <td><?= h($r['assigned_to_name'] ?: '—') ?></td>
          <td><span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span></td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#actModal<?= $r['id'] ?>">कृती</button>
          </td>
        </tr>
        <div class="modal fade" id="actModal<?= $r['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <div class="modal-body" style="padding:20px;">
              <div style="font-weight:600;margin-bottom:4px;"><?= h($r['title']) ?></div>
              <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;"><?= h($r['description']) ?></div>

              <form method="POST" class="mb-3">
                <input type="hidden" name="action" value="assign"><input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <label class="form-label-custom">कोणाला नेमायचं</label>
                <div class="d-flex gap-2">
                  <select name="assigned_to" class="form-select-custom" required>
                    <option value="">-- IT Staff निवडा --</option>
                    <?php foreach ($itStaff as $s): ?><option value="<?= $s['id'] ?>" <?= (int)$r['assigned_to'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:6px 14px;">नेमा</button>
                </div>
              </form>

              <form method="POST">
                <input type="hidden" name="action" value="update_status"><input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <label class="form-label-custom">स्थिती</label>
                <select name="status" class="form-select-custom mb-2">
                  <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $r['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                </select>
                <label class="form-label-custom">Resolution टिप्पणी</label>
                <textarea name="resolution_notes" class="form-control-custom mb-2" rows="2"><?= h($r['resolution_notes']) ?></textarea>
                <button type="submit" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px;">जतन करा</button>
              </form>
            </div>
          </div></div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
