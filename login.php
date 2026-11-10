<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/two-factor.php';

if (currentUser()) {
    header('Location: index.php');
    exit;
}

$error = null;
// Logout आधी ज्या पानावर होता — Login नंतर तिथेच परत (फक्त याच Site मधलं पान)
if (isset($_GET['next'])) { $_SESSION['login_next'] = safeNextPath($_GET['next']); }
if (($_GET['msg'] ?? '') === 'session_expired') {
    $error = t('s456');
} elseif (($_GET['msg'] ?? '') === 'tfa_locked') {
    $error = 'खूप वेळा चुकीचा 2-Step Code टाकला — सुरक्षिततेसाठी खाते १५ मिनिटांसाठी तात्पुरते बंद केले आहे.';
}

/** अयशस्वी Login ची नोंद — त्या Email ची संस्था सापडली तर तिच्याच Activity Log मध्ये */
function logFailedLogin($pdo, $email, $reason) {
    try {
        $oStmt = $pdo->prepare("SELECT id, organization_id FROM users WHERE email = ? LIMIT 1");
        $oStmt->execute([$email]);
        $oRow = $oStmt->fetch();
        $pdo->prepare("INSERT INTO activity_logs (organization_id, user_id, actor_name, action, entity_type, entity_id, details, created_at) VALUES (?, NULL, ?, ?, 'user', ?, ?, ?)")
            ->execute([$oRow['organization_id'] ?? null, mb_substr($email, 0, 255), 'Login अयशस्वी', $oRow['id'] ?? null, $reason . ' — IP: ' . clientIp(), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        // activity_logs table नसेल तर शांतपणे वगळा
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // 🔒 एकाच IP वरून १५ मिनिटांत २० पेक्षा जास्त चुकीचे प्रयत्न — Brute-force
    // (अनेक खाती एकामागून एक तपासणं) रोखण्यासाठी तो IP तात्पुरता थांबवतो.
    if (publicFormRateLimited('login_fail', 20, 900)) {
        $error = 'या नेटवर्कवरून खूप चुकीचे Login प्रयत्न झाले — कृपया १५ मिनिटांनी पुन्हा प्रयत्न करा.';
    } else {
    $stmt = $pdo->prepare("SELECT u.*, o.is_active AS org_is_active FROM users u LEFT JOIN organizations o ON o.id = u.organization_id WHERE u.email = ? AND u.is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $orgIsActive = $user ? ((int)($user['org_is_active'] ?? 0) === 1) : false;
    if ($user) { unset($user['org_is_active']); }

    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        logFailedLogin($pdo, $email, 'खाते Lock असताना प्रयत्न');
        // Account is currently locked out — don't even check the password,
        // and don't reveal exactly why to avoid helping an attacker guess.
        $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        $error = "खूप चुकीचे प्रयत्न झाल्यामुळे हे खाते तात्पुरते बंद केले आहे — कृपया $minutesLeft मिनिटांनी पुन्हा प्रयत्न करा.";
    } elseif ($user && !$orgIsActive && password_verify($password, $user['password'])) {
        // संस्था निष्क्रिय केलेली आहे — बरोबर पासवर्ड असला तरी प्रवेश नाही
        logFailedLogin($pdo, $email, 'संस्था निष्क्रिय');
        $error = 'तुमची संस्था सध्या निष्क्रिय (Inactive) आहे — Login करता येणार नाही. कृपया Super Admin शी संपर्क साधा.';
    } elseif ($user && password_verify($password, $user['password'])) {
        // बरोबर पासवर्ड — 2-Step चालू असेल (आणि हा Browser विश्वासू नसेल) तर आधी Code
        if (tfaReady($pdo) && tfaEnabled($user) && !tfaDeviceTrusted($pdo, $user['id'])) {
            session_regenerate_id(true);
            unset($_SESSION['tfa_email']);
            $_SESSION['tfa_pending'] = ['uid' => (int)$user['id'], 'at' => time(), 'tries' => 0];
            header('Location: login-2fa.php');
            exit;
        }
        eowmsCompleteLogin($pdo, $user, tfaEnabled($user) ? 'Password + विश्वासू Device' : 'Password');
    } else {
        publicFormRecordSubmission('login_fail');
        logFailedLogin($pdo, $email, $user ? 'चुकीचा पासवर्ड' : 'अज्ञात / निष्क्रिय खाते');
        if ($user) {
            // Wrong password for a real account — count the attempt
            // आधीचा Lockout संपला असेल तर मोजणी शून्यापासून — नाहीतर पुढच्याच
            // एका चुकीनंतर खाते पुन्हा लगेच Lock होतं
            $priorAttempts = !empty($user['locked_until']) ? 0 : (int)$user['failed_login_attempts'];
            $attempts = $priorAttempts + 1;
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                    ->execute([$attempts, $user['id']]);
                $error = "खूप चुकीचे प्रयत्न झाले — सुरक्षिततेसाठी हे खाते १५ मिनिटांसाठी तात्पुरते बंद केले आहे.";
                // 🚨 Super Admin ला लगेच सूचना
                try {
                    $saIds = notifyUserIdsByCategory((int)$user['organization_id'], ['super_admin']);
                    notifyUsers($saIds, (int)$user['organization_id'], '🚨 खातं Lock झालं: ' . $user['name'], '5 वेळा चुकीचा Password — IP ' . clientIp() . '. सुरक्षा इशारे पानावरून Unlock करता येईल.', 'admin/security.php');
                } catch (Throwable $e) {}
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = NULL WHERE id = ?")->execute([$attempts, $user['id']]);
                $remaining = 5 - $attempts;
                $error = "चुकीचा ईमेल किंवा पासवर्ड — $remaining प्रयत्न शिल्लक आहेत, त्यानंतर खाते तात्पुरते बंद होईल.";
            }
        } else {
            $error = t('s457');
        }
    }
    }
}

// Pre-login — use the primary/first organization's name for branding (typical single-org deployment)
$loginOrg = $pdo->query("SELECT name FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$loginOrgName = $loginOrg['name'] ?? 'VJMAT';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(t('s157')) ?> <?= h($loginOrgName) ?></title>
<link rel="icon" type="image/png" href="assets/brand/favicon-32.png">
<script>
  // App मधल्या Theme Studio च्या निवडी (रंग + Mode) Login पानावरही — निवड नसेल तर Device नुसार
  (function () {
    var h = document.documentElement, g = function (k) { try { return localStorage.getItem('eowms_' + k); } catch (e) { return null; } };
    var t = g('theme'), sys = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
    if (t === 'dark' || ((t === 'auto' || !t) && sys)) h.setAttribute('data-theme', 'dark');
    var a = g('accent'); if (a) h.setAttribute('data-accent', a);
  })();
</script>
<meta name="theme-color" content="#0B1224">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --lg-teal:#D9530F;--lg-teal-d:#B6420A;--lg-teal-l:#F7A574;--lg-amber:#E0A043;--lg-ink:#0F172A;--lg-muted:#64708A;
    --lg-side:#F7F5F2;--lg-card:#ffffff;--lg-line:#EAE5DF;--lg-field:#F8FAFC;--lg-text:#0F172A;--lg-sub:#475069;
    --lg-err-bg:#FDECEC;--lg-err:#B42318;--lg-ok-bg:#E7F6EF;--lg-ok:#137A4B;--lg-warn-bg:#FFF4E0;--lg-warn:#9A5B07;
    --lg-brand:linear-gradient(160deg,#1C1F40 0%,#13152D 55%,#0C0D20 100%);
  }
  /* Theme Studio मधला मुख्य रंग — Login पानावरही तोच */
  html[data-accent="blue"]{--lg-teal:#2563EB;--lg-teal-d:#1D4ED8;--lg-teal-l:#8CB0F5;}
  html[data-accent="teal"]{--lg-teal:#138A80;--lg-teal-d:#0E6F67;--lg-teal-l:#5ED3C8;}
  html[data-accent="purple"]{--lg-teal:#7C3AED;--lg-teal-d:#6025D0;--lg-teal-l:#C4A6F9;}
  html[data-accent="green"]{--lg-teal:#15803D;--lg-teal-d:#116A32;--lg-teal-l:#78D7A0;}
  html[data-accent="maroon"]{--lg-teal:#A3123A;--lg-teal-d:#850E2F;--lg-teal-l:#F08BA6;}
  html[data-theme="dark"]{--lg-side:#0B1020;--lg-card:#141B2D;--lg-line:#253049;--lg-field:#0F1626;--lg-text:#E3E8F2;--lg-sub:#B7BFD6;--lg-muted:#8E99B6;
    --lg-err-bg:rgba(240,68,56,.14);--lg-err:#FDA29B;--lg-ok-bg:rgba(18,183,106,.14);--lg-ok:#6CE9A6;--lg-warn-bg:rgba(247,144,9,.14);--lg-warn:#FEC84B;color-scheme:dark;}
  html[data-theme="dark"] .lg-card{box-shadow:0 30px 60px -20px rgba(0,0,0,.6);}
  html[data-theme="dark"] .lg-btn{--lg-teal-d:color-mix(in srgb,var(--lg-teal) 70%,#000);}
  *{box-sizing:border-box;}
  html,body{margin:0;min-height:100%;}
  body{min-height:100vh;background:var(--lg-side);color:var(--lg-text);font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;}
  .lg-wrap{min-height:100vh;display:grid;grid-template-columns:1.1fr 1fr;}

  /* ---------- डावी बाजू : ब्रँड ---------- */
  .lg-brand{position:relative;overflow:hidden;color:#fff;padding:48px 56px 36px;display:flex;flex-direction:column;justify-content:space-between;gap:32px;background:var(--lg-brand);isolation:isolate;}
  .lg-brand::after{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--lg-teal),var(--lg-amber),var(--lg-teal));opacity:.9;}
  .lg-blob{position:absolute;border-radius:50%;filter:blur(70px);opacity:.55;z-index:-1;animation:lgFloat 18s ease-in-out infinite alternate;}
  .lg-blob.b1{width:460px;height:460px;background:var(--lg-teal);left:-120px;top:-140px;opacity:.45;}
  .lg-blob.b2{width:380px;height:380px;background:#E0A043;right:-120px;bottom:-120px;opacity:.26;animation-delay:-6s;}
  .lg-blob.b3{width:300px;height:300px;background:#3B5BDB;right:12%;top:30%;opacity:.18;animation-delay:-12s;}
  @keyframes lgFloat{0%{transform:translate(0,0) scale(1);}100%{transform:translate(40px,30px) scale(1.12);}}
  .lg-grid{position:absolute;inset:0;z-index:-1;opacity:.5;background-image:radial-gradient(rgba(255,255,255,.12) 1px,transparent 1px);background-size:22px 22px;
    -webkit-mask-image:radial-gradient(ellipse at 30% 40%,#000 20%,transparent 75%);mask-image:radial-gradient(ellipse at 30% 40%,#000 20%,transparent 75%);}
  .lg-org{display:flex;align-items:center;gap:14px;}
  .lg-logo{width:54px;height:54px;border-radius:16px;background:rgba(255,255,255,.95);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,0,0,.25);flex-shrink:0;}
  .lg-logo img{width:42px;height:42px;object-fit:contain;}
  .lg-org b{display:block;font-family:'Space Grotesk','Noto Sans Devanagari';font-size:18px;font-weight:700;letter-spacing:.01em;}
  .lg-org span{font-size:12.5px;color:#A9B0CC;}
  .lg-mid{max-width:520px;}
  .lg-mantra{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:color-mix(in srgb,var(--lg-teal-l) 12%,transparent);border:1px solid color-mix(in srgb,var(--lg-teal-l) 30%,transparent);color:var(--lg-teal-l);font-size:13px;font-weight:600;letter-spacing:.06em;margin-bottom:18px;}
  .lg-mid h1{font-family:'Space Grotesk','Noto Sans Devanagari';font-size:36px;font-weight:700;line-height:1.3;margin:0 0 14px;}
  .lg-mid h1 em{font-style:normal;background:linear-gradient(90deg,var(--lg-teal-l),#F2C27B);-webkit-background-clip:text;background-clip:text;color:transparent;}
  .lg-mid p{color:#B7BDD6;font-size:14.5px;line-height:1.8;margin:0 0 26px;max-width:460px;}
  .lg-feat{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .lg-feat div{display:flex;gap:11px;align-items:flex-start;padding:13px 14px;border-radius:14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);backdrop-filter:blur(6px);font-size:13px;line-height:1.5;color:#DCE1F0;transition:background .2s,transform .2s;}
  .lg-feat div:hover{background:rgba(255,255,255,.09);transform:translateY(-2px);}
  .lg-feat i{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
  .lg-feat b{display:block;color:#fff;font-size:13.5px;font-weight:600;}
  .lg-feat small{color:#9FA6C0;font-size:12px;}
  .ic-teal{background:color-mix(in srgb,var(--lg-teal) 28%,transparent);color:var(--lg-teal-l);} .ic-amber{background:rgba(224,160,67,.2);color:#F2C27B;}
  .ic-blue{background:rgba(76,95,213,.25);color:#A9B5FF;} .ic-rose{background:rgba(232,90,112,.2);color:#FFA3B1;}
  .lg-foot{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
  .lg-clock{display:flex;align-items:center;gap:14px;padding:12px 16px;border-radius:14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);}
  .lg-clock .t{font-family:'Space Grotesk',monospace;font-size:24px;font-weight:600;line-height:1;}
  .lg-clock .d{font-size:12px;color:#A9B0CC;line-height:1.5;}
  .lg-clock .d b{display:block;color:#fff;font-size:13px;font-weight:600;}
  .lg-copy{font-size:11.5px;color:#7D84A3;}

  /* ---------- उजवी बाजू : फॉर्म ---------- */
  .lg-form-side{position:relative;display:flex;align-items:center;justify-content:center;padding:40px 24px;}
  .lg-card{width:100%;max-width:420px;background:var(--lg-card);border:1px solid var(--lg-line);border-radius:22px;padding:30px 30px 24px;box-shadow:0 30px 60px -20px rgba(16,22,43,.18),0 2px 6px rgba(16,22,43,.04);animation:lgUp .5s cubic-bezier(.2,.8,.2,1) both;}
  @keyframes lgUp{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:none;}}
  .lg-card.shake{animation:lgShake .45s ease both;}
  @keyframes lgShake{0%,100%{transform:none;}20%,60%{transform:translateX(-7px);}40%,80%{transform:translateX(7px);}}
  .lg-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:20px;}
  .lg-badge{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--lg-ok);background:var(--lg-ok-bg);padding:5px 11px;border-radius:999px;}
  .lg-badge .dot{width:7px;height:7px;border-radius:50%;background:#12B76A;box-shadow:0 0 0 3px rgba(18,183,106,.25);}
  .lg-lang{position:relative;margin-left:auto;}
  .lg-lang i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--lg-muted);font-size:12px;pointer-events:none;}
  .lg-lang select{appearance:none;-webkit-appearance:none;border:1px solid var(--lg-line);border-radius:999px;padding:6px 28px 6px 12px;font-size:12px;color:var(--lg-sub);background:var(--lg-field) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238E95AE' fill='none' stroke-width='1.5'/%3E%3C/svg%3E") no-repeat right 11px center;cursor:pointer;font-family:inherit;}
  .lg-tabs{display:grid;grid-template-columns:1fr 1fr;background:var(--lg-field);border:1px solid var(--lg-line);border-radius:12px;padding:4px;margin-bottom:22px;}
  .lg-tabs a,.lg-tabs span{display:flex;align-items:center;justify-content:center;gap:7px;padding:8px;border-radius:9px;font-size:13px;font-weight:600;color:var(--lg-muted);text-decoration:none;}
  .lg-tabs span{background:var(--lg-card);color:var(--lg-teal);box-shadow:0 1px 3px rgba(16,22,43,.1);}
  .lg-tabs a:hover{color:var(--lg-text);}
  .lg-card h2{font-family:'Space Grotesk','Noto Sans Devanagari';font-size:23px;font-weight:700;margin:0 0 4px;color:var(--lg-text);}
  .lg-card .sub{font-size:13px;color:var(--lg-muted);margin-bottom:20px;}

  .lg-msg{display:flex;gap:10px;align-items:flex-start;padding:11px 13px;border-radius:12px;font-size:13px;line-height:1.55;margin-bottom:16px;}
  .lg-msg i{margin-top:3px;}
  .lg-msg.err{background:var(--lg-err-bg);color:var(--lg-err);} .lg-msg.ok{background:var(--lg-ok-bg);color:var(--lg-ok);} .lg-msg.warn{background:var(--lg-warn-bg);color:var(--lg-warn);}

  /* Floating label fields */
  .lg-field{position:relative;margin-bottom:14px;}
  .lg-field input{width:100%;height:56px;border:1.5px solid var(--lg-line);border-radius:13px;padding:20px 46px 6px 44px;font-size:15px;background:var(--lg-field);font-family:inherit;color:var(--lg-text);transition:border-color .15s,box-shadow .15s,background .15s;}
  .lg-field label{position:absolute;left:44px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--lg-muted);pointer-events:none;transition:all .15s ease;}
  .lg-field input:focus + label,.lg-field input:not(:placeholder-shown) + label{top:17px;font-size:11px;font-weight:600;color:var(--lg-teal);}
  .lg-field input:focus{outline:none;border-color:var(--lg-teal);box-shadow:0 0 0 4px color-mix(in srgb,var(--lg-teal) 15%,transparent);background:var(--lg-card);}
  .lg-field .lead{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--lg-muted);font-size:15px;transition:color .15s;}
  .lg-field input:focus ~ .lead{color:var(--lg-teal);}
  .lg-field.bad input{border-color:#E5484D;} .lg-field.bad input:focus{box-shadow:0 0 0 4px rgba(229,72,77,.15);}
  .lg-field .ok-mark{position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#12B76A;font-size:15px;opacity:0;transition:opacity .15s;}
  .lg-field.good .ok-mark{opacity:1;}
  .lg-hint{font-size:11.5px;color:#E5484D;margin:-8px 0 10px 4px;display:none;}
  .lg-field.bad + .lg-hint{display:block;}
  .lg-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:var(--lg-muted);width:36px;height:36px;cursor:pointer;border-radius:9px;}
  .lg-eye:hover{color:var(--lg-teal);background:color-mix(in srgb,var(--lg-teal) 8%,transparent);}
  .lg-caps{display:none;align-items:center;gap:6px;font-size:12px;color:var(--lg-warn);background:var(--lg-warn-bg);border-radius:8px;padding:5px 10px;font-weight:600;margin:-6px 0 12px;}

  .lg-row{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 20px;font-size:13px;}
  .lg-row label{display:flex;align-items:center;gap:8px;color:var(--lg-sub);cursor:pointer;}
  .lg-row input{accent-color:var(--lg-teal);width:16px;height:16px;}
  .lg-row a{color:var(--lg-teal);font-weight:600;text-decoration:none;} .lg-row a:hover{text-decoration:underline;}
  .lg-btn{position:relative;overflow:hidden;width:100%;height:52px;border:0;border-radius:13px;font-size:15.5px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--lg-teal),var(--lg-teal-d));cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-family:inherit;box-shadow:0 10px 24px -8px color-mix(in srgb,var(--lg-teal) 60%,transparent);transition:filter .15s,transform .1s,box-shadow .15s;}
  .lg-btn:hover{filter:brightness(1.07);box-shadow:0 14px 28px -8px color-mix(in srgb,var(--lg-teal) 70%,transparent);}
  .lg-btn:active{transform:translateY(1px);}
  .lg-btn .arr{transition:transform .2s;} .lg-btn:hover .arr{transform:translateX(4px);}
  .lg-btn::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.25),transparent);transform:skewX(-20deg);transition:left .6s;}
  .lg-btn:hover::after{left:120%;}
  .lg-btn[disabled]{opacity:.8;cursor:progress;}
  .lg-btn:focus-visible,.lg-eye:focus-visible,.lg-tabs a:focus-visible{outline:3px solid color-mix(in srgb,var(--lg-teal) 45%,transparent);outline-offset:2px;}

  .lg-links{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:22px;}
  .lg-links a{display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 6px;border-radius:12px;border:1px solid var(--lg-line);color:var(--lg-sub);text-decoration:none;font-size:11.5px;text-align:center;transition:border-color .15s,color .15s,transform .15s;}
  .lg-links a i{font-size:15px;color:var(--lg-teal);}
  .lg-links a:hover{border-color:var(--lg-teal);color:var(--lg-teal);transform:translateY(-2px);}
  .lg-secure{display:flex;align-items:center;justify-content:center;gap:7px;font-size:11.5px;color:var(--lg-muted);margin-top:16px;text-align:center;}
  .lg-secure i{color:#12B76A;}

  @media (max-width:980px){
    .lg-wrap{grid-template-columns:1fr;}
    .lg-brand{padding:26px 20px 70px;gap:14px;}
    .lg-mid h1{font-size:22px;margin:0;} .lg-mid p,.lg-feat,.lg-foot,.lg-mantra{display:none;}
    .lg-form-side{padding:0 16px 32px;margin-top:-52px;align-items:flex-start;}
    .lg-card{padding:24px 20px 20px;border-radius:20px;}
  }
  @media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none !important;transition:none !important;}}
</style>
</head>
<body>
<?= i18nClientScript() ?>

<div class="lg-wrap">
  <aside class="lg-brand">
    <div class="lg-blob b1"></div><div class="lg-blob b2"></div><div class="lg-blob b3"></div><div class="lg-grid"></div>
    <div class="lg-org">
      <div class="lg-logo"><img src="assets/brand/e-admin-mark.png" alt="E-Admin"></div>
      <div><b><?= h($loginOrgName) ?></b><span>E-Admin — Smart Administration</span></div>
    </div>
    <div class="lg-mid">
      <div class="lg-mantra">🪷 !! आत्मा मालिक !!</div>
      <h1>संस्थेचं सगळं कामकाज — <em>एकाच ठिकाणी</em></h1>
      <p>रोजची कामं, Tasks, पत्रव्यवहार, हजेरी, नूतनीकरण आणि अहवाल — सुरक्षित, जलद आणि सोप्या पद्धतीने.</p>
      <div class="lg-feat">
        <div><i class="fa-solid fa-gauge-high ic-teal"></i><span><b>Smart Dashboard</b><small>आजचं संपूर्ण चित्र एका नजरेत</small></span></div>
        <div><i class="fa-solid fa-list-check ic-amber"></i><span><b>Tasks व विनंत्या</b><small>मुदतींची आपोआप आठवण</small></span></div>
        <div><i class="fa-solid fa-chart-pie ic-blue"></i><span><b>अहवाल</b><small>कर्मचारी व विभागनिहाय</small></span></div>
        <div><i class="fa-solid fa-shield-halved ic-rose"></i><span><b>सुरक्षित</b><small>चुकीच्या प्रयत्नांवर आपोआप Lock</small></span></div>
      </div>
    </div>
    <div class="lg-foot">
      <div class="lg-clock"><div class="t" id="lgClock">--:--</div><div class="d"><b id="lgGreet"></b><span id="lgDate"></span></div></div>
      <div class="lg-copy">© <?= date('Y') ?> <?= h($loginOrgName) ?></div>
    </div>
  </aside>

  <main class="lg-form-side">
    <div class="lg-card<?= $error ? ' shake' : '' ?>" id="lgCard">
      <div class="lg-top">
        <div class="lg-badge"><span class="dot"></span> सुरक्षित प्रवेश</div>
        <div class="lg-lang">
          <?= i18nSwitcher('') ?>
        </div>
      </div>

      <h2>पुन्हा स्वागत आहे 🙏</h2>
      <div class="sub">पुढे जाण्यासाठी तुमच्या खात्यात Login करा</div>

      <?php if (!empty($_SESSION['session_expired'])): ?>
        <div class="lg-msg warn"><i class="fa-solid fa-clock"></i><span>बराच वेळ काहीही न केल्यामुळे तुम्ही सुरक्षिततेसाठी आपोआप Logout झालात. कृपया परत Login करा<?= !empty($_SESSION['login_next']) ? ' — तुम्ही जिथे होता त्याच पानावर परत जाल' : '' ?>.</span></div>
        <?php unset($_SESSION['session_expired']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['force_logged_out'])): ?>
        <div class="lg-msg err"><i class="fa-solid fa-right-from-bracket"></i><span>सुरक्षिततेसाठी Super Admin ने तुमचं हे Login बंद केलं आहे. कृपया परत Login करा.</span></div>
        <?php unset($_SESSION['force_logged_out']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['account_deactivated'])): ?>
        <div class="lg-msg err"><i class="fa-solid fa-user-slash"></i><span>तुमचं खातं किंवा संस्था निष्क्रिय (Inactive) केली गेली आहे — त्यामुळे तुम्हाला Logout केलं. कृपया Super Admin शी संपर्क साधा.</span></div>
        <?php unset($_SESSION['account_deactivated']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['reset_success'])): ?>
        <div class="lg-msg ok"><i class="fa-solid fa-circle-check"></i><span>तुमचा पासवर्ड यशस्वीरित्या बदलला गेला — आता नवीन पासवर्डने Login करा.</span></div>
        <?php unset($_SESSION['reset_success']); ?>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="lg-msg err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= h($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" id="lgForm" novalidate>
        <div class="lg-field" id="lgEmailWrap">
          <input type="email" name="email" id="loginEmailField" placeholder=" " autocomplete="username" required inputmode="email"
                 value="<?= h($_POST['email'] ?? '') ?>" <?= empty($_POST['email']) ? 'autofocus' : '' ?>>
          <label for="loginEmailField"><?= h(t('s48')) ?></label>
          <i class="fa-regular fa-envelope lead"></i>
          <i class="fa-solid fa-circle-check ok-mark"></i>
        </div>
        <div class="lg-hint">कृपया योग्य ईमेल लिहा (उदा. name@school.in)</div>
        <div class="lg-field">
          <input type="password" name="password" id="loginPassField" placeholder=" " autocomplete="current-password" required <?= !empty($_POST['email']) ? 'autofocus' : '' ?>>
          <label for="loginPassField"><?= h(t('s62')) ?></label>
          <i class="fa-solid fa-lock lead"></i>
          <button type="button" class="lg-eye" id="lgEye" aria-label="पासवर्ड दाखवा"><i class="fa-regular fa-eye"></i></button>
        </div>
        <div class="lg-caps" id="lgCaps"><i class="fa-solid fa-triangle-exclamation"></i> Caps Lock चालू आहे</div>
        <div class="lg-row">
          <label><input type="checkbox" id="lgRemember"> ईमेल लक्षात ठेवा</label>
          <a href="forgot-password.php">पासवर्ड विसरलात?</a>
        </div>
        <button type="submit" class="lg-btn" id="lgSubmit"><span><?= h(t('s158')) ?></span> <i class="fa-solid fa-arrow-right arr"></i></button>
      </form>

      <div class="lg-links">
        <a href="index.php"><i class="fa-solid fa-house"></i>मुख्य पान</a>
        <a href="track-request.php"><i class="fa-solid fa-magnifying-glass"></i>विनंतीची स्थिती</a>
        <a href="forgot-password.php"><i class="fa-solid fa-key"></i>पासवर्ड रीसेट</a>
      </div>
      <div class="lg-secure"><i class="fa-solid fa-shield-halved"></i> सुरक्षित Login — ५ चुकीच्या प्रयत्नांनंतर खाते १५ मिनिटांसाठी Lock</div>
    </div>
  </main>
</div>

<script>
(function () {
  var email = document.getElementById('loginEmailField'), pass = document.getElementById('loginPassField');
  var emailWrap = document.getElementById('lgEmailWrap');
  var remember = document.getElementById('lgRemember'), KEY = 'eowmsLoginEmail';
  var okEmail = function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); };
  // ईमेल लक्षात ठेवा — फक्त ईमेल, पासवर्ड कधीच नाही
  try {
    var saved = localStorage.getItem(KEY);
    if (saved && !email.value) { email.value = saved; remember.checked = true; pass.focus(); }
  } catch (e) {}
  // ईमेल तपासणी — ✓ / लाल चौकट
  function checkEmail(strict) {
    var v = email.value.trim(), good = okEmail(v);
    emailWrap.classList.toggle('good', good);
    emailWrap.classList.toggle('bad', strict && v !== '' && !good);
    return good;
  }
  email.addEventListener('input', function () { checkEmail(emailWrap.classList.contains('bad')); });
  email.addEventListener('blur', function () { checkEmail(true); });
  checkEmail(false);
  // पासवर्ड दाखवा / लपवा
  var eye = document.getElementById('lgEye');
  eye.addEventListener('click', function () {
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    eye.innerHTML = show ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
    eye.setAttribute('aria-label', show ? 'पासवर्ड लपवा' : 'पासवर्ड दाखवा');
    pass.focus();
  });
  // Caps Lock इशारा
  var caps = document.getElementById('lgCaps');
  ['keydown', 'keyup'].forEach(function (ev) {
    pass.addEventListener(ev, function (e) { if (e.getModifierState) caps.style.display = e.getModifierState('CapsLock') ? 'flex' : 'none'; });
  });
  pass.addEventListener('blur', function () { caps.style.display = 'none'; });
  // Submit — तपासणी, ईमेल साठवणं, बटण "Login होत आहे…"
  var form = document.getElementById('lgForm'), btn = document.getElementById('lgSubmit'), card = document.getElementById('lgCard');
  var shake = function () { card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake'); };
  form.addEventListener('submit', function (e) {
    checkEmail(true);
    if (email.value.trim().indexOf('@') < 1) { e.preventDefault(); emailWrap.classList.add('bad'); shake(); email.focus(); return; }
    if (!pass.value) { e.preventDefault(); shake(); pass.focus(); return; }
    try { if (remember.checked) localStorage.setItem(KEY, email.value.trim()); else localStorage.removeItem(KEY); } catch (x) {}
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Login होत आहे…';
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { btn.disabled = false; btn.innerHTML = '<span><?= h(t('s158')) ?></span> <i class="fa-solid fa-arrow-right arr"></i>'; } });
  // नमस्कार + घड्याळ + तारीख
  var g = document.getElementById('lgGreet'), c = document.getElementById('lgClock'), dt = document.getElementById('lgDate');
  function tick() {
    var d = new Date(), h = d.getHours();
    g.textContent = h < 12 ? '☀️ शुभ सकाळ' : h < 17 ? '🌤️ शुभ दुपार' : h < 21 ? '🌇 शुभ संध्याकाळ' : '🌙 शुभ रात्री';
    c.textContent = d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
    try { dt.textContent = d.toLocaleDateString('mr-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); } catch (x) { dt.textContent = d.toDateString(); }
  }
  tick(); setInterval(tick, 15000);
})();
</script>
</body>
</html>
