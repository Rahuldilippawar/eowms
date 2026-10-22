<?php
require_once __DIR__ . '/config/db.php';
session_start();

$orgId = $_SESSION['user']['organization_id'] ?? null;
if (!$orgId) {
    // Session नसेल तर — Domain वरून जी पहिली Active Organization सापडेल ती दाखवतो
    $orgId = $pdo->query("SELECT id FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch()['id'] ?? null;
}

$org = null;
$isStillInMaintenance = true;
if ($orgId) {
    $stmt = $pdo->prepare("SELECT name, maintenance_mode, maintenance_message, maintenance_started_at FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    $org = $stmt->fetch();
    $isStillInMaintenance = !empty($org) && (int)$org['maintenance_mode'] === 1;
}

// Maintenance आधीच बंद झाली असेल (कोणी दुसऱ्या Tab मध्ये आधीच बंद केली)
// तर — आपोआप मुख्य पानाकडे परत पाठवतो.
if (!$isStillInMaintenance) {
    header('Location: ' . (!empty($_SESSION['user']) ? 'index.php' : 'welcome.php'));
    exit;
}

$orgName = $org['name'] ?? 'संस्था';
$customMessage = $org['maintenance_message'] ?? '';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="30">
<title>Site Maintenance — <?= htmlspecialchars($orgName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{
    font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;margin:0;min-height:100vh;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(160deg,#0A0E1A 0%,#1B2340 55%,#0F5E58 130%);
    padding:24px;position:relative;overflow:hidden;
  }
  .ring{position:absolute;top:50%;left:50%;width:420px;height:420px;transform:translate(-50%,-50%);border:1px solid rgba(220,239,236,.08);border-radius:50%;animation:spin 60s linear infinite;}
  .ring::before{content:'';position:absolute;inset:36px;border:1px dashed rgba(201,127,29,.12);border-radius:50%;}
  @keyframes spin{from{transform:translate(-50%,-50%) rotate(0deg);}to{transform:translate(-50%,-50%) rotate(360deg);}}

  .card{background:#fff;border-radius:22px;padding:44px 36px;max-width:440px;width:100%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.35);position:relative;z-index:1;}
  .gear-wrap{width:76px;height:76px;border-radius:50%;background:linear-gradient(150deg,#1B8A83,#0F5E58);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 10px 26px rgba(27,138,131,.4);}
  .gear-wrap i{font-size:32px;color:#fff;animation:rotate-gear 3s linear infinite;}
  @keyframes rotate-gear{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}
  h1{font-family:'Space Grotesk';font-weight:700;font-size:22px;color:#10162B;margin:0 0 10px;}
  p.sub{color:#666B7D;font-size:14px;line-height:1.7;margin:0 0 6px;}
  .org-name{font-family:'Space Grotesk';font-weight:600;font-size:13px;color:#1B8A83;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;}
  .pulse-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#C97F1D;margin-right:8px;animation:pulse 1.5s ease-in-out infinite;}
  @keyframes pulse{0%,100%{opacity:1;}50%{opacity:.3;}}
  .status-line{font-size:12px;color:#8A90AC;margin-top:22px;}
  .sound-toggle{margin-top:20px;background:#F3F4F7;border:none;border-radius:10px;padding:9px 18px;font-size:12.5px;font-weight:600;color:#10162B;cursor:pointer;font-family:inherit;}
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

  <div class="ring"></div>
  <div class="card">
    <div class="gear-wrap"><i class="fa-solid fa-gear"></i></div>
    <div class="org-name"><?= htmlspecialchars($orgName) ?></div>
    <h1><span class="pulse-dot"></span>Site Maintenance चालू आहे</h1>
    <p class="sub"><?= $customMessage ? nl2br(htmlspecialchars($customMessage)) : 'System मध्ये सध्या काही महत्त्वाचे बदल केले जात आहेत. कृपया थोड्या वेळाने पुन्हा प्रयत्न करा.' ?></p>
    <div class="status-line">हे पान दर 30 सेकंदांनी आपोआप Refresh होईल</div>
    <button type="button" class="sound-toggle" onclick="toggleSound()" id="soundBtn">🔇 आवाज बंद करा</button>
  </div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script>
// ============================================================
// हलका, शांत Chime आवाज — Web Audio API ने थेट तयार केलेला (कुठलीही
// Audio File लागत नाही — Copyright ची कुठलीही अडचण नाही), दर काही
// सेकंदांनी हलकेच वाजतो — त्रासदायक न होता, "System काम करतंय" ही जाणीव.
// ============================================================
let audioCtx = null;
let soundOn = true;
let chimeInterval = null;

function playChime() {
  if (!soundOn) return;
  if (!audioCtx) { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
  const now = audioCtx.currentTime;
  [523.25, 659.25].forEach(function (freq, i) {
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0, now + i * 0.15);
    gain.gain.linearRampToValueAtTime(0.06, now + i * 0.15 + 0.05);
    gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.15 + 1.2);
    osc.connect(gain).connect(audioCtx.destination);
    osc.start(now + i * 0.15);
    osc.stop(now + i * 0.15 + 1.3);
  });
}

function toggleSound() {
  soundOn = !soundOn;
  document.getElementById('soundBtn').textContent = soundOn ? '🔇 आवाज बंद करा' : '🔊 आवाज सुरू करा';
  if (soundOn) { playChime(); }
}

// पहिला Chime — User ने पानावर कुठेही Click/Touch केल्यावर (Browser च्या
// Autoplay-Restriction मुळे आपोआप, Interaction शिवाय आवाज सुरू करता येत
// नाही — हे सामान्य, अपेक्षित वर्तन आहे).
document.body.addEventListener('click', function initAudio() {
  playChime();
  chimeInterval = setInterval(playChime, 8000);
  document.body.removeEventListener('click', initAudio);
}, { once: true });
</script>
</body>
</html>
