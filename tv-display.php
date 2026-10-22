<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
$user = currentUser();
$orgId = activeOrgId();

$org = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$orgInfo = $org->fetch();

// आजचे आकडे — Live Display साठी
$todayAttStmt = $pdo->prepare("SELECT COUNT(*) c FROM staff_attendance WHERE organization_id = ? AND attendance_date = CURDATE() AND status = 'present'");
try { $todayAttStmt->execute([$orgId]); $presentCount = $todayAttStmt->fetch()['c']; } catch (PDOException $e) { $presentCount = 0; }

$totalStaffStmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND is_active = 1");
$totalStaffStmt->execute([$orgId]);
$totalStaff = $totalStaffStmt->fetch()['c'];

$guestsInStmt = $pdo->prepare("SELECT COUNT(*) c FROM guests WHERE organization_id = ? AND DATE(check_in_time) = CURDATE() AND check_out_time IS NULL");
try { $guestsInStmt->execute([$orgId]); $guestsIn = $guestsInStmt->fetch()['c']; } catch (PDOException $e) { $guestsIn = 0; }

$guestsTodayStmt = $pdo->prepare("SELECT COUNT(*) c FROM guests WHERE organization_id = ? AND DATE(check_in_time) = CURDATE()");
try { $guestsTodayStmt->execute([$orgId]); $guestsToday = $guestsTodayStmt->fetch()['c']; } catch (PDOException $e) { $guestsToday = 0; }

$pendingReqStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_requests WHERE organization_id = ? AND status = 'pending'");
$pendingReqStmt->execute([$orgId]);
$pendingReq = $pendingReqStmt->fetch()['c'];

$todayEventsStmt = $pdo->prepare("SELECT name, venue, start_time FROM events WHERE organization_id = ? AND event_date = CURDATE() ORDER BY start_time");
try { $todayEventsStmt->execute([$orgId]); $todayEvents = $todayEventsStmt->fetchAll(); } catch (PDOException $e) { $todayEvents = []; }

$recentGuestsStmt = $pdo->prepare("
    SELECT g.name, g.guest_organization, g.check_in_time, u.name AS meeting_with_name
    FROM guests g LEFT JOIN users u ON u.id = g.meeting_with
    WHERE g.organization_id = ? AND DATE(g.check_in_time) = CURDATE() ORDER BY g.check_in_time DESC LIMIT 6
");
try { $recentGuestsStmt->execute([$orgId]); $recentGuests = $recentGuestsStmt->fetchAll(); } catch (PDOException $e) { $recentGuests = []; }
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="30">
<title>Live Display — <?= htmlspecialchars($orgInfo['name'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#0A0E1A;color:#fff;min-height:100vh;overflow:hidden;}
  .tv-header{display:flex;align-items:center;justify-content:space-between;padding:26px 44px;border-bottom:1px solid rgba(255,255,255,.08);}
  .tv-header .org{display:flex;align-items:center;gap:16px;}
  .tv-header .org img{height:52px;}
  .tv-header .org-name{font-family:'Space Grotesk';font-weight:700;font-size:26px;}
  .tv-clock{font-family:'Space Grotesk';font-weight:700;font-size:32px;color:#2FB3A8;}
  .tv-date{font-size:14px;color:#8A90AC;text-align:right;}
  .tv-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:22px;padding:34px 44px;}
  .tv-card{background:#171D2E;border-radius:20px;padding:26px;border:1px solid rgba(255,255,255,.06);}
  .tv-card .lbl{font-size:14px;color:#8A90AC;font-weight:600;margin-bottom:10px;}
  .tv-card .val{font-family:'Space Grotesk';font-weight:700;font-size:48px;}
  .tv-card .sub{font-size:13px;color:#8A90AC;margin-top:6px;}
  .tv-lower{display:grid;grid-template-columns:1.2fr 1fr;gap:22px;padding:0 44px 34px;}
  .tv-panel{background:#171D2E;border-radius:20px;padding:26px;border:1px solid rgba(255,255,255,.06);}
  .tv-panel h3{font-size:15px;color:#8A90AC;font-weight:600;margin-bottom:16px;text-transform:uppercase;letter-spacing:.06em;}
  .tv-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:15px;}
  .tv-row:last-child{border-bottom:none;}
  .tv-row .name{font-weight:600;}
  .tv-row .meta{color:#8A90AC;font-size:12.5px;}
  .live-pulse{display:inline-block;width:10px;height:10px;border-radius:50%;background:#2FB3A8;margin-right:8px;animation:pulse 1.5s ease-in-out infinite;}
  @keyframes pulse{0%,100%{opacity:1;}50%{opacity:.3;}}
</style>
</head>
<body>
  <div class="tv-header">
    <div class="org">
      <?php if (!empty($orgInfo['logo_path'])): ?><img src="<?= htmlspecialchars($orgInfo['logo_path']) ?>"><?php endif; ?>
      <div class="org-name"><?= htmlspecialchars($orgInfo['name'] ?? '') ?></div>
    </div>
    <div style="text-align:right;">
      <div class="tv-clock" id="tvClock"></div>
      <div class="tv-date" id="tvDate"></div>
    </div>
  </div>

  <div class="tv-grid">
    <div class="tv-card">
      <div class="lbl">👥 आजची उपस्थिती</div>
      <div class="val" style="color:#2FB3A8;"><?= $presentCount ?><span style="font-size:22px;color:#8A90AC;">/<?= $totalStaff ?></span></div>
    </div>
    <div class="tv-card">
      <div class="lbl"><span class="live-pulse"></span>सध्या Building मध्ये पाहुणे</div>
      <div class="val" style="color:#E0994A;"><?= $guestsIn ?></div>
      <div class="sub">आज एकूण <?= $guestsToday ?> पाहुणे</div>
    </div>
    <div class="tv-card">
      <div class="lbl">📋 प्रलंबित विनंत्या</div>
      <div class="val" style="color:<?= $pendingReq > 5 ? '#E06070' : '#fff' ?>;"><?= $pendingReq ?></div>
    </div>
    <div class="tv-card">
      <div class="lbl">🎉 आजचे कार्यक्रम</div>
      <div class="val"><?= count($todayEvents) ?></div>
    </div>
  </div>

  <div class="tv-lower">
    <div class="tv-panel">
      <h3>🎫 आज आलेले पाहुणे</h3>
      <?php if (empty($recentGuests)): ?><div style="color:#8A90AC;">अजून कोणीही आलेलं नाही</div><?php endif; ?>
      <?php foreach ($recentGuests as $g): ?>
        <div class="tv-row">
          <div><div class="name"><?= htmlspecialchars($g['name']) ?></div><div class="meta"><?= htmlspecialchars($g['guest_organization'] ?: '') ?><?= $g['meeting_with_name'] ? ' · ' . htmlspecialchars($g['meeting_with_name']) . ' यांना भेटायला' : '' ?></div></div>
          <div class="meta"><?= date('h:i A', strtotime($g['check_in_time'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tv-panel">
      <h3>🎉 आजचे कार्यक्रम</h3>
      <?php if (empty($todayEvents)): ?><div style="color:#8A90AC;">आज कुठलाही कार्यक्रम नाही</div><?php endif; ?>
      <?php foreach ($todayEvents as $e): ?>
        <div class="tv-row">
          <div><div class="name"><?= htmlspecialchars($e['name']) ?></div><div class="meta"><?= htmlspecialchars($e['venue'] ?: '') ?></div></div>
          <div class="meta"><?= $e['start_time'] ? date('h:i A', strtotime($e['start_time'])) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

<script>
function updateClock() {
  var now = new Date();
  document.getElementById('tvClock').textContent = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  document.getElementById('tvDate').textContent = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
updateClock();
setInterval(updateClock, 1000);
</script>
</body>
</html>
