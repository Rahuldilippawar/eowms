<?php
// 📥 कामाच्या विनंत्या — Smart विश्लेषण (requests/index.php मधून; $scopeSql/$scopeParams त्याच पानाचे)
// मागील ३० दिवस: निपटारा-वेळ, SLA पालन, समाधान; उघड्या विनंत्यांचं वय; प्रकार व मागणी करणारे विभाग.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            SUM(r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) new30,
            SUM(r.status IN ('completed','closed') AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) done30,
            AVG(CASE WHEN r.status IN ('completed','closed') AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN TIMESTAMPDIFF(MINUTE, r.created_at, r.completed_at) END) avg_min,
            SUM(r.status IN ('completed','closed') AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND r.due_at IS NOT NULL AND r.completed_at <= r.due_at) in_sla,
            SUM(r.status IN ('completed','closed') AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND r.due_at IS NOT NULL) with_sla,
            AVG(CASE WHEN r.rating IS NOT NULL AND r.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN r.rating END) rating,
            SUM(r.reopened_count > 0 AND r.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) reopened,
            SUM(r.status IN ('pending','in_progress') AND r.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) age1,
            SUM(r.status IN ('pending','in_progress') AND r.created_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND r.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)) age3,
            SUM(r.status IN ('pending','in_progress') AND r.created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) age7,
            SUM(r.status IN ('pending','in_progress') AND r.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) age_old,
            SUM(r.status IN ('pending','in_progress') AND r.assigned_to_user_id IS NULL) unassigned
        FROM work_requests r" . $scopeSql);
    $st->execute($scopeParams);
    $ra = $st->fetch() ?: [];
    $avgMin = (float)($ra['avg_min'] ?? 0);
    $avgTxt = !$avgMin ? '—' : ($avgMin >= 1440 ? round($avgMin / 1440, 1) . ' दिवस' : ($avgMin >= 60 ? round($avgMin / 60, 1) . ' तास' : round($avgMin) . ' मि.'));
    $slaPct = (int)($ra['with_sla'] ?? 0) ? (int)round($ra['in_sla'] * 100 / $ra['with_sla']) : null;

    smartTiles([
        ['label' => 'मागील ३० दिवस', 'value' => (int)$ra['new30'] . ' नवीन', 'sub' => (int)$ra['done30'] . ' निपटल्या'],
        ['label' => 'सरासरी निपटारा-वेळ', 'value' => $avgTxt, 'sub' => 'नोंद ते पूर्ण (३० दिवस)'],
        ['label' => 'SLA पालन', 'value' => $slaPct === null ? '—' : $slaPct . '%', 'tone' => $slaPct === null ? '' : ($slaPct >= 90 ? 'ok' : ($slaPct >= 70 ? 'warn' : 'bad')), 'sub' => 'मुदतीत पूर्ण झालेल्या'],
        ['label' => 'समाधान (९० दिवस)', 'value' => $ra['rating'] ? '⭐ ' . number_format((float)$ra['rating'], 1) . '/5' : '—', 'sub' => (int)$ra['reopened'] ? (int)$ra['reopened'] . ' पुन्हा उघडल्या' : ''],
    ]);
    $al = [];
    if ((int)$ra['unassigned']) $al[] = ['warn', (int)$ra['unassigned'] . ' उघड्या विनंत्या कोणालाही नेमलेल्या नाहीत', '?status=open'];
    if ((int)$ra['age_old']) $al[] = ['bad', (int)$ra['age_old'] . ' विनंत्या ७ दिवसांपेक्षा जास्त उघड्या आहेत', '?status=open'];
    smartAlerts($al);

    // उघड्या विनंत्यांचं वय + प्रकार + मागणी करणारे विभाग
    $cat = $pdo->prepare("SELECT r.category, COUNT(*) c FROM work_requests r" . $scopeSql . " AND r.status IN ('pending','in_progress') GROUP BY r.category ORDER BY c DESC");
    $cat->execute($scopeParams);
    $cats = $cat->fetchAll();
    $rq = $pdo->prepare("SELECT COALESCE(rd.name_local, 'बाहेरील / सार्वजनिक') name, COUNT(*) c FROM work_requests r LEFT JOIN departments rd ON rd.id = r.requesting_department_id"
        . $scopeSql . " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY name ORDER BY c DESC LIMIT 6");
    $rq->execute($scopeParams);
    $reqDepts = $rq->fetchAll();
    $ageRows = [
        ['name' => '२४ तासांच्या आत', 'value' => (int)$ra['age1'], 'color' => 'var(--rail)'],
        ['name' => '१–३ दिवस', 'value' => (int)$ra['age3'], 'color' => '#3B6FC9'],
        ['name' => '३–७ दिवस', 'value' => (int)$ra['age7'], 'color' => 'var(--amber)'],
        ['name' => '७+ दिवस', 'value' => (int)$ra['age_old'], 'color' => 'var(--rose)'],
    ];
    echo '<div class="row g-3 mb-3"><div class="col-lg-4">';
    smartBars('⏳ उघड्या विनंत्यांचं वय', 'जुन्या विनंत्या आधी हाताळा', array_sum(array_column($ageRows, 'value')) ? $ageRows : [], 'var(--rail)', '✅ एकही विनंती उघडी नाही');
    echo '</div><div class="col-lg-4">';
    smartBars('🗂️ उघड्या — प्रकारानुसार', '', array_map(fn($c) => ['name' => TICKET_CATEGORIES[$c['category']] ?? $c['category'], 'value' => (int)$c['c']], $cats), '#8A5CD6', 'उघड्या विनंत्या नाहीत');
    echo '</div><div class="col-lg-4">';
    smartBars('🏢 सर्वाधिक विनंत्या कोणाकडून', 'मागील ३० दिवस', array_map(fn($d) => ['name' => $d['name'], 'value' => (int)$d['c']], $reqDepts), 'var(--amber)', 'नोंदी नाहीत');
    echo '</div></div>';
} catch (PDOException $e) {}
