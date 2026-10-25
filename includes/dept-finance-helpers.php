<?php
// विभाग Finance — सामायिक Helper Functions (Material Purchase, Renewal, Maintenance तिन्हीसाठी)

/**
 * सध्याचा User कोणत्या Department चं Finance बघू शकतो — HOD फक्त स्वतःचं, Management सगळं.
 * परत: null = सगळे विभाग; >0 = फक्त तो विभाग; 0 = कुठलाच नाही (विभाग नेमलेला नाही).
 * Callers नी "=== null" तपासावं — 0 म्हणजे "सगळं" नाही!
 */
function deptFinanceAccessibleDeptId($user) {
    if (isManagementOrAbove() || hasRole('super_admin') || hasPermission('manage_budget')) { return null; } // null = सगळे विभाग
    $deptId = (int)($user['department_id'] ?? 0);
    if ($deptId) { return $deptId; }
    // विभाग नसलेला — फक्त Finance-Permission असेल तरच संपूर्ण संस्था, नाहीतर काहीच नाही
    return hasPermission('manage_dept_finance') ? null : 0;
}

/** हा User या विभागाचं Finance हाताळू शकतो का */
function deptFinanceCanAccessDept($user, $departmentId) {
    $acc = deptFinanceAccessibleDeptId($user);
    return $acc === null || ($acc > 0 && (int)$acc === (int)$departmentId);
}

/**
 * नूतनीकरण — फक्त "चालू Cycle" चे Payments (शेवटच्या Renew नंतर नोंदवलेले).
 * आधीच्या Cycle चे Payments चालू Cost मधून वजा करायचे नाहीत.
 */
function deptFinanceRenewalCycleStart($pdo, $renewalItemId) {
    try {
        $st = $pdo->prepare("SELECT MAX(renewed_at) FROM renewal_history WHERE renewal_item_id = ?");
        $st->execute([$renewalItemId]);
        return $st->fetchColumn() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** चालू Cycle मध्ये किती भरलं (Renewal) */
function deptFinanceRenewalCyclePaid($pdo, $renewalItemId) {
    $start = deptFinanceRenewalCycleStart($pdo, $renewalItemId);
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM dept_payment_ledger WHERE source_type = 'renewal' AND source_id = ?";
    $params = [$renewalItemId];
    if ($start) { $sql .= " AND created_at >= ?"; $params[] = $start; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (float)$st->fetchColumn();
}

/** एका Source (Purchase/Renewal/Maintenance) साठी आतापर्यंत किती भरलं */
function deptFinancePaidTotal($pdo, $sourceType, $sourceId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) total FROM dept_payment_ledger WHERE source_type = ? AND source_id = ?");
    $stmt->execute([$sourceType, $sourceId]);
    return (float)$stmt->fetch()['total'];
}

/** एकाच वेळी अनेक Sources साठी Paid-Totals — N+1 Query टाळण्यासाठी */
function deptFinancePaidTotalsBulk($pdo, $sourceType, array $sourceIds) {
    if (empty($sourceIds)) { return []; }
    $in = implode(',', array_fill(0, count($sourceIds), '?'));
    $stmt = $pdo->prepare("SELECT source_id, COALESCE(SUM(amount), 0) total FROM dept_payment_ledger WHERE source_type = ? AND source_id IN ($in) GROUP BY source_id");
    $stmt->execute(array_merge([$sourceType], $sourceIds));
    $out = [];
    foreach ($stmt->fetchAll() as $r) { $out[$r['source_id']] = (float)$r['total']; }
    return $out;
}

function deptFinanceStatusLabel($totalAmount, $paidAmount) {
    if ($paidAmount <= 0) { return ['🔴 Pending', 'overdue']; }
    if ($paidAmount >= $totalAmount) { return ['🟢 पूर्ण Paid', 'done']; }
    return ['🟡 अंशतः Paid', 'pending'];
}

/** Recurring-Monthly Bill साठी — Start-Month पासून आजपर्यंत किती Months "Due" आहेत, आणि कोणत्या महिन्यांचं अजून Payment आलेलं नाही */
function deptFinanceRecurringMonths($startMonth, $monthlyAmount, array $paidMonths) {
    $months = [];
    if (empty($startMonth) || empty($monthlyAmount)) { return $months; }
    $cursor = new DateTime(date('Y-m-01', strtotime($startMonth)));
    $today = new DateTime(date('Y-m-01'));
    while ($cursor <= $today) {
        $key = $cursor->format('Y-m');
        $months[] = ['month' => $key, 'label' => $cursor->format('M Y'), 'paid' => in_array($key, $paidMonths, true)];
        $cursor->modify('+1 month');
    }
    return $months;
}

/**
 * विभाग-निहाय Paid vs Due — Material Purchase, नूतनीकरण (फक्त चालू Cycle), देखभाल.
 * परत: [dept_id => ['material' => ['total'=>, 'paid'=>], 'renewal' => [...], 'maintenance' => [...]]]
 */
function deptFinancePaidDueByDept($pdo, $orgId, $filterDeptId = null) {
    $out = [];
    $add = function ($deptId, $key, $total, $paid) use (&$out) {
        if (!isset($out[$deptId][$key])) { $out[$deptId][$key] = ['total' => 0.0, 'paid' => 0.0]; }
        $out[$deptId][$key]['total'] += (float)$total;
        $out[$deptId][$key]['paid'] += (float)$paid;
    };
    $deptSql = $filterDeptId ? " AND x.department_id = " . (int)$filterDeptId : "";

    // 1. Material Purchase
    $stmt = $pdo->prepare("
        SELECT x.department_id, SUM(x.total_amount) total,
          SUM((SELECT COALESCE(SUM(l.amount),0) FROM dept_payment_ledger l WHERE l.source_type = 'material_purchase' AND l.source_id = x.id)) paid
        FROM dept_material_purchases x WHERE x.organization_id = ? $deptSql GROUP BY x.department_id
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) { $add($r['department_id'], 'material', $r['total'], $r['paid']); }

    // 2. नूतनीकरण — चालू Cycle चेच Payments
    try {
        $stmt = $pdo->prepare("SELECT x.* FROM renewal_items x WHERE x.organization_id = ? $deptSql AND (x.renewal_cost IS NOT NULL OR x.is_recurring_monthly = 1)");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $ri) {
            if (!empty($ri['is_recurring_monthly'])) {
                $pm = $pdo->prepare("SELECT DATE_FORMAT(for_month, '%Y-%m') m FROM dept_payment_ledger WHERE source_type = 'renewal' AND source_id = ? AND for_month IS NOT NULL");
                $pm->execute([$ri['id']]);
                $months = deptFinanceRecurringMonths($ri['recurring_start_month'], $ri['monthly_amount'], $pm->fetchAll(PDO::FETCH_COLUMN));
                $total = count($months) * (float)$ri['monthly_amount'];
                $paid = count(array_filter($months, fn($m) => $m['paid'])) * (float)$ri['monthly_amount'];
                $add($ri['department_id'], 'renewal', $total, $paid);
            } else {
                $add($ri['department_id'], 'renewal', (float)$ri['renewal_cost'], deptFinanceRenewalCyclePaid($pdo, $ri['id']));
            }
        }
    } catch (PDOException $e) {}

    // 3. देखभाल
    try {
        $stmt = $pdo->prepare("
            SELECT x.department_id, SUM(x.cost) total,
              SUM((SELECT COALESCE(SUM(l.amount),0) FROM dept_payment_ledger l WHERE l.source_type = 'maintenance' AND l.source_id = x.id)) paid
            FROM maintenance_logs x WHERE x.organization_id = ? AND x.cost IS NOT NULL $deptSql GROUP BY x.department_id
        ");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $r) { $add($r['department_id'], 'maintenance', $r['total'], $r['paid']); }
    } catch (PDOException $e) {}

    return $out;
}

/** Dashboard/Export साठी — विभाग-निहाय Budget vs Actual + Paid/Due, एकाच ठिकाणी */
function deptFinanceSummaryRows($pdo, $orgId, $fy, $filterDeptId = null) {
    $sql = "SELECT d.id, d.name_local, d.name, c.name AS campus_name FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id WHERE d.organization_id = ? AND d.is_active = 1";
    $params = [$orgId];
    if ($filterDeptId) { $sql .= " AND d.id = ?"; $params[] = $filterDeptId; }
    $sql .= " ORDER BY c.name, d.name_local";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $paidDue = deptFinancePaidDueByDept($pdo, $orgId, $filterDeptId);
    $rows = [];
    foreach ($st->fetchAll() as $d) {
        $b = getBudgetStatus($pdo, $orgId, $d['id'], $fy);
        $pd = $paidDue[$d['id']] ?? [];
        $owed = 0; $paid = 0;
        foreach ($pd as $v) { $owed += $v['total']; $paid += $v['paid']; }
        $rows[] = [
            'id' => (int)$d['id'], 'name' => $d['name_local'] ?: $d['name'], 'campus_name' => $d['campus_name'],
            'allocated' => $b['allocated'], 'spent' => $b['spent'], 'remaining' => $b['remaining'],
            'percent_used' => $b['percent_used'], 'has_budget' => $b['has_budget'], 'by_source' => $b['by_source'],
            'paid_due' => $pd, 'owed' => $owed, 'paid' => $paid, 'due' => max(0, $owed - $paid),
        ];
    }
    return $rows;
}
