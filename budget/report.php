<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_budget');
$fy = (int)($_GET['fy'] ?? getCurrentFinancialYear());
$deptParam = $_GET['department_id'] ?? ($user['department_id'] ?? 0);
$isAllView = $isMgmt && $deptParam === 'all';
$deptId = $isAllView ? 0 : (int)$deptParam;

if (!$isMgmt && !$isAllView && (int)($user['department_id'] ?? 0) !== $deptId) {
    http_response_code(403);
    die('तुम्हाला दुसऱ्या विभागाचं Budget अहवाल पाहण्याची परवानगी नाही.');
}

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$allDeptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$allDeptsStmt->execute([$orgId]);
$allDepts = $allDeptsStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();

$categoryColor = ['नवीन खरेदी' => '#1B8A83', 'देखभाल' => '#C97F1D', 'इंधन' => '#2255AA', 'इतर' => '#8A90AC'];

// Year-over-Year Comparison — मागच्या 3 आर्थिक वर्षांचा खर्च
$yoySpent = [];
for ($y = $fy - 2; $y <= $fy; $y++) {
    if (!$isAllView) {
        $yoyStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) c FROM budget_transactions WHERE department_id = ? AND financial_year = ?");
        $yoyStmt->execute([$deptId, $y]);
    } else {
        $yoyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(bt.amount),0) c FROM budget_transactions bt
            JOIN departments d ON d.id = bt.department_id WHERE d.organization_id = ? AND bt.financial_year = ?
        ");
        $yoyStmt->execute([$orgId, $y]);
    }
    $yoySpent[$y] = (float)$yoyStmt->fetch()['c'];
}

if (!$isAllView) {
    $deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
    $deptStmt->execute([$deptId, $orgId]);
    $dept = $deptStmt->fetch();
    if (!$dept) { die('विभाग सापडला नाही.'); }

    $budget = getBudgetStatus($pdo, $orgId, $deptId, $fy);

    $txnStmt = $pdo->prepare("
        SELECT bt.*, u.name AS created_by_name FROM budget_transactions bt
        LEFT JOIN users u ON u.id = bt.created_by
        WHERE bt.department_id = ? AND bt.financial_year = ? ORDER BY bt.created_at DESC
    ");
    $txnStmt->execute([$deptId, $fy]);
    $transactions = $txnStmt->fetchAll();
} else {
    $allBudgets = [];
    foreach ($allDepts as $d) {
        $allBudgets[$d['id']] = getBudgetStatus($pdo, $orgId, $d['id'], $fy);
    }
    $grandAllocated = array_sum(array_column($allBudgets, 'allocated'));
    $grandSpent = array_sum(array_column($allBudgets, 'spent'));
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>Budget अहवाल<?= $isAllView ? ' — सर्व विभाग' : ' — ' . h($dept['name_local'] ?: $dept['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:12.5px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;flex-wrap:wrap;gap:10px;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .toolbar select{border-radius:6px;border:none;padding:6px 10px;font-size:12.5px;}
  .doc{max-width:1000px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:19px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-top:6px;color:var(--rail);}
  .meta{text-align:center;font-size:12px;color:var(--text-dim);margin-top:4px;}
  .summary-row{display:flex;gap:14px;margin:22px 0;justify-content:center;flex-wrap:wrap;}
  .summary-box{border:1px solid var(--line);border-radius:10px;padding:12px 22px;text-align:center;min-width:120px;}
  .summary-box .lbl{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;}
  .summary-box .val{font-family:'Space Grotesk';font-weight:700;font-size:19px;}
  table{width:100%;border-collapse:collapse;margin-top:12px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:11.5px;}
  .chip{padding:2px 10px;border-radius:14px;font-size:9.5px;font-weight:700;color:#fff;white-space:nowrap;}
  h3{font-family:'Space Grotesk';font-size:13.5px;color:var(--ink);margin:22px 0 8px;}
  @media print{ .toolbar{display:none;} #eowmsLangSelect, #google_translate_element{display:none !important;} body{background:#fff;} .doc{margin:0;box-shadow:none;} }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
  <a href="index.php">← मागे</a>
  <form method="GET">
    <?php if ($isMgmt): ?>
      <?php if (count($campusesForCascade) > 1): ?>
        <select id="campusSelect" onchange="loadDeptsCascade_budRep(this.value)">
          <option value="">-- College/School --</option>
          <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="department_id" id="departmentSelect" onchange="this.form.submit()">
        <option value="all" <?= $isAllView ? 'selected' : '' ?>>🏢 सर्व विभाग (एकत्र)</option>
        <?php foreach ($allDepts as $d): ?>
          <option value="<?= $d['id'] ?>" <?= !$isAllView && $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_budRep(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value="all">🏢 सर्व विभाग (एकत्र)</option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
    <?php else: ?>
      <input type="hidden" name="department_id" value="<?= $deptId ?>">
    <?php endif; ?>
    <select name="fy" onchange="this.form.submit()">
      <?php for ($y = getCurrentFinancialYear() + 1; $y >= getCurrentFinancialYear() - 3; $y--): ?>
        <option value="<?= $y ?>" <?= $fy === $y ? 'selected' : '' ?>>FY <?= $y ?>-<?= substr($y + 1, 2) ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn" type="submit" style="padding:6px 14px;">पहा</button>
  </form>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= h($org['logo_path']) ?>" style="height:52px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">विभाग Budget अहवाल<?= $isAllView ? ' — सर्व विभाग (Consolidated)' : '' ?></div>
  <div class="meta"><?= $isAllView ? 'संपूर्ण संस्था' : h($dept['name_local'] ?: $dept['name']) . ' विभाग' ?> · <?= h(formatFinancialYearLabel($fy)) ?></div>

  <?php if ($isAllView): ?>

    <div class="summary-row">
      <div class="summary-box"><div class="lbl">एकूण दिलेलं Budget</div><div class="val" style="color:#1B8A83;">₹<?= number_format($grandAllocated, 0) ?></div></div>
      <div class="summary-box"><div class="lbl">एकूण खर्च</div><div class="val" style="color:#C97F1D;">₹<?= number_format($grandSpent, 0) ?></div></div>
      <div class="summary-box"><div class="lbl">एकूण शिल्लक</div><div class="val" style="color:<?= ($grandAllocated - $grandSpent) < 0 ? '#B23A48' : '#1B8A83' ?>;">₹<?= number_format($grandAllocated - $grandSpent, 0) ?></div></div>
    </div>

    <h3>विभाग-निहाय तुलना</h3>
    <table>
      <thead><tr><th>विभाग</th><th>दिलेलं</th><th>नवीन खरेदी</th><th>देखभाल</th><th>इंधन</th><th>इतर</th><th>एकूण खर्च</th><th>शिल्लक</th></tr></thead>
      <tbody>
        <?php foreach ($allDepts as $d): $b = $allBudgets[$d['id']]; ?>
          <tr>
            <td style="font-weight:600;"><?= h($d['name_local'] ?: $d['name']) ?></td>
            <td>₹<?= number_format($b['allocated'], 0) ?></td>
            <td>₹<?= number_format($b['by_category']['नवीन खरेदी'] ?? 0, 0) ?></td>
            <td>₹<?= number_format($b['by_category']['देखभाल'] ?? 0, 0) ?></td>
            <td>₹<?= number_format($b['by_category']['इंधन'] ?? 0, 0) ?></td>
            <td>₹<?= number_format($b['by_category']['इतर'] ?? 0, 0) ?></td>
            <td style="font-weight:700;">₹<?= number_format($b['spent'], 0) ?></td>
            <td style="font-weight:700;color:<?= $b['remaining'] < 0 ? '#B23A48' : '#1A1D29' ?>;">₹<?= number_format($b['remaining'], 0) ?><?= $b['remaining'] < 0 ? ' ⚠️' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php else: ?>

    <div class="summary-row">
      <div class="summary-box"><div class="lbl">दिलेलं Budget</div><div class="val" style="color:#1B8A83;">₹<?= number_format($budget['allocated'], 0) ?></div></div>
      <div class="summary-box"><div class="lbl">एकूण खर्च</div><div class="val" style="color:#C97F1D;">₹<?= number_format($budget['spent'], 0) ?></div></div>
      <div class="summary-box"><div class="lbl">शिल्लक</div><div class="val" style="color:<?= $budget['remaining'] < 0 ? '#B23A48' : '#1B8A83' ?>;">₹<?= number_format($budget['remaining'], 0) ?></div></div>
      <div class="summary-box"><div class="lbl">वापर</div><div class="val"><?= $budget['percent_used'] ?>%</div></div>
    </div>

    <h3>📈 Year-over-Year तुलना</h3>
    <div style="max-width:500px;margin:0 auto 20px;"><canvas id="yoyChart" height="180"></canvas></div>
    <script>
    new Chart(document.getElementById('yoyChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_map(fn($y) => "FY $y-" . substr($y + 1, 2), array_keys($yoySpent))) ?>,
        datasets: [{ label: 'खर्च (₹)', data: <?= json_encode(array_values($yoySpent)) ?>, backgroundColor: ['#D7DAE0', '#D7DAE0', '#1B8A83'], borderRadius: 6 }]
      },
      options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: function (v) { return '₹' + v.toLocaleString('en-IN'); } } } } }
    });
    </script>

    <h3>श्रेणी-निहाय खर्च</h3>
    <table>
      <thead><tr><th>श्रेणी</th><th>एकूण खर्च</th></tr></thead>
      <tbody>
        <?php foreach (['नवीन खरेदी', 'देखभाल', 'इंधन', 'इतर'] as $cat): ?>
          <tr><td><span class="chip" style="background:<?= $categoryColor[$cat] ?>;"><?= $cat ?></span></td><td>₹<?= number_format($budget['by_category'][$cat] ?? 0, 0) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h3>संपूर्ण व्यवहार नोंद (Transaction Ledger)</h3>
    <table>
      <thead><tr><th>दिनांक</th><th>श्रेणी</th><th>तपशील</th><th>नोंदवलं</th><th>रक्कम</th></tr></thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text-dim);">या आर्थिक वर्षात कोणताही खर्च नोंदवला गेला नाही</td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $t): ?>
          <tr>
            <td><?= date('d M Y', strtotime($t['created_at'])) ?></td>
            <td><span class="chip" style="background:<?= $categoryColor[$t['category']] ?? '#666' ?>;"><?= h($t['category']) ?></span></td>
            <td><?= h($t['description'] ?? '—') ?></td>
            <td><?= h($t['created_by_name'] ?? '—') ?></td>
            <td style="font-weight:700;">₹<?= number_format($t['amount'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php endif; ?>

  <div style="margin-top:24px;font-size:10.5px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>

</body>
</html>
