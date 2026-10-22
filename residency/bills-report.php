<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();

$month = $_GET['month'] ?? date('Y-m');

$stmt = $pdo->prepare("
    SELECT rb.*, ml.name AS room_name, b.name AS building_name
    FROM residency_bills rb JOIN maintenance_locations ml ON ml.id = rb.location_id LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE rb.organization_id = ? AND rb.billing_month = ?
    ORDER BY b.name, ml.name
");
$stmt->execute([$orgId, $month]);
$bills = $stmt->fetchAll();

$totalRent = 0; $totalElectricity = 0; $totalWater = 0; $totalAmount = 0; $totalCollected = 0; $totalPending = 0;
$billDetails = [];
foreach ($bills as $b) {
    $totalRent += $b['room_rent'];
    $totalElectricity += $b['electricity_bill'];
    $totalWater += $b['water_bill'];
    $totalAmount += $b['total_amount'];

    $sharesStmt = $pdo->prepare("SELECT s.*, o.name AS occ_name, u.name AS user_name FROM residency_bill_shares s JOIN residency_occupants o ON o.id = s.occupant_id LEFT JOIN users u ON u.id = o.user_id WHERE s.bill_id = ?");
    $sharesStmt->execute([$b['id']]);
    $shares = $sharesStmt->fetchAll();
    $collected = 0; $pending = 0;
    foreach ($shares as $s) {
        if ($s['status'] === 'paid') { $collected += $s['amount_due']; } else { $pending += $s['amount_due']; }
    }
    $totalCollected += $collected;
    $totalPending += $pending;
    $billDetails[] = ['bill' => $b, 'shares' => $shares, 'collected' => $collected, 'pending' => $pending];
}

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>निवास-बिल अहवाल — <?= h($month) ?></title>
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text-dim:#666B7D; --amber:#C97F1D; --rose:#B23A48; }
  *{box-sizing:border-box;}
  body{font-family:'Segoe UI',sans-serif;color:#1A1D29;margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;z-index:10;align-items:center;}
  .toolbar a, .toolbar button{color:#fff;text-decoration:none;font-size:13px;background:var(--rail);border:none;border-radius:8px;padding:9px 20px;font-weight:600;cursor:pointer;}
  .toolbar select{border-radius:8px;border:none;padding:8px 12px;font-size:13px;}
  .doc{max-width:900px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-weight:700;font-size:19px;text-align:center;}
  .report-title{text-align:center;font-weight:600;font-size:15px;margin-top:6px;color:var(--rail);}
  .stat-row{display:flex;gap:14px;margin:24px 0;justify-content:center;flex-wrap:wrap;}
  .stat-box{background:#F7F8FA;border-radius:10px;padding:14px 22px;text-align:center;min-width:110px;}
  .stat-box .num{font-size:20px;font-weight:700;}
  .stat-box .lbl{font-size:10px;color:var(--text-dim);text-transform:uppercase;margin-top:2px;}
  table{width:100%;border-collapse:collapse;margin-top:14px;}
  th{background:#F3F4F7;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;color:var(--text-dim);}
  td{padding:8px 10px;border-bottom:1px solid #EEF0F4;font-size:12px;}
  h3{font-size:14px;margin:22px 0 4px;}
  @media print{ .toolbar{display:none;} #eowmsLangSelect, #google_translate_element{display:none !important;} body{background:#fff;} .doc{margin:0;} }
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

<div class="toolbar">
  <a href="bills.php">← मागे</a>
  <form method="GET" style="display:flex;gap:8px;">
    <input type="month" name="month" value="<?= h($month) ?>" style="border-radius:8px;border:none;padding:8px 12px;">
    <button type="submit" style="background:#fff;color:var(--ink);">बघा</button>
  </form>
  <button onclick="window.print()">PDF डाउनलोड करा</button>
</div>
<div class="doc">
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">निवास — भाडं/वीज-बिल अहवाल — <?= date('F Y', strtotime($month . '-01')) ?></div>

  <div class="stat-row">
    <div class="stat-box"><div class="num"><?= count($bills) ?></div><div class="lbl">एकूण Rooms</div></div>
    <div class="stat-box"><div class="num">₹<?= number_format($totalRent, 0) ?></div><div class="lbl">एकूण भाडं</div></div>
    <div class="stat-box"><div class="num">₹<?= number_format($totalElectricity + $totalWater, 0) ?></div><div class="lbl">वीज+पाणी</div></div>
    <div class="stat-box" style="background:#DCEFEC;"><div class="num" style="color:var(--rail);">₹<?= number_format($totalCollected, 0) ?></div><div class="lbl">जमा झालेलं</div></div>
    <div class="stat-box" style="background:#F7E2E3;"><div class="num" style="color:var(--rose);">₹<?= number_format($totalPending, 0) ?></div><div class="lbl">बाकी</div></div>
  </div>

  <h3>📋 Room-निहाय तपशील</h3>
  <table>
    <thead><tr><th>Room</th><th>भाडं+वीज</th><th>किती जणांत</th><th>प्रत्येकी</th><th>जमा</th><th>बाकी</th></tr></thead>
    <tbody>
      <?php if (empty($billDetails)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:20px;">या महिन्याचं कुठलंही बिल नाही</td></tr><?php endif; ?>
      <?php foreach ($billDetails as $bd): $b = $bd['bill']; ?>
        <tr>
          <td style="font-weight:600;"><?= h($b['building_name'] ? $b['building_name'] . ' — ' : '') ?><?= h($b['room_name']) ?></td>
          <td>₹<?= number_format($b['total_amount'], 0) ?></td>
          <td><?= $b['billing_unit_count'] ?></td>
          <td>₹<?= number_format($b['per_person_amount'], 0) ?></td>
          <td style="color:var(--rail);">₹<?= number_format($bd['collected'], 0) ?></td>
          <td style="color:<?= $bd['pending'] > 0 ? 'var(--rose)' : 'inherit' ?>;">₹<?= number_format($bd['pending'], 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>👤 व्यक्ती-निहाय तपशील (कोणी भरलं, कोणी बाकी)</h3>
  <table>
    <thead><tr><th>व्यक्ती</th><th>Room</th><th>रक्कम</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php foreach ($billDetails as $bd): $b = $bd['bill']; foreach ($bd['shares'] as $s): ?>
        <tr>
          <td><?= h($s['user_name'] ?? $s['occ_name']) ?></td>
          <td><?= h($b['building_name'] ? $b['building_name'] . ' — ' : '') ?><?= h($b['room_name']) ?></td>
          <td>₹<?= number_format($s['amount_due'], 0) ?></td>
          <td><?= $s['status'] === 'paid' ? '✅ भरलं (' . date('d M Y, h:i A', strtotime($s['paid_at'])) . ')' : '⏳ बाकी' ?></td>
        </tr>
      <?php endforeach; endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
