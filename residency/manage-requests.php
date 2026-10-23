<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
// सर्व-विभाग परवानगी, किंवा रहिवासी विभागाचे (RES) HOD — विनंती-फॉर्म "HOD/Management" मंजुरी सांगतो
$resDeptStmt = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$resDeptStmt->execute([$user['department_id'] ?? 0]);
$isResHod = hasCategory('hod') && ($resDeptStmt->fetch()['code'] ?? null) === 'RES';
if (!hasPermission('manage_residency_all_depts') && !$isResHod) {
    http_response_code(403);
    die('तुम्हाला ही माहिती पाहण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $reqStmt = $pdo->prepare("SELECT * FROM residency_allotment_requests WHERE id = ? AND organization_id = ?");
    $reqStmt->execute([$reqId, $orgId]);
    $req = $reqStmt->fetch();
    // आधीच निर्णय झालेल्या (मंजूर/नामंजूर) विनंतीवर पुन्हा कृती नाही
    if ($req && !in_array($req['status'], ['pending', 'waitlisted'], true)) {
        $_SESSION['flash'] = 'या विनंतीवर आधीच निर्णय झाला आहे.';
        header('Location: manage-requests.php');
        exit;
    }

    if ($req && $action === 'approve') {
        $locationId = (int)($_POST['location_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            // Room याच संस्थेची, Active व निवासी — आणि Lock करून शेवटच्या क्षणी क्षमता तपासतो
            $capCheckStmt = $pdo->prepare("
                SELECT id, capacity FROM maintenance_locations
                WHERE id = ? AND organization_id = ? AND is_active = 1 AND is_residential = 1 FOR UPDATE
            ");
            $capCheckStmt->execute([$locationId, $orgId]);
            $capInfo = $capCheckStmt->fetch();
            $occCountStmt = $pdo->prepare("SELECT COUNT(*) FROM residency_occupants WHERE location_id = ? AND status = 'active'");
            $occCountStmt->execute([$locationId]);
            $occupied = (int)$occCountStmt->fetchColumn();
            $needed = max(1, (int)$req['family_size']);
            $alreadyHousedStmt = $pdo->prepare("SELECT COUNT(*) FROM residency_occupants WHERE user_id = ? AND status = 'active'");
            $alreadyHousedStmt->execute([$req['requested_by']]);
            $reqStatusStmt = $pdo->prepare("SELECT status FROM residency_allotment_requests WHERE id = ? FOR UPDATE");
            $reqStatusStmt->execute([$reqId]);
            $currentStatus = $reqStatusStmt->fetchColumn();

            if (!$capInfo) {
                $_SESSION['flash'] = 'अवैध Room — कृपया यादीतली निवासी Room निवडा.';
                $pdo->rollBack();
            } elseif (!in_array($currentStatus, ['pending', 'waitlisted'], true)) {
                $_SESSION['flash'] = 'या विनंतीवर आधीच निर्णय झाला आहे.';
                $pdo->rollBack();
            } elseif ((int)$alreadyHousedStmt->fetchColumn() > 0) {
                $_SESSION['flash'] = 'हा कर्मचारी आधीच एका खोलीत राहतो — आधी खोली-बदल (Transfer) वापरा.';
                $pdo->rollBack();
            } elseif ($capInfo['capacity'] !== null && $occupied + $needed > (int)$capInfo['capacity']) {
                $_SESSION['flash'] = "या Room मध्ये पुरेशी जागा नाही (क्षमता {$capInfo['capacity']}, भरलेल्या $occupied, कुटुंब $needed) — कृपया दुसरी Room निवडा.";
                $pdo->rollBack();
            } else {
                $pdo->prepare("UPDATE residency_allotment_requests SET status = 'approved', allotted_location_id = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
                    ->execute([$locationId, $user['id'], $reqId]);
                // प्रत्यक्ष Occupant Record तयार करतो
                $requesterStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $requesterStmt->execute([$req['requested_by']]);
                $requesterName = $requesterStmt->fetch()['name'] ?? '';
                $pdo->prepare("INSERT INTO residency_occupants (organization_id, location_id, occupant_type, user_id, name, moved_in_date, status, created_by) VALUES (?, ?, 'staff', ?, ?, CURDATE(), 'active', ?)")
                    ->execute([$orgId, $locationId, $req['requested_by'], $requesterName, $user['id']]);
                $pdo->commit();
                notifyUser($req['requested_by'], $orgId, 'निवास मंजूर झाला', 'तुमची निवास-विनंती मंजूर झाली आहे', 'residency/my-requests.php');
                $_SESSION['flash'] = 'विनंती मंजूर करून, Room वाटप झालं.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['flash'] = 'त्रुटी आली — पुन्हा प्रयत्न करा.';
        }
    } elseif ($req && $action === 'reject') {
        $remarks = trim($_POST['remarks'] ?? '');
        $pdo->prepare("UPDATE residency_allotment_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), remarks = ? WHERE id = ?")
            ->execute([$user['id'], $remarks, $reqId]);
        notifyUser($req['requested_by'], $orgId, 'निवास-विनंती नामंजूर', $remarks ?: 'सध्या रिकामी जागा उपलब्ध नाही', 'residency/my-requests.php');
        $_SESSION['flash'] = 'विनंती नामंजूर केली.';
    } elseif ($req && $action === 'waitlist') {
        $pdo->prepare("UPDATE residency_allotment_requests SET status = 'waitlisted', approved_by = ? WHERE id = ?")->execute([$user['id'], $reqId]);
        notifyUser($req['requested_by'], $orgId, 'प्रतीक्षा-यादीत टाकलं', 'सध्या रिकामी Room नाही — जागा रिकामी झाल्यावर कळवलं जाईल', 'residency/my-requests.php');
        $_SESSION['flash'] = 'प्रतीक्षा-यादीत टाकलं.';
    }
    header('Location: manage-requests.php');
    exit;
}

$pageTitle = 'निवास-विनंत्या व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$filterStatus = $_GET['status'] ?? 'pending';
$sql = "
    SELECT r.*, u.name AS requester_name, u.designation, b.name AS building_name
    FROM residency_allotment_requests r JOIN users u ON u.id = r.requested_by LEFT JOIN buildings b ON b.id = r.preferred_building_id
    WHERE r.organization_id = ?
";
$params = [$orgId];
if ($filterStatus !== 'all') { $sql .= " AND r.status = ?"; $params[] = $filterStatus; }
$sql .= " ORDER BY r.created_at";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// रिकाम्या Rooms ची यादी (Approve करताना निवडण्यासाठी) — क्षमता ठरवलेली
// असेल आणि पूर्ण भरलेली असेल तर — अशी Room यादीत दाखवतच नाही.
$vacantStmt = $pdo->prepare("
    SELECT ml.id, ml.name, ml.capacity, b.name AS building_name,
      (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND status = 'active') AS occupant_count
    FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ml.organization_id = ? AND ml.is_active = 1 AND ml.is_residential = 1
      AND (ml.capacity IS NULL OR (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND status = 'active') < ml.capacity)
    ORDER BY b.name, ml.name
");
$vacantStmt->execute([$orgId]);
$allRooms = $vacantStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🏠 निवास-विनंत्या व्यवस्थापन</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex gap-2 mb-3">
  <a href="?status=pending" class="btn" style="border-radius:9px;font-size:13px;padding:8px 18px;<?= $filterStatus === 'pending' ? 'background:var(--ink);color:#fff;' : 'border:1px solid var(--line);' ?>">प्रलंबित</a>
  <a href="?status=waitlisted" class="btn" style="border-radius:9px;font-size:13px;padding:8px 18px;<?= $filterStatus === 'waitlisted' ? 'background:var(--ink);color:#fff;' : 'border:1px solid var(--line);' ?>">प्रतीक्षा-यादी</a>
  <a href="?status=all" class="btn" style="border-radius:9px;font-size:13px;padding:8px 18px;<?= $filterStatus === 'all' ? 'background:var(--ink);color:#fff;' : 'border:1px solid var(--line);' ?>">सगळ्या</a>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>व्यक्ती</th><th>पसंतीची Building</th><th>कुटुंब आकार</th><th>कारण</th><th>दिनांक</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($requests)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलीही विनंती नाही</td></tr><?php endif; ?>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td style="font-weight:600;"><?= h($r['requester_name']) ?><div style="font-size:10.5px;color:var(--text-dim);"><?= h($r['designation']) ?></div></td>
          <td><?= h($r['building_name'] ?: '—') ?></td>
          <td><?= $r['family_size'] ?></td>
          <td style="max-width:200px;"><?= h($r['reason']) ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td>
            <?php if ($r['status'] === 'pending' || $r['status'] === 'waitlisted'): ?>
              <button type="button" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#approveModal<?= $r['id'] ?>">मंजूर करा</button>
              <?php if ($r['status'] === 'pending'): ?>
                <button type="button" class="btn btn-sm" style="border:1px solid var(--amber);color:var(--amber);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#waitlistModal<?= $r['id'] ?>">प्रतीक्षा-यादी</button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $r['id'] ?>">नामंजूर</button>
            <?php else: ?>
              <span class="status-chip <?= $r['status'] === 'approved' ? 'done' : 'overdue' ?>"><?= $r['status'] === 'approved' ? '✅ मंजूर' : '❌ नामंजूर' ?></span>
            <?php endif; ?>
          </td>
        </tr>

        <div class="modal fade" id="approveModal<?= $r['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="approve">
              <div class="modal-body" style="padding:20px;">
                <div style="font-weight:600;margin-bottom:10px;"><?= h($r['requester_name']) ?> यांना कोणती Room द्यायची?</div>
                <select name="location_id" class="form-select-custom" required>
                  <option value="">-- निवडा --</option>
                  <?php foreach ($allRooms as $room): ?>
                    <option value="<?= $room['id'] ?>"><?= h($room['building_name'] ? $room['building_name'] . ' — ' : '') ?><?= h($room['name']) ?> (<?= $room['occupant_count'] ?><?= $room['capacity'] !== null ? '/' . $room['capacity'] : '' ?> भरलेली)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">✓ मंजूर करा</button>
              </div>
            </form>
          </div></div>
        </div>

        <div class="modal fade" id="rejectModal<?= $r['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="reject">
              <div class="modal-body" style="padding:20px;">
                <div style="font-weight:600;margin-bottom:10px;">नामंजूर करण्याचं कारण</div>
                <textarea name="remarks" class="form-control-custom" rows="3"></textarea>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;">नामंजूर करा</button>
              </div>
            </form>
          </div></div>
        </div>

        <?php if ($r['status'] === 'pending'): ?>
        <div class="modal fade" id="waitlistModal<?= $r['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="waitlist">
              <div class="modal-body" style="padding:20px;">सध्या रिकामी Room नाही — <?= h($r['requester_name']) ?> यांना प्रतीक्षा-यादीत टाकायचं?</div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--amber);color:#fff;border-radius:7px;">प्रतीक्षा-यादीत टाका</button>
              </div>
            </form>
          </div></div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
