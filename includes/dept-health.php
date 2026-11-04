<?php
// 🩺 विभाग-आरोग्य — Dashboard आणि "सर्व विभाग" Showroom दोघांसाठी एकच हिशोब.
// $departments = departments च्या Rows (hod_name, campus_name, college_type सह).

/** प्रत्येक विभागाचे आरोग्य गुण व आकडे */
function deptHealthRows(PDO $pdo, $orgId, array $departments) {
    $rows = [];
    $deptIds = array_map(fn($d) => (int)$d['id'], $departments);
    if (!$deptIds) return $rows;
    $ph = implode(',', array_fill(0, count($deptIds), '?'));
    $metric = function ($sql, $params) use ($pdo) {
        $out = [];
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $r) { $out[(int)$r['department_id']] = $r; }
        } catch (PDOException $e) {}
        return $out;
    };
    $p = array_merge([$orgId], $deptIds);
    $mToday = $metric("SELECT department_id, COUNT(*) c FROM work_records WHERE organization_id = ? AND work_date = CURDATE() AND department_id IN ($ph) GROUP BY department_id", $p);
    $m30 = $metric("SELECT department_id, COUNT(*) total, SUM(status = 'completed') done FROM work_records WHERE organization_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND department_id IN ($ph) GROUP BY department_id", $p);
    $mTasks = $metric("SELECT department_id, SUM(status IN ('pending','in_progress')) open_cnt, SUM(status IN ('pending','in_progress') AND target_date < CURDATE()) overdue FROM tasks WHERE organization_id = ? AND department_id IN ($ph) GROUP BY department_id", $p);
    $mReq = $metric("SELECT department_id, SUM(status IN ('pending','in_progress')) open_cnt, SUM(status IN ('pending','in_progress') AND due_at < NOW()) overdue FROM work_requests WHERE organization_id = ? AND department_id IN ($ph) GROUP BY department_id", $p);
    $mRen = $metric("SELECT department_id, SUM(expiry_date < CURDATE()) expired, SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) due30 FROM renewal_items WHERE organization_id = ? AND status <> 'inactive' AND department_id IN ($ph) GROUP BY department_id", $p);

    foreach ($departments as $d) {
        $id = (int)$d['id'];
        $total = (int)($m30[$id]['total'] ?? 0);
        $done = (int)($m30[$id]['done'] ?? 0);
        $rate = $total > 0 ? (int)round($done * 100 / $total) : null;
        $tOver = (int)($mTasks[$id]['overdue'] ?? 0);
        $rOver = (int)($mReq[$id]['overdue'] ?? 0);
        $expired = (int)($mRen[$id]['expired'] ?? 0);
        // आरोग्य गुण — १०० पासून सुरू, अडचणींनुसार कमी
        $score = 100;
        if ($rate !== null) { $score -= (int)round((100 - $rate) * 0.4); }
        $score -= min(30, $tOver * 8);
        $score -= min(24, $rOver * 6);
        $score -= min(20, $expired * 10);
        $score = max(0, $score);
        $issues = [];
        if ($tOver) $issues[] = $tOver . ' Task मुदतबाह्य';
        if ($rOver) $issues[] = $rOver . ' विनंत्या SLA बाहेर';
        if ($expired) $issues[] = $expired . ' नूतनीकरण संपलं';
        $rows[] = [
            'id' => $id,
            'name' => $d['name_local'] ?: $d['name'],
            'icon' => $d['icon'] ?: 'fa-solid fa-building',
            'hod' => $d['hod_name'] ?? '',
            'group' => (empty($d['campus_name']) || ($d['college_type'] ?? '') === 'Trust HQ') ? 'Trust-स्तरीय' : $d['campus_name'],
            'today' => (int)($mToday[$id]['c'] ?? 0),
            'total30' => $total,
            'done30' => $done,
            'rate' => $rate,
            'openTasks' => (int)($mTasks[$id]['open_cnt'] ?? 0),
            'overdueTasks' => $tOver,
            'openReq' => (int)($mReq[$id]['open_cnt'] ?? 0),
            'overdueReq' => $rOver,
            'renDue' => (int)($mRen[$id]['due30'] ?? 0),
            'expired' => $expired,
            'score' => $score,
            'level' => deptHealthLevel($score),
            'issues' => $issues,
        ];
    }
    return $rows;
}

function deptHealthLevel($score) {
    return $score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'bad');
}

/** सर्व विभागांचं एकत्रित (Centralized) आरोग्य */
function deptHealthSummary(array $rows) {
    $n = count($rows);
    $sum = ['count' => $n, 'score' => 0, 'level' => 'good', 'good' => 0, 'warn' => 0, 'bad' => 0,
        'today' => 0, 'total30' => 0, 'done30' => 0, 'rate' => null, 'openTasks' => 0, 'overdueTasks' => 0,
        'openReq' => 0, 'overdueReq' => 0, 'renDue' => 0, 'expired' => 0, 'idleToday' => 0, 'best' => null, 'worst' => null];
    if (!$n) return $sum;
    foreach ($rows as $r) {
        $sum[$r['level']]++;
        foreach (['today', 'total30', 'done30', 'openTasks', 'overdueTasks', 'openReq', 'overdueReq', 'renDue', 'expired'] as $k) $sum[$k] += $r[$k];
        if ($r['today'] === 0) $sum['idleToday']++;
        if (!$sum['best'] || $r['score'] > $sum['best']['score']) $sum['best'] = $r;
        if (!$sum['worst'] || $r['score'] < $sum['worst']['score']) $sum['worst'] = $r;
    }
    $sum['score'] = (int)round(array_sum(array_column($rows, 'score')) / $n);
    $sum['level'] = deptHealthLevel($sum['score']);
    $sum['rate'] = $sum['total30'] > 0 ? (int)round($sum['done30'] * 100 / $sum['total30']) : null;
    return $sum;
}

/** गेल्या $days दिवसांतील विभागवार रोजची कामं — Sparkline साठी */
function deptHealthTrend(PDO $pdo, $orgId, array $deptIds, $days = 14) {
    $out = [];
    if (!$deptIds) return $out;
    $ph = implode(',', array_fill(0, count($deptIds), '?'));
    try {
        $st = $pdo->prepare("SELECT department_id, work_date, COUNT(*) c FROM work_records
            WHERE organization_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL " . ((int)$days - 1) . " DAY) AND department_id IN ($ph)
            GROUP BY department_id, work_date");
        $st->execute(array_merge([$orgId], $deptIds));
        foreach ($st->fetchAll() as $r) $out[(int)$r['department_id']][$r['work_date']] = (int)$r['c'];
    } catch (PDOException $e) {}
    $series = [];
    foreach ($deptIds as $id) {
        $s = [];
        for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $s[] = $out[$id][$d] ?? 0; }
        $series[$id] = $s;
    }
    return $series;
}
