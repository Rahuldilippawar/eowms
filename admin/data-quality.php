<?php
// 🗂️ Data दर्जा — अपूर्ण / डुप्लिकेट / विसरलेल्या नोंदी, तिथूनच दुरुस्तीची Link
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$orgId = activeOrgId();
$q = function ($sql, $p = []) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (PDOException $e) { return []; } };
$o = [$orgId];
$staffLink = fn($r) => '../staff/edit.php?id=' . (int)$r['id'];

// [key, गट, शीर्षक, का महत्त्वाचं, rows, link fn, label fn, severity]
$checks = [
    ['mobile', '👥 कर्मचारी', 'Mobile नंबर नाही', 'SOS, WhatsApp, OTP व तातडीच्या संपर्कासाठी',
        $q("SELECT u.id, u.name, d.name_local dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ? AND u.is_active = 1 AND (u.mobile IS NULL OR TRIM(u.mobile) = '') ORDER BY u.name", $o), $staffLink, null, 'warn'],
    ['dept', '👥 कर्मचारी', 'विभाग दिलेला नाही', 'विभाग नसेल तर कामं, अहवाल, Access नीट चालत नाहीत',
        $q("SELECT u.id, u.name, '' dept FROM users u WHERE u.organization_id = ? AND u.is_active = 1 AND u.department_id IS NULL AND u.role <> 'super_admin' ORDER BY u.name", $o), $staffLink, null, 'bad'],
    ['desig', '👥 कर्मचारी', 'पदनाम (Designation) नाही', 'ओळखपत्र, पत्रं व अहवालात रिकामं दिसतं',
        $q("SELECT u.id, u.name, d.name_local dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ? AND u.is_active = 1 AND (u.designation IS NULL OR TRIM(u.designation) = '') AND u.role <> 'super_admin' ORDER BY u.name", $o), $staffLink, null, 'info'],
    ['dob', '👥 कर्मचारी', 'जन्मतारीख / रुजू तारीख नाही', 'वाढदिवस शुभेच्छा, सेवाकाळ व निवृत्ती गणनेसाठी',
        $q("SELECT u.id, u.name, d.name_local dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ? AND u.is_active = 1 AND (u.date_of_birth IS NULL OR u.joining_date IS NULL) AND u.role <> 'super_admin' ORDER BY u.name", $o), $staffLink, null, 'info'],
    ['dupmob', '👥 कर्मचारी', 'एकच Mobile दोघांचा', 'चुकीची नोंद किंवा डुप्लिकेट कर्मचारी असू शकतो',
        $q("SELECT u.id, u.name, CONCAT('📱 ', u.mobile) dept FROM users u JOIN (SELECT mobile FROM users WHERE organization_id = ? AND is_active = 1 AND mobile IS NOT NULL AND TRIM(mobile) <> '' GROUP BY mobile HAVING COUNT(*) > 1) x ON x.mobile = u.mobile WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.mobile, u.name", [$orgId, $orgId]), $staffLink, null, 'bad'],
    ['dupname', '👥 कर्मचारी', 'एकाच नावाचे दोन सक्रिय कर्मचारी', 'एकच व्यक्ती दोनदा नोंदली गेली का ते तपासा',
        $q("SELECT u.id, u.name, d.name_local dept FROM users u LEFT JOIN departments d ON d.id = u.department_id JOIN (SELECT LOWER(TRIM(name)) n FROM users WHERE organization_id = ? AND is_active = 1 GROUP BY LOWER(TRIM(name)) HAVING COUNT(*) > 1) x ON x.n = LOWER(TRIM(u.name)) WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.name", [$orgId, $orgId]), $staffLink, null, 'warn'],
    ['nohod', '🗂️ रचना', 'HOD नसलेले विभाग', 'मंजुरी, विनंत्या व अहवाल कोणाकडे जाणार हे ठरत नाही',
        $q("SELECT d.id, COALESCE(NULLIF(d.name_local,''), d.name) name, CONCAT((SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1), ' कर्मचारी') dept FROM departments d WHERE d.organization_id = ? AND d.is_active = 1 AND d.hod_id IS NULL ORDER BY name", $o),
        fn($r) => '../departments/edit.php?id=' . (int)$r['id'], null, 'warn'],
    ['emptydept', '🗂️ रचना', 'एकही कर्मचारी नसलेले विभाग', 'वापरात नसेल तर निष्क्रिय करा — याद्या स्वच्छ राहतात',
        $q("SELECT d.id, COALESCE(NULLIF(d.name_local,''), d.name) name, '' dept FROM departments d WHERE d.organization_id = ? AND d.is_active = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.department_id = d.id AND u.is_active = 1) ORDER BY name", $o),
        fn($r) => '../departments/edit.php?id=' . (int)$r['id'], null, 'info'],
    ['draft', '📄 कामकाज', '30+ दिवस जुने Draft पत्र', 'विसरलेले Draft — पाठवा किंवा काढा',
        $q("SELECT l.id, l.subject name, CONCAT(DATEDIFF(NOW(), l.created_at), ' दिवस · ', COALESCE(u.name,'')) dept FROM letters l LEFT JOIN users u ON u.id = l.created_by WHERE l.organization_id = ? AND l.status = 'draft' AND l.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY l.created_at", $o),
        fn($r) => '../letters/view.php?id=' . (int)$r['id'], null, 'info'],
    ['oldtask', '📄 कामकाज', '60+ दिवस मुदत उलटलेले Tasks', 'खरंच चालू आहेत की बंद करायचे?',
        $q("SELECT t.id, t.title name, CONCAT(DATEDIFF(CURDATE(), t.target_date), ' दिवस उशीर · ', COALESCE(u.name,'')) dept FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.organization_id = ? AND t.status IN ('pending','in_progress') AND t.target_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) ORDER BY t.target_date", $o),
        fn($r) => '../tasks/index.php?q=' . rawurlencode($r['name']), null, 'warn'],
    ['renresp', '📄 कामकाज', 'जबाबदार व्यक्ती नसलेली नूतनीकरणं', 'मुदत संपण्याची आठवण कोणालाच जाणार नाही',
        $q("SELECT r.id, r.item_name name, CONCAT('मुदत ', DATE_FORMAT(r.expiry_date, '%d/%m/%Y')) dept FROM renewal_items r LEFT JOIN users u ON u.id = r.responsible_user_id WHERE r.organization_id = ? AND r.status IN ('active','due_soon','expired') AND (r.responsible_user_id IS NULL OR u.is_active = 0) ORDER BY r.expiry_date", $o),
        fn($r) => '../renewals/edit.php?id=' . (int)$r['id'], null, 'warn'],
    ['inactiveholder', '📄 कामकाज', 'निष्क्रिय कर्मचाऱ्याकडे अडकलेली पत्रं / Tasks', 'सोडून गेलेल्याकडे काम अडकलं आहे — दुसऱ्याकडे द्या',
        $q("SELECT l.id, l.subject name, CONCAT('✉️ पत्र · ', u.name) dept FROM letters l JOIN users u ON u.id = l.current_holder_id WHERE l.organization_id = ? AND l.status = 'in_progress' AND u.is_active = 0
             UNION ALL SELECT t.id, t.title, CONCAT('✅ Task · ', u.name) FROM tasks t JOIN users u ON u.id = t.assigned_to WHERE t.organization_id = ? AND t.status IN ('pending','in_progress') AND u.is_active = 0", [$orgId, $orgId]),
        fn($r) => strpos($r['dept'], '✉️') === 0 ? '../letters/view.php?id=' . (int)$r['id'] : '../tasks/index.php', null, 'bad'],
];

$total = 0; $bad = 0;
foreach ($checks as $c) { $total += count($c[4]); if ($c[7] === 'bad') { $bad += count($c[4]); } }
$clean = count(array_filter($checks, fn($c) => !$c[4]));
$score = (int)round($clean * 100 / count($checks));

$pageTitle = 'Data दर्जा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">🗂️ Data दर्जा तपासणी</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">अपूर्ण, डुप्लिकेट किंवा विसरलेल्या नोंदी — प्रत्येक नावावर Click करून तिथेच दुरुस्त करा.</div>
<?= saNav('data-quality') ?>
<?php smartTiles([
    ['label' => 'Data दर्जा गुण', 'value' => $score . '%', 'sub' => $clean . '/' . count($checks) . ' तपासण्या स्वच्छ', 'tone' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'bad')],
    ['label' => 'दुरुस्त करायच्या नोंदी', 'value' => $total, 'tone' => $total ? 'warn' : 'ok'],
    ['label' => 'गंभीर', 'value' => $bad, 'sub' => 'विभाग नाही, डुप्लिकेट Mobile, अडकलेलं काम', 'tone' => $bad ? 'bad' : 'ok'],
]); ?>
<div class="dq-grid">
  <?php $grp = null; foreach ($checks as [$key, $g, $title, $why, $rows, $link, , $sev]): ?>
    <?php if ($g !== $grp): $grp = $g; ?><div class="dq-group"><?= h($g) ?></div><?php endif; ?>
    <details class="dq <?= $rows ? $sev : 'ok' ?>" <?= $rows && $sev === 'bad' ? 'open' : '' ?>>
      <summary><span class="dot"></span><span class="t"><b><?= h($title) ?></b><small><?= h($why) ?></small></span><span class="c"><?= $rows ? count($rows) : '✓' ?></span></summary>
      <?php if ($rows): ?><div class="dq-list">
        <?php foreach (array_slice($rows, 0, 40) as $r): ?><a href="<?= h($link($r)) ?>"><?= h(mb_strimwidth((string)$r['name'], 0, 60, '…')) ?><?= !empty($r['dept']) ? ' <small>' . h($r['dept']) . '</small>' : '' ?></a><?php endforeach; ?>
        <?php if (count($rows) > 40): ?><span style="font-size:12px;color:var(--text-dim);">+ <?= count($rows) - 40 ?> आणखी</span><?php endif; ?>
      </div><?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>
<style>
  .dq-group { font-size: 12px; font-weight: 700; color: var(--text-dim); margin: 14px 2px 6px; text-transform: uppercase; letter-spacing: .04em; }
  .dq { border: 1px solid var(--line); border-radius: 12px; margin-bottom: 8px; background: var(--panel, #fff); }
  .dq summary { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; list-style: none; } .dq summary::-webkit-details-marker { display: none; }
  .dq .dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; } .dq.bad .dot { background: #B42318; } .dq.warn .dot { background: #B45309; } .dq.info .dot { background: #3B82F6; } .dq.ok .dot { background: #137A4B; }
  .dq .t { flex: 1; min-width: 0; } .dq .t b { display: block; font-size: 13.5px; } .dq .t small { font-size: 11.5px; color: var(--text-dim); }
  .dq .c { font-weight: 700; font-size: 15px; min-width: 32px; text-align: center; border-radius: 999px; padding: 1px 9px; background: var(--canvas); }
  .dq.bad .c { color: #B42318; } .dq.warn .c { color: #B45309; } .dq.ok .c { color: #137A4B; } .dq.ok { opacity: .75; }
  .dq-list { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 14px 12px 34px; }
  .dq-list a { border: 1px solid var(--line); border-radius: 8px; padding: 4px 9px; font-size: 12.5px; text-decoration: none; color: var(--text); } .dq-list a:hover { border-color: var(--rail); color: var(--rail); }
  .dq-list small { color: var(--text-dim); font-size: 11px; }
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
