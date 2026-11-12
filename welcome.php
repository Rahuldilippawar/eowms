<?php
require_once __DIR__ . '/includes/photo-helpers.php';
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

// सुरक्षा: वसतिगृहातील विद्यार्थी (बहुतेक अल्पवयीन) — त्यांचं नाव/फोटो/वाढदिवस
// हे सार्वजनिक (Login न केलेल्या) पानावर कधीही दिसू नये; फक्त Login केलेल्यांनाच.
if (currentUser()) try {
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
// 🔒 Welcome हे Public पान आहे — Login नसल्यास कोणाचीही नावं/फोटो (वाढदिवस, वर्धापनदिन, शुभेच्छा) दाखवू नका
// संस्थेने परवानगी दिली असेल (वाढदिवस व वर्धापनदिन पान → सेटिंग, default चालू) तर Login शिवायही
// फक्त कर्मचाऱ्यांचे — नाव, फोटो, पद. वसतिगृहातील विद्यार्थी व शुभेच्छा-संदेश कधीच नाही.
if (!$loggedInUser) {
    require_once __DIR__ . '/includes/app-settings.php';
    $publicCelebrate = (string)orgSetting($pdo, (int)($org['id'] ?? 0), 'celebration_public', '1') !== '0';
    $birthdays = $publicCelebrate ? array_values(array_filter($birthdays, fn($b) => ($b['kind'] ?? '') === 'staff')) : [];
    if (!$publicCelebrate) { $anniversaries = []; }
    $wishesByUser = [];
}

// 📊 सेवा-आकडे — फक्त Login केलेल्यांना (Public पानावर संस्थेचा Data नाही)
$publicStats = null;
if ($loggedInUser) try {
    $psOrg = (int)($org['id'] ?? 0);
    $ps = $pdo->prepare("SELECT
            SUM(status IN ('completed','closed') AND completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) done_month,
            AVG(CASE WHEN status IN ('completed','closed') AND completed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) avg_min,
            SUM(status IN ('completed','closed') AND completed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND due_at IS NOT NULL AND completed_at <= due_at) in_sla,
            SUM(status IN ('completed','closed') AND completed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND due_at IS NOT NULL) with_sla
        FROM work_requests WHERE organization_id = ?");
    $ps->execute([$psOrg]);
    $psr = $ps->fetch() ?: [];
    $dc = $pdo->prepare("SELECT (SELECT COUNT(*) FROM departments WHERE organization_id = ? AND is_active = 1) d, (SELECT COUNT(*) FROM campuses WHERE organization_id = ? AND is_active = 1) c");
    $dc->execute([$psOrg, $psOrg]);
    $dcr = $dc->fetch() ?: [];
    $avgMin = (float)($psr['avg_min'] ?? 0);
    $publicStats = [
        'done' => (int)($psr['done_month'] ?? 0),
        'avg' => !$avgMin ? null : ($avgMin >= 1440 ? round($avgMin / 1440, 1) . ' दिवस' : ($avgMin >= 60 ? round($avgMin / 60, 1) . ' तास' : round($avgMin) . ' मिनिटं')),
        'sla' => (int)($psr['with_sla'] ?? 0) ? (int)round($psr['in_sla'] * 100 / $psr['with_sla']) : null,
        'depts' => (int)($dcr['d'] ?? 0), 'campuses' => (int)($dcr['c'] ?? 0),
    ];
} catch (PDOException $e) {}

// Login केलेले असतील तर — त्यांच्यासाठी आजचं छोटं चित्र
$mySnapshot = null;
if ($loggedInUser) {
    try {
        $ms = $pdo->prepare("SELECT COUNT(*) c, SUM(target_date < CURDATE()) od FROM tasks WHERE assigned_to = ? AND status IN ('pending','in_progress')");
        $ms->execute([$loggedInUser['id']]);
        $msr = $ms->fetch();
        $ml = $pdo->prepare("SELECT COUNT(*) FROM letters WHERE current_holder_id = ? AND status IN ('in_progress','returned')");
        $ml->execute([$loggedInUser['id']]);
        $mySnapshot = ['tasks' => (int)$msr['c'], 'overdue' => (int)$msr['od'], 'letters' => (int)$ml->fetchColumn()];
    } catch (PDOException $e) {}
}

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
<meta name="apple-mobile-web-app-title" content="E-Admin">
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
  .hero::before{width:420px;height:420px;background:#D9530F;top:-160px;left:-100px;animation:eowms-float1 14s ease-in-out infinite;}
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
    display:flex;width:fit-content;margin-left:auto;margin-right:auto;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px 0;max-width:100%;
    font-family:'Noto Sans Devanagari','Inter',sans-serif;font-size:12px;font-weight:500;
    color:#9AA1BA;margin-bottom:24px;padding:7px 16px;
    border:1px solid rgba(220,239,236,.12);border-radius:30px;background:rgba(255,255,255,.03);
    animation:eowms-fade-up .8s -.1s ease both;
  }
  .today-strip .dot{width:6px;height:6px;border-radius:50%;background:#D9530F;box-shadow:0 0 8px #D9530F;margin-right:8px;flex:none;}
  .today-strip .ts-i{white-space:nowrap;}
  .today-strip .ts-i:empty{display:none;}
  .today-strip .ts-i:not(:empty) ~ .ts-i:not(:empty)::before{content:'·';margin:0 8px;color:#5A6280;}

  .hero-eyebrow{
    display:flex;width:fit-content;margin-left:auto;margin-right:auto;align-items:center;gap:12px;font-family:'Noto Sans Devanagari','Inter',sans-serif;font-size:14px;font-weight:600;
    letter-spacing:0;color:#7FC9C2;margin-bottom:16px;
    animation:eowms-fade-up .8s -.05s ease both;
  }
  .hero-eyebrow::before,.hero-eyebrow::after{content:'';width:28px;height:1px;background:linear-gradient(90deg,transparent,#D9530F);}
  .hero-eyebrow::after{background:linear-gradient(90deg,#D9530F,transparent);}

  .hero-inner{position:relative;z-index:2;}

  .mark{
    width:88px;height:88px;border-radius:24px;
    background:linear-gradient(150deg,#D9530F,#B6420A);
    display:flex;align-items:center;justify-content:center;
    font-family:'Space Grotesk';font-weight:700;font-size:32px;margin:0 auto 24px;
    box-shadow:0 0 0 1px rgba(220,239,236,.15), 0 12px 34px rgba(217,83,15,.35);
    animation:eowms-fade-up .8s ease both;
  }
  .mark img{width:100%;height:100%;object-fit:contain;border-radius:24px;background:#fff;padding:7px;}

  .hero h1{
    font-family:'Space Grotesk';font-weight:700;font-size:36px;margin-bottom:12px;letter-spacing:-.3px;line-height:1.15;
    animation:eowms-fade-up .8s .1s ease both;
    background:linear-gradient(90deg,#fff,#DCEFEC);-webkit-background-clip:text;background-clip:text;
  }
  .hero p{color:#9FA6C0;font-size:14.5px;line-height:1.7;max-width:520px;margin:0 auto;animation:eowms-fade-up .8s .2s ease both;}
  .hero h1{max-width:820px;margin-left:auto;margin-right:auto;text-wrap:balance;overflow-wrap:anywhere;}
  @media (max-width:600px){
    .hero{padding:56px 16px 72px;}
    .hero h1{font-size:clamp(22px,6.6vw,28px);letter-spacing:0;line-height:1.25;}
    .hero p{font-size:13.5px;}
    .today-strip{font-size:11.5px;padding:7px 12px;}
    .action-row{gap:10px;margin-top:26px;}
    .action-row .btn-hero{width:100%;max-width:340px;justify-content:center;padding:13px 20px;}
  }

  @keyframes eowms-fade-up{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}

  /* ---------------- 🧘 ध्यान Animation ---------------- */
  .med-wrap{position:relative;width:min(300px,72vw);margin:-20px auto 4px;animation:eowms-fade-up .9s ease both;}
  .med-art{display:block;width:100%;height:auto;overflow:visible;}
  .med-aura{transform-origin:150px 150px;transform-box:view-box;animation:med-breathe 8s ease-in-out infinite;}
  .med-figure{transform-box:view-box;transform-origin:150px 220px;animation:med-rise 8s ease-in-out infinite;}
  .med-mandala{transform-box:view-box;transform-origin:150px 150px;animation:med-spin 60s linear infinite;}
  .med-mandala.rev{animation-duration:90s;animation-direction:reverse;}
  .med-chakras circle{transform-box:fill-box;transform-origin:center;animation:med-pulse 3.85s ease-in-out infinite;}
  .med-crown{animation:med-crown 8s ease-in-out infinite !important;filter:drop-shadow(0 0 6px #E9D5FF);}
  .med-lotus{animation:med-float 6s ease-in-out infinite;}
  .med-sparks circle{transform-box:fill-box;transform-origin:center;opacity:0;animation:med-spark 6s ease-in infinite;}
  .med-breath{position:relative;height:20px;margin:6px auto 16px;font-size:12.5px;letter-spacing:.06em;color:#FDBA8C;font-weight:600;}
  .med-breath span{position:absolute;left:0;right:0;transition:opacity .9s;}
  .med-breath .in{animation:med-in 8s ease-in-out infinite;} .med-breath .out{animation:med-out 8s ease-in-out infinite;}
  @keyframes med-breathe{0%,100%{transform:scale(.86);opacity:.75;}50%{transform:scale(1.06);opacity:1;}}
  @keyframes med-rise{0%,100%{transform:scale(1);}50%{transform:scale(1.025);}}
  @keyframes med-spin{to{transform:rotate(360deg);}}
  @keyframes med-pulse{0%,100%{opacity:.55;transform:scale(.85);}50%{opacity:1;transform:scale(1.35);}}
  @keyframes med-crown{0%,100%{opacity:.4;transform:scale(.8);}50%{opacity:1;transform:scale(1.6);}}
  @keyframes med-float{0%,100%{transform:translate(150px,244px);}50%{transform:translate(150px,240px);}}
  @keyframes med-spark{0%{opacity:0;transform:translateY(0);}20%{opacity:.9;}100%{opacity:0;transform:translateY(-46px);}}
  @keyframes med-in{0%,45%{opacity:1;}50%,95%{opacity:0;}100%{opacity:1;}}
  @keyframes med-out{0%,45%{opacity:0;}50%,95%{opacity:1;}100%{opacity:0;}}
  @media (max-width:600px){ .med-wrap{width:min(250px,70vw);margin-top:-30px;} }
  @media (prefers-reduced-motion: reduce){ .med-breath .out{display:none;} }

  .quote-card{
    max-width:600px;margin:40px auto 0;padding:34px 38px 28px;position:relative;
    background:linear-gradient(160deg,rgba(217,83,15,.16),rgba(255,255,255,.03));
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
  .quote-divider i{font-style:normal;color:#D9530F;font-size:11px;}
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
  .wish-form button{background:#D9530F;color:#fff;border:none;border-radius:7px;padding:6px 12px;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;}
  .wish-login-link{display:inline-block;margin-top:10px;font-size:11px;color:#FBEBD6;text-decoration:underline;}

  .btn-hero{
    padding:14px 32px;border-radius:12px;font-size:14.5px;font-weight:600;text-decoration:none;
    display:inline-flex;align-items:center;gap:8px;transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-hero:hover{transform:translateY(-2px);}
  .btn-login{background:linear-gradient(135deg,#D9530F,#B6420A);color:#fff;box-shadow:0 8px 24px rgba(217,83,15,.35);}
  .btn-login:hover{box-shadow:0 12px 30px rgba(217,83,15,.5);}
  .btn-student{background:linear-gradient(135deg,#C97F1D,#8A5A14);color:#fff;box-shadow:0 8px 24px rgba(201,127,29,.35);}
  .btn-student:hover{box-shadow:0 12px 30px rgba(201,127,29,.5);}
  .btn-request{background:rgba(255,255,255,.08);color:#fff;border:1.5px solid rgba(255,255,255,.22);backdrop-filter:blur(6px);}
  .btn-request:hover{background:rgba(255,255,255,.14);}

  .content{max-width:960px;margin:-20px auto 60px;padding:0 20px;position:relative;z-index:3;}
  .content-eyebrow{
    text-align:center;font-family:'Noto Sans Devanagari','Inter',sans-serif;font-size:13px;font-weight:600;letter-spacing:0;
    color:#E07A3F;margin-bottom:8px;
    opacity:0;animation:eowms-fade-up .7s .05s ease both;
  }
  .content-title{
    text-align:center;font-family:'Space Grotesk';font-size:22px;font-weight:700;color:#E4E6EC;margin-bottom:14px;
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
  .info-card:nth-child(odd){border-top-color:#D9530F;}
  .info-card:nth-child(even){border-top-color:#C97F1D;}
  .info-card:hover{transform:translateY(-6px);box-shadow:0 16px 36px rgba(16,22,43,.12);}
  .info-card .icon-chip{
    width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;
    margin-bottom:14px;font-size:19px;
  }
  .info-card:nth-child(odd) .icon-chip{background:#DCEFEC;color:#B6420A;}
  .info-card:nth-child(even) .icon-chip{background:#FBEBD6;color:#8A5A14;}
  .info-card h3{font-family:'Space Grotesk';font-size:15px;font-weight:600;margin-bottom:6px;color:#10162B;}
  .info-card p{font-size:13px;color:#666B7D;margin:0;line-height:1.6;}
  footer{text-align:center;padding:26px;font-size:12px;color:#8A90AC;}

  @media (prefers-reduced-motion: reduce){
    .med-aura,.med-figure,.med-mandala,.med-chakras circle,.med-crown,.med-lotus,.med-sparks circle,.med-breath .in,.hero::before,.hero::after,.quote-om,.mark,.hero h1,.hero p,.quote-card,.action-row,.birthday-wrap,.sacred-ring,.yantra,.hero-eyebrow,.today-strip,.content-eyebrow,.content-title,.content-glyph{animation:none;opacity:1;}
  }
</style>
</head>
<body>
<div style="display:flex;justify-content:flex-end;padding:14px 16px 0;position:relative;z-index:50;"><?= i18nSwitcher('style="border:1px solid rgba(255,255,255,.3);border-radius:9px;padding:8px 12px;font-size:12.5px;color:#fff;background:rgba(255,255,255,.1);cursor:pointer;"') ?></div>
<?= i18nClientScript() ?>

<div class="hero">
  <div class="sacred-ring"></div>
  <div class="yantra">
    <?php for ($i = 0; $i < 16; $i++): ?>
      <div class="petal" style="transform:rotate(<?= $i * 22.5 ?>deg);"></div>
    <?php endfor; ?>
  </div>
  <div class="yantra-core"></div>
  <div class="hero-inner">
  <!-- 🧘 ध्यान — Animation (SVG, बाहेरचं चित्र नाही) -->
  <div class="med-wrap" aria-hidden="true">
    <svg class="med-art" viewBox="0 0 300 300" role="img" aria-label="ध्यान">
      <defs>
        <radialGradient id="medAura" cx="50%" cy="48%" r="50%"><stop offset="0%" stop-color="#FDBA74" stop-opacity=".55"/><stop offset="45%" stop-color="#F59E0B" stop-opacity=".18"/><stop offset="100%" stop-color="#F59E0B" stop-opacity="0"/></radialGradient>
        <linearGradient id="medBody" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#FDBA8C"/><stop offset="55%" stop-color="#F97316"/><stop offset="100%" stop-color="#C2410C"/></linearGradient>
        <linearGradient id="medLotus" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#FBCFE8"/><stop offset="100%" stop-color="#DB2777"/></linearGradient>
        <filter id="medGlow" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="3.2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
      </defs>
      <!-- प्रकाशवलय — श्वासासोबत -->
      <circle class="med-aura" cx="150" cy="150" r="140" fill="url(#medAura)"/>
      <!-- फिरणारं मंडल -->
      <g class="med-mandala" fill="none" stroke="#FDBA74">
        <circle cx="150" cy="150" r="132" stroke-opacity=".22" stroke-dasharray="2 7"/>
        <circle cx="150" cy="150" r="118" stroke-opacity=".14"/>
        <?php for ($i = 0; $i < 16; $i++): ?><ellipse cx="150" cy="30" rx="7" ry="16" stroke-opacity=".28" transform="rotate(<?= $i * 22.5 ?> 150 150)"/><?php endfor; ?>
      </g>
      <g class="med-mandala rev" fill="none" stroke="#FDE68A" stroke-opacity=".18">
        <?php for ($i = 0; $i < 8; $i++): ?><path d="M150 46 L158 62 L150 78 L142 62 Z" transform="rotate(<?= $i * 45 ?> 150 150)"/><?php endfor; ?>
      </g>
      <!-- ध्यानस्थ आकृती -->
      <g class="med-figure" filter="url(#medGlow)">
        <path d="M84 216 C108 194 192 194 216 216 C200 236 100 236 84 216 Z" fill="url(#medBody)" opacity=".95"/>
        <path d="M150 110 C127 110 115 121 113 140 L123 196 L177 196 L187 140 C185 121 173 110 150 110 Z" fill="url(#medBody)"/>
        <path d="M117 134 C99 150 93 170 101 200" stroke="url(#medBody)" stroke-width="14" stroke-linecap="round" fill="none"/>
        <path d="M183 134 C201 150 207 170 199 200" stroke="url(#medBody)" stroke-width="14" stroke-linecap="round" fill="none"/>
        <circle cx="103" cy="204" r="8" fill="#FDBA8C"/><circle cx="197" cy="204" r="8" fill="#FDBA8C"/>
        <rect x="143" y="100" width="14" height="14" rx="5" fill="#FDBA8C"/>
        <circle cx="150" cy="86" r="21" fill="url(#medBody)"/>
      </g>
      <!-- सप्तचक्र -->
      <g class="med-chakras">
        <?php foreach ([[206, '#EF4444'], [190, '#F97316'], [174, '#FACC15'], [156, '#22C55E'], [137, '#38BDF8'], [120, '#6366F1'], [84, '#A855F7']] as $ci => [$cy, $cc]): ?>
          <circle cx="150" cy="<?= $cy ?>" r="<?= $ci === 6 ? 3.4 : 4 ?>" fill="<?= $cc ?>" style="animation-delay:<?= $ci * .55 ?>s"/>
        <?php endforeach; ?>
        <circle class="med-crown" cx="150" cy="58" r="5" fill="#F5F3FF"/>
      </g>
      <!-- कमळ -->
      <g class="med-lotus" transform="translate(150 244)">
        <?php foreach ([-60, -35, -12, 12, 35, 60] as $a): ?><path d="M0 0 C-10 -14 -8 -30 0 -38 C8 -30 10 -14 0 0 Z" fill="url(#medLotus)" opacity=".85" transform="rotate(<?= $a ?>)"/><?php endforeach; ?>
        <path d="M0 0 C-12 -16 -9 -36 0 -46 C9 -36 12 -16 0 0 Z" fill="url(#medLotus)"/>
        <ellipse cx="0" cy="4" rx="58" ry="6" fill="#0F766E" opacity=".55"/>
      </g>
      <!-- तरंगणारे प्रकाशकण -->
      <g class="med-sparks" fill="#FDE68A">
        <?php foreach ([[62, 220, 0], [238, 210, 1.2], [84, 120, 2.4], [222, 112, .6], [46, 160, 3.1], [258, 166, 1.8], [118, 52, 2.8], [190, 48, .3]] as [$x, $y, $d]): ?><circle cx="<?= $x ?>" cy="<?= $y ?>" r="2.2" style="animation-delay:<?= $d ?>s"/><?php endforeach; ?>
      </g>
    </svg>
  </div>
  <div class="med-breath" id="medBreath"><span class="in">🌬️ श्वास घ्या…</span><span class="out">🍃 श्वास सोडा…</span></div>
  <div class="today-strip"><span class="dot"></span><span id="wlGreet" class="ts-i"></span><span class="ts-i"><?= h($todayLabel) ?></span><span id="wlClock" class="ts-i wl-clock"></span></div>
  <div class="hero-eyebrow">!! आत्मा मालिक !!</div>
  <h1><?= htmlspecialchars($org['name'] ?? 'E-Admin — Smart Administration') ?></h1>
  <p><?= h(t('s145')) ?></p>

  <div class="action-row">
    <?php if ($loggedInUser): ?>
      <a href="index.php" class="btn-hero btn-login"><i class="fa-solid fa-gauge-high"></i> Dashboard उघडा</a>
    <?php else: ?>
      <a href="login.php" class="btn-hero btn-login"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    <?php endif; ?>
    <a href="request.php" class="btn-hero btn-request"><i class="fa-solid fa-clipboard-question"></i> <?= h(t('s146')) ?></a>
    <a href="track-request.php" class="btn-hero btn-request"><i class="fa-solid fa-magnifying-glass"></i> स्थिती तपासा</a>
    <a href="guests/pre-register.php" class="btn-hero btn-request"><i class="fa-solid fa-id-card-clip"></i> 🎫 पाहुणे Pre-Registration</a>
  </div>

  <?php if ($mySnapshot): ?>
    <div class="wl-me">
      <span>👋 <?= h(explode(' ', $loggedInUser['name'])[0]) ?>, तुमच्यासाठी आज:</span>
      <a href="tasks/my-tasks.php"><b><?= $mySnapshot['tasks'] ?></b> उघडे Tasks<?= $mySnapshot['overdue'] ? ' <em>(' . $mySnapshot['overdue'] . ' मुदत संपलेले)</em>' : '' ?></a>
      <a href="letters/index.php"><b><?= $mySnapshot['letters'] ?></b> पत्रं प्रलंबित</a>
    </div>
  <?php endif; ?>

  <form class="wl-track" action="track-request.php" method="GET" role="search">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="text" name="code" placeholder="विनंती क्रमांक (उदा. REQ-…)" required maxlength="50" aria-label="विनंती क्रमांक">
    <button type="submit">स्थिती पहा</button>
  </form>
  <button type="button" class="wl-install" id="wlInstall" hidden><i class="fa-solid fa-mobile-screen-button"></i> 📲 App म्हणून Install करा</button>

  <?php if (!empty($birthdays)): ?>
    <div class="birthday-wrap">
      <div class="birthday-heading">🎉 आज वाढदिवस — शुभेच्छा! 🎂</div>
      <div class="birthday-row" id="wishes">
        <?php foreach ($birthdays as $b): ?>
          <div class="birthday-card">
            <?php if (eowmsPhotoExists($b['photo_path'] ?? null)): ?>
              <img src="<?= h(ltrim(preg_replace('#^(\.\./)+#', '', $b['photo_path']), '/')) ?>" class="birthday-photo" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><div class="birthday-avatar" style="display:none;"><?= h(mb_substr($b['name'], 0, 1)) ?></div>
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
            <?php if (eowmsPhotoExists($a['photo_path'] ?? null)): ?>
              <img src="<?= h(ltrim(preg_replace('#^(\.\./)+#', '', $a['photo_path']), '/')) ?>" class="birthday-photo" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><div class="birthday-avatar" style="display:none;"><?= h(mb_substr($a['name'], 0, 1)) ?></div>
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

<?php if ($publicStats && ($publicStats['done'] || $publicStats['depts'])): ?>
<div class="wl-stats">
  <div><b data-count="<?= $publicStats['done'] ?>"><?= $publicStats['done'] ?></b><span>विनंत्या पूर्ण — या महिन्यात</span></div>
  <?php if ($publicStats['avg']): ?><div><b><?= h($publicStats['avg']) ?></b><span>सरासरी निपटारा-वेळ</span></div><?php endif; ?>
  <?php if ($publicStats['sla'] !== null): ?><div><b><?= $publicStats['sla'] ?>%</b><span>विनंत्या मुदतीत पूर्ण</span></div><?php endif; ?>
  <div><b data-count="<?= $publicStats['depts'] ?>"><?= $publicStats['depts'] ?></b><span>विभाग<?= $publicStats['campuses'] > 1 ? ' · ' . $publicStats['campuses'] . ' College/School' : '' ?></span></div>
</div>
<?php endif; ?>

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
<style>
  .wl-clock{font-family:'JetBrains Mono',monospace;}
  .wl-me{display:flex;flex-wrap:wrap;gap:8px 14px;justify-content:center;align-items:center;margin:16px auto 0;max-width:640px;font-size:13px;color:#DCE1F0;}
  .wl-me a{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:20px;padding:6px 14px;color:#fff;text-decoration:none;}
  .wl-me a:hover{background:rgba(255,255,255,.18);}
  .wl-me em{color:#F3A6B0;font-style:normal;}
  .wl-track{display:flex;align-items:center;gap:8px;max-width:560px;margin:20px auto 0;background:rgba(255,255,255,.96);border-radius:14px;padding:6px 6px 6px 16px;box-shadow:0 10px 30px rgba(0,0,0,.25);}
  .wl-track i{color:#6B7080;}
  .wl-track input{flex:1;border:0;outline:none;font-size:14px;padding:10px 4px;background:transparent;color:#10162B;min-width:0;font-family:inherit;}
  .wl-track button{border:0;background:#D9530F;color:#fff;font-weight:700;border-radius:10px;padding:10px 18px;font-size:13.5px;cursor:pointer;white-space:nowrap;font-family:inherit;}
  .wl-track button:hover{filter:brightness(1.08);}
  .wl-install{display:block;margin:14px auto 0;border:1px dashed rgba(255,255,255,.4);background:transparent;color:#fff;border-radius:10px;padding:8px 16px;font-size:13px;cursor:pointer;font-family:inherit;}
  .wl-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;max-width:1000px;margin:-38px auto 46px;padding:0 20px;position:relative;z-index:3;}
  .wl-stats>div{background:#fff;border-radius:14px;padding:18px 16px;text-align:center;box-shadow:0 12px 30px rgba(16,22,43,.12);border:1px solid #E4E6EC;}
  .wl-stats b{display:block;font-family:'Space Grotesk',sans-serif;font-size:28px;color:#D9530F;}
  .wl-stats span{font-size:12.5px;color:#6B7080;font-weight:600;}
  @media (max-width:600px){.wl-track{margin-left:0;margin-right:0;padding-left:12px;}.wl-track button{padding:10px 14px;}.wl-stats{margin-top:-24px;}}
</style>
<script>
(function () {
  // वेळेनुसार नमस्कार + चालू घड्याळ
  var g = document.getElementById('wlGreet'), c = document.getElementById('wlClock');
  function tick() {
    var d = new Date(), h = d.getHours();
    if (g) g.textContent = (h < 12 ? '☀️ शुभ सकाळ' : h < 17 ? '🌤️ नमस्कार' : h < 21 ? '🌇 शुभ संध्याकाळ' : '🌙 शुभ रात्री');
    if (c) c.textContent = d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }
  tick(); setInterval(tick, 30000);
  // आकडे हळूहळू वाढताना
  document.querySelectorAll('.wl-stats b[data-count]').forEach(function (b) {
    var n = parseInt(b.getAttribute('data-count'), 10) || 0, i = 0, steps = 30;
    if (n < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var t = setInterval(function () { i++; b.textContent = Math.round(n * i / steps); if (i >= steps) clearInterval(t); }, 25);
  });
  // माहिती-Cards — Scroll करून दिसू लागले की हळूच दाखवतो (आधी हे कधीच दिसत नव्हते: opacity 0 राहायचं)
  var cards = document.querySelectorAll('.info-card');
  if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en, i) {
        if (!en.isIntersecting) return;
        setTimeout(function () { en.target.classList.add('is-visible'); }, (i % 3) * 90);
        io.unobserve(en.target);
      });
    }, { threshold: 0.12 });
    cards.forEach(function (cd) { io.observe(cd); });
  } else {
    cards.forEach(function (cd) { cd.classList.add('is-visible'); });
  }
  // 📲 App Install (Chrome/Android) — Browser परवानगी देईल तेव्हाच बटण दिसतं
  var deferred = null, btn = document.getElementById('wlInstall');
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; if (btn) btn.hidden = false; });
  if (btn) btn.addEventListener('click', function () { if (!deferred) return; deferred.prompt(); deferred.userChoice.finally(function () { deferred = null; btn.hidden = true; }); });
})();
</script>
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
