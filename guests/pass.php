<?php
// 🎫 पाहुण्यांचा Gate Pass — Print साठी (A6 / Badge आकार)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$orgId = activeOrgId();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT g.*, u.name AS meeting_with_name, u.designation AS meeting_with_desig, c.name AS campus_name, o.name AS org_name, o.logo_path
    FROM guests g LEFT JOIN users u ON u.id = g.meeting_with LEFT JOIN campuses c ON c.id = g.campus_id JOIN organizations o ON o.id = g.organization_id
    WHERE g.id = ? AND g.organization_id = ?");
$st->execute([$id, $orgId]);
$g = $st->fetch();
if (!$g) { http_response_code(404); die('पाहुण्याची नोंद सापडली नाही.'); }
?><!DOCTYPE html>
<html lang="mr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gate Pass — <?= h($g['pass_code']) ?></title>
<style>
  @page{size:A6;margin:6mm;}
  *{box-sizing:border-box;} body{font-family:'Noto Sans Devanagari','Inter',system-ui,sans-serif;margin:0;background:#EEF1F5;color:#10162B;}
  .pass{width:100%;max-width:380px;margin:20px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(16,22,43,.15);border:1px solid #E4E6EC;}
  .top{background:linear-gradient(135deg,#D9530F,#B6420A);color:#fff;padding:14px 18px;display:flex;align-items:center;gap:12px;}
  .top img{width:42px;height:42px;border-radius:10px;background:#fff;object-fit:contain;padding:3px;}
  .top b{display:block;font-size:14px;line-height:1.25;} .top small{font-size:11px;opacity:.9;}
  .tag{text-align:center;font-weight:800;letter-spacing:.2em;font-size:13px;color:#B6420A;padding:10px 0 2px;}
  .body{padding:8px 18px 16px;display:flex;gap:14px;align-items:flex-start;}
  .ph{width:86px;height:100px;border-radius:10px;object-fit:cover;background:#F1F5F9;border:1px solid #E4E6EC;display:flex;align-items:center;justify-content:center;font-size:34px;color:#94A3B8;flex:none;}
  .nm{font-size:18px;font-weight:700;line-height:1.25;} .row{font-size:12px;margin-top:5px;color:#475569;} .row b{color:#10162B;}
  .code{margin:0 18px 14px;border:2px dashed #D9530F;border-radius:12px;text-align:center;padding:8px;font-family:'JetBrains Mono',monospace;font-size:24px;font-weight:800;letter-spacing:.08em;}
  .ft{display:flex;justify-content:space-between;font-size:10.5px;color:#64748B;padding:0 18px 14px;}
  .sign{margin:6px 18px 16px;display:flex;justify-content:space-between;font-size:10.5px;color:#64748B;} .sign span{border-top:1px solid #CBD5E1;padding-top:3px;width:45%;text-align:center;}
  .act{text-align:center;margin:14px;} .act button{background:#D9530F;color:#fff;border:0;border-radius:9px;padding:9px 22px;font-weight:600;cursor:pointer;font-family:inherit;}
  @media print{body{background:#fff;} .pass{box-shadow:none;margin:0;max-width:none;} .act{display:none;}}
</style></head>
<body>
<div class="pass">
  <div class="top">
    <?php if (!empty($g['logo_path'])): ?><img src="../<?= h(ltrim($g['logo_path'], '/')) ?>" alt=""><?php endif; ?>
    <div><b><?= h($g['org_name']) ?></b><small><?= h($g['campus_name'] ?: '') ?></small></div>
  </div>
  <div class="tag">VISITOR · पाहुणे</div>
  <div class="body">
    <?php if ($g['photo_path']): ?><img class="ph" src="../<?= h($g['photo_path']) ?>" alt=""><?php else: ?><div class="ph">👤</div><?php endif; ?>
    <div>
      <div class="nm"><?= h($g['name']) ?></div>
      <?php if ($g['guest_organization']): ?><div class="row"><?= h($g['guest_organization']) ?></div><?php endif; ?>
      <?php if ($g['mobile']): ?><div class="row">📱 <?= h($g['mobile']) ?></div><?php endif; ?>
      <div class="row">भेट: <b><?= h($g['meeting_with_name'] ?: '—') ?></b><?= $g['meeting_with_desig'] ? ' (' . h($g['meeting_with_desig']) . ')' : '' ?></div>
      <?php if ($g['purpose']): ?><div class="row">कारण: <b><?= h($g['purpose']) ?></b></div><?php endif; ?>
    </div>
  </div>
  <div class="code"><?= h($g['pass_code']) ?></div>
  <div class="ft"><span>Check-in: <?= date('d M Y, h:i A', strtotime($g['check_in_time'])) ?></span><span><?= $g['check_out_time'] ? 'Out: ' . date('h:i A', strtotime($g['check_out_time'])) : 'आज वैध' ?></span></div>
  <div class="sign"><span>Security</span><span>भेटलेल्या व्यक्तीची सही</span></div>
</div>
<div class="act"><button onclick="window.print()">🖨️ Print</button></div>
<script>if (location.hash !== '#noprint') setTimeout(function () { window.print(); }, 400);</script>
</body></html>
