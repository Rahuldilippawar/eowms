<?php
// 🔐 2-Step Login — पासवर्ड बरोबर झाल्यावर दुसरी पायरी (Authenticator / Email Code / Recovery Code)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/two-factor.php';

if (currentUser()) { header('Location: index.php'); exit; }

$pending = $_SESSION['tfa_pending'] ?? null;
if (isset($_GET['cancel']) || !$pending || time() - (int)$pending['at'] > 600) {
    unset($_SESSION['tfa_pending'], $_SESSION['tfa_email']);
    header('Location: login.php' . ($pending && !isset($_GET['cancel']) ? '?msg=session_expired' : ''));
    exit;
}

$st = $pdo->prepare("SELECT u.*, o.is_active AS org_is_active, o.name AS org_name FROM users u LEFT JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? AND u.is_active = 1");
$st->execute([(int)$pending['uid']]);
$user = $st->fetch();
if (!$user || (int)$user['org_is_active'] !== 1 || !tfaEnabled($user)) {
    unset($_SESSION['tfa_pending'], $_SESSION['tfa_email']);
    header('Location: login.php');
    exit;
}
$orgName = $user['org_name'] ?: 'E-Admin';
unset($user['org_is_active'], $user['org_name']);

$hasEmail = !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL);
$maskedEmail = $hasEmail ? preg_replace_callback('/^(.{1,2})(.*)(@.*)$/u', fn($m) => $m[1] . str_repeat('•', max(2, mb_strlen($m[2]))) . $m[3], $user['email']) : '';
$mode = $_POST['mode'] ?? $_GET['mode'] ?? ($user['tfa_method'] === 'email' ? 'email' : 'totp');
if (!in_array($mode, ['totp', 'email', 'recovery'], true)) { $mode = 'totp'; }
if ($mode === 'email' && !$hasEmail) { $mode = 'recovery'; }
$error = null;
$info = null;

$sendEmail = function () use ($user, $orgName, &$error, &$info) {
    $last = $_SESSION['tfa_email']['sent'] ?? 0;
    if (($_SESSION['tfa_email']['uid'] ?? 0) == $user['id'] && time() - $last < 60) {
        $error = 'Code नुकताच पाठवला आहे — ' . (60 - (time() - $last)) . ' सेकंदांनी पुन्हा मागवा.';
        return;
    }
    if (tfaSendEmailCode($user, $orgName)) { $info = 'Code Email वर पाठवला आहे — Inbox (आणि Spam) तपासा.'; }
    else { $error = 'Email पाठवता आला नाही. Authenticator App किंवा Recovery Code वापरा.'; }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';
    if ($action === 'send_email' && $hasEmail) {
        $mode = 'email';
        $sendEmail();
    } elseif ($action === 'verify') {
        $code = trim($_POST['code'] ?? '');
        $ok = false;
        $how = '';
        if ($mode === 'totp') {
            $secret = secretDecrypt($user['tfa_secret'] ?? '');
            $step = $secret ? tfaTotpVerify($secret, $code, $user['tfa_last_step']) : false;
            if ($step !== false) {
                $pdo->prepare("UPDATE users SET tfa_last_step = ? WHERE id = ?")->execute([$step, $user['id']]);
                $ok = true; $how = 'Authenticator';
            } elseif (!$secret) {
                $error = 'Authenticator ची किल्ली वाचता आली नाही — Email Code किंवा Recovery Code वापरा.';
            }
        } elseif ($mode === 'email') {
            $ok = tfaCheckEmailCode($user['id'], $code); $how = 'Email Code';
        } else {
            $left = tfaUseRecoveryCode($user['tfa_recovery'] ?? '', $code);
            if ($left !== false) {
                $pdo->prepare("UPDATE users SET tfa_recovery = ? WHERE id = ?")->execute([$left, $user['id']]);
                $user['tfa_recovery'] = $left;
                $ok = true; $how = 'Recovery Code';
            }
        }

        if ($ok) {
            if (!empty($_POST['remember'])) { tfaTrustDevice($pdo, $user['id']); }
            eowmsCompleteLogin($pdo, $user, 'Password + ' . $how);
        }

        publicFormRecordSubmission('login_fail');
        $_SESSION['tfa_pending']['tries'] = (int)$pending['tries'] + 1;
        try {
            $pdo->prepare("INSERT INTO activity_logs (organization_id, user_id, actor_name, action, entity_type, entity_id, details, created_at) VALUES (?, NULL, ?, '2-Step Code चुकीचा', 'user', ?, ?, NOW())")
                ->execute([$user['organization_id'], mb_substr($user['email'] ?? '', 0, 255), $user['id'], $mode . ' — IP: ' . clientIp()]);
        } catch (PDOException $e) {}
        if ($_SESSION['tfa_pending']['tries'] >= 5) {
            // पासवर्ड माहीत असलेला कोणीतरी Code अंदाजाने शोधतोय — खाते 15 मिनिटं Lock
            $pdo->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")->execute([$user['id']]);
            unset($_SESSION['tfa_pending'], $_SESSION['tfa_email']);
            header('Location: login.php?msg=tfa_locked');
            exit;
        }
        $error = $error ?: 'Code चुकीचा आहे — ' . (5 - $_SESSION['tfa_pending']['tries']) . ' प्रयत्न शिल्लक.';
    }
} elseif ($mode === 'email' && empty($_SESSION['tfa_email'])) {
    $sendEmail(); // Email पद्धत — पान उघडताच Code पाठवतो
}
$recLeft = tfaRecoveryLeft($user['tfa_recovery'] ?? '');
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>2-Step Login — <?= h($orgName) ?></title>
<link rel="icon" type="image/png" href="assets/brand/favicon-32.png">
<meta name="theme-color" content="#191C3A">
<script>
  (function () {
    var h = document.documentElement, g = function (k) { try { return localStorage.getItem('eowms_' + k); } catch (e) { return null; } };
    var t = g('theme'), sys = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
    if (t === 'dark' || ((t === 'auto' || !t) && sys)) h.setAttribute('data-theme', 'dark');
    var a = g('accent'); if (a) h.setAttribute('data-accent', a);
  })();
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--ac:#D9530F;--ac-d:#B6420A;--bg:#F7F5F2;--card:#fff;--line:#EAE5DF;--text:#0F172A;--sub:#5B6478;--field:#F8FAFC;--err-bg:#FDECEC;--err:#B42318;--ok-bg:#E7F6EF;--ok:#137A4B;}
  html[data-accent="blue"]{--ac:#2563EB;--ac-d:#1D4ED8;} html[data-accent="teal"]{--ac:#138A80;--ac-d:#0E6F67;}
  html[data-accent="purple"]{--ac:#7C3AED;--ac-d:#6025D0;} html[data-accent="green"]{--ac:#15803D;--ac-d:#116A32;} html[data-accent="maroon"]{--ac:#A3123A;--ac-d:#850E2F;}
  html[data-theme="dark"]{--bg:#0B1020;--card:#141B2D;--line:#253049;--text:#E3E8F2;--sub:#A9B2CA;--field:#0F1626;--err-bg:rgba(240,68,56,.14);--err:#FDA29B;--ok-bg:rgba(18,183,106,.14);--ok:#6CE9A6;color-scheme:dark;}
  *{box-sizing:border-box;} body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px 16px;background:var(--bg);color:var(--text);font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;
    background-image:radial-gradient(circle at 15% 10%,color-mix(in srgb,var(--ac) 14%,transparent),transparent 45%),radial-gradient(circle at 90% 90%,rgba(25,28,58,.12),transparent 45%);}
  .card{width:100%;max-width:410px;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:28px 26px 22px;box-shadow:0 30px 60px -24px rgba(16,22,43,.25);animation:up .45s cubic-bezier(.2,.8,.2,1) both;}
  @keyframes up{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:none;}}
  .shield{width:58px;height:58px;border-radius:18px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;background:linear-gradient(135deg,var(--ac),var(--ac-d));box-shadow:0 12px 24px -10px var(--ac);}
  h1{font-size:20px;margin:0 0 4px;text-align:center;} .sub{font-size:13px;color:var(--sub);text-align:center;margin:0 0 18px;line-height:1.6;}
  .who{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:12px;background:var(--field);border:1px solid var(--line);font-size:13px;margin-bottom:16px;}
  .who i{color:var(--ac);} .who b{display:block;font-size:13.5px;}
  .tabs{display:flex;gap:4px;padding:4px;border-radius:12px;background:var(--field);border:1px solid var(--line);margin-bottom:16px;}
  .tabs a{flex:1;text-align:center;padding:8px 4px;border-radius:9px;font-size:12.5px;font-weight:600;color:var(--sub);text-decoration:none;white-space:nowrap;}
  .tabs a.on{background:var(--card);color:var(--ac);box-shadow:0 1px 4px rgba(0,0,0,.08);}
  label.l{display:block;font-size:12.5px;font-weight:600;color:var(--sub);margin-bottom:6px;}
  .code{width:100%;font-size:26px;letter-spacing:10px;text-align:center;font-weight:700;padding:12px;border-radius:12px;border:1.5px solid var(--line);background:var(--field);color:var(--text);font-family:'Space Grotesk',monospace;outline:none;}
  .code.rec{letter-spacing:4px;font-size:20px;text-transform:uppercase;}
  .code:focus{border-color:var(--ac);box-shadow:0 0 0 4px color-mix(in srgb,var(--ac) 18%,transparent);}
  .rem{display:flex;align-items:center;gap:8px;font-size:13px;margin:14px 0 4px;color:var(--sub);cursor:pointer;} .rem input{width:17px;height:17px;accent-color:var(--ac);}
  .btn{width:100%;margin-top:14px;padding:13px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--ac),var(--ac-d));color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;min-height:48px;}
  .btn:active{transform:scale(.99);} .link{background:none;border:0;color:var(--ac);font-weight:600;font-size:13px;cursor:pointer;font-family:inherit;padding:6px 0;}
  .msg{border-radius:12px;padding:10px 12px;font-size:13px;margin-bottom:14px;line-height:1.5;} .msg.err{background:var(--err-bg);color:var(--err);} .msg.ok{background:var(--ok-bg);color:var(--ok);}
  .hint{font-size:12px;color:var(--sub);margin-top:8px;line-height:1.6;}
  .foot{display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:12px;border-top:1px dashed var(--line);font-size:12.5px;}
  .foot a{color:var(--sub);text-decoration:none;} .foot a:hover{color:var(--ac);}
</style>
</head>
<body>
<div class="card">
  <div class="shield"><i class="fa-solid fa-shield-halved"></i></div>
  <h1>2-Step Login</h1>
  <p class="sub">पासवर्ड बरोबर आहे. खातं तुमचंच आहे याची खात्री करण्यासाठी आणखी एक पायरी.</p>
  <div class="who"><i class="fa-solid fa-circle-user fa-lg"></i><div><b><?= h($user['name']) ?></b><?= h($user['email']) ?></div></div>

  <div class="tabs" role="tablist">
    <?php if ($user['tfa_method'] === 'totp'): ?><a href="?mode=totp" class="<?= $mode === 'totp' ? 'on' : '' ?>"><i class="fa-solid fa-mobile-screen"></i> App</a><?php endif; ?>
    <?php if ($hasEmail): ?><a href="?mode=email" class="<?= $mode === 'email' ? 'on' : '' ?>"><i class="fa-solid fa-envelope"></i> Email</a><?php endif; ?>
    <a href="?mode=recovery" class="<?= $mode === 'recovery' ? 'on' : '' ?>"><i class="fa-solid fa-key"></i> Recovery</a>
  </div>

  <?php if ($error): ?><div class="msg err"><i class="fa-solid fa-circle-exclamation"></i> <?= h($error) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="msg ok"><i class="fa-solid fa-paper-plane"></i> <?= h($info) ?></div><?php endif; ?>

  <form method="POST" autocomplete="off">
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="mode" value="<?= h($mode) ?>">
    <?php if ($mode === 'recovery'): ?>
      <label class="l" for="code">Recovery Code</label>
      <input id="code" name="code" class="code rec" maxlength="12" placeholder="XXXX-XXXX" autofocus required>
      <div class="hint">2-Step चालू करताना दिलेल्या 10 Codes पैकी कोणताही एक. प्रत्येक Code एकदाच वापरता येतो. शिल्लक: <b><?= $recLeft ?></b></div>
    <?php else: ?>
      <label class="l" for="code"><?= $mode === 'email' ? 'Email वर आलेला 6 अंकी Code' : 'Authenticator App मधला 6 अंकी Code' ?></label>
      <input id="code" name="code" class="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="••••••" autofocus required>
      <?php if ($mode === 'email'): ?><div class="hint">Code <b><?= h($maskedEmail) ?></b> वर पाठवला जातो, आणि 10 मिनिटं वैध असतो.</div>
      <?php else: ?><div class="hint">Google Authenticator / Microsoft Authenticator उघडा आणि <b><?= h($orgName) ?></b> समोरचा Code टाका.</div><?php endif; ?>
    <?php endif; ?>
    <label class="rem"><input type="checkbox" name="remember" value="1"> हा Device <?= TFA_TRUST_DAYS ?> दिवस लक्षात ठेवा (फक्त स्वतःच्या फोन/Computer वर)</label>
    <button class="btn" type="submit"><i class="fa-solid fa-unlock-keyhole"></i> पुढे जा</button>
  </form>
  <?php if ($mode === 'email'): ?>
    <form method="POST" style="text-align:center;margin-top:6px;"><input type="hidden" name="action" value="send_email"><button class="link" type="submit"><i class="fa-solid fa-rotate-right"></i> Code पुन्हा पाठवा</button></form>
  <?php endif; ?>

  <div class="foot">
    <a href="?cancel=1"><i class="fa-solid fa-arrow-left"></i> दुसऱ्या खात्याने Login</a>
    <span style="color:var(--sub);">🔒 <?= h($orgName) ?></span>
  </div>
</div>
<script>
  // Code टाकताना फक्त अंक; 6 अंक पूर्ण झाले की आपोआप Submit
  (function () {
    var i = document.getElementById('code'); if (!i || i.classList.contains('rec')) return;
    i.addEventListener('input', function () { var v = i.value.replace(/\D/g, '').slice(0, 6); i.value = v; if (v.length === 6) i.form.requestSubmit ? i.form.requestSubmit() : i.form.submit(); });
  })();
</script>
</body>
</html>
