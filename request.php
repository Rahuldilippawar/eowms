<?php
require_once __DIR__ . '/includes/auth.php';

// Primary organization for this deployment
$org = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$orgId = $org['id'] ?? 0;

$departments = [];
$campuses = [];
if ($orgId) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();

    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campuses = $cStmt->fetchAll();
}

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['requester_name'] ?? '');
    $contact = trim($_POST['requester_contact'] ?? '') ?: null;
    $deptId = (int)($_POST['department_id'] ?? 0);
    $description = trim($_POST['work_description'] ?? '');

    // विभाग याच संस्थेचा, सक्रिय आहे ना — तपासतो
    $deptValid = in_array($deptId, array_map('intval', array_column($departments, 'id')), true);

    if (honeypotTripped()) {
        // Bot — काहीही जतन न करता सर्वसाधारण संदेश
        $success = 'तुमची विनंती नोंदवली गेली.';
    } elseif (publicFormRateLimited('work_request')) {
        $error = 'या ठिकाणावरून गेल्या तासाभरात खूप विनंत्या आल्या आहेत — कृपया थोड्या वेळाने पुन्हा प्रयत्न करा.';
    } elseif (!$name || !$deptId || !$description || !$deptValid) {
        $error = t('s453');
    } else {
        $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_requests WHERE organization_id = ?");
        $countStmt->execute([$orgId]);
        $seq = $countStmt->fetch()['c'] + 1;
        // एखादी विनंती Delete झाली असेल तर COUNT मागे जातो — request_code
        // UNIQUE असल्यामुळे आधीच वापरलेला Code पुन्हा येऊ नये म्हणून पुढे सरकतो
        $requestCode = generateUniqueCode($pdo, 'work_requests', 'request_code', 'REQ-%s%04d', $seq, date('y'));

        $insert = $pdo->prepare("
            INSERT INTO work_requests (request_code, organization_id, department_id, requester_name, requester_contact, work_description, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $insert->execute([$requestCode, $orgId, $deptId, $name, $contact, $description]);
        $newRequestId = $pdo->lastInsertId();
        try {
            $pdo->prepare("INSERT INTO work_request_actions (request_id, action_type, new_status) VALUES (?, 'created', 'pending')")
                ->execute([$newRequestId]);
        } catch (PDOException $e) {
            // work_request_actions table not migrated yet — the core request
            // was already saved successfully above, so don't block the user.
        }
        publicFormRecordSubmission('work_request');
        notifyDepartment($deptId, $orgId, 'नवीन कामाची विनंती आली', "$name कडून: " . mb_substr($description, 0, 80), 'requests/index.php');
        $success = "तुमची विनंती यशस्वीरित्या नोंदवली गेली — Request ID: $requestCode. संबंधित विभाग लवकरच त्यावर काम करेल.";
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(t('s135')) ?> — <?= htmlspecialchars($org['name'] ?? 'VJMAT') ?></title>
<link rel="icon" type="image/png" href="assets/brand/favicon-32.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --amber:#C97F1D; --rose:#B23A48; --line:#E4E7ED; --text-dim:#666B7D; --canvas:#F3F4F7; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:var(--canvas);color:#1A1D29;margin:0;}

  .hero{
    background:linear-gradient(160deg,#0A0E1A 0%,#1B2340 55%,#0F5E58 130%);
    padding:44px 20px 76px;position:relative;overflow:hidden;text-align:center;
  }
  .hero-ring{position:absolute;top:50%;left:50%;width:340px;height:340px;transform:translate(-50%,-50%);border:1px solid rgba(220,239,236,.08);border-radius:50%;animation:eowms-spin 100s linear infinite;}
  .hero-ring::before{content:'';position:absolute;inset:30px;border:1px dashed rgba(201,127,29,.12);border-radius:50%;}
  .hero-ring::after{content:'';position:absolute;inset:58px;border:1px solid rgba(220,239,236,.06);border-radius:50%;}
  @keyframes eowms-spin{from{transform:translate(-50%,-50%) rotate(0deg);}to{transform:translate(-50%,-50%) rotate(360deg);}}
  .mark{width:58px;height:58px;border-radius:17px;background:linear-gradient(150deg,#1B8A83,#0F5E58);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;box-shadow:0 0 0 1px rgba(220,239,236,.15), 0 10px 26px rgba(27,138,131,.4);position:relative;z-index:1;}
  .mark img{width:100%;height:100%;object-fit:contain;border-radius:17px;background:#fff;padding:6px;}
  .mark i{color:#fff;font-size:24px;}
  .hero-eyebrow{display:inline-flex;align-items:center;gap:8px;font-family:'Space Grotesk';font-size:10.5px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:#7FC9C2;margin-bottom:10px;position:relative;z-index:1;}
  .hero-eyebrow::before,.hero-eyebrow::after{content:'';width:18px;height:1px;background:linear-gradient(90deg,transparent,#1B8A83);}
  .hero-eyebrow::after{background:linear-gradient(90deg,#1B8A83,transparent);}
  .hero h1{font-family:'Space Grotesk';font-weight:700;font-size:21px;color:#fff;margin:0 0 8px;position:relative;z-index:1;}
  .hero p{font-size:12.5px;color:#B7BBD6;max-width:340px;margin:0 auto;position:relative;z-index:1;line-height:1.65;}

  .lang-select{position:fixed;top:14px;right:14px;z-index:50;border:1px solid rgba(255,255,255,.25);border-radius:9px;padding:7px 11px;font-size:11.5px;color:#fff;background:rgba(255,255,255,.08);backdrop-filter:blur(4px);cursor:pointer;}
  .lang-select option{color:#1A1D29;}

  .box{max-width:520px;margin:-46px auto 30px;background:#fff;border-radius:22px;padding:8px 26px 30px;box-shadow:0 20px 50px rgba(16,22,43,.14);position:relative;z-index:2;}

  .step-label{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:700;color:var(--ink);margin:24px 0 4px;}
  .step-num{width:20px;height:20px;border-radius:50%;background:var(--rail);color:#fff;font-size:10.5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .step-label:first-of-type{margin-top:22px;}

  .field-wrap{position:relative;}
  .field-wrap .fi{position:absolute;left:14px;top:14px;color:#A6ABC2;font-size:14px;pointer-events:none;transition:color .15s ease;}
  .field-wrap textarea ~ .fi{top:14px;}
  .field-wrap:focus-within .fi{color:var(--rail);}

  label.field-label{font-size:11.5px;font-weight:500;display:block;margin-bottom:6px;color:var(--text-dim);}
  input,select,textarea{width:100%;padding:12px 14px 12px 40px;border:1.5px solid var(--line);border-radius:12px;font-size:14.5px;box-sizing:border-box;font-family:inherit;background:#FAFBFC;transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--rail);background:#fff;box-shadow:0 0 0 4px rgba(27,138,131,.08);}
  textarea{resize:vertical;min-height:90px;}
  select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23666B7D' stroke-width='1.5' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;}

  .char-count{font-size:10.5px;color:var(--text-dim);text-align:right;margin-top:4px;}

  button{width:100%;margin-top:26px;padding:15px;border:none;border-radius:13px;background:linear-gradient(135deg,#1B8A83,#0F5E58);color:#fff;font-weight:600;font-size:14.5px;cursor:pointer;box-shadow:0 10px 24px rgba(27,138,131,.32);transition:transform .15s ease, box-shadow .15s ease;}
  button:hover{box-shadow:0 12px 28px rgba(27,138,131,.42);}
  button:active{transform:scale(.98);}

  .msg{padding:14px 16px;border-radius:11px;font-size:13.5px;margin-bottom:6px;display:flex;gap:10px;align-items:flex-start;}
  .msg.err{background:#F7E2E3;color:#8A1F2B;}

  .success-icon{width:64px;height:64px;border-radius:50%;background:#DCEFEC;display:flex;align-items:center;justify-content:center;margin:14px auto 18px;font-size:28px;color:var(--rail);animation:eowms-pop .4s cubic-bezier(.34,1.56,.64,1) both;}
  @keyframes eowms-pop{from{transform:scale(0);opacity:0;}to{transform:scale(1);opacity:1;}}
  .success-title{text-align:center;font-family:'Space Grotesk';font-weight:700;font-size:17px;color:var(--ink);margin-bottom:8px;}
  .success-code{text-align:center;font-family:'Space Grotesk';font-weight:700;font-size:15px;color:var(--rail);background:#DCEFEC;display:inline-block;padding:6px 16px;border-radius:9px;letter-spacing:.5px;}
  .success-code-wrap{text-align:center;margin-bottom:14px;}
  .success-sub{text-align:center;font-size:13px;color:var(--text-dim);line-height:1.6;margin-bottom:6px;}
  .track-btn{display:block;text-align:center;margin-top:16px;background:var(--rail);color:#fff;text-decoration:none;padding:13px 18px;border-radius:12px;font-size:13.5px;font-weight:600;box-shadow:0 8px 20px rgba(27,138,131,.28);}
  .hint{font-size:11.5px;color:var(--text-dim);margin-top:14px;text-align:center;line-height:1.6;}

  a.back{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:22px;font-size:13px;color:var(--rail);text-decoration:none;font-weight:600;}
</style>
</head>
<body>
<select id="eowmsLangSelect" class="lang-select" onchange="eowmsChangeLanguage(this.value)">
  <option value="">🌐 भाषा</option>
  <option value="en">English</option>
  <option value="hi">हिंदी</option>
  <option value="gu">ગુજરાતી</option>
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

<div class="hero">
  <div class="hero-ring"></div>
  <?php if (!empty($org['logo_path'])): ?>
    <div class="mark"><img src="<?= htmlspecialchars($org['logo_path']) ?>" alt="Logo"></div>
  <?php else: ?>
    <div class="mark"><i class="fa-solid fa-clipboard-question"></i></div>
  <?php endif; ?>
  <div class="hero-eyebrow">Login शिवाय</div>
  <h1><?= h(t('s136')) ?></h1>
  <p><?= h(t('s137')) ?></p>
</div>

<div class="box">
  <?php if ($success): ?>
    <div class="success-icon"><i class="fa-solid fa-check"></i></div>
    <div class="success-title">विनंती यशस्वीरित्या नोंदवली!</div>
    <div class="success-code-wrap"><span class="success-code"><?= htmlspecialchars($requestCode) ?></span></div>
    <p class="success-sub">संबंधित विभागाला याची सूचना मिळाली आहे — ते लवकरच त्यावर काम करतील.</p>
    <a href="track-request.php?code=<?= urlencode($requestCode) ?>" class="track-btn">📋 स्थिती तपासा (Track Status)</a>
    <div class="hint">💡 हा Request ID विसरलात तरी काळजी नाही — तुमच्या Mobile Number ने पुन्हा शोधता येईल.</div>
    <a href="welcome.php" class="back"><i class="fa-solid fa-arrow-left"></i> <?= h(t('s138')) ?></a>
  <?php else: ?>
    <?php if ($error): ?><div class="msg err" style="margin-top:20px;"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
    <form method="POST">
      <?= honeypotField() ?>

      <div class="step-label"><span class="step-num">1</span> तुमची माहिती</div>
      <label class="field-label"><?= h(t('s139')) ?></label>
      <div class="field-wrap"><i class="fa-regular fa-user fi"></i><input type="text" name="requester_name" placeholder="तुमचं पूर्ण नाव" required></div>

      <label class="field-label" style="margin-top:14px;"><?= h(t('s140')) ?></label>
      <div class="field-wrap"><i class="fa-solid fa-phone fi"></i><input type="text" name="requester_contact" placeholder="Mobile किंवा Email"></div>

      <div class="step-label"><span class="step-num">2</span> <?= h(t('s141')) ?></div>
      <?php if (count($campuses) > 1): ?>
        <label class="field-label">College/School</label>
        <div class="field-wrap"><i class="fa-solid fa-building-columns fi"></i>
          <select id="campusSelect" onchange="loadDeptsForCampus(this.value)">
            <option value="">-- निवडा --</option>
            <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <label class="field-label" style="margin-top:12px;">विभाग</label>
      <?php endif; ?>
      <div class="field-wrap"><i class="fa-solid fa-building fi"></i>
        <select name="department_id" id="departmentSelect" required <?= count($campuses) > 1 ? 'disabled' : '' ?>>
          <option value=""><?= count($campuses) > 1 ? 'आधी College/School निवडा' : h(t('s65')) ?></option>
          <?php if (count($campuses) <= 1): foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name_local'] ?: $d['name']) ?></option>
          <?php endforeach; endif; ?>
        </select>
      </div>
      <script>
      function loadDeptsForCampus(campusId) {
        const deptSelect = document.getElementById('departmentSelect');
        if (!campusId) { deptSelect.innerHTML = '<option value="">आधी College/School निवडा</option>'; deptSelect.disabled = true; return; }
        deptSelect.disabled = true;
        deptSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
        fetch('ajax-cascade-public.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json())
          .then(data => {
            deptSelect.innerHTML = '<option value="">-- निवडा --</option>';
            (data.departments || []).forEach(d => {
              const opt = document.createElement('option');
              opt.value = d.id; opt.textContent = d.name;
              deptSelect.appendChild(opt);
            });
            deptSelect.disabled = false;
          });
      }
      </script>

      <div class="step-label"><span class="step-num">3</span> <?= h(t('s142')) ?></div>
      <div class="field-wrap"><i class="fa-regular fa-message fi"></i><textarea name="work_description" id="workDesc" rows="4" required maxlength="500" placeholder="उदा. वर्ग क्र. ५ मधील पंखा बंद पडला आहे, दुरुस्ती हवी आहे" oninput="document.getElementById('charCount').textContent = this.value.length"></textarea></div>
      <div class="char-count"><span id="charCount">0</span>/500</div>

      <button type="submit"><i class="fa-solid fa-paper-plane" style="margin-right:8px;"></i><?= h(t('s143')) ?></button>
    </form>
    <a href="welcome.php" class="back"><i class="fa-solid fa-arrow-left"></i> <?= h(t('s144')) ?></a>
  <?php endif; ?>
</div>

</body>
</html>
