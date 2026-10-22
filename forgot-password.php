<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$error = null;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $foundUser = $stmt->fetch();

    // Always show the same success message whether or not the email exists —
    // this prevents someone from using this form to discover which emails
    // are registered in the system.
    if ($foundUser) {
        $code = (string)random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // नवीन Code दिला की जुने (न वापरलेले) Codes रद्द — एका वेळी एकच Code चालू
        $pdo->prepare("UPDATE password_reset_codes SET used = 1 WHERE user_id = ? AND used = 0")->execute([$foundUser['id']]);
        $pdo->prepare("INSERT INTO password_reset_codes (user_id, code, expires_at) VALUES (?, ?, ?)")
            ->execute([$foundUser['id'], $code, $expiresAt]);
        $_SESSION['reset_attempts'] = 0;

        $orgStmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $orgStmt->execute([$foundUser['organization_id']]);
        $orgName = $orgStmt->fetch()['name'] ?? 'E-Admin';

        sendPasswordResetEmail($foundUser['email'], $foundUser['name'], $code, $orgName);
        $_SESSION['reset_email'] = $email;
    }
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>पासवर्ड विसरलात?</title>
<link rel="icon" type="image/png" href="assets/brand/favicon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#10162B;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;}
  .card{background:#fff;max-width:420px;width:92%;padding:36px 34px;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
  .icon{width:56px;height:56px;border-radius:14px;background:#1B8A83;display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:18px;}
  h1{font-family:'Space Grotesk';font-size:21px;margin:0 0 8px;color:#10162B;}
  p{font-size:13.5px;color:#666B7D;line-height:1.6;margin:0 0 22px;}
  label{font-size:12.5px;font-weight:600;color:#1A1D29;display:block;margin-bottom:5px;}
  input{width:100%;padding:11px 14px;border:1px solid #D7DAE0;border-radius:9px;font-size:14px;margin-bottom:16px;font-family:inherit;}
  button{width:100%;background:#1B8A83;color:#fff;border:none;border-radius:9px;padding:12px;font-size:14.5px;font-weight:700;cursor:pointer;font-family:inherit;}
  .success{background:#DCEFEC;color:#0F5E58;border-radius:8px;padding:14px;font-size:13px;margin-bottom:16px;line-height:1.6;}
  a.back{display:block;text-align:center;margin-top:16px;font-size:12.5px;color:#1B8A83;text-decoration:none;}
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

<div class="card">
  <div class="icon">🔑</div>
  <h1>पासवर्ड विसरलात?</h1>

  <?php if ($sent): ?>
    <div class="success">जर हा ईमेल आमच्या system मध्ये नोंदणीकृत असेल, तर त्यावर एक 6-अंकी verification code पाठवला गेला आहे. तो पुढील पानावर टाका.</div>
    <a href="verify-reset.php" style="display:inline-block;width:100%;background:#1B8A83;color:#fff;text-decoration:none;padding:12px;border-radius:9px;font-size:14.5px;font-weight:700;text-align:center;">पुढे जा — Code टाका</a>
  <?php else: ?>
    <p>तुमचा नोंदणीकृत ईमेल टाका — आम्ही तिथे एक verification code पाठवू.</p>
    <form method="POST">
      <label>ईमेल</label>
      <input type="email" name="email" required autofocus>
      <button type="submit">Code पाठवा</button>
    </form>
  <?php endif; ?>

  <a class="back" href="login.php">← Login कडे परत जा</a>
</div>
</body>
</html>
