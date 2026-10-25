<?php
require_once __DIR__ . '/../includes/auth.php';

$org = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$orgId = $org['id'] ?? 0;

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();

// सुरक्षा: सार्वजनिक पानावर सगळ्या कर्मचाऱ्यांची नावं/पदं यादी म्हणून दाखवत नाही —
// पाहुणा ज्यांना भेटायचंय त्यांचं नाव स्वतः टाईप करतो.

$success = null;
$regCode = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $guestOrg = trim($_POST['guest_organization'] ?? '') ?: null;
    $purpose = trim($_POST['purpose'] ?? '') ?: null;
    $hostName = mb_substr(trim($_POST['meeting_with_name'] ?? ''), 0, 100);
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    if ($campusId && !in_array($campusId, array_map('intval', array_column($campuses, 'id')), true)) { $campusId = null; }
    $expectedDate = $_POST['expected_date'] ?? '';
    $dObj = is_string($expectedDate) ? DateTime::createFromFormat('Y-m-d', $expectedDate) : false;
    if (!$dObj || $dObj->format('Y-m-d') !== $expectedDate) { $expectedDate = ''; }

    // टाईप केलेलं नाव संस्थेतल्या नेमक्या एकाच सक्रिय कर्मचाऱ्याशी जुळलं तरच त्याला कळवतो
    $meetingWith = null;
    if ($hostName !== '') {
        $hostStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1 AND LOWER(name) = LOWER(?) LIMIT 2");
        $hostStmt->execute([$orgId, $hostName]);
        $hostMatches = $hostStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($hostMatches) === 1) { $meetingWith = (int)$hostMatches[0]; }
        $purpose = mb_substr(($purpose ? $purpose . ' — ' : '') . 'भेट: ' . $hostName, 0, 255);
    }

    if (honeypotTripped()) {
        // Bot — काहीही जतन न करता
        $success = true;
        $regCode = 'PR' . date('md') . random_int(1000, 9999);
    } elseif (publicFormRateLimited('guest_pre_register')) {
        $error = 'या ठिकाणावरून गेल्या तासाभरात खूप नोंदण्या झाल्या आहेत — कृपया थोड्या वेळाने पुन्हा प्रयत्न करा.';
    } elseif ($name && $mobile && $expectedDate) {
        // registration_code UNIQUE — आधीच वापरलेला असेल तर पुढचा मोकळा क्रमांक
        $regCode = generateUniqueCode($pdo, 'guest_pre_registrations', 'registration_code', 'PR%s%d', random_int(1000, 9999), date('md'));
        $pdo->prepare("
            INSERT INTO guest_pre_registrations (organization_id, campus_id, name, mobile, guest_organization, purpose, meeting_with, expected_date, registration_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$orgId, $campusId, mb_substr($name, 0, 255), mb_substr($mobile, 0, 15), $guestOrg, $purpose, $meetingWith, $expectedDate, $regCode]);
        publicFormRecordSubmission('guest_pre_register');

        if ($meetingWith) {
            try { notifyUser($meetingWith, $orgId, '📱 पाहुण्याने Pre-Register केलं', "$name — " . date('d M Y', strtotime($expectedDate)), 'guests/index.php'); } catch (Exception $e) {}
        }
        $success = true;
    } else {
        $error = 'कृपया नाव, Mobile आणि योग्य दिनांक भरा.';
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>पाहुणे Pre-Registration — <?= htmlspecialchars($org['name'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:linear-gradient(160deg,#0A0E1A,#1B2340,#0F5E58);margin:0;min-height:100vh;padding:24px;display:flex;align-items:center;justify-content:center;}
  .card{background:#fff;border-radius:20px;padding:32px 28px;max-width:420px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);}
  h1{font-family:'Space Grotesk';font-size:19px;text-align:center;margin:0 0 6px;color:#10162B;}
  p.sub{text-align:center;color:#666B7D;font-size:12.5px;margin:0 0 22px;}
  label{font-size:12px;font-weight:600;display:block;margin-bottom:6px;color:#10162B;}
  input,select{width:100%;padding:11px 14px;border:1.5px solid #D7DAE0;border-radius:10px;font-size:14px;margin-bottom:14px;font-family:inherit;}
  button{width:100%;padding:13px;background:#1B8A83;color:#fff;border:none;border-radius:11px;font-size:14.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .success-box{text-align:center;}
  .success-icon{width:60px;height:60px;border-radius:50%;background:#DCEFEC;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;color:#1B8A83;}
  .code-badge{font-family:'Space Grotesk';font-weight:700;font-size:17px;color:#1B8A83;background:#DCEFEC;padding:8px 20px;border-radius:9px;display:inline-block;margin:10px 0;}
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
    <?php if ($success): ?>
      <div class="success-box">
        <div class="success-icon">✅</div>
        <h1>Pre-Registration झालं!</h1>
        <p class="sub">भेटीच्या वेळी हा Code दाखवा — Check-in जलद होईल</p>
        <div class="code-badge"><?= htmlspecialchars($regCode) ?></div>
      </div>
    <?php else: ?>
      <h1>🎫 पाहुणे Pre-Registration</h1>
      <p class="sub">आधीच माहिती भरा — भेटीच्या वेळी वेळ वाचेल</p>
      <?php if ($error): ?><p style="background:#F7E2E3;color:#8A1F2B;border-radius:9px;padding:10px 12px;font-size:12.5px;"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="POST">
        <?= honeypotField() ?>
        <label>तुमचं नाव</label>
        <input type="text" name="name" required>
        <label>Mobile Number</label>
        <input type="text" name="mobile" required>
        <label>संस्था/कंपनी (ऐच्छिक)</label>
        <input type="text" name="guest_organization">
        <label>भेटीचं कारण</label>
        <input type="text" name="purpose">
        <label>कोणाला भेटायचंय</label>
        <input type="text" name="meeting_with_name" maxlength="100" placeholder="व्यक्तीचं नाव (ऐच्छिक)">
        <?php if (count($campuses) > 1): ?>
          <label>College/School</label>
          <select name="campus_id">
            <option value="">-- निवडा --</option>
            <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <label>कधी येणार</label>
        <input type="date" name="expected_date" min="<?= date('Y-m-d') ?>" required>
        <button type="submit">Pre-Register करा</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
