<?php
// 🎵 संगीत विभाग — Smart आढावा (music/index.php मधून): उपस्थिती, परीक्षांचे निकाल, विषयनिहाय विद्यार्थी,
// वाद्यांची स्थिती, जुनं Tuning, बाहेर दिलेली वाद्यं, आगामी परीक्षा/कार्यक्रम.
require_once __DIR__ . '/../smart-insights.php';
try {
    $a = $pdo->prepare("SELECT SUM(ma.status = 'present') p, COUNT(*) n FROM music_attendance ma JOIN music_enrollments e ON e.id = ma.enrollment_id JOIN music_students s ON s.id = e.student_id
        WHERE s.organization_id = ? AND ma.attendance_date >= CURDATE() - INTERVAL 29 DAY");
    $a->execute([$orgId]);
    $att = $a->fetch() ?: [];
    $attPct = (int)$att['n'] ? (int)round($att['p'] * 100 / $att['n']) : null;
    $x = $pdo->prepare("SELECT SUM(e.result IN ('pass','distinction')) pass, SUM(e.result = 'distinction') dist, SUM(e.result = 'fail') fail, SUM(e.result = 'pending' AND e.exam_date >= CURDATE()) upcoming
        FROM music_exams e JOIN music_students s ON s.id = e.student_id WHERE s.organization_id = ? AND e.exam_date >= CURDATE() - INTERVAL 1 YEAR");
    $x->execute([$orgId]);
    $ex = $x->fetch() ?: [];
    $done = (int)$ex['pass'] + (int)$ex['fail'];
    smartTiles([
        ['label' => 'उपस्थिती (30 दिवस)', 'value' => $attPct === null ? '—' : $attPct . '%', 'tone' => $attPct === null ? '' : ($attPct >= 80 ? 'ok' : ($attPct >= 60 ? 'warn' : 'bad'))],
        ['label' => 'परीक्षा निकाल (12 महिने)', 'value' => $done ? (int)round($ex['pass'] * 100 / $done) . '% पास' : '—', 'sub' => (int)$ex['dist'] . ' Distinction · ' . (int)$ex['fail'] . ' नापास'],
        ['label' => 'आगामी परीक्षा', 'value' => (int)$ex['upcoming'], 'href' => 'exams.php'],
    ]);
    $al = [];
    $t = $pdo->prepare("SELECT COUNT(*) FROM music_instruments WHERE organization_id = ? AND is_active = 1 AND (last_tuned_date IS NULL OR last_tuned_date < CURDATE() - INTERVAL 90 DAY)");
    $t->execute([$orgId]);
    if ($n = (int)$t->fetchColumn()) $al[] = ['warn', '🎻 ' . $n . ' वाद्यांचं Tuning 90 दिवसांत झालेलं नाही', 'instruments.php'];
    $h = $pdo->prepare("SELECT COUNT(*) FROM music_instruments WHERE organization_id = ? AND is_active = 1 AND (current_holder_id IS NOT NULL OR (current_holder_name IS NOT NULL AND current_holder_name <> ''))");
    $h->execute([$orgId]);
    if ($n = (int)$h->fetchColumn()) $al[] = ['info', '🎒 ' . $n . ' वाद्यं सध्या बाहेर दिलेली आहेत', 'instruments.php'];
    $lo = $pdo->prepare("SELECT s.name, SUM(ma.status = 'present') p, COUNT(*) n FROM music_attendance ma JOIN music_enrollments e ON e.id = ma.enrollment_id JOIN music_students s ON s.id = e.student_id
        WHERE s.organization_id = ? AND s.is_active = 1 AND ma.attendance_date >= CURDATE() - INTERVAL 29 DAY GROUP BY s.id, s.name HAVING n >= 4 AND p / n < 0.6 ORDER BY p / n LIMIT 3");
    $lo->execute([$orgId]);
    foreach ($lo->fetchAll() as $r) $al[] = ['bad', '🙋 ' . $r['name'] . ' — उपस्थिती फक्त ' . (int)round($r['p'] * 100 / $r['n']) . '% (30 दिवस)', 'students.php'];
    smartAlerts($al);
    $sub = $pdo->prepare("SELECT e.subject, COUNT(*) n FROM music_enrollments e JOIN music_students s ON s.id = e.student_id WHERE s.organization_id = ? AND e.status = 'active' GROUP BY e.subject ORDER BY n DESC LIMIT 8");
    $sub->execute([$orgId]);
    $subs = $sub->fetchAll();
    $cond = $pdo->prepare("SELECT condition_status c, COUNT(*) n FROM music_instruments WHERE organization_id = ? AND is_active = 1 GROUP BY c");
    $cond->execute([$orgId]);
    $cl = ['excellent' => ['उत्कृष्ट', 'var(--rail)'], 'good' => ['चांगलं', '#3B6FC9'], 'needs_tuning' => ['Tuning हवं', 'var(--amber)'], 'under_repair' => ['दुरुस्तीत', '#E07A3F'], 'damaged' => ['खराब', 'var(--rose)']];
    $conds = array_map(fn($r) => ['name' => $cl[$r['c']][0] ?? $r['c'], 'value' => (int)$r['n'], 'color' => $cl[$r['c']][1] ?? 'var(--rail)'], $cond->fetchAll());
    if ($subs || $conds) {
        echo '<div class="row g-3 mb-3"><div class="col-lg-6">';
        smartBars('🎼 विषयनिहाय विद्यार्थी', 'चालू प्रवेश', array_map(fn($r) => ['name' => $r['subject'], 'value' => (int)$r['n']], $subs), '#8A5CD6', 'प्रवेश नाहीत');
        echo '</div><div class="col-lg-6">';
        smartBars('🎻 वाद्यांची स्थिती', '', $conds, 'var(--rail)', 'वाद्यं नाहीत');
        echo '</div></div>';
    }
} catch (PDOException $e) {}
