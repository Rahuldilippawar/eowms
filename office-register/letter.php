<?php
// 🖨️ जावक पत्र — A4 Print (letterhead, जा.क्र., प्रति, विषय, मजकूर, सह्या, शेरा/सही, प्रत माहितीसाठी)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
if (!orCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">आवक-जावक नोंदवही पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
if (!orReady($pdo)) { header('Location: index.php'); exit; }
$st = $pdo->prepare("SELECT * FROM office_letters WHERE id = ? AND organization_id = ? AND direction = 'out'"); $st->execute([(int)($_GET['id'] ?? 0), $orgId]); $row = $st->fetch();
if (!$row) { header('Location: index.php?dir=out'); exit; }
$SET = orLetterSettings($pdo, $orgId);
$doc = orLetterDoc($row, $SET);
$hasDoc = !empty($row['doc_json']);
if (!$hasDoc) { $doc['body'] = ''; $doc['to'] = (string)$row['party']; }
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>जावक <?= $h($row['reg_no']) ?> — <?= $h(mb_strimwidth((string)$row['subject'], 0, 60, '…')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;} body{margin:0;background:#E7E5E4;font-family:'Noto Sans Devanagari',system-ui,sans-serif;}
  .bar{position:sticky;top:0;z-index:5;background:#3B0764;color:#fff;display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px 12px;font-size:12.5px;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:6px 11px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,255,255,.14);}
  .bar .print{background:linear-gradient(90deg,#9333EA,#7E22CE);margin-left:auto;}
  .ok{background:#DCFCE7;color:#166534;padding:8px 14px;font-size:13px;font-weight:600;text-align:center;}
  .sheet{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:14mm 16mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);}
  <?= orLetterCss() ?>
  @media print{ body{background:#fff;} .bar,.ok{display:none !important;} .sheet{margin:0;box-shadow:none;width:auto;min-height:0;padding:0;} @page{size:A4 portrait;margin:14mm 16mm;} }
  @media screen and (max-width:820px){ .sheet{width:auto;min-height:0;margin:8px;padding:16px 14px;} .ltr{font-size:13.5px;} }
</style>
</head>
<body>
<div class="bar">
  <a href="index.php?dir=out">← जावक नोंदवही</a>
  <?php if (orCanEdit()): ?><a href="compose.php?id=<?= (int)$row['id'] ?>">✎ पत्र बदला</a><a href="compose.php?copy=<?= (int)$row['id'] ?>">📄 याच पत्रावरून नवीन</a><?php endif; ?>
  <a href="entry.php?id=<?= (int)$row['id'] ?>">🛠️ नोंद / कामाची स्थिती</a>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</div>
<?php if ($flash): ?><div class="ok"><?= $h($flash) ?></div><?php endif; ?>
<?php if (!$hasDoc): ?><div class="ok" style="background:#FEF3C7;color:#92400E;">हे पत्र E-Admin मध्ये बनवलेले नाही (नोंदवहीतील नोंद) — <?= orCanEdit() ? '<a href="compose.php?id=' . (int)$row['id'] . '" style="color:inherit;">✎ आता पत्र बनवा</a>' : 'फक्त विषय दिसत आहे.' ?></div><?php endif; ?>
<div class="sheet"><?= orLetterHtml($doc, ['date' => $row['letter_date'], 'subject' => $row['subject'], 'reg_no' => $row['reg_no'], 'fy' => $row['fy']], $SET['head'], ($doc['digits'] ?? 'mr') === 'mr') ?></div>
</body>
</html>
