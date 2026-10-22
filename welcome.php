<?php
require_once __DIR__ . '/includes/auth.php';

// Show the primary organization's name/logo if one exists (typical single-org deployment)
$org = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();

// प्राधान्य 1: Super Admin ने "संस्था व्यवस्थापन" मधून Upload केलेलं Music
// (Database मध्ये साठवलेलं) — असेल तर तेच वापरा.
// प्राधान्य 2 (जुनी पद्धत, Backward-Compatibility साठी): assets/audio/
// फोल्डरमध्ये कोणी थेट FTP ने टाकलेली कुठलीही audio file.
$welcomeAudioFile = $org['welcome_audio_path'] ?? null;
if (!$welcomeAudioFile) {
    $audioDir = __DIR__ . '/assets/audio/';
    if (is_dir($audioDir)) {
        foreach (['mp3', 'wav', 'ogg', 'm4a', 'MP3', 'WAV'] as $ext) {
            $matches = glob($audioDir . '*.' . $ext);
            if (!empty($matches)) {
                $welcomeAudioFile = 'assets/audio/' . basename($matches[0]);
                break;
            }
        }
    }
}

$quote = null;
try {
    $qStmt = $pdo->prepare("SELECT * FROM meditation_quotes WHERE organization_id = ? AND is_active = 1 ORDER BY RAND() LIMIT 1");
    $qStmt->execute([$org['id'] ?? 0]);
    $quote = $qStmt->fetch();
} catch (PDOException $e) {
    // meditation_quotes table not migrated yet — skip silently
}

// Today's birthdays — staff + hostel students (month+day match, year ignored)
$birthdays = [];
try {
    $bStmt = $pdo->prepare("
        SELECT id, name, photo_path, designation AS sub_label, 'staff' AS kind
        FROM users
        WHERE organization_id = ? AND is_active = 1 AND date_of_birth IS NOT NULL
          AND MONTH(date_of_birth) = MONTH(CURDATE()) AND DAY(date_of_birth) = DAY(CURDATE())
    ");
    $bStmt->execute([$org['id'] ?? 0]);
    $birthdays = $bStmt->fetchAll();
} catch (PDOException $e) {}

try {
    $bStmt2 = $pdo->prepare("
        SELECT hs.name, hs.photo_path, CONCAT(h.name, COALESCE(CONCAT(' — ', hs.class_grade), '')) AS sub_label, 'student' AS kind
        FROM hostel_students hs
        JOIN hostels h ON h.id = hs.hostel_id
        WHERE h.organization_id = ? AND hs.is_active = 1 AND hs.date_of_birth IS NOT NULL
          AND MONTH(hs.date_of_birth) = MONTH(CURDATE()) AND DAY(hs.date_of_birth) = DAY(CURDATE())
    ");
    $bStmt2->execute([$org['id'] ?? 0]);
    $birthdays = array_merge($birthdays, $bStmt2->fetchAll());
} catch (PDOException $e) {}

// आजची कामाची वर्धापनदिनं (Work Anniversary) — किती वर्षं पूर्ण झाली तेही दाखवतो
$anniversaries = [];
try {
    $aStmt = $pdo->prepare("
        SELECT id, name, photo_path, designation AS sub_label, TIMESTAMPDIFF(YEAR, joining_date, CURDATE()) AS years_completed
        FROM users
        WHERE organization_id = ? AND is_active = 1 AND joining_date IS NOT NULL
          AND MONTH(joining_date) = MONTH(CURDATE()) AND DAY(joining_date) = DAY(CURDATE())
          AND YEAR(joining_date) < YEAR(CURDATE())
    ");
    $aStmt->execute([$org['id'] ?? 0]);
    $anniversaries = $aStmt->fetchAll();
} catch (PDOException $e) {}

// आजच्या वाढदिवस/वर्धापनदिन असलेल्यांना आधीच कोणी शुभेच्छा दिल्या आहेत का —
// त्या सगळ्या एकत्र काढून, target_user_id प्रमाणे गटवार करतो.
$wishesByUser = [];
try {
    $wStmt = $pdo->prepare("SELECT w.*, u.name AS poster_name FROM birthday_wishes w JOIN users u ON u.id = w.posted_by WHERE w.wish_date = CURDATE() AND w.organization_id = ? ORDER BY w.posted_at");
    $wStmt->execute([$org['id'] ?? 0]);
    foreach ($wStmt->fetchAll() as $w) {
        $wishesByUser[$w['target_user_id']][] = $w;
    }
} catch (PDOException $e) {}
$loggedInUser = currentUser();

// आजच्या दिनांकासाठी — मराठी वार/महिना नावं (PHP च्या date() ला मराठी
// Locale नसतं, म्हणून इथेच छोटी यादी ठेवतो).
$marathiDays = [0 => 'रविवार', 1 => 'सोमवार', 2 => 'मंगळवार', 3 => 'बुधवार', 4 => 'गुरुवार', 5 => 'शुक्रवार', 6 => 'शनिवार'];
$marathiMonths = [1 => 'जानेवारी', 2 => 'फेब्रुवारी', 3 => 'मार्च', 4 => 'एप्रिल', 5 => 'मे', 6 => 'जून', 7 => 'जुलै', 8 => 'ऑगस्ट', 9 => 'सप्टेंबर', 10 => 'ऑक्टोबर', 11 => 'नोव्हेंबर', 12 => 'डिसेंबर'];
$todayLabel = $marathiDays[(int)date('w')] . ', ' . (int)date('j') . ' ' . $marathiMonths[(int)date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($org['name'] ?? 'VJMAT') ?></title>
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#1B8A83">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="EOWMS">
<link rel="apple-touch-icon" href="icon.php?size=192">
<link rel="icon" type="image/png" sizes="192x192" href="icon.php?size=192">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Inter',sans-serif;background:#0A0E1A;color:#1A1D29;margin:0;overflow-x:hidden;}

  /* ---------------- Ambient animated background ---------------- */
  .hero{
    position:relative;overflow:hidden;
    background:radial-gradient(ellipse 120% 80% at 50% -10%, #1B2340 0%, #0F1424 55%, #0A0E1A 100%);
    color:#fff;padding:70px 20px 90px;text-align:center;
  }
  .hero::before, .hero::after{
    content:'';position:absolute;border-radius:50%;filter:blur(60px);opacity:.22;pointer-events:none;
  }
  .hero::before{width:420px;height:420px;background:#1B8A83;top:-160px;left:-100px;animation:eowms-float1 14s ease-in-out infinite;}
  .hero::after{width:380px;height:380px;background:#C97F1D;bottom:-180px;right:-100px;animation:eowms-float2 16s ease-in-out infinite;}
  @keyframes eowms-float1{0%,100%{transform:translate(0,0);}50%{transform:translate(40px,30px);}}
  @keyframes eowms-float2{0%,100%{transform:translate(0,0);}50%{transform:translate(-30px,-40px);}}

  .sacred-ring{
    position:absolute;top:50%;left:50%;width:640px;height:640px;transform:translate(-50%,-50%);
    border:1px solid rgba(220,239,236,.06);border-radius:50%;pointer-events:none;
    animation:eowms-ring-spin 90s linear infinite;
  }
  .sacred-ring::before{
    content:'';position:absolute;inset:40px;border:1px solid rgba(220,239,236,.05);border-radius:50%;
  }
  .sacred-ring::after{
    content:'';position:absolute;inset:78px;border:1px dashed rgba(201,127,29,.10);border-radius:50%;
  }
  @keyframes eowms-ring-spin{from{transform:translate(-50%,-50%) rotate(0deg);}to{transform:translate(-50%,-50%) rotate(360deg);}}

  /* यंत्र — Petal Ring: 16-दळी कमळ, संस्थेच्या अध्यात्मिक ओळखीचं प्रतीक —
     पानाचं एकमेव, लक्षात राहणारं Signature Element. */
  .yantra{
    position:absolute;top:50%;left:50%;width:520px;height:520px;transform:translate(-50%,-50%);
    pointer-events:none;animation:eowms-ring-spin 130s linear infinite reverse;
  }
  .yantra .petal{
    position:absolute;top:0;left:50%;width:2px;height:50%;transform-origin:bottom center;
  }
  .yantra .petal::before{
    content:'';position:absolute;top:6px;left:-1px;width:2px;height:64px;
    background:linear-gradient(to bottom, rgba(220,239,236,.16), transparent);
    border-radius:2px;
  }
  .yantra-core{
    position:absolute;top:50%;left:50%;width:200px;height:200px;transform:translate(-50%,-50%) rotate(45deg);
    border:1px solid rgba(201,127,29,.14);pointer-events:none;
  }
  .yantra-core::before{
    content:'';position:absolute;inset:26px;border:1px solid rgba(220,239,236,.10);transform:rotate(45deg);
  }

  .today-strip{
    display:inline-flex;align-items:center;gap:10px;font-family:'Space Grotesk';font-size:11px;font-weight:500;
    letter-spacing:1.2px;color:#7A8199;margin-bottom:26px;padding:7px 16px;
    border:1px solid rgba(220,239,236,.12);border-radius:30px;background:rgba(255,255,255,.03);
    animation:eowms-fade-up .8s -.1s ease both;
  }
  .today-strip .dot{width:5px;height:5px;border-radius:50%;background:#1B8A83;box-shadow:0 0 8px #1B8A83;}

  .hero-eyebrow{
    display:inline-flex;align-items:center;gap:10px;font-family:'Space Grotesk';font-size:11.5px;font-weight:600;
    letter-spacing:3px;text-transform:uppercase;color:#7FC9C2;margin-bottom:20px;
    animation:eowms-fade-up .8s -.05s ease both;
  }
  .hero-eyebrow::before,.hero-eyebrow::after{content:'';width:28px;height:1px;background:linear-gradient(90deg,transparent,#1B8A83);}
  .hero-eyebrow::after{background:linear-gradient(90deg,#1B8A83,transparent);}

  .hero-inner{position:relative;z-index:2;}

  .mark{
    width:88px;height:88px;border-radius:24px;
    background:linear-gradient(150deg,#1B8A83,#0F5E58);
    display:flex;align-items:center;justify-content:center;
    font-family:'Space Grotesk';font-weight:700;font-size:32px;margin:0 auto 24px;
    box-shadow:0 0 0 1px rgba(220,239,236,.15), 0 12px 34px rgba(27,138,131,.35);
    animation:eowms-fade-up .8s ease both;
  }
  .mark img{width:100%;height:100%;object-fit:contain;border-radius:24px;background:#fff;padding:7px;}

  .hero h1{
    font-family:'Space Grotesk';font-weight:700;font-size:36px;margin-bottom:12px;letter-spacing:-.3px;line-height:1.15;
    animation:eowms-fade-up .8s .1s ease both;
    background:linear-gradient(90deg,#fff,#DCEFEC);-webkit-background-clip:text;background-clip:text;
  }
  .hero p{color:#9FA6C0;font-size:14.5px;max-width:520px;margin:0 auto;animation:eowms-fade-up .8s .2s ease both;}

  @keyframes eowms-fade-up{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}

  .quote-card{
    max-width:600px;margin:40px auto 0;padding:34px 38px 28px;position:relative;
    background:linear-gradient(160deg,rgba(27,138,131,.16),rgba(255,255,255,.03));
    border:1px solid rgba(220,239,236,.22);border-radius:20px;
    box-shadow:0 10px 40px rgba(0,0,0,.25);
    animation:eowms-fade-up .9s .35s ease both;
  }
  .quote-card::before, .quote-card::after{
    content:'';position:absolute;width:26px;height:26px;border-color:rgba(220,239,236,.35);
  }
  .quote-card::before{top:10px;left:10px;border-top:1.5px solid;border-left:1.5px solid;border-radius:6px 0 0 0;}
  .quote-card::after{bottom:10px;right:10px;border-bottom:1.5px solid;border-right:1.5px solid;border-radius:0 0 6px 0;}
  .quote-om{
    font-family:'Space Grotesk',serif;font-size:30px;color:#DCEFEC;opacity:.9;
    margin-bottom:12px;text-shadow:0 0 24px rgba(220,239,236,.55);
    animation:eowms-glow-pulse 3.2s ease-in-out infinite;
  }
  @keyframes eowms-glow-pulse{0%,100%{opacity:.7;text-shadow:0 0 16px rgba(220,239,236,.4);}50%{opacity:1;text-shadow:0 0 30px rgba(220,239,236,.75);}}
  .quote-text{
    font-family:'Noto Sans Devanagari',serif;font-size:16px;color:#EAF3F1;
    font-style:italic;line-height:1.9;letter-spacing:.2px;
  }
  .quote-divider{display:flex;align-items:center;justify-content:center;gap:10px;margin:18px 0 10px;}
  .quote-divider span{height:1px;width:46px;background:linear-gradient(90deg,transparent,rgba(220,239,236,.45),transparent);}
  .quote-divider i{font-style:normal;color:#1B8A83;font-size:11px;}
  .quote-author{font-size:12.5px;color:#9FB0C3;font-weight:500;letter-spacing:.3px;}

  .action-row{display:flex;gap:14px;justify-content:center;margin-top:34px;flex-wrap:wrap;animation:eowms-fade-up .9s .45s ease both;}

  .birthday-wrap{max-width:900px;margin:40px auto 0;animation:eowms-fade-up .9s .5s ease both;}
  .birthday-heading{font-family:'Space Grotesk';font-size:15px;color:#FBEBD6;text-align:center;margin-bottom:16px;letter-spacing:.3px;}
  .birthday-row{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;}
  .birthday-card{
    background:linear-gradient(160deg,rgba(201,127,29,.18),rgba(255,255,255,.04));
    border:1px solid rgba(251,235,214,.3);border-radius:16px;padding:20px 22px;
    width:240px;text-align:center;box-shadow:0 8px 26px rgba(0,0,0,.16);
    transition:transform .25s ease;
  }
  .birthday-card:hover{transform:translateY(-4px);}
  .birthday-photo{
    width:72px;height:72px;border-radius:50%;object-fit:cover;margin:0 auto 12px;
    border:3px solid #C97F1D;display:block;
  }
  .birthday-avatar{
    width:72px;height:72px;border-radius:50%;margin:0 auto 12px;
    background:#C97F1D;color:#fff;display:flex;align-items:center;justify-content:center;
    font-family:'Space Grotesk';font-size:24px;font-weight:700;
  }
  .birthday-name{font-size:15px;font-weight:700;color:#fff;margin-bottom:4px;}
  .birthday-sub{font-size:11.5px;color:#DCEFEC;margin-bottom:10px;}
  .birthday-wish{font-size:12px;color:#FBEBD6;font-style:italic;}
  .wishes-list{margin-top:10px;text-align:left;max-height:110px;overflow-y:auto;}
  .wish-item{font-size:11px;color:#DCEFEC;background:rgba(255,255,255,.06);border-radius:7px;padding:6px 9px;margin-bottom:5px;line-height:1.4;word-break:break-word;}
  .wish-form{display:flex;gap:6px;margin-top:10px;}
  .wish-form input[type=text]{flex:1;min-width:0;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:7px;padding:6px 10px;font-size:11.5px;color:#fff;}
  .wish-form input[type=text]::placeholder{color:rgba(255,255,255,.5);}
  .wish-form button{background:#1B8A83;color:#fff;border:none;border-radius:7px;padding:6px 12px;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;}
  .wish-login-link{display:inline-block;margin-top:10px;font-size:11px;color:#FBEBD6;text-decoration:underline;}

  .btn-hero{
    padding:14px 32px;border-radius:12px;font-size:14.5px;font-weight:600;text-decoration:none;
    display:inline-flex;align-items:center;gap:8px;transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-hero:hover{transform:translateY(-2px);}
  .btn-login{background:linear-gradient(135deg,#1B8A83,#0F5E58);color:#fff;box-shadow:0 8px 24px rgba(27,138,131,.35);}
  .btn-login:hover{box-shadow:0 12px 30px rgba(27,138,131,.5);}
  .btn-student{background:linear-gradient(135deg,#C97F1D,#8A5A14);color:#fff;box-shadow:0 8px 24px rgba(201,127,29,.35);}
  .btn-student:hover{box-shadow:0 12px 30px rgba(201,127,29,.5);}
  .btn-request{background:rgba(255,255,255,.08);color:#fff;border:1.5px solid rgba(255,255,255,.22);backdrop-filter:blur(6px);}
  .btn-request:hover{background:rgba(255,255,255,.14);}

  .content{max-width:960px;margin:-20px auto 60px;padding:0 20px;position:relative;z-index:3;}
  .content-eyebrow{
    text-align:center;font-family:'Space Grotesk';font-size:11px;font-weight:600;letter-spacing:2px;
    text-transform:uppercase;color:#1B8A83;margin-bottom:8px;
    opacity:0;animation:eowms-fade-up .7s .05s ease both;
  }
  .content-title{
    text-align:center;font-family:'Space Grotesk';font-size:22px;font-weight:700;color:#10162B;margin-bottom:14px;
    opacity:0;animation:eowms-fade-up .7s .1s ease both;
  }
  .content-glyph{text-align:center;color:#C97F1D;font-size:12px;margin-bottom:30px;opacity:0;animation:eowms-fade-up .7s .15s ease both;}
  .card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-top:10px;}
  .info-card{
    display:block;
    background:#fff;border-radius:16px;padding:26px 24px;box-shadow:0 4px 20px rgba(16,22,43,.06);
    border-top:3px solid transparent;transition:transform .3s ease, box-shadow .3s ease, border-color .3s ease;
    opacity:0;transform:translateY(18px);
  }
  .info-card.is-visible{opacity:1;transform:translateY(0);transition:transform .6s ease, box-shadow .3s ease, border-color .3s ease, opacity .6s ease;}
  .info-card:nth-child(odd){border-top-color:#1B8A83;}
  .info-card:nth-child(even){border-top-color:#C97F1D;}
  .info-card:hover{transform:translateY(-6px);box-shadow:0 16px 36px rgba(16,22,43,.12);}
  .info-card .icon-chip{
    width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;
    margin-bottom:14px;font-size:19px;
  }
  .info-card:nth-child(odd) .icon-chip{background:#DCEFEC;color:#0F5E58;}
  .info-card:nth-child(even) .icon-chip{background:#FBEBD6;color:#8A5A14;}
  .info-card h3{font-family:'Space Grotesk';font-size:15px;font-weight:600;margin-bottom:6px;color:#10162B;}
  .info-card p{font-size:13px;color:#666B7D;margin:0;line-height:1.6;}
  footer{text-align:center;padding:26px;font-size:12px;color:#8A90AC;}

  @media (prefers-reduced-motion: reduce){
    .hero::before,.hero::after,.quote-om,.mark,.hero h1,.hero p,.quote-card,.action-row,.birthday-wrap,.sacred-ring,.yantra,.hero-eyebrow,.today-strip,.content-eyebrow,.content-title,.content-glyph{animation:none;opacity:1;}
  }
</style>
</head>
<body>
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid rgba(255,255,255,.3);border-radius:9px;padding:8px 12px;font-size:12.5px;color:#fff;background:rgba(255,255,255,.1);cursor:pointer;">
  <option value="" style="color:#000;">🌐 भाषा (Language)</option>
  <option value="en" style="color:#000;">English</option>
  <option value="hi" style="color:#000;">हिंदी (Hindi)</option>
  <option value="gu" style="color:#000;">ગુજરાતી (Gujarati)</option>
  <option value="mr" style="color:#000;">मराठी (मूळ)</option>
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

<div class="hero">
  <div class="sacred-ring"></div>
  <div class="yantra">
    <?php for ($i = 0; $i < 16; $i++): ?>
      <div class="petal" style="transform:rotate(<?= $i * 22.5 ?>deg);"></div>
    <?php endfor; ?>
  </div>
  <div class="yantra-core"></div>
  <div class="hero-inner">
  <?php if (!empty($org['logo_path'])): ?>
    <div class="mark"><img src="<?= htmlspecialchars($org['logo_path']) ?>" alt="Logo"></div>
  <?php else: ?>
    <div class="mark"><?= htmlspecialchars(mb_substr($org['name'] ?? 'EO', 0, 2)) ?></div>
  <?php endif; ?>
  <div class="today-strip"><span class="dot"></span><?= h($todayLabel) ?></div>
  <div class="hero-eyebrow">ॐ जय जंगली महाराज</div>
  <h1><?= htmlspecialchars($org['name'] ?? 'Enterprise Operations & Workflow Management System') ?></h1>
  <p><?= h(t('s145')) ?></p>

  <div class="action-row">
    <a href="login.php" class="btn-hero btn-login"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    <a href="student/login.php" class="btn-hero btn-student"><i class="fa-solid fa-graduation-cap"></i> 🎓 विद्यार्थी Login (Virtual Classroom)</a>
    <a href="request.php" class="btn-hero btn-request"><i class="fa-solid fa-clipboard-question"></i> <?= h(t('s146')) ?></a>
    <a href="track-request.php" class="btn-hero btn-request"><i class="fa-solid fa-magnifying-glass"></i> स्थिती तपासा</a>
    <a href="guests/pre-register.php" class="btn-hero btn-request"><i class="fa-solid fa-id-card-clip"></i> 🎫 पाहुणे Pre-Registration</a>
  </div>

  <?php if (!empty($birthdays)): ?>
    <div class="birthday-wrap">
      <div class="birthday-heading">🎉 आज वाढदिवस — शुभेच्छा! 🎂</div>
      <div class="birthday-row" id="wishes">
        <?php foreach ($birthdays as $b): ?>
          <div class="birthday-card">
            <?php if (!empty($b['photo_path'])): ?>
              <img src="<?= h($b['photo_path']) ?>" class="birthday-photo" alt="">
            <?php else: ?>
              <div class="birthday-avatar"><?= h(mb_substr($b['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div class="birthday-name"><?= h($b['name']) ?></div>
            <div class="birthday-sub"><?= h($b['sub_label'] ?? '') ?></div>
            <div class="birthday-wish">🎈 वाढदिवसाच्या खूप खूप शुभेच्छा!</div>
            <?php if ($b['kind'] === 'staff'): ?>
              <?php if (!empty($wishesByUser[$b['id']])): ?>
                <div class="wishes-list">
                  <?php foreach ($wishesByUser[$b['id']] as $w): if ($w['wish_type'] !== 'birthday') continue; ?>
                    <div class="wish-item">💬 <strong><?= h($w['poster_name']) ?>:</strong> <?= h($w['wish_text']) ?></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($loggedInUser): ?>
                <form method="POST" action="wish-post.php" class="wish-form">
                  <input type="hidden" name="target_user_id" value="<?= $b['id'] ?>">
                  <input type="hidden" name="wish_type" value="birthday">
                  <input type="text" name="wish_text" maxlength="200" placeholder="छोटी शुभेच्छा लिहा..." required>
                  <button type="submit">पाठवा</button>
                </form>
              <?php else: ?>
                <a href="login.php" class="wish-login-link">🔑 Login करून शुभेच्छा द्या</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($anniversaries)): ?>
    <div class="birthday-wrap">
      <div class="birthday-heading">🎊 आज कामाचा वर्धापनदिन — अभिनंदन! 🏆</div>
      <div class="birthday-row">
        <?php foreach ($anniversaries as $a): ?>
          <div class="birthday-card">
            <?php if (!empty($a['photo_path'])): ?>
              <img src="<?= h($a['photo_path']) ?>" class="birthday-photo" alt="">
            <?php else: ?>
              <div class="birthday-avatar"><?= h(mb_substr($a['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div class="birthday-name"><?= h($a['name']) ?></div>
            <div class="birthday-sub"><?= h($a['sub_label'] ?? '') ?></div>
            <div class="birthday-wish">🎊 संस्थेसोबत <?= (int)$a['years_completed'] ?> वर्षं पूर्ण — अभिनंदन!</div>
            <?php if (!empty($wishesByUser[$a['id']])): ?>
              <div class="wishes-list">
                <?php foreach ($wishesByUser[$a['id']] as $w): if ($w['wish_type'] !== 'anniversary') continue; ?>
                  <div class="wish-item">💬 <strong><?= h($w['poster_name']) ?>:</strong> <?= h($w['wish_text']) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($loggedInUser): ?>
              <form method="POST" action="wish-post.php" class="wish-form">
                <input type="hidden" name="target_user_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="wish_type" value="anniversary">
                <input type="text" name="wish_text" maxlength="200" placeholder="छोटी शुभेच्छा लिहा..." required>
                <button type="submit">पाठवा</button>
              </form>
            <?php else: ?>
              <a href="login.php" class="wish-login-link">🔑 Login करून शुभेच्छा द्या</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($quote): ?>
    <div class="quote-card">
      <div class="quote-om">ॐ</div>
      <div class="quote-text">“<?= nl2br(htmlspecialchars($quote['quote_text'])) ?>”</div>
      <div class="quote-divider"><span></span><i>❖</i><span></span></div>
      <?php if ($quote['author']): ?><div class="quote-author">— <?= htmlspecialchars($quote['author']) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div class="content">
  <div class="content-eyebrow">या System मधून काय करता येईल</div>
  <div class="content-title">रोजच्या कामकाजासाठी, एकाच ठिकाणी</div>
  <div class="content-glyph">❖</div>
  <div class="card-grid">
    <div class="info-card">
      <i class="fa-solid fa-clipboard-check"></i>
      <h3><?= h(t('s66')) ?></h3>
      <p><?= h(t('s147')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-envelope-open-text"></i>
      <h3><?= h(t('s700')) ?></h3>
      <p><?= h(t('s701')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-fingerprint"></i>
      <h3><?= h(t('s702')) ?></h3>
      <p><?= h(t('s703')) ?></p>
    </div>
    <a href="student/login.php" class="info-card" style="text-decoration:none;color:inherit;cursor:pointer;">
      <i class="fa-solid fa-chalkboard-user"></i>
      <h3><?= h(t('s704')) ?></h3>
      <p><?= h(t('s705')) ?></p>
      <div style="margin-top:10px;font-size:11.5px;font-weight:700;color:#C97F1D;">🎓 विद्यार्थी येथून Login करा →</div>
    </a>
    <div class="info-card">
      <i class="fa-solid fa-shield-halved"></i>
      <h3><?= h(t('s67')) ?></h3>
      <p><?= h(t('s148')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-list-check"></i>
      <h3><?= h(t('s149')) ?></h3>
      <p><?= h(t('s150')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-clipboard-question"></i>
      <h3><?= h(t('s151')) ?></h3>
      <p><?= h(t('s152')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-house-user"></i>
      <h3><?= h(t('s706')) ?></h3>
      <p><?= h(t('s707')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-warehouse"></i>
      <h3><?= h(t('s708')) ?></h3>
      <p><?= h(t('s709')) ?></p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-file-pdf"></i>
      <h3><?= h(t('s155')) ?></h3>
      <p><?= h(t('s156')) ?></p>
    </div>
  </div>
</div>

<footer>
  <div style="margin-bottom:4px;"><?= h(t('s68')) ?></div>
  <div>© <?= date('Y') ?> <?= htmlspecialchars($org['name'] ?? 'VJMAT') ?><?= h(t('s69')) ?></div>
  <div style="margin-top:3px;">Developed by Rahul Pawar</div>
  <div style="margin-top:8px;"><a href="about.php" style="color:#8A90AC;font-size:11.5px;text-decoration:none;">🕉️ आमच्याबद्दल (About)</a></div>
</footer>
<script src="assets/js/welcome-chime.js"></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {});
  });
}
window.addEventListener('load', function () {
  if (typeof eowmsPlayWelcomeChime === 'function') { eowmsPlayWelcomeChime('', <?= json_encode($welcomeAudioFile) ?>); }
  // eslint-disable-next-line no-console
  console.log('[EOWMS Chime Debug] PHP ला सापडलेली audio file:', <?= json_encode($welcomeAudioFile) ?>);
});
</script>
</body>
</html>
