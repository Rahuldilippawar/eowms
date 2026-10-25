<?php
require_once __DIR__ . '/includes/auth.php';

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

if ($code) {
    $stmt = $pdo->prepare("
        SELECT r.*, d.name_local AS dept_name, u.name AS completed_by_name
        FROM work_requests r
        JOIN departments d ON d.id = r.department_id
        LEFT JOIN users u ON u.id = r.completed_by
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

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusColors = ['pending' => '#C97F1D', 'in_progress' => '#3B6FC9', 'completed' => '#1B8A83'];
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
  .searchcard button{background:#1B8A83;color:#fff;border:none;border-radius:9px;padding:12px 22px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;}
  .tabrow{display:flex;gap:8px;margin-bottom:16px;}
  .tabrow a{flex:1;text-align:center;padding:10px;border-radius:9px;background:#1B2340;color:#9FA6C0;text-decoration:none;font-size:13px;font-weight:600;}
  .tabrow a.active{background:#1B8A83;color:#fff;}
  .result-row{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid #EEF0F4;text-decoration:none;color:inherit;}
  .result-row:last-child{border-bottom:none;}
  .result-row:hover{background:#FAFBFC;}
  .card{background:#fff;border-radius:14px;padding:24px 26px;margin-bottom:18px;box-shadow:0 4px 20px rgba(0,0,0,.04);}
  .status-badge{display:inline-block;padding:6px 16px;border-radius:20px;color:#fff;font-size:13px;font-weight:700;}
  .meta{font-size:12.5px;color:#666B7D;margin-top:8px;}
  h3{font-family:'Space Grotesk';font-size:15px;color:#10162B;margin:0 0 12px;}
  .timeline-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #EEF0F4;}
  .timeline-item:last-child{border-bottom:none;}
  .dot{width:28px;height:28px;border-radius:50%;background:#DCEFEC;color:#0F5E58;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
  .err{background:#F7E2E3;color:#8A1F2B;border-radius:8px;padding:12px 16px;font-size:13px;}
  .viewer-chip{display:inline-block;background:#F3F4F7;border-radius:20px;padding:6px 14px;font-size:12px;margin:0 6px 6px 0;}
  a.back{color:#1B8A83;text-decoration:none;font-size:13px;font-weight:600;}
</style>
</head>
<body>
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text-dim);background:#fff;cursor:pointer;">
  <option value="">🌐 भाषा (Language)</option>
  <option value="en">English</option>
  <option value="hi">हिंदी (Hindi)</option>
  <option value="gu">ગુજરાતી (Gujarati)</option>
  <option value="mr">मराठी (मूळ)</option>
</select>
<div id="google_translate_element" style="display:none;"></div>
<style>body > .skiptranslate{display:none !important;} body{top:0 !important;}</style>
<script>
  function googleTranslateElementInit() {
    new google.translate.TranslateElement({ pageLanguage: 'mr', autoDisplay: false }, 'google_translate_element');
  }
  function eowmsChangeLanguage(lang) {
    var tries = 0;
    var trigger = function () {
      var combo = document.querySelector('.goog-te-combo');
      if (combo) {
        combo.value = lang;
        combo.dispatchEvent(new Event('change'));
      } else if (tries < 20) {
        tries++;
        setTimeout(trigger, 250);
      }
    };
    trigger();
  }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>

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

  <?php if ($mobileSearched && !$code): ?>
    <div class="card">
      <p style="font-size:13px;color:#666B7D;">सुरक्षिततेसाठी — Mobile Number सोबत तुमचा Request ID (विनंती केल्यावर मिळालेला क्रमांक) सुद्धा टाका.</p>
    </div>
  <?php endif; ?>

  <?php if ($notFound): ?>
    <div class="card"><div class="err">"<?= htmlspecialchars($code) ?>" या क्रमांकाची कोणतीही विनंती सापडली नाही<?= $searchMode === 'mobile' ? ' (किंवा Mobile Number जुळत नाही)' : '' ?>. कृपया Request ID तपासा.</div></div>
  <?php elseif ($request): ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:16px;color:#10162B;"><?= htmlspecialchars($request['request_code']) ?></div>
          <div class="meta"><?= htmlspecialchars($request['dept_name']) ?> · <?= date('d M Y, h:i A', strtotime($request['created_at'])) ?></div>
        </div>
        <span class="status-badge" style="background:<?= $statusColors[$request['status']] ?>;"><?= $statusLabels[$request['status']] ?></span>
      </div>
      <p style="font-size:14px;color:#1A1D29;margin-top:16px;line-height:1.6;"><?= nl2br(htmlspecialchars($request['work_description'])) ?></p>
      <?php if ($request['status'] === 'completed'): ?>
        <div style="background:#DCEFEC;color:#0F5E58;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:10px;">
          ✅ <?= htmlspecialchars($request['completed_by_name'] ?? '') ?> यांनी <?= $request['completed_at'] ? date('d M Y, h:i A', strtotime($request['completed_at'])) : '' ?> रोजी पूर्ण केले
        </div>
      <?php endif; ?>
    </div>

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
