<?php
$pageTitle = 'इमारत आराखडा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$isMgmt = hasPermission('manage_residency_all_depts');

if (!$isMgmt && $userDeptCode !== 'RES') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला रहिवासी विभागाची माहिती पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$buildingsStmt = $pdo->prepare("
    SELECT b.*, (SELECT COUNT(*) FROM maintenance_locations WHERE building_id = b.id AND is_active = 1 AND is_residential = 1) AS room_count
    FROM buildings b WHERE b.organization_id = ? ORDER BY b.name
");
$buildingsStmt->execute([$orgId]);
$buildings = $buildingsStmt->fetchAll();

$selectedBuildingId = (int)($_GET['building_id'] ?? ($buildings[0]['id'] ?? 0));

$roomsStmt = $pdo->prepare("
    SELECT ml.*, (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND status = 'active') AS occupant_count
    FROM maintenance_locations ml
    WHERE ml.organization_id = ? AND ml.building_id = ? AND ml.is_active = 1 AND ml.is_residential = 1
    ORDER BY ml.floor DESC, ml.name
");
$roomsStmt->execute([$orgId, $selectedBuildingId]);
$rooms = $roomsStmt->fetchAll();

$byFloor = [];
foreach ($rooms as $r) { $byFloor[(int)$r['floor']][] = $r; }
krsort($byFloor); // सगळ्यात वरचा मजला वरती दिसावा — खऱ्या इमारतीसारखं

function floorLabel($f) {
    if ($f === 0) return 'तळमजला';
    return $f . ' वा मजला';
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">🏢 इमारत आराखडा (Building Layout)</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">मजला-निहाय दृश्य — कोणती खोली कुठे आहे, कोणती रिकामी आहे ते एका दृष्टीक्षेपात</div>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list me-1"></i>यादी-स्वरूप</a>
</div>

<?php if (empty($buildings)): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कोणतीही इमारत जोडलेली नाही.</div>
<?php else: ?>

<div class="building-tabs mb-4">
  <?php foreach ($buildings as $b): ?>
    <a href="?building_id=<?= $b['id'] ?>" class="building-tab <?= $selectedBuildingId === (int)$b['id'] ? 'active' : '' ?>">
      <i class="fa-solid fa-building"></i> <?= h($b['name']) ?>
      <span class="tab-count"><?= $b['room_count'] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($rooms)): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">या इमारतीत अजून कोणतीही खोली जोडलेली नाही.</div>
<?php else: ?>

<div class="building-3d-wrap">
  <?php foreach ($byFloor as $floorNum => $floorRooms): ?>
    <div class="floor-level">
      <div class="floor-label">
        <div class="floor-num"><?= $floorNum ?></div>
        <div class="floor-text"><?= h(floorLabel($floorNum)) ?></div>
      </div>
      <div class="floor-rooms">
        <?php foreach ($floorRooms as $room): $vacant = $room['occupant_count'] == 0; ?>
          <a href="room.php?id=<?= $room['id'] ?>" class="room-card-3d <?= $vacant ? 'vacant' : 'occupied' ?>">
            <div class="room-card-face">
              <div class="room-status-dot"></div>
              <div class="room-name"><?= h($room['name']) ?></div>
              <div class="room-code"><?= h($room['location_code']) ?></div>
              <?php if (!$vacant): ?>
                <div class="room-occupants">👤 <?= $room['occupant_count'] ?></div>
              <?php else: ?>
                <div class="room-occupants vacant-text">रिकामी</div>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="d-flex gap-4 mt-4" style="font-size:12px;color:var(--text-dim);">
  <div><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#1B8A83;margin-right:6px;"></span>रिकामी खोली</div>
  <div><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#C97F1D;margin-right:6px;"></span>भरलेली खोली</div>
</div>

<?php endif; ?>
<?php endif; ?>

<style>
  .building-tabs{display:flex;gap:10px;flex-wrap:wrap;}
  .building-tab{
    display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:11px;
    background:#fff;border:1.5px solid var(--line);color:var(--text-dim);text-decoration:none;
    font-size:13px;font-weight:600;transition:all .2s ease;
  }
  .building-tab:hover{border-color:var(--rail);color:var(--rail);}
  .building-tab.active{background:var(--ink);border-color:var(--ink);color:#fff;}
  .building-tab .tab-count{background:rgba(255,255,255,.2);padding:1px 8px;border-radius:10px;font-size:11px;}
  .building-tab.active .tab-count{background:rgba(255,255,255,.25);}
  .building-tab:not(.active) .tab-count{background:var(--canvas);}

  .building-3d-wrap{
    background:linear-gradient(180deg,#EEF1F7 0%,#F8F9FB 100%);
    border-radius:20px;padding:30px 26px;
    box-shadow:inset 0 2px 12px rgba(16,22,43,.06);
  }
  .floor-level{
    display:flex;gap:22px;align-items:stretch;margin-bottom:18px;
    background:#fff;border-radius:16px;padding:18px 20px;
    box-shadow:0 6px 22px rgba(16,22,43,.07), 0 1px 0 rgba(255,255,255,.8) inset;
    border-left:4px solid var(--rail);
  }
  .floor-label{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    min-width:76px;border-right:1px dashed var(--line);padding-right:18px;
  }
  .floor-num{font-family:'Space Grotesk';font-weight:700;font-size:26px;color:var(--ink);}
  .floor-text{font-size:10.5px;color:var(--text-dim);text-align:center;margin-top:2px;white-space:nowrap;}
  .floor-rooms{display:flex;gap:14px;flex-wrap:wrap;flex:1;}

  .room-card-3d{
    text-decoration:none;display:block;width:118px;perspective:600px;
  }
  .room-card-face{
    position:relative;border-radius:12px;padding:14px 12px 12px;
    background:linear-gradient(155deg,#ffffff,#F3F4F7);
    border:1px solid var(--line);
    box-shadow:0 3px 0 rgba(16,22,43,.06), 0 8px 18px rgba(16,22,43,.05);
    transition:transform .25s cubic-bezier(.2,.8,.3,1.2), box-shadow .25s ease;
    transform-style:preserve-3d;
  }
  .room-card-3d:hover .room-card-face{
    transform:translateY(-6px) rotateX(4deg) rotateY(-3deg);
    box-shadow:0 3px 0 rgba(16,22,43,.06), 0 18px 30px rgba(16,22,43,.14);
  }
  .room-card-3d.vacant .room-card-face{border-top:3px solid #1B8A83;}
  .room-card-3d.occupied .room-card-face{border-top:3px solid #C97F1D;}
  .room-status-dot{
    position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;
  }
  .room-card-3d.vacant .room-status-dot{background:#1B8A83;box-shadow:0 0 0 3px rgba(27,138,131,.18);}
  .room-card-3d.occupied .room-status-dot{background:#C97F1D;box-shadow:0 0 0 3px rgba(201,127,29,.18);}
  .room-name{font-size:12.5px;font-weight:700;color:var(--ink);margin-top:6px;}
  .room-code{font-size:10px;color:var(--text-dim);font-family:'JetBrains Mono',monospace;margin-top:2px;}
  .room-occupants{font-size:11px;font-weight:600;color:#C97F1D;margin-top:8px;}
  .room-occupants.vacant-text{color:#1B8A83;}

  @media (max-width: 767px){
    .floor-level{flex-direction:column;}
    .floor-label{flex-direction:row;border-right:none;border-bottom:1px dashed var(--line);padding-right:0;padding-bottom:10px;min-width:0;justify-content:flex-start;gap:8px;}
    .room-card-3d{width:100px;}
  }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
