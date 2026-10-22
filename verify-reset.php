<?php
require_once __DIR__ . '/includes/auth.php';

$error = null;
$email = $_SESSION['reset_email'] ?? '';

if (!$email) {
    header('Location: forgot-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $foundUser = $stmt->fetch();

    if (!$foundUser) {
        $error = 'काहीतरी चुकलं — कृपया पुन्हा सुरुवात करा.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'नवीन पासवर्ड किमान ६ अक्षरांचा असावा.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'नवीन पासवर्ड आणि पुष्टी जुळत नाहीत.';
    } else {
        $codeStmt = $pdo->prepare("
            SELECT * FROM password_reset_codes
            WHERE user_id = ? AND code = ? AND used = 0 AND expires_at >= NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $codeStmt->execute([$foundUser['id'], $code]);
        $validCode = $codeStmt->fetch();

        if (!$validCode) {
            // Brute-force संरक्षण — 5 चुकीच्या प्रयत्नांनंतर Code रद्द, नवीन Code मागवावा लागेल
            $_SESSION['reset_attempts'] = ($_SESSION['reset_attempts'] ?? 0) + 1;
            if ($_SESSION['reset_attempts'] >= 5) {
                $pdo->prepare("UPDATE password_reset_codes SET used = 1 WHERE user_id = ? AND used = 0")->execute([$foundUser['id']]);
                unset($_SESSION['reset_email'], $_SESSION['reset_attempts']);
                header('Location: forgot-password.php');
                exit;
            }
            $error = 'Code चुकीचा आहे किंवा त्याची मुदत संपली आहे (१५ मिनिटे). परत विनंती करा.';
        } else {
            $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $foundUser['id']]);
            $pdo->prepare("UPDATE password_reset_codes SET used = 1 WHERE id = ?")->execute([$validCode['id']]);
            logActivity('पासवर्ड रिसेट (self-service)', 'user', $foundUser['id'], $foundUser['name']);

            unset($_SESSION['reset_email'], $_SESSION['reset_attempts']);
            $_SESSION['reset_success'] = true;
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verification Code टाका</title>
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
  input[name="code"]{letter-spacing:6px;font-size:20px;text-align:center;font-weight:700;}
  button{width:100%;background:#1B8A83;color:#fff;border:none;border-radius:9px;padding:12px;font-size:14.5px;font-weight:700;cursor:pointer;font-family:inherit;}
  .err{background:#F7E2E3;color:#8A1F2B;border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:16px;}
  a.back{display:block;text-align:center;margin-top:16px;font-size:12.5px;color:#1B8A83;text-decoration:none;}
</style>
</head>
<body>
<div class="card">
  <div class="icon">✅</div>
  <h1>Code व नवीन पासवर्ड</h1>
  <p><strong><?= h($email) ?></strong> वर पाठवलेला 6-अंकी code आणि तुमचा नवीन पासवर्ड टाका.</p>

  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

  <form method="POST">
    <label>Verification Code</label>
    <input type="text" name="code" maxlength="6" pattern="\d{6}" required autofocus>
    <label>नवीन पासवर्ड (किमान ६ अक्षरे)</label>
    <input type="password" name="new_password" required minlength="6">
    <label>नवीन पासवर्ड पुन्हा टाइप करा</label>
    <input type="password" name="confirm_password" required minlength="6">
    <button type="submit">पासवर्ड बदला</button>
  </form>

  <a class="back" href="forgot-password.php">← नवीन code मागवा</a>
</div>
</body>
</html>
