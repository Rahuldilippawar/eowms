<?php
// Command Center (index.php) च्या "Campus-निहाय विभाजन" Section साठी
// वापरलेली Helper Functions — आधी centralized-dashboard.php या वेगळ्या,
// स्वतंत्र पानावर होती; आता Command Center चाच एक भाग म्हणून इथे आहेत.

function getDashboardRowMetrics($pdo, $orgId, $campusIdOrNull, $isTrustLevel = false) {
    if ($isTrustLevel) {
        $deptFilter = "(d.campus_id IS NULL OR c.college_type = 'Trust HQ')";
        $deptJoin = "LEFT JOIN campuses c ON c.id = d.campus_id";
        $params = [$orgId];
    } else {
        $deptFilter = "d.campus_id = ?";
        $deptJoin = "";
        $params = [$orgId, $campusIdOrNull];
    }
    $deptStmt = $pdo->prepare("SELECT d.id FROM departments d $deptJoin WHERE d.organization_id = ? AND d.is_active = 1 AND $deptFilter");
    $deptStmt->execute($params);
    $deptIds = array_column($deptStmt->fetchAll(), 'id');

    $trendLabels = []; $trendTotal = []; $trendCompleted = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trendLabels[] = date('d M', strtotime($d));
        if (empty($deptIds)) { $trendTotal[] = 0; $trendCompleted[] = 0; continue; }
        $ph2 = implode(',', array_fill(0, count($deptIds), '?'));
        $t = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id IN ($ph2) AND work_date = ?");
        $t->execute(array_merge($deptIds, [$d]));
        $trendTotal[] = (int)$t->fetch()['c'];
        $c = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id IN ($ph2) AND work_date = ? AND status = 'completed'");
        $c->execute(array_merge($deptIds, [$d]));
        $trendCompleted[] = (int)$c->fetch()['c'];
    }

    if (empty($deptIds)) {
        return ['dept_count' => 0, 'staff_count' => 0, 'pending_tasks' => 0, 'completed_tasks' => 0, 'renewals_due' => 0, 'renewals_expired' => 0, 'pending_letters' => 0, 'completed_letters' => 0, 'trend_labels' => $trendLabels, 'trend_total' => $trendTotal, 'trend_completed' => $trendCompleted, 'dept_distribution' => []];
    }
    $ph = implode(',', array_fill(0, count($deptIds), '?'));

    $staffStmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE department_id IN ($ph) AND is_active = 1");
    $staffStmt->execute($deptIds);

    $taskStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM tasks WHERE department_id IN ($ph) GROUP BY status");
    $taskStmt->execute($deptIds);
    $taskCounts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
    foreach ($taskStmt->fetchAll() as $r) { $taskCounts[$r['status']] = (int)$r['c']; }

    $renewalStmt = $pdo->prepare("SELECT COUNT(*) c FROM renewal_items WHERE department_id IN ($ph) AND expiry_date < CURDATE()");
    $renewalStmt->execute($deptIds);
    $renewalsExpired = (int)$renewalStmt->fetch()['c'];
    $renewalStmt2 = $pdo->prepare("SELECT COUNT(*) c FROM renewal_items WHERE department_id IN ($ph) AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $renewalStmt2->execute($deptIds);
    $renewalsDueSoon = (int)$renewalStmt2->fetch()['c'];

    $letterStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM letters WHERE department_id IN ($ph) GROUP BY status");
    $letterStmt->execute($deptIds);
    $pendingLetters = 0; $completedLetters = 0;
    foreach ($letterStmt->fetchAll() as $r) {
        if ($r['status'] === 'completed') { $completedLetters = (int)$r['c']; } else { $pendingLetters += (int)$r['c']; }
    }

    $ph3 = implode(',', array_fill(0, count($deptIds), '?'));
    $deptDistStmt = $pdo->prepare("
        SELECT d.name_local AS name, COUNT(wr.id) AS cnt
        FROM departments d
        LEFT JOIN work_records wr ON wr.department_id = d.id AND wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE d.id IN ($ph3)
        GROUP BY d.id, d.name_local
        HAVING cnt > 0
        ORDER BY cnt DESC
    ");
    $deptDistStmt->execute($deptIds);
    $deptDistribution = $deptDistStmt->fetchAll();

    return [
        'dept_count' => count($deptIds), 'staff_count' => (int)$staffStmt->fetch()['c'],
        'pending_tasks' => $taskCounts['pending'] + $taskCounts['in_progress'], 'completed_tasks' => $taskCounts['completed'],
        'renewals_due' => $renewalsDueSoon, 'renewals_expired' => $renewalsExpired,
        'pending_letters' => $pendingLetters, 'completed_letters' => $completedLetters,
        'trend_labels' => $trendLabels, 'trend_total' => $trendTotal, 'trend_completed' => $trendCompleted, 'dept_distribution' => $deptDistribution,
    ];
}

/** एका "Entity" (Overall/Trust/College) साठी Icon-KPI-Card स्वरूपाचा Block. */
function renderDashboardEntityCard($title, $subtitle, $m, $chartId, $accentColor) {
    ?>
    <div class="dash-hero" style="background:<?= $accentColor ?>;margin-bottom:20px;">
      <div class="dash-hero-bg"></div>
      <div style="position:relative;z-index:1;">
        <div style="font-family:'Space Grotesk';font-weight:700;font-size:20px;color:#fff;"><?= $title ?></div>
        <?php if ($subtitle): ?><div style="font-size:12px;color:#B7BBD6;margin-top:2px;"><?= $subtitle ?></div><?php endif; ?>
      </div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-between align-items-start"><span class="kpi-label">विभाग</span><div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-building"></i></div></div><div class="kpi-value"><?= $m['dept_count'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-between align-items-start"><span class="kpi-label">Staff</span><div class="kpi-icon" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-users"></i></div></div><div class="kpi-value"><?= $m['staff_count'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-between align-items-start"><span class="kpi-label">प्रलंबित Tasks</span><div class="kpi-icon" style="background:#FBEBD6;color:var(--amber);"><i class="fa-solid fa-list-check"></i></div></div><div class="kpi-value"><?= $m['pending_tasks'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-between align-items-start"><span class="kpi-label">मुदत संपलेली नूतनीकरणे</span><div class="kpi-icon" style="background:var(--rose-soft);color:var(--rose);"><i class="fa-solid fa-shield-halved"></i></div></div><div class="kpi-value" style="color:<?= $m['renewals_expired'] > 0 ? 'var(--rose)' : 'var(--ink)' ?>;"><?= $m['renewals_expired'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-between align-items-start"><span class="kpi-label">30 दिवसांत नूतनीकरण</span><div class="kpi-icon" style="background:#FBEBD6;color:var(--amber);"><i class="fa-solid fa-clock"></i></div></div><div class="kpi-value"><?= $m['renewals_due'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-between align-items-start"><span class="kpi-label">प्रलंबित पत्रे</span><div class="kpi-icon" style="background:#FBEBD6;color:var(--amber);"><i class="fa-regular fa-envelope"></i></div></div><div class="kpi-value"><?= $m['pending_letters'] ?></div></div></div>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-lg-7">
        <div class="panel">
          <div class="panel-head"><div class="title">गेल्या ७ दिवसांचा कल</div><div class="sub">रोजची कामं — एकूण vs पूर्ण</div></div>
          <div style="padding:18px 20px;"><canvas id="<?= $chartId ?>_trend" height="90"></canvas></div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="panel h-100">
          <div class="panel-head"><div class="title">विभागनिहाय काम वितरण</div><div class="sub">मागील ३० दिवस</div></div>
          <div style="padding:16px 20px;">
            <?php if (!empty($m['dept_distribution'])): ?>
              <div style="position:relative;height:180px;"><canvas id="<?= $chartId ?>_deptdist"></canvas></div>
            <?php else: ?>
              <p style="font-size:12.5px;color:var(--text-dim);text-align:center;padding:40px 0;">मागील ३० दिवसांत कुठलीही कामाची नोंद नाही</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <script>
    window.addEventListener('DOMContentLoaded', function () {
      new Chart(document.getElementById('<?= $chartId ?>_trend'), {
        type: 'line',
        data: {
          labels: <?= json_encode($m['trend_labels']) ?>,
          datasets: [
            { label: 'एकूण', data: <?= json_encode($m['trend_total']) ?>, borderColor: '#6B7080', backgroundColor: 'rgba(107,112,128,.08)', tension: .35, fill: true },
            { label: 'पूर्ण', data: <?= json_encode($m['trend_completed']) ?>, borderColor: '#1B8A83', backgroundColor: 'rgba(27,138,131,.12)', tension: .35, fill: true }
          ]
        },
        options: {
          plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 12 }, boxWidth: 12 } } },
          scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#EEF0F4' } }
          }
        }
      });
      <?php if (!empty($m['dept_distribution'])): ?>
      new Chart(document.getElementById('<?= $chartId ?>_deptdist'), {
        type: 'doughnut',
        data: {
          labels: <?= json_encode(array_column($m['dept_distribution'], 'name'), JSON_UNESCAPED_UNICODE) ?>,
          datasets: [{
            data: <?= json_encode(array_map('intval', array_column($m['dept_distribution'], 'cnt'))) ?>,
            backgroundColor: ['#1B8A83','#C97F1D','#B23A48','#6B7080','#3B6FC9','#8A5CD6','#2FA37A','#D4A017','#5D8AA8','#A0522D'],
            borderWidth: 2, borderColor: '#fff'
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 10 }, boxWidth: 9, padding: 6 } } },
          cutout: '60%'
        }
      });
      <?php endif; ?>
    });
    </script>
    <?php
}
