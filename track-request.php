<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ticket-helpers.php';

$code = trim($_GET['code'] ?? $_POST['code'] ?? '');
$mobile = trim($_GET['mobile'] ?? $_POST['mobile'] ?? '');
$searchMode = $_GET['mode'] ?? ($mobile ? 'mobile' : 'code');
$request = null;
$actions = [];
$viewers = [];
$notFound = false;
$mobileResults = [];
$mobileSearched = false;

// सुरक्षा: फक्त Mobile नंबरवरून (जो इतरांनाही माहीत असू शकतो) त्या नंबरच्या
// सगळ्या विनंत्यांची यादी कधीही दाखवत नाही — Mobile + Request ID दोन्ही जुळले तरच तपशील.
$mobileLast10 = '';
if ($mobile) {
    $mobileSearched = true;
    $mobileLast10 = substr(preg_replace('/\D/', '', $mobile), -10);
}

// 🔒 Request ID अंदाजाने एकामागून एक शोधणं (Enumeration) रोखण्यासाठी —
// एका IP वरून १५ मिनिटांत ३० पेक्षा जास्त शोध नाहीत.
$rateLimited = false;
if ($code) {
    if (publicFormRateLimited('track_lookup', 30, 900)) {
        $rateLimited = true;
        $code = '';
    } else {
        publicFormRecordSubmission('track_lookup');
    }
}

if ($code) {
    $stmt = $pdo->prepare("
        SELECT r.*, d.name_local AS dept_name, u.name AS completed_by_name, au.name AS assignee_name
        FROM work_requests r
        JOIN departments d ON d.id = r.department_id
        LEFT JOIN users u ON u.id = r.completed_by
        LEFT JOIN users au ON au.id = r.assigned_to_user_id
        WHERE r.request_code = ?
    ");
    $stmt->execute([$code]);
    $request = $stmt->fetch();
    // Mobile ने शोध — Request ID सोबत Mobile ही जुळायलाच हवा
    if ($request && $searchMode === 'mobile') {
        $reqDigits = substr(preg_replace('/\D/', '', (string)$request['requester_contact']), -10);
        if (strlen($mobileLast10) !== 10 || $reqDigits !== $mobileLast10) { $request = null; }
    }

    if (!$request) {
        $notFound = true;
    } else {
        // ⭐ काम पूर्ण झाल्यावर विनंती करणाऱ्याचा अभिप्राय (Rating) — Mobile नोंदवलेला असेल तर तोच जुळायला हवा
        $rateMsg = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate' && $request['status'] === 'completed' && empty($request['rating'])) {
            $rating = (int)($_POST['rating'] ?? 0);
            $fb = trim(mb_substr((string)($_POST['feedback'] ?? ''), 0, 500)) ?: null;
            $reqDigits = substr(preg_replace('/\D/', '', (string)$request['requester_contact']), -10);
            $given = substr(preg_replace('/\D/', '', (string)($_POST['verify_mobile'] ?? '')), -10);
            if (publicFormRateLimited('track_rate', 10, 3600)) {
                $rateMsg = ['err', 'खूप प्रयत्न झाले — थोड्या वेळाने पुन्हा करा.'];
            } elseif ($rating < 1 || $rating > 5) {
                $rateMsg = ['err', 'कृपया 1 ते 5 तारे निवडा.'];
            } elseif (strlen($reqDigits) === 10 && $given !== $reqDigits) {
                $rateMsg = ['err', 'विनंती करताना दिलेला Mobile नंबर जुळत नाही.'];
            } else {
                publicFormRecordSubmission('track_rate');
                $pdo->prepare("UPDATE work_requests SET status = 'closed', closed_at = NOW(), rating = ?, feedback = ? WHERE id = ? AND status = 'completed' AND rating IS NULL")->execute([$rating, $fb, $request['id']]);
                ticketLog($pdo, $request['id'], 'closed', 'completed', 'closed', "Rating: $rating/5" . ($fb ? " — $fb" : '') . ' (विनंती करणाऱ्याकडून)', null);
                try { notifyUsers(array_filter([$request['completed_by'], $request['assigned_to_user_id']]), $request['organization_id'], '⭐ अभिप्राय मिळाला — ' . $request['request_code'], str_repeat('★', $rating) . ($fb ? ' — ' . mb_substr($fb, 0, 60) : ''), 'requests/index.php'); } catch (Throwable $e) {}
                header('Location: track-request.php?code=' . urlencode($request['request_code']) . '&rated=1');
                exit;
            }
        }
        try {
            $stmt = $pdo->prepare("
                SELECT wa.*, u.name AS changed_by_name
                FROM work_request_actions wa LEFT JOIN users u ON u.id = wa.changed_by
                WHERE wa.request_id = ? ORDER BY wa.id ASC
            ");
            $stmt->execute([$request['id']]);
            $actions = $stmt->fetchAll();
        } catch (PDOException $e) {
            $actions = [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT wv.viewed_at, u.name, u.designation
                FROM work_request_views wv JOIN users u ON u.id = wv.user_id
                WHERE wv.request_id = ? ORDER BY wv.viewed_at ASC
            ");
            $stmt->execute([$request['id']]);
            $viewers = $stmt->fetchAll();
        } catch (PDOException $e) {
            $viewers = [];
        }
    }
}

$org = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले', 'closed' => 'बंद (पूर्ण)', 'cancelled' => 'रद्द'];
$statusColors = ['pending' => '#C97F1D', 'in_progress' => '#3B6FC9', 'completed' => '#1B8A83', 'closed' => '#16A34A', 'cancelled' => '#8A90AC'];
$priLabels = ['low' => 'कमी', 'normal' => 'सामान्य', 'high' => 'लवकर हवं', 'urgent' => '🚨 तातडीचे'];
$catLabels = ['maintenance' => '🔧 दुरुस्ती / देखभाल', 'housekeeping' => '🧹 स्वच्छता', 'it' => '🖥️ Computer / Internet', 'general' => '📋 सर्वसाधारण', 'other' => '📄 इतर'];
$actionLabels = ['created' => 'विनंती नोंदवली गेली', 'status_changed' => 'स्थिती बदलली'];
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>विनंतीची स्थिती तपासा</title>
<link rel="icon" type="image/png" href="assets/brand/favicon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#F0F1F4;margin:0;min-height:100vh;padding:30px 16px;}
  .wrap{max-width:640px;margin:0 auto;}
  .searchcard{background:#10162B;border-radius:16px;padding:28px 30px;margin-bottom:20px;}
  .searchcard h1{font-family:'Space Grotesk';color:#fff;font-size:22px;margin:0 0 6px;}
  .searchcard p{color:#9FA6C0;font-size:13px;margin:0 0 18px;}
  .searchcard form{display:flex;gap:10px;}
  .searchcard input{flex:1;padding:12px 16px;border-radius:9px;border:none;font-size:14px;font-family:'JetBrains Mono',monospace;}
  .searchcard button{background:#D9530F;color:#fff;border:none;border-radius:9px;padding:12px 22px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;}
  .tabrow{display:flex;gap:8px;margin-bottom:16px;}
  .tabrow a{flex:1;text-align:center;padding:10px;border-radius:9px;background:#1B2340;color:#9FA6C0;text-decoration:none;font-size:13px;font-weight:600;}
  .tabrow a.active{background:#D9530F;color:#fff;}
  .result-row{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid #EEF0F4;text-decoration:none;color:inherit;}
  .result-row:last-child{border-bottom:none;}
  .result-row:hover{background:#FAFBFC;}
  .card{background:#fff;border-radius:14px;padding:24px 26px;margin-bottom:18px;box-shadow:0 4px 20px rgba(0,0,0,.04);}
  .status-badge{display:inline-block;padding:6px 16px;border-radius:20px;color:#fff;font-size:13px;font-weight:700;}
  .meta{font-size:12.5px;color:#666B7D;margin-top:8px;}
  h3{font-family:'Space Grotesk';font-size:15px;color:#10162B;margin:0 0 12px;}
  .timeline-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #EEF0F4;}
  .timeline-item:last-child{border-bottom:none;}
  .dot{width:28px;height:28px;border-radius:50%;background:#DCEFEC;color:#B6420A;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
  .err{background:#F7E2E3;color:#8A1F2B;border-radius:8px;padding:12px 16px;font-size:13px;}
  .viewer-chip{display:inline-block;background:#F3F4F7;border-radius:20px;padding:6px 14px;font-size:12px;margin:0 6px 6px 0;}
  a.back{color:#D9530F;text-decoration:none;font-size:13px;font-weight:600;}
  .rq-chip{display:inline-block;background:#10162B;color:#FDBA8C;border-radius:99px;padding:3px 10px;margin:4px 6px 0 0;text-decoration:none;font-family:'JetBrains Mono',monospace;font-size:12px;}
  .stepper{display:grid;grid-template-columns:repeat(4,1fr);position:relative;}
  .stepper::before{content:'';position:absolute;top:19px;left:12.5%;right:12.5%;height:3px;background:#E4E7ED;border-radius:3px;}
  .stp{text-align:center;position:relative;}
  .stp-dot{width:40px;height:40px;border-radius:50%;margin:0 auto;display:grid;place-items:center;font-size:17px;background:#F3F4F7;border:3px solid #E4E7ED;position:relative;filter:grayscale(1);opacity:.6;}
  .stp.done .stp-dot{background:#DCFCE7;border-color:#16A34A;filter:none;opacity:1;}
  .stp.cur .stp-dot{background:#FFF4E5;border-color:#D9530F;filter:none;opacity:1;box-shadow:0 0 0 6px rgba(217,83,15,.12);animation:pulse 1.8s ease-in-out infinite;}
  @keyframes pulse{50%{box-shadow:0 0 0 10px rgba(217,83,15,.04);}}
  .stp-lbl{font-size:12px;font-weight:700;color:#10162B;margin-top:6px;} .stp:not(.done):not(.cur) .stp-lbl{color:#9AA0B4;font-weight:600;}
  .stp-at{font-size:10.5px;color:#8A90AC;margin-top:2px;min-height:14px;}
  .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;}
  .info-grid div{background:#F7F8FA;border-radius:10px;padding:8px 12px;} .info-grid span{display:block;font-size:11px;color:#8A90AC;} .info-grid b{font-size:13px;color:#10162B;}
  .stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:4px;margin:4px 0 10px;}
  .stars input{display:none;} .stars label{font-size:34px;color:#E4E7ED;cursor:pointer;line-height:1;transition:color .12s;}
  .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label{color:#F59E0B;}
  .fb{width:100%;padding:10px 12px;border:1.5px solid #E4E7ED;border-radius:10px;font-family:inherit;font-size:14px;margin-bottom:8px;box-sizing:border-box;}
  .rate-btn{width:100%;background:#D9530F;color:#fff;border:none;border-radius:10px;padding:12px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;}
  @media (max-width:480px){ .stp-lbl{font-size:11px;} .stp-dot{width:34px;height:34px;font-size:15px;} .stepper::before{top:16px;} .searchcard form{flex-wrap:wrap;} .searchcard button{width:100%;} }
</style>
</head>
<body>
<?= i18nSwitcher('style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text-dim);background:#fff;cursor:pointer;"') ?>
<?= i18nClientScript() ?>

<div class="wrap">
  <div class="searchcard">
    <h1>📋 विनंतीची स्थिती तपासा</h1>
    <p>Request ID माहीत असेल तर तेच टाका, नाहीतर तुमच्या Mobile Number ने शोधा</p>
    <div class="tabrow">
      <a href="?mode=code" class="<?= $searchMode === 'code' ? 'active' : '' ?>">🔢 Request ID ने</a>
      <a href="?mode=mobile" class="<?= $searchMode === 'mobile' ? 'active' : '' ?>">📱 Mobile Number ने</a>
    </div>
    <?php if ($searchMode === 'mobile'): ?>
      <form method="GET" style="flex-wrap:wrap;">
        <input type="hidden" name="mode" value="mobile">
        <input type="tel" name="mobile" placeholder="Mobile, उदा. 9876543210" value="<?= htmlspecialchars($mobile) ?>" required autofocus>
        <input type="text" name="code" placeholder="Request ID, उदा. REQ-260012" value="<?= htmlspecialchars($code) ?>" required>
        <button type="submit">शोधा</button>
      </form>
    <?php else: ?>
      <form method="GET">
        <input type="hidden" name="mode" value="code">
        <input type="text" name="code" placeholder="उदा. REQ-260012" value="<?= htmlspecialchars($code) ?>" required autofocus>
        <button type="submit">तपासा</button>
      </form>
    <?php endif; ?>
  </div>

  <div id="myReqs" class="card" style="display:none;padding:12px 18px;font-size:12.5px;color:#666B7D;">🕘 या Phone वरच्या तुमच्या विनंत्या: <span id="myReqsList"></span></div>
  <script>
  (function () { try {
    var a = JSON.parse(localStorage.getItem('eowms_my_requests') || '[]'); if (!a.length) return;
    var box = document.getElementById('myReqsList'); a.forEach(function (c) { var l = document.createElement('a'); l.href = '?code=' + encodeURIComponent(c); l.textContent = c; l.className = 'rq-chip'; box.appendChild(l); });
    document.getElementById('myReqs').style.display = 'block';
  } catch (e) {} })();
  </script>
  <?php if ($mobileSearched && !$code): ?>
    <div class="card">
      <p style="font-size:13px;color:#666B7D;">सुरक्षिततेसाठी — Mobile Number सोबत तुमचा Request ID (विनंती केल्यावर मिळालेला क्रमांक) सुद्धा टाका.</p>
    </div>
  <?php endif; ?>

  <?php if ($rateLimited): ?>
    <div class="card"><div class="err">खूप शोध झाले — कृपया १५ मिनिटांनी पुन्हा प्रयत्न करा.</div></div>
  <?php endif; ?>
  <?php if ($notFound): ?>
    <div class="card"><div class="err">"<?= htmlspecialchars($code) ?>" या क्रमांकाची कोणतीही विनंती सापडली नाही<?= $searchMode === 'mobile' ? ' (किंवा Mobile Number जुळत नाही)' : '' ?>. कृपया Request ID तपासा.</div></div>
  <?php elseif ($request):
    $st = $request['status'];
    $seen = !empty($viewers);
    $stepIdx = $st === 'cancelled' ? -1 : (in_array($st, ['completed', 'closed'], true) ? 3 : ($st === 'in_progress' ? 2 : ($seen ? 1 : 0)));
    $steps = [['📝', 'नोंदवली', $request['created_at']], ['👀', 'विभागाने पाहिली', $viewers[0]['viewed_at'] ?? null], ['🔧', 'काम सुरू', null], ['✅', 'पूर्ण', $request['completed_at']]];
    foreach ($actions as $a) { if ($a['new_status'] === 'in_progress' && !$steps[2][2]) { $steps[2][2] = $a['created_at']; } }
    $dueTxt = null; $dueBad = false;
    if (!empty($request['due_at']) && in_array($st, ['pending', 'in_progress'], true)) {
        $dueBad = strtotime($request['due_at']) < time();
        $dueTxt = ($dueBad ? 'अपेक्षित वेळ उलटली — ' : 'अपेक्षित: ') . date('d M, h:i A', strtotime($request['due_at']));
    }
  ?>
    <script>try { var k = 'eowms_my_requests', a = JSON.parse(localStorage.getItem(k) || '[]').filter(function (x) { return x !== '<?= htmlspecialchars($request['request_code']) ?>'; }); a.unshift('<?= htmlspecialchars($request['request_code']) ?>'); localStorage.setItem(k, JSON.stringify(a.slice(0, 8))); } catch (e) {}</script>
    <?php if (!empty($_GET['rated'])): ?><div class="card" style="background:#DCFCE7;color:#166534;font-weight:600;">🙏 धन्यवाद! तुमचा अभिप्राय विभागापर्यंत पोहोचला.</div><?php endif; ?>
    <?php if ($st !== 'cancelled'): ?>
    <div class="card" style="padding:18px 16px;">
      <div class="stepper">
        <?php foreach ($steps as $i => [$ic, $lbl, $at]): ?>
          <div class="stp <?= $i < $stepIdx || ($i === $stepIdx && $i === 3) ? 'done' : ($i === $stepIdx ? 'cur' : '') ?>">
            <div class="stp-dot"><?= $ic ?></div>
            <div class="stp-lbl"><?= $lbl ?></div>
            <div class="stp-at"><?= $at && $i <= $stepIdx ? date('d M, h:i A', strtotime($at)) : '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:16px;color:#10162B;"><?= htmlspecialchars($request['request_code']) ?></div>
          <div class="meta"><?= htmlspecialchars($request['dept_name']) ?> · <?= date('d M Y, h:i A', strtotime($request['created_at'])) ?></div>
        </div>
        <span class="status-badge" style="background:<?= $statusColors[$request['status']] ?>;"><?= $statusLabels[$request['status']] ?></span>
      </div>
      <p style="font-size:14px;color:#1A1D29;margin-top:16px;line-height:1.6;"><?= nl2br(htmlspecialchars($request['work_description'])) ?></p>
      <div class="info-grid">
        <?php if (!empty($request['category'])): ?><div><span>प्रकार</span><b><?= htmlspecialchars($catLabels[$request['category']] ?? $request['category']) ?></b></div><?php endif; ?>
        <div><span>प्राधान्य</span><b><?= htmlspecialchars($priLabels[$request['priority']] ?? $request['priority']) ?></b></div>
        <?php if (!empty($request['location'])): ?><div><span>ठिकाण</span><b><?= htmlspecialchars($request['location']) ?></b></div><?php endif; ?>
        <?php if (!empty($request['assignee_name'])): ?><div><span>काम सोपवलं</span><b><?= htmlspecialchars($request['assignee_name']) ?></b></div><?php endif; ?>
        <?php if ($dueTxt): ?><div style="grid-column:1/-1;"><span>वेळ</span><b style="color:<?= $dueBad ? '#B42318' : '#166534' ?>;">⏱️ <?= htmlspecialchars($dueTxt) ?></b></div><?php endif; ?>
      </div>
      <?php if ($request['status'] === 'completed'): ?>
        <div style="background:#DCEFEC;color:#B6420A;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:10px;">
          ✅ <?= htmlspecialchars($request['completed_by_name'] ?? '') ?> यांनी <?= $request['completed_at'] ? date('d M Y, h:i A', strtotime($request['completed_at'])) : '' ?> रोजी पूर्ण केले
        </div>
      <?php endif; ?>
    </div>

    <?php if ($st === 'completed' && empty($request['rating'])): ?>
    <div class="card" style="border:2px solid #F59E0B;">
      <h3>⭐ काम कसं झालं? अभिप्राय द्या</h3>
      <?php if (!empty($rateMsg)): ?><div class="err" style="margin-bottom:10px;"><?= htmlspecialchars($rateMsg[1]) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="rate"><input type="hidden" name="code" value="<?= htmlspecialchars($request['request_code']) ?>">
        <?php if ($searchMode === 'mobile'): ?><input type="hidden" name="mode" value="mobile"><input type="hidden" name="mobile" value="<?= htmlspecialchars($mobile) ?>"><?php endif; ?>
        <div class="stars">
          <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>"><label for="r<?= $i ?>" title="<?= $i ?>">★</label><?php endfor; ?>
        </div>
        <textarea name="feedback" rows="2" maxlength="500" class="fb" placeholder="काही सांगायचं आहे? (ऐच्छिक)"></textarea>
        <?php if (strlen(substr(preg_replace('/\D/', '', (string)$request['requester_contact']), -10)) === 10): ?>
          <input type="tel" name="verify_mobile" class="fb" placeholder="खात्रीसाठी — विनंती करताना दिलेला Mobile नंबर" value="<?= htmlspecialchars($mobile) ?>" required>
        <?php endif; ?>
        <button type="submit" class="rate-btn">अभिप्राय पाठवा</button>
      </form>
    </div>
    <?php elseif (!empty($request['rating'])): ?>
    <div class="card"><h3>⭐ तुमचा अभिप्राय</h3><div style="font-size:22px;color:#F59E0B;letter-spacing:2px;"><?= str_repeat('★', (int)$request['rating']) . '<span style="color:#E4E7ED;">' . str_repeat('★', 5 - (int)$request['rating']) . '</span>' ?></div><?php if ($request['feedback']): ?><div style="font-size:13px;color:#666B7D;margin-top:4px;"><?= htmlspecialchars($request['feedback']) ?></div><?php endif; ?></div>
    <?php endif; ?>

    <div class="card">
      <h3>प्रगती व टिप्पण्या (History)</h3>
      <?php foreach ($actions as $a): ?>
        <div class="timeline-item">
          <div class="dot"><i class="fa-solid fa-check" style="font-size:11px;"></i></div>
          <div>
            <div style="font-size:13px;color:#1A1D29;">
              <?php if ($a['action_type'] === 'created'): ?>
                विनंती नोंदवली गेली
              <?php else: ?>
                स्थिती <strong><?= $statusLabels[$a['old_status']] ?? $a['old_status'] ?></strong> वरून <strong><?= $statusLabels[$a['new_status']] ?? $a['new_status'] ?></strong> झाली
                <?= $a['changed_by_name'] ? '— ' . htmlspecialchars($a['changed_by_name']) : '' ?>
              <?php endif; ?>
            </div>
            <?php if ($a['remark']): ?><div style="font-size:12.5px;color:#666B7D;margin-top:2px;"><?= htmlspecialchars($a['remark']) ?></div><?php endif; ?>
            <div style="font-size:11px;color:#8A90AC;margin-top:2px;"><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3>👀 विभागातून कोणी बघितलं</h3>
      <?php if (empty($viewers)): ?>
        <p style="font-size:13px;color:#666B7D;">अजून कोणीही बघितलेलं नाही.</p>
      <?php else: ?>
        <?php foreach ($viewers as $v): ?>
          <span class="viewer-chip"><?= htmlspecialchars($v['name']) ?><?= $v['designation'] ? ' (' . htmlspecialchars($v['designation']) . ')' : '' ?></span>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:20px;">
    <a class="back" href="welcome.php">← मुख्य पानावर परत जा</a>
  </div>
</div>
</body>
</html>
