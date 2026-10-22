<?php
require_once __DIR__ . '/includes/auth.php';

$org = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$orgId = $org['id'] ?? 0;

$sectionsStmt = $pdo->prepare("SELECT * FROM about_sections WHERE organization_id = ? AND is_active = 1 ORDER BY FIELD(section_type,'god','sadguru','guide'), display_order");
$sectionsStmt->execute([$orgId]);
$allSections = $sectionsStmt->fetchAll();
$godSections = array_values(array_filter($allSections, fn($s) => $s['section_type'] === 'god'));
$sadguruSections = array_values(array_filter($allSections, fn($s) => $s['section_type'] === 'sadguru'));
$guideSections = array_values(array_filter($allSections, fn($s) => $s['section_type'] === 'guide'));

$contribStmt = $pdo->prepare("SELECT * FROM about_contributors WHERE organization_id = ? AND is_active = 1 ORDER BY display_order, name");
$contribStmt->execute([$orgId]);
$contributors = $contribStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>आमच्याबद्दल — <?= htmlspecialchars($org['name'] ?? 'EOWMS') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Inter',sans-serif;background:#0A0E1A;color:#EAF3F1;margin:0;overflow-x:hidden;}

  .top{position:relative;padding:60px 20px 50px;text-align:center;overflow:hidden;
    background:radial-gradient(ellipse 120% 80% at 50% -10%, #1B2340 0%, #0F1424 55%, #0A0E1A 100%);}
  .top::before{content:'';position:absolute;width:420px;height:420px;border-radius:50%;background:#1B8A83;
    filter:blur(70px);opacity:.18;top:-160px;left:-100px;}
  .top::after{content:'';position:absolute;width:380px;height:380px;border-radius:50%;background:#C97F1D;
    filter:blur(70px);opacity:.18;bottom:-180px;right:-100px;}
  .top-inner{position:relative;z-index:2;}
  .back-link{position:absolute;top:20px;left:20px;color:#DCEFEC;text-decoration:none;font-size:13px;z-index:5;}
  .om-big{font-family:'Space Grotesk',serif;font-size:44px;color:#DCEFEC;opacity:.9;margin-bottom:6px;
    text-shadow:0 0 30px rgba(220,239,236,.55);animation:glow 3.2s ease-in-out infinite;}
  @keyframes glow{0%,100%{opacity:.7;}50%{opacity:1;}}
  .top h1{font-family:'Space Grotesk';font-weight:700;font-size:26px;margin:8px 0 6px;}
  .top p{color:#9FA6C0;font-size:13.5px;max-width:480px;margin:0 auto;}

  .section{max-width:840px;margin:0 auto;padding:50px 24px;}
  .section-heading{text-align:center;font-family:'Space Grotesk';font-size:14px;color:#C97F1D;
    letter-spacing:2px;text-transform:uppercase;margin-bottom:28px;}
  .divider{display:flex;align-items:center;justify-content:center;gap:12px;margin:0 auto 28px;max-width:200px;}
  .divider span{height:1px;flex:1;background:linear-gradient(90deg,transparent,rgba(220,239,236,.4),transparent);}
  .divider i{color:#1B8A83;font-size:10px;}

  .rev-card{
    background:linear-gradient(160deg,rgba(27,138,131,.14),rgba(255,255,255,.03));
    border:1px solid rgba(220,239,236,.2);border-radius:22px;padding:36px;text-align:center;
    max-width:460px;margin:0 auto 26px;box-shadow:0 12px 40px rgba(0,0,0,.25);position:relative;
  }
  .rev-card::before,.rev-card::after{content:'';position:absolute;width:24px;height:24px;border-color:rgba(220,239,236,.3);}
  .rev-card::before{top:10px;left:10px;border-top:1.5px solid;border-left:1.5px solid;border-radius:6px 0 0 0;}
  .rev-card::after{bottom:10px;right:10px;border-bottom:1.5px solid;border-right:1.5px solid;border-radius:0 0 6px 0;}
  .rev-photo{
    width:150px;height:150px;border-radius:50%;object-fit:cover;margin:0 auto 20px;display:block;
    border:3px solid #C97F1D;box-shadow:0 0 0 6px rgba(201,127,29,.15), 0 10px 30px rgba(0,0,0,.3);
  }
  .rev-photo-fallback{
    width:150px;height:150px;border-radius:50%;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(160deg,rgba(201,127,29,.2),rgba(255,255,255,.04));
    border:2px dashed rgba(251,235,214,.35);color:#FBEBD6;font-size:13px;padding:20px;text-align:center;
  }
  .rev-title{font-family:'Space Grotesk';font-size:19px;font-weight:700;color:#fff;margin-bottom:10px;}
  .rev-desc{font-size:14px;color:#DCEFEC;font-style:italic;line-height:1.85;}

  .guide-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:22px;}
  .guide-card{
    background:#10162B;border:1px solid rgba(220,239,236,.12);border-radius:18px;padding:26px 22px;text-align:center;
    transition:transform .25s ease;
  }
  .guide-card:hover{transform:translateY(-5px);}
  .guide-photo{width:92px;height:92px;border-radius:50%;object-fit:cover;margin:0 auto 14px;border:2.5px solid #1B8A83;}
  .guide-photo-fallback{width:92px;height:92px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;
    background:rgba(27,138,131,.15);color:#1B8A83;font-family:'Space Grotesk';font-size:26px;font-weight:700;}
  .guide-name{font-family:'Space Grotesk';font-size:15px;font-weight:600;color:#fff;margin-bottom:6px;}
  .guide-desc{font-size:12.5px;color:#9FA6C0;line-height:1.7;}

  .contrib-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;}
  .contrib-card{background:#10162B;border:1px solid rgba(220,239,236,.1);border-radius:16px;padding:22px;
    display:flex;gap:16px;align-items:flex-start;}
  .contrib-photo{width:60px;height:60px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #1B8A83;}
  .contrib-photo-fallback{width:60px;height:60px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
    background:rgba(27,138,131,.18);color:#1B8A83;font-family:'Space Grotesk';font-size:18px;font-weight:700;}
  .contrib-name{font-family:'Space Grotesk';font-size:14.5px;font-weight:600;color:#fff;}
  .contrib-role{font-size:11.5px;color:#C97F1D;margin:3px 0 6px;}
  .contrib-bio{font-size:12px;color:#9FA6C0;line-height:1.6;}

  .empty-note{text-align:center;color:#666B7D;font-size:13px;padding:20px;border:1px dashed rgba(220,239,236,.15);border-radius:14px;}

  .dev-footer{text-align:center;padding:50px 24px 40px;border-top:1px solid rgba(220,239,236,.08);margin-top:20px;}
  .dev-footer .thanks{font-size:13px;color:#9FA6C0;margin-bottom:14px;}
  .dev-footer .dev-name{font-family:'Space Grotesk';font-size:20px;font-weight:700;
    background:linear-gradient(90deg,#1B8A83,#DCEFEC);-webkit-background-clip:text;background-clip:text;color:transparent;}
  .dev-footer .dev-label{font-size:12px;color:#666B7D;margin-top:6px;letter-spacing:.5px;}
  .dev-footer .om-small{font-size:20px;color:#DCEFEC;opacity:.7;margin-top:20px;}
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


<div class="top">
  <a href="welcome.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> मुख्य पानावर</a>
  <div class="top-inner">
    <div class="om-big">ॐ</div>
    <h1><?= htmlspecialchars($org['name'] ?? 'EOWMS') ?></h1>
    <p>आमच्या श्रद्धेचा, गुरुकृपेचा आणि या प्रकल्पामागील सर्वांच्या योगदानाचा हा एक छोटासा परिचय</p>
  </div>
</div>

<?php if (!empty($godSections)): ?>
<div class="section">
  <div class="section-heading">🕉️ ईश्वर चरणी</div>
  <?php foreach ($godSections as $s): ?>
    <div class="rev-card">
      <?php if ($s['photo_path']): ?>
        <img src="<?= h($s['photo_path']) ?>" class="rev-photo" alt="<?= h($s['title']) ?>">
      <?php else: ?>
        <div class="rev-photo-fallback">फोटो लवकरच जोडला जाईल</div>
      <?php endif; ?>
      <div class="rev-title"><?= h($s['title']) ?></div>
      <?php if ($s['description']): ?><div class="rev-desc">"<?= nl2br(h($s['description'])) ?>"</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($sadguruSections)): ?>
<div class="section">
  <div class="section-heading">🙏 सद्गुरू आशीर्वाद</div>
  <?php foreach ($sadguruSections as $s): ?>
    <div class="rev-card">
      <?php if ($s['photo_path']): ?>
        <img src="<?= h($s['photo_path']) ?>" class="rev-photo" alt="<?= h($s['title']) ?>">
      <?php else: ?>
        <div class="rev-photo-fallback">फोटो लवकरच जोडला जाईल</div>
      <?php endif; ?>
      <div class="rev-title"><?= h($s['title']) ?></div>
      <?php if ($s['description']): ?><div class="rev-desc">"<?= nl2br(h($s['description'])) ?>"</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($guideSections)): ?>
<div class="section">
  <div class="section-heading">🧭 मार्गदर्शक</div>
  <div class="guide-grid">
    <?php foreach ($guideSections as $s): ?>
      <div class="guide-card">
        <?php if ($s['photo_path']): ?>
          <img src="<?= h($s['photo_path']) ?>" class="guide-photo" alt="">
        <?php else: ?>
          <div class="guide-photo-fallback"><?= h(mb_substr($s['title'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="guide-name"><?= h($s['title']) ?></div>
        <?php if ($s['description']): ?><div class="guide-desc"><?= nl2br(h($s['description'])) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (empty($godSections) && empty($sadguruSections) && empty($guideSections)): ?>
<div class="section">
  <div class="empty-note">Super Admin अजून देव/सद्गुरू/मार्गदर्शक यांची माहिती व फोटो जोडू शकतो — <strong>about-manage.php</strong> मधून.</div>
</div>
<?php endif; ?>

<div class="section">
  <div class="section-heading">👥 या प्रकल्पासाठी योगदान</div>
  <?php if (!empty($contributors)): ?>
    <div class="contrib-grid">
      <?php foreach ($contributors as $c): ?>
        <div class="contrib-card">
          <?php if ($c['photo_path']): ?>
            <img src="<?= h($c['photo_path']) ?>" class="contrib-photo" alt="">
          <?php else: ?>
            <div class="contrib-photo-fallback"><?= h(mb_substr($c['name'], 0, 1)) ?></div>
          <?php endif; ?>
          <div>
            <div class="contrib-name"><?= h($c['name']) ?></div>
            <?php if ($c['contribution']): ?><div class="contrib-role"><?= h($c['contribution']) ?></div><?php endif; ?>
            <?php if ($c['bio']): ?><div class="contrib-bio"><?= h($c['bio']) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-note">या प्रकल्पासाठी योगदान देणाऱ्यांची यादी अजून जोडलेली नाही.</div>
  <?php endif; ?>
</div>

<div class="dev-footer">
  <div class="thanks">या सर्वांच्या आशीर्वादाने व सहकार्याने साकार झालेली प्रणाली</div>
  <div class="dev-name">Rahul Pawar</div>
  <div class="dev-label">DEVELOPED BY</div>
  <div class="om-small">ॐ</div>
</div>

</body>
</html>
