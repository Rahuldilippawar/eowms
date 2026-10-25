<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
// रहिवासी विभाग (RES) चे कर्मचारी किंवा सर्व-विभाग परवानगी असलेले — index.php प्रमाणेच
$resDeptStmt = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$resDeptStmt->execute([$user['department_id'] ?? 0]);
if (!hasPermission('manage_residency_all_depts') && ($resDeptStmt->fetch()['code'] ?? null) !== 'RES') {
    http_response_code(403);
    die('तुम्हाला ही माहिती पाहण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'checkin') {
        $locationId = (int)($_POST['location_id'] ?? 0);
        $hostOccupantId = ($_POST['host_occupant_id'] ?? '') !== '' ? (int)$_POST['host_occupant_id'] : null;
        $guestName = trim($_POST['guest_name'] ?? '');
        $guestContact = trim($_POST['guest_contact'] ?? '');
        $relation = trim($_POST['relation'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $arrivalDate = ($_POST['arrival_date'] ?? '') ?: date('Y-m-d');
        $expectedDeparture = ($_POST['expected_departure_date'] ?? '') ?: null;

        $locChk = $pdo->prepare("SELECT COUNT(*) FROM maintenance_locations WHERE id = ? AND organization_id = ? AND is_residential = 1");
        $locChk->execute([$locationId, $orgId]);
        if ($hostOccupantId) {
            $hostChk = $pdo->prepare("SELECT COUNT(*) FROM residency_occupants WHERE id = ? AND organization_id = ?");
            $hostChk->execute([$hostOccupantId, $orgId]);
            if (!(int)$hostChk->fetchColumn()) { $hostOccupantId = null; }
        }
        if ($locationId && $guestName && (int)$locChk->fetchColumn() > 0) {
            $pdo->prepare("
                INSERT INTO residency_guests (organization_id, location_id, host_occupant_id, guest_name, guest_contact, relation, purpose, arrival_date, expected_departure_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$orgId, $locationId, $hostOccupantId, $guestName, $guestContact, $relation, $purpose, $arrivalDate, $expectedDeparture, $user['id']]);
            logActivity('पाहुणा नोंदवला', 'residency_guest', $pdo->lastInsertId(), $guestName);
            $_SESSION['flash'] = "$guestName यांची नोंद झाली.";
        }
    } elseif ($action === 'checkout') {
        $guestId = (int)($_POST['guest_id'] ?? 0);
        $pdo->prepare("UPDATE residency_guests SET status = 'checked_out', actual_departure_date = CURDATE() WHERE id = ? AND organization_id = ?")->execute([$guestId, $orgId]);
        $_SESSION['flash'] = 'पाहुणा Check-out झाला म्हणून नोंदवलं.';
    }
    header('Location: guests.php');
    exit;
}

$pageTitle = 'पाहुणे-नोंदणी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$locStmt = $pdo->prepare("SELECT ml.*, b.name AS building_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.organization_id = ? AND ml.is_active = 1 AND ml.is_residential = 1 ORDER BY b.name, ml.name");
$locStmt->execute([$orgId]);
$locations = $locStmt->fetchAll();

$occStmt = $pdo->prepare("SELECT o.*, ml.name AS room_name, u.name AS user_name FROM residency_occupants o JOIN maintenance_locations ml ON ml.id = o.location_id LEFT JOIN users u ON u.id = o.user_id WHERE o.organization_id = ? AND o.status = 'active' ORDER BY o.name");
$occStmt->execute([$orgId]);
$occupants = $occStmt->fetchAll();

$guestsStmt = $pdo->prepare("
    SELECT g.*, ml.name AS room_name, b.name AS building_name, o.name AS host_name, ou.name AS host_user_name
    FROM residency_guests g JOIN maintenance_locations ml ON ml.id = g.location_id LEFT JOIN buildings b ON b.id = ml.building_id
    LEFT JOIN residency_occupants o ON o.id = g.host_occupant_id LEFT JOIN users ou ON ou.id = o.user_id
    WHERE g.organization_id = ? ORDER BY g.status = 'checked_in' DESC, g.arrival_date DESC LIMIT 50
");
$guestsStmt->execute([$orgId]);
$guests = $guestsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">👋 पाहुणे-नोंदणी</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन पाहुणा — Check-in</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="checkin">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">Room</label>
        <select name="location_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>"><?= h($loc['building_name'] ? $loc['building_name'] . ' — ' : '') ?><?= h($loc['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">यजमान (कोणाकडे आले)</label>
        <select name="host_occupant_id" class="form-select-custom">
          <option value="">-- ऐच्छिक --</option>
          <?php foreach ($occupants as $o): ?><option value="<?= $o['id'] ?>"><?= h($o['user_name'] ?? $o['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label-custom">पाहुण्याचं नाव</label><input type="text" name="guest_name" class="form-control-custom" required></div>
      <div class="col-md-3"><label class="form-label-custom">संपर्क</label><input type="text" name="guest_contact" class="form-control-custom"></div>
      <div class="col-md-3"><label class="form-label-custom">नातं</label><input type="text" name="relation" class="form-control-custom" placeholder="उदा. भाऊ, मित्र"></div>
      <div class="col-md-3"><label class="form-label-custom">कारण</label><input type="text" name="purpose" class="form-control-custom"></div>
      <div class="col-md-3"><label class="form-label-custom">आगमन दिनांक</label><input type="date" name="arrival_date" class="form-control-custom" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-3"><label class="form-label-custom">अपेक्षित प्रस्थान</label><input type="date" name="expected_departure_date" class="form-control-custom"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Check-in करा</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">पाहुण्यांची यादी</div></div>
  <table class="rtable">
    <thead><tr><th>पाहुणा</th><th>Room</th><th>यजमान</th><th>आगमन</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($guests)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही पाहुणा नोंदवलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($guests as $g): ?>
        <tr>
          <td style="font-weight:600;"><?= h($g['guest_name']) ?><?= $g['relation'] ? ' (' . h($g['relation']) . ')' : '' ?></td>
          <td><?= h($g['building_name'] ? $g['building_name'] . ' — ' : '') ?><?= h($g['room_name']) ?></td>
          <td><?= h($g['host_user_name'] ?? $g['host_name'] ?? '—') ?></td>
          <td><?= date('d M Y', strtotime($g['arrival_date'])) ?></td>
          <td><span class="status-chip <?= $g['status'] === 'checked_in' ? 'pending' : 'done' ?>"><?= $g['status'] === 'checked_in' ? '🟡 राहत आहे' : '✅ गेले' ?></span></td>
          <td>
            <?php if ($g['status'] === 'checked_in'): ?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="checkout"><input type="hidden" name="guest_id" value="<?= $g['id'] ?>">
                <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;">Check-out करा</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
